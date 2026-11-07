<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Offline transaction: hide the old JAR before publishing the new JAR, with a durable recovery receipt. */
final class ModUpgradeTransaction
{
    public function requireSnapshot(string $root, array $expected, ?\Closure $heartbeat = null): void
    {
        $actual = []; $directory = $root.'/mods';
        if (is_link($directory) || ! is_dir($directory) || ! is_readable($directory)) { throw new RuntimeException('Cannot verify the active mod directory before a logical upgrade.'); }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $heartbeat?->__invoke();
            if ($entry->isLink()) { throw new RuntimeException('Mod directory contains unscanned symlinks. Logical upgrade needs a complete active-JAR inventory.'); }
            $path = substr($entry->getPathname(), strlen($root) + 1);
            if (UpdatePlanner::isMod($path)) {
                if (! $entry->isFile()) { throw new RuntimeException('Active JAR path is not a regular file.'); }
                $actual[] = $path;
            }
        }
        $paths = array_keys($expected); sort($paths, SORT_STRING); sort($actual, SORT_STRING);
        if ($paths !== $actual || (new DirectoryInventory)->hashPaths($root, $paths, $heartbeat) !== $this->sorted($expected)) {
            throw new RuntimeException('The active JAR set changed since approval. Keep Minecraft stopped; compare again before any upgrade.');
        }
    }

    private function sorted(array $hashes): array { ksort($hashes, SORT_STRING); return $hashes; }

    public function apply(string $root, string $stage, array $change, \Closure $receipt, ?\Closure $heartbeat = null): void
    {
        $upgrade = $change['mod_upgrade'] ?? [];
        if (($upgrade['schema'] ?? null) !== ModUpgradePlanner::SCHEMA || ($upgrade['identity_verified'] ?? false) !== true || $change['action'] !== 'upgrade_mod') {
            throw new RuntimeException('Logical upgrade has no verified embedded identity operation.');
        }
        $old = $upgrade['installed']['path'] ?? ''; $new = $upgrade['target']['path'] ?? '';
        foreach ([$old, $new] as $path) {
            UpdatePlanner::assertPath($path);
            if (! UpdatePlanner::isMod($path) || SafetyPaths::reason($path) !== null) { throw new RuntimeException('Logical upgrade may touch only safe mod JARs.'); }
        }
        $files = new SameHostDeployment; $inventory = new DirectoryInventory;
        $oldFile = $files->regular($root, $old); $source = $files->regular($stage, $new); $destination = $root.'/'.$new;
        if (($inventory->hashPaths($root, [$old], $heartbeat)[$old] ?? null) !== $upgrade['installed']['sha256'] ||
            ($inventory->hashPaths($stage, [$new], $heartbeat)[$new] ?? null) !== $upgrade['target']['sha256']) { throw new RuntimeException('Logical upgrade source changed after approval.'); }
        if (realpath(dirname($destination)) !== dirname($destination) || ! is_dir(dirname($destination))) { throw new RuntimeException('Target JAR parent must be an existing safe mod directory.'); }
        if ($old !== $new && (file_exists($destination) || is_link($destination))) { throw new RuntimeException('The target JAR path is already occupied; no second version installed.'); }
        $token = bin2hex(random_bytes(16));
        $pending = dirname($destination).'/.arcturus-mod-upgrade-'.$token.'.pending';
        $held = dirname($oldFile).'/.arcturus-mod-upgrade-'.$token.'.original';
        $details = ['mod_identity' => $upgrade['identity'], 'old_path' => $old, 'new_path' => $new,
            'pending_path' => substr($pending, strlen($root) + 1), 'held_original_path' => substr($held, strlen($root) + 1)];
        $hidden = false; $published = false; $restoreFailed = false; $publishedInode = null;
        try {
            $receipt('mod_upgrade_preparing', $details); // Record both private recovery names before creating or renaming anything.
            $input = fopen($source, 'rb'); $output = fopen($pending, 'x+b');
            if (! $input || ! $output) { if (is_resource($input)) { fclose($input); } if (is_resource($output)) { fclose($output); } throw new RuntimeException('Cannot stage the verified target JAR beside its destination.'); }
            try {
                while (! feof($input)) {
                    $chunk = fread($input, 1048576);
                    if ($chunk === false || fwrite($output, $chunk) !== strlen($chunk)) { throw new RuntimeException('Target JAR copy failed; original is retained.'); }
                    $heartbeat?->__invoke();
                }
                if (! fflush($output) || ! fsync($output)) { throw new RuntimeException('Cannot flush staged target JAR.'); }
            } finally { fclose($input); fclose($output); }
            $pendingPath = substr($pending, strlen($root) + 1);
            if (($inventory->hashPaths($root, [$pendingPath], $heartbeat)[$pendingPath] ?? null) !== $upgrade['target']['sha256'] ||
                ($inventory->hashPaths($root, [$old], $heartbeat)[$old] ?? null) !== $upgrade['installed']['sha256']) { throw new RuntimeException('JAR changed during transaction preparation; no upgrade published.'); }
            $receipt('mod_upgrade_hiding_original', $details);
            if (! rename($oldFile, $held)) { throw new RuntimeException('Cannot retain original JAR before publishing the target.'); }
            $hidden = true;
            // link() publishes without replacing an unexpected concurrently created destination.
            $publishedInode = fileinode($pending);
            if (! link($pending, $destination)) { throw new RuntimeException('Cannot publish the verified target JAR without overwriting another file.'); }
            $published = true;
            if (($inventory->hashPaths($root, [$new], $heartbeat)[$new] ?? null) !== $upgrade['target']['sha256'] || ($old !== $new && file_exists($oldFile))) {
                throw new RuntimeException('Logical JAR upgrade verification failed.');
            }
            $receipt('mod_upgrade_installed', $details);
            if (! unlink($pending)) { throw new RuntimeException('Cannot clean up the staged target JAR.'); }
            if (! unlink($held)) { throw new RuntimeException('Cannot finalize retained original JAR; recovery is required.'); }
            $hidden = false;
        } catch (Throwable $error) {
            try {
                if ($published) {
                    clearstatcache(true, $destination);
                    if (is_link($destination) || ! is_file($destination) || fileinode($destination) !== $publishedInode || ! unlink($destination)) { throw new RuntimeException('Target changed during recovery; do not overwrite concurrent content.'); }
                }
                if ($hidden && (! link($held, $oldFile) || ! unlink($held))) { throw new RuntimeException('Cannot restore the retained original JAR without overwriting another file.'); }
                if ($hidden && ($inventory->hashPaths($root, [$old], $heartbeat)[$old] ?? null) !== $upgrade['installed']['sha256']) { throw new RuntimeException('Restored original JAR hash mismatch.'); }
                $receipt('mod_upgrade_reverted', $details);
            } catch (Throwable) {
                $restoreFailed = true;
                throw new RuntimeException('Mod upgrade recovery is incomplete. Keep Minecraft stopped; restore the locked full backup and inspect the logical upgrade journal.');
            }
            throw new RuntimeException('Mod upgrade aborted; original JAR retained. '.$error->getMessage());
        } finally {
            if (is_file($pending) && ! @unlink($pending) && ! $restoreFailed) { throw new RuntimeException('Temporary target JAR cleanup failed. Keep Minecraft stopped and use the recorded transaction journal.'); }
        }
    }
}
