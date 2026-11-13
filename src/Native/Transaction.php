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

    /** One physical scope for qualification, approval, deployment and recovery. */
    public static function scope(array $changes): array
    {
        $before = []; $after = []; $moves = [];
        foreach (\Arcturus\ModpackManager\Services\ModUpgradePlanner::mutations($changes) as $change) {
            $action = $change['action'];
            if (! in_array($action, ['add', 'update', 'replace_with_override', 'remove', 'remove_with_override', 'disable_requires_enforcement'], true)) { continue; }
            $path = $change['path'];
            if (array_key_exists($path, $before)) { throw new RuntimeException('Overlapping native mutations require a new comparison: '.$path); }
            $before[$path] = $change['local_sha256'];
            $after[$path] = in_array($action, ['remove', 'remove_with_override', 'disable_requires_enforcement'], true) ? null : $change['target_sha256'];
            if ($action === 'disable_requires_enforcement') {
                $destination = $path.'.disabled';
                if (! preg_match('~^mods/(?:[^/]+/)*[^/]+\\.jar$~iD', $path) || $before[$path] === null || array_key_exists($destination, $before)) { throw new RuntimeException('Only an existing mod JAR can be disabled without overwriting another mutation.'); }
                $before[$destination] = null; $after[$destination] = $before[$path]; $moves[$path] = $destination;
            }
        }
        ksort($before); ksort($after); ksort($moves);
        return ['before' => $before, 'after' => $after, 'moves' => $moves];
    }

    /**
     * Caller owns the durable backup, stopped-container guard and exclusive operation receipt.
     * $before/$after contain ONLY approved mutations; null means an absent file.
     * Every journal intent must be durably saved before its corresponding remote mutation.
     */
    public function apply(string $id, string $stage, array $before, array $after, array $safetyRoots, \Closure $journal, array $moves = []): void
    {
        if (! preg_match('/^[a-f0-9-]{32,36}$/D', $id) || array_keys($before) !== array_keys($after)) { throw new RuntimeException('Invalid native transaction identity or scope.'); }
        $remote = '.arcturus-update-'.$id;
        if ($this->files->stat($remote) !== null) { throw new RuntimeException('A retained native stage exists. Recover its operation; do not replay apply.'); }
        foreach ($before as $path => $hash) {
            UpdatePlanner::assertPath($path);
            if (SafetyPaths::reason($path, $safetyRoots) !== null || str_starts_with($path, '.arcturus-update-')) { throw new RuntimeException('Protected path cannot enter a native transaction: '.$path); }
            foreach ([$hash, $after[$path]] as $digest) { if ($digest !== null && (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D', $digest))) { throw new RuntimeException('Invalid transaction file digest.'); } }
        }
        $moved = [];
        foreach ($moves as $from => $to) {
            if (! is_string($to) || ! preg_match('~^mods/(?:[^/]+/)*[^/]+\\.jar$~iD', $from) || $to !== $from.'.disabled' ||
                ! array_key_exists($from, $before) || ! array_key_exists($to, $before) || $before[$from] === null || $before[$to] !== null || $after[$from] !== null || $after[$to] !== $before[$from] || isset($moved[$from]) || isset($moved[$to])) {
                throw new RuntimeException('Invalid approved disabled-mod rename.');
            }
            if ($this->files->stat($to) !== null) { throw new RuntimeException('Disabled mod destination already exists: '.$to.'. Review it in Pelican Files and compare again.'); }
            $moved[$from] = true; $moved[$to] = true;
        }
        $installs = array_keys(array_filter(array_diff_key($after, $moved), fn ($h) => $h !== null));
        $actual = (new DirectoryInventory)->hashPaths($stage, $installs);
        $expected = array_filter(array_diff_key($after, $moved), fn ($h) => $h !== null); ksort($expected);
        if ($actual !== $expected) { throw new RuntimeException('Private stage differs from the approved target manifest.'); }
        $this->verify($before);
        $modes = []; foreach ($before as $path => $digest) { if ($digest !== null) { $modes[$path] = $this->files->permissions($path); } }
        $journal(['step' => 'stage_intent', 'stage' => $remote, 'before' => $before, 'after' => $after, 'moves' => $moves]);
        $this->files->mkdir($remote.'/new'); $this->files->mkdir($remote.'/old');
        foreach ($installs as $index => $path) {
            $journal(['step' => 'upload_intent', 'path' => $path]);
            $this->files->upload($stage.'/'.$path, $remote.'/new/'.$index, $after[$path]);
            if (isset($modes[$path])) { $this->files->permissions($remote.'/new/'.$index, $modes[$path]); }
            $journal(['step' => 'upload_verified', 'path' => $path]);
        }
        // All target bytes are present and verified before the first live-path mutation.
        $this->verify($before);
        foreach ($moves as $from => $to) {
            if ($this->files->hash($from) !== $before[$from] || $this->files->permissions($from) !== $modes[$from] || $this->files->stat($to) !== null) { throw new RuntimeException('Disabled mod source or destination changed after approval.'); }
            $journal(['step' => 'disable_intent', 'path' => $from, 'destination' => $to]);
            $this->files->rename($from, $to);
            if ($this->files->hash($from) !== null || $this->files->hash($to) !== $before[$from] || $this->files->permissions($to) !== $modes[$from]) { throw new RuntimeException('Disabled mod rename failed integrity or permission verification.'); }
            $journal(['step' => 'disable_verified', 'path' => $from, 'destination' => $to]);
        }
        $order = [...$installs, ...array_keys(array_filter(array_diff_key($after, $moved), fn ($h) => $h === null))];
        foreach ($order as $index => $path) {
            if ($this->files->hash($path) !== $before[$path]) { throw new RuntimeException('Live file changed since approval: '.$path); }
            if ($before[$path] !== null) {
                if ($this->files->permissions($path) !== $modes[$path]) { throw new RuntimeException('Live permissions changed during transfer: '.$path); }
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

    public function verify(array $expected, ?\Closure $progress = null): void
    {
        // Directory ordering improves sharing without broadening the explicit approved scope.
        uksort($expected, fn ($a, $b) => [dirname($a), $a] <=> [dirname($b), $b]);
        $done = 0; $total = count($expected);
        foreach (array_chunk($expected, 32, true) as $batch) {
            $hashes = $this->files instanceof BatchFiles ? $this->files->hashBatch(array_keys($batch)) : null;
            foreach ($batch as $path => $digest) {
                if (($hashes !== null ? $hashes[$path] : $this->files->hash($path)) !== $digest) { throw new RuntimeException('Native verification mismatch: '.$path); }
                $done++;
            }
            $progress?->__invoke($done, $total);
        }
    }
}
