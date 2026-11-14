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
    public function __construct(private readonly Files $files, private readonly ?ApplyBudget $budget = null) {}

    private function unit(\Closure $work): mixed
    { return $this->budget !== null ? $this->budget->run($work) : $work(); }

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
    public function apply(string $id, string $stage, array $before, array $after, array $safetyRoots, \Closure $journal, array $moves = [], ?\Closure $progress = null, ?PreparedStaging $prepared = null, string $verification = 'parallel'): void
    {
        if (! preg_match('/^[a-f0-9-]{32,36}$/D', $id) || array_keys($before) !== array_keys($after)) { throw new RuntimeException('Invalid native transaction identity or scope.'); }
        $remote = '.arcturus-update-'.$id;
        if ($this->unit(fn () => $this->files->stat($remote)) !== null) { throw new RuntimeException('A retained native stage exists. Recover its operation; do not replay apply.'); }
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
        $report = fn (string $step, int $done, int $total) => $progress?->__invoke($step, $done, $total);
        if ($prepared !== null) {
            if (! $this->files instanceof BulkStaging) { throw new RuntimeException('Bulk transport was not selected before mutation.'); }
            $prepared->requirePlan($after, $moves);
        }
        $report('stage_verification', $prepared !== null ? count($installs) : 0, count($installs));
        $expected = array_filter(array_diff_key($after, $moved), fn ($h) => $h !== null); ksort($expected);
        $actual = []; $localDone = 0;
        foreach ($prepared !== null ? [] : array_chunk($installs, 32) as $batch) {
            $this->unit(function () use ($installs, $stage, $report, $expected, &$actual, &$localDone, $batch): void {
                $checked = (new DirectoryInventory)->hashPaths($stage, $batch, function () use ($report, &$localDone, $installs): void { $this->budget?->timeout(600); $report('stage_verification', $localDone, count($installs)); });
                $wanted = array_intersect_key($expected, array_flip($batch));
                if ($checked !== $wanted) { throw new RuntimeException('Private stage differs from the approved target manifest.'); }
                $actual += $checked;
                $localDone += count($batch); $report('stage_verification', $localDone, count($installs));
            });
        }
        ksort($actual);
        if ($prepared === null && $actual !== $expected) { throw new RuntimeException('Private stage differs from the approved target manifest.'); }
        if ($prepared === null) {
            $report('live_verification', 0, count($before));
            $this->verify($before, fn ($done, $total) => $report('live_verification', $done, $total));
        }
        $permissionPaths = array_keys(array_filter($before, fn ($h) => $h !== null));
        $report('permissions', 0, count($permissionPaths)); $modes = [];
        usort($permissionPaths, fn ($a, $b) => [dirname($a), $a] <=> [dirname($b), $b]);
        foreach (array_chunk($permissionPaths, 32) as $batch) {
            $this->unit(function () use ($batch, &$modes, $report, $permissionPaths): void {
                if ($this->files instanceof MutationBatches) { $modes += $this->files->permissionsBatch($batch); }
                else { foreach ($batch as $path) { $modes[$path] = $this->files->permissions($path); } }
                $report('permissions', count($modes), count($permissionPaths));
            });
        }
        $this->unit(function () use ($journal, $remote, $before, $after, $moves): void {
            $journal(['step' => 'stage_intent', 'stage' => $remote, 'before' => $before, 'after' => $after, 'moves' => $moves]);
            $this->files->mkdir($remote.'/new'); $this->files->mkdir($remote.'/old');
        });
        $report('upload', 0, count($installs));
        $stageModes = []; $uploaded = 0; $candidates = [];
        if ($prepared !== null) {
            foreach ($prepared->bundles as $bundleIndex => $bundle) {
                foreach ($bundle['members'] as $index => $row) { $candidates[$row['path']] = $remote.'/new/b'.$bundleIndex.'/'.$index; }
            }
        }
        if ($prepared !== null) {
            foreach ($installs as $index => $path) { if (isset($modes[$path])) { $stageModes[$candidates[$path]] = $modes[$path]; } }
            foreach ($prepared->bundles as $bundleIndex => $bundle) {
                $directory = $remote.'/new/b'.$bundleIndex;
                $this->unit(function () use ($remote, $directory, $bundle, $verification, $journal): void {
                    $journal(['step' => 'bundle_intent', 'stage' => $remote, 'directory' => $directory, 'archive_pending' => true,
                        'bundle_sha256' => $bundle['sha256'], 'members' => array_map(fn ($r) => array_diff_key($r, ['local' => true]), $bundle['members'])]);
                    $this->files->mkdir($directory);
                    $this->files->uploadBundle($directory, $bundle, $verification);
                    $journal(['step' => 'bundle_verified', 'archive_pending' => false, 'members' => array_keys($bundle['members'])]);
                });
                $uploaded += count($bundle['members']); $report('upload', $uploaded, count($installs));
            }
            $rootMembers = [];
            foreach ($this->files->listing($remote.'/new') as $row) {
                if ($row['file'] || ! $row['directory'] || $row['symlink']) { throw new RuntimeException('Unexpected staging directory type.'); }
                $rootMembers[] = $row['name'];
            }
            $wantedRoots = [];
            foreach ($prepared->bundles as $bundleIndex => $bundle) {
                $wantedRoots[] = 'b'.$bundleIndex;
                $wanted = array_map('strval', array_keys($bundle['members'])); $found = [];
                foreach ($this->files->listing($remote.'/new/b'.$bundleIndex) as $row) {
                    if (! $row['file'] || $row['directory'] || $row['symlink']) { throw new RuntimeException('Unexpected staging member type.'); }
                    $found[] = $row['name'];
                }
                sort($wanted, SORT_STRING); sort($found, SORT_STRING);
                if ($found !== $wanted) { throw new RuntimeException('Extracted staging member set differs from the approved plan.'); }
            }
            sort($rootMembers, SORT_STRING); sort($wantedRoots, SORT_STRING);
            if ($rootMembers !== $wantedRoots) { throw new RuntimeException('Unexpected staging bundle directory.'); }
        } elseif ($this->files instanceof StagingUploads) {
            // Four files and normally <=64 MiB per unit; one larger file is its own unit.
            $batches = []; $batch = []; $bytes = 0;
            foreach ($installs as $index => $path) {
                $size = filesize($stage.'/'.$path);
                if ($batch !== [] && (count($batch) >= 4 || $bytes + $size > 67108864)) { $batches[] = $batch; $batch = []; $bytes = 0; }
                $batch[$remote.'/new/'.$index] = ['local' => $stage.'/'.$path, 'sha256' => $after[$path]]; $bytes += $size;
                if (isset($modes[$path])) { $stageModes[$remote.'/new/'.$index] = $modes[$path]; }
            }
            if ($batch !== []) { $batches[] = $batch; }
            foreach ($batches as $batch) {
                $this->unit(function () use ($batch, $remote, $journal): void {
                    $journal(['step' => 'upload_batch_intent', 'stage' => $remote, 'files' => array_map(fn ($row) => $row['sha256'], $batch)]);
                    $this->files->uploadBatch($remote.'/new', $batch);
                    $journal(['step' => 'upload_batch_verified', 'paths' => array_keys($batch)]);
                });
                $uploaded += count($batch); $report('upload', $uploaded, count($installs));
            }
        } else {
            foreach ($installs as $index => $path) {
                $this->unit(function () use ($journal, $path, $stage, $remote, $index, $after, $modes, &$stageModes): void {
                    $journal(['step' => 'upload_intent', 'path' => $path]);
                    $this->files->upload($stage.'/'.$path, $remote.'/new/'.$index, $after[$path]);
                    if (isset($modes[$path])) {
                        if ($this->files instanceof MutationBatches) { $stageModes[$remote.'/new/'.$index] = $modes[$path]; }
                        else { $this->files->permissions($remote.'/new/'.$index, $modes[$path]); }
                    }
                    $journal(['step' => 'upload_verified', 'path' => $path]);
                });
                $report('upload', $index + 1, count($installs));
            }
        }
        $report('stage_permissions', 0, count($stageModes)); $chmodDone = 0;
        foreach (array_chunk($stageModes, 32, true) as $batch) {
            $this->unit(function () use ($batch, $journal, &$chmodDone, $report, $stageModes): void {
                $journal(['step' => 'chmod_batch_intent', 'modes' => $batch]);
                $this->files->chmodBatch($batch); $chmodDone += count($batch);
                $report('stage_permissions', $chmodDone, count($stageModes));
            });
        }
        // All target bytes are present and verified before the first live-path mutation.
        // Bulk path checks every live batch immediately before its mutations below.
        if ($prepared === null) {
            $report('live_recheck', 0, count($before));
            $this->verify($before, fn ($done, $total) => $report('live_recheck', $done, $total));
        }
        $deployed = 0; $report('deploy', 0, count($after));
        foreach ($moves as $from => $to) {
            $this->unit(function () use ($from, $to, $before, $after, $modes, $journal, &$deployed, $report): void {
                if ($this->files->hash($from) !== $before[$from] || $this->files->permissions($from) !== $modes[$from] || $this->files->stat($to) !== null) { throw new RuntimeException('Disabled mod source or destination changed after approval.'); }
                $journal(['step' => 'disable_intent', 'path' => $from, 'destination' => $to]);
                $this->files->rename($from, $to);
                if ($this->files->hash($from) !== null || $this->files->hash($to) !== $before[$from] || $this->files->permissions($to) !== $modes[$from]) { throw new RuntimeException('Disabled mod rename failed integrity or permission verification.'); }
                $journal(['step' => 'disable_verified', 'path' => $from, 'destination' => $to]);
                $deployed += 2; $report('deploy', $deployed, count($after));
            });
        }
        $order = [...$installs, ...array_keys(array_filter(array_diff_key($after, $moved), fn ($h) => $h === null))];
        if ($this->files instanceof MutationBatches) {
            $installIndexes = array_flip($installs);
            $oldDirectories = [];
            foreach (array_chunk($order, $prepared !== null ? 64 : 16, true) as $batch) {
                $this->unit(function () use ($batch, $before, $after, $modes, $remote, $installIndexes, $journal, &$deployed, $report, $prepared, $candidates, &$oldDirectories): void {
                    $live = array_intersect_key($before, array_flip($batch));
                    $this->verify($live);
                    $existing = array_keys(array_filter($live, fn ($h) => $h !== null));
                    $currentModes = $this->files->permissionsBatch($existing);
                    $retire = []; $replace = []; $retiredExpected = []; $candidateExpected = [];
                    $parents = [];
                    foreach ($batch as $index => $path) {
                        if ($before[$path] !== null) {
                            if ($currentModes[$path] !== $modes[$path]) { throw new RuntimeException('Live permissions changed during transfer.'); }
                            $oldDirectory = $remote.'/old'.($prepared !== null ? '/b'.intdiv($index, 256) : '');
                            if ($prepared !== null && ! isset($oldDirectories[$oldDirectory])) {
                                $this->files->mkdir($oldDirectory); $oldDirectories[$oldDirectory] = true;
                            }
                            $retire[$path] = $oldDirectory.'/'.$index;
                            $retiredExpected[$oldDirectory.'/'.$index] = $before[$path];
                        }
                        if ($after[$path] !== null) {
                            $parent = dirname($path); if ($parent !== '.' && ! isset($parents[$parent])) { $this->files->mkdir($parent); $parents[$parent] = true; }
                            $candidate = $candidates[$path] ?? $remote.'/new/'.$installIndexes[$path];
                            $replace[$candidate] = $path; $candidateExpected[$candidate] = $after[$path];
                        }
                    }
                    $this->verify($candidateExpected);
                    if ($prepared !== null) {
                        // One intent contains both independent legs; partial rename is never replayed.
                        $journal(['step' => 'apply_batch_intent', 'retire' => $retire, 'replace' => $replace, 'before' => $live, 'after' => array_intersect_key($after, $live)]);
                    }
                    if ($retire !== []) {
                        if ($prepared === null) { $journal(['step' => 'retire_batch_intent', 'moves' => $retire]); }
                        $this->files->renameBatch($retire);
                        $this->verify($retiredExpected);
                    }
                    if ($replace !== []) {
                        if ($prepared === null) { $journal(['step' => 'replace_batch_intent', 'moves' => $replace]); }
                        $this->files->renameBatch($replace);
                    }
                    $this->verify(array_intersect_key($after, $live));
                    $installedModes = $this->files->permissionsBatch(array_keys(array_filter(array_intersect_key($after, $live), fn ($h) => $h !== null)));
                    foreach ($installedModes as $path => $mode) {
                        if (isset($modes[$path]) && $mode !== $modes[$path]) { throw new RuntimeException('Installed permissions differ from the original approved mode.'); }
                    }
                    $journal(['step' => 'batch_verified', 'paths' => array_values($batch)]);
                    $deployed += count($batch); $report('deploy', $deployed, count($after));
                });
            }
        } else {
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
                $report('deploy', ++$deployed, count($after));
            }
        }
        $report('final_verification', 0, count($after));
        $this->verify($after, fn ($done, $total) => $report('final_verification', $done, $total));
        $this->unit(function () use ($journal, $remote): void {
            $journal(['step' => 'cleanup_intent', 'stage' => $remote]);
            $this->files->delete($remote);
            $journal(['step' => 'files_verified']);
        });
    }

    public function verify(array $expected, ?\Closure $progress = null): void
    {
        // Directory ordering improves sharing without broadening the explicit approved scope.
        uksort($expected, fn ($a, $b) => [dirname($a), $a] <=> [dirname($b), $b]);
        $done = 0; $total = count($expected);
        foreach (array_chunk($expected, 32, true) as $batch) {
            $this->unit(function () use ($batch, &$done, $total, $progress): void {
                $hashes = $this->files instanceof BatchFiles ? $this->files->hashBatch(array_keys($batch)) : null;
                foreach ($batch as $path => $digest) {
                    if (($hashes !== null ? $hashes[$path] : $this->files->hash($path)) !== $digest) { throw new RuntimeException('Native verification mismatch: '.$path); }
                    $done++;
                }
                $progress?->__invoke($done, $total);
            });
        }
    }
}
