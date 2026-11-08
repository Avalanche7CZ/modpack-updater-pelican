<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\SafetyPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use RuntimeException;

/** Recoverable, ordered operations, NOT an atomic filesystem transaction. Never replay apply. */
final class Transaction
{
    public function __construct(private readonly Files $files) {}

    /**
     * Caller owns the durable backup, stopped-container guard and exclusive operation receipt.
     * $before/$after contain ONLY approved mutations; null means an absent file.
     * Every journal intent must be durably saved before its corresponding remote mutation.
     */
    public function apply(string $id, string $stage, array $before, array $after, array $safetyRoots, \Closure $journal): void
    {
        if (! preg_match('/^[a-f0-9-]{32,36}$/D', $id) || array_keys($before) !== array_keys($after)) { throw new RuntimeException('Invalid native transaction identity or scope.'); }
        $remote = '.arcturus-update-'.$id;
        if ($this->files->stat($remote) !== null) { throw new RuntimeException('A retained native stage exists. Recover its operation; do not replay apply.'); }
        foreach ($before as $path => $hash) {
            UpdatePlanner::assertPath($path);
            if (SafetyPaths::reason($path, $safetyRoots) !== null || str_starts_with($path, '.arcturus-update-')) { throw new RuntimeException('Protected path cannot enter a native transaction: '.$path); }
            foreach ([$hash, $after[$path]] as $digest) { if ($digest !== null && (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest))) { throw new RuntimeException('Invalid transaction file digest.'); } }
        }
        $installs = array_keys(array_filter($after, fn ($h) => $h !== null));
        $actual = (new DirectoryInventory)->hashPaths($stage, $installs);
        $expected = array_filter($after, fn ($h) => $h !== null); ksort($expected);
        if ($actual !== $expected) { throw new RuntimeException('Private stage differs from the approved target manifest.'); }
        $this->verify($before);
        $journal(['step' => 'stage_intent', 'stage' => $remote, 'before' => $before, 'after' => $after]);
        $this->files->mkdir($remote.'/new'); $this->files->mkdir($remote.'/old');
        foreach ($installs as $index => $path) {
            $journal(['step' => 'upload_intent', 'path' => $path]);
            $this->files->upload($stage.'/'.$path, $remote.'/new/'.$index, $after[$path]);
            $journal(['step' => 'upload_verified', 'path' => $path]);
        }
        // All target bytes are present and verified before the first live-path mutation.
        $this->verify($before);
        $order = [...$installs, ...array_keys(array_filter($after, fn ($h) => $h === null))];
        foreach ($order as $index => $path) {
            if ($this->files->hash($path) !== $before[$path]) { throw new RuntimeException('Live file changed since approval: '.$path); }
            if ($before[$path] !== null) {
                $journal(['step' => 'retire_intent', 'path' => $path, 'saved' => $remote.'/old/'.$index]);
                $this->files->rename($path, $remote.'/old/'.$index);
                if ($this->files->hash($remote.'/old/'.$index) !== $before[$path]) { throw new RuntimeException('Retired file failed integrity verification.'); }
            }
            if ($after[$path] !== null) {
                $parent = dirname($path); if ($parent !== '.') { $this->files->mkdir($parent); }
                $journal(['step' => 'replace_intent', 'path' => $path]);
                $this->files->rename($remote.'/new/'.array_search($path, $installs, true), $path);
            }
            if ($this->files->hash($path) !== $after[$path]) { throw new RuntimeException('Installed file failed integrity verification: '.$path); }
            $journal(['step' => 'file_verified', 'path' => $path]);
        }
        $this->verify($after);
        $journal(['step' => 'cleanup_intent', 'stage' => $remote]);
        $this->files->delete($remote);
        $journal(['step' => 'files_verified']);
    }

    public function verify(array $expected): void
    {
        foreach ($expected as $path => $digest) { if ($this->files->hash($path) !== $digest) { throw new RuntimeException('Native verification mismatch: '.$path); } }
    }
}
