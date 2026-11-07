<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use RuntimeException;

/** Bounded recovery receipt. Complete server data belongs to Pelican/Wings backups. */
final class RestoreInventory
{
    public function capture(string $root, array $changes, array $launchHashes, array $safetyRoots = [], ?\Closure $heartbeat = null, array $launchLinks = []): array
    {
        $safetyRoots = array_values(array_unique([...$safetyRoots, ...(new DirectoryInventory)->safetyRoots($root)]));
        $hashes = $launchHashes;
        foreach (ModUpgradePlanner::mutations($changes) as $change) {
            if (! in_array($change['action'], ['add', 'update', 'remove', 'replace_with_override', 'remove_with_override', 'disable_requires_enforcement'], true)) { continue; }
            if (! array_key_exists('local_sha256', $change)) { throw new RuntimeException('Approved change has no pre-update hash. Compare and approve again.'); }
            $path = $change['path'];
            if (array_key_exists($path, $hashes) && $hashes[$path] !== $change['local_sha256']) { throw new RuntimeException('Conflicting launcher recovery evidence.'); }
            $hashes[$path] = $change['local_sha256'];
        }
        ksort($hashes);
        $receipt = ['schema' => 1, 'hashes' => $hashes, 'safety_roots' => $safetyRoots, 'links' => $launchLinks];
        $this->verify($root, $receipt, $heartbeat);
        return $receipt;
    }

    public function verify(string $root, array $receipt, ?\Closure $heartbeat = null): void
    {
        if (($receipt['schema'] ?? null) !== 1 || ! is_array($receipt['hashes'] ?? null) || ! $receipt['hashes'] || ! is_array($receipt['safety_roots'] ?? null)) {
            throw new RuntimeException('No scoped pre-update verification receipt. Keep the recovery checkpoint and request host-operator recovery.');
        }
        // Validate the complete scope before opening any file. Never enumerate directories.
        foreach ($receipt['hashes'] as $path => $hash) {
            UpdatePlanner::assertPath($path);
            if (SafetyPaths::reason($path, $receipt['safety_roots']) !== null || ($hash !== null && (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)))) {
                throw new RuntimeException('Unsafe recovery verification scope; keep Minecraft stopped.');
            }
        }
        foreach ($receipt['links'] ?? [] as $path => $target) {
            // Only the qualified root-level Forge argument alias is supported, never arbitrary links.
            if ($path !== 'unix_args.txt' || ! is_string($target) || ! is_link($root.'/'.$path) || readlink($root.'/'.$path) !== $target) {
                throw new RuntimeException('Original launcher symlink changed. Restore the exact backup and keep Minecraft stopped.');
            }
        }
        $inventory = new DirectoryInventory;
        $inventory->hashPaths($root, []); // Validate the root without enumerating it.
        if (! is_executable($root)) { throw new RuntimeException('Managed recovery root requires traversal access.'); }
        foreach (array_keys($receipt['hashes']) as $path) {
            $parent = rtrim($root, '/');
            $segments = explode('/', $path); array_pop($segments);
            foreach ($segments as $segment) {
                if (! is_executable($parent)) { throw new RuntimeException('Cannot verify managed-file absence: parent traversal unavailable for '.$path); }
                $parent .= '/'.$segment;
                if (is_link($parent)) { throw new RuntimeException('Symlink in managed recovery scope: '.$path); }
                if (! file_exists($parent)) { break; } // Confirmed absent ancestor, not EACCES.
                if (! is_dir($parent) || ! is_executable($parent)) { throw new RuntimeException('Cannot verify managed recovery path: '.$path); }
            }
        }
        $actual = $inventory->hashPaths($root, array_keys($receipt['hashes']), $heartbeat);
        foreach ($receipt['hashes'] as $path => $hash) {
            // Absence is evidence too: newly installed files must disappear after restore.
            if (($actual[$path] ?? null) !== $hash) { throw new RuntimeException('Managed file or launcher differs from its pre-update hash: '.$path.'. Restore the exact locked backup; keep Minecraft stopped.'); }
        }
    }
}
