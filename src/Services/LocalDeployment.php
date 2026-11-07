<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Local-node, OFFLINE-ONLY deployment of a trusted, already unpacked server pack.
 *
 * Filesystem mode takes a full local snapshot; Pelican mode uses an externally
 * verified locked Pelican backup before any modification. No shell execution, downloads,
 * ZIP extraction, symlink following, or Pelican power controls are performed here.
 *
 * Caller MUST verify the Pelican server is stopped and the pack source is trusted.
 */
final class LocalDeployment
{
    private DirectoryInventory $inventory;
    private UpdatePlanner $planner;

    public function __construct()
    {
        $this->inventory = new DirectoryInventory();
        $this->planner = new UpdatePlanner();
    }

    /**
     * @param array<string,string> $base
     * @param array<string,string> $target
     * @param list<array{pattern:string,mode:string}> $rules
     * @return array{backup:?string,external_backup_uuid:?string,modified:list<string>,plan:array<string,mixed>}
     */
    public function apply(
        string $liveRoot,
        string $stagedRoot,
        string $backupParent,
        array $base,
        array $target,
        array $rules,
        bool $offlineConfirmed = false,
        ?string $verifiedPelicanBackupUuid = null,
        array $policyContext = [],
        ?array $expectedDecisions = null,
        ?string $transactionRoot = null,
        ?\Closure $heartbeat = null
    ): array {
        if (!$offlineConfirmed) {
            throw new RuntimeException('Server stop must be explicitly confirmed before deployment');
        }
        if (!$base || !$target) {
            throw new RuntimeException('Both verified upstream manifests are required');
        }
        $live = $this->root($liveRoot);
        $stage = $this->root($stagedRoot);
        // Only the Pelican-integrated job may supply a previously completed
        // backup UUID. The standalone CLI always uses a local snapshot.
        if ($verifiedPelicanBackupUuid !== null &&
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                $verifiedPelicanBackupUuid) !== 1) {
            throw new RuntimeException('Invalid external Pelican backup reference');
        }
        $backups = $verifiedPelicanBackupUuid === null ? $this->root($backupParent) : null;
        if ($backups === null) {
            $this->checkSeparate($live, $stage);
        } else {
            $this->checkSeparate($live, $stage, $backups);
        }
        $lockPath = $transactionRoot !== null ? $this->root($transactionRoot).'/.deployment.lock' : dirname($live) . '/.' . basename($live) . '.arcturus-modpack-manager.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Unsafe lock file');
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is running for this directory');
        }
        try {
            $decisionPaths = array_column($expectedDecisions ?? [], 'path');
            foreach ($expectedDecisions ?? [] as $decision) { $decisionPaths = array_merge($decisionPaths, $decision['mod_upgrade']['paths'] ?? []); }
            $paths = array_unique(array_merge(array_keys($base), array_keys($target), $decisionPaths, array_keys($policyContext['local_mod_files'] ?? [])));
            $safetyRoots = array_values(array_unique(array_merge($this->inventory->safetyRoots($live), $policyContext['safety_roots'] ?? [])));
            $paths = array_values(array_filter($paths, fn ($p) => SafetyPaths::reason($p, $safetyRoots) === null));
            $local = $this->inventory->hashPaths($live, $paths, $heartbeat);
            $verified = $this->inventory->hashPaths($stage, array_keys($target), $heartbeat);
            if (count($verified) !== count($target)) {
                throw new RuntimeException('Staged release is incomplete');
            }
            foreach ($target as $path => $digest) {
                if (!hash_equals(strtolower($digest), strtolower($verified[$path] ?? ''))) {
                    throw new RuntimeException("Staged hash mismatch: {$path}");
                }
            }
            $plan = $this->planner->plan($base, $local, $target, $rules, $policyContext['identities'] ?? [], $policyContext['disabled'] ?? [], $safetyRoots, $policyContext['metadata'] ?? [], $policyContext['mod_evidence'] ?? []);
            if ($expectedDecisions !== null && $plan['changes'] !== $expectedDecisions) {
                throw new RuntimeException('Deployment decisions differ from the approved preview');
            }
            $mutations = [];
            foreach ($plan['changes'] as $entry) {
                $action = $entry['action'];
                if ($entry['blocked'] && $action !== 'disable_requires_enforcement') {
                    throw new RuntimeException("Unresolved change: {$entry['path']} ({$action})");
                }
                if ($action === 'disable_requires_enforcement' &&
                    !preg_match('~^mods/(?:[^/]+/)*[^/]+\.jar$~iD', $entry['path'])) {
                    throw new RuntimeException("Cannot disable a non-mod file: {$entry['path']}");
                }
                if (in_array($action, [
                    'upgrade_mod', 'add', 'update', 'remove', 'replace_with_override',
                    'remove_with_override', 'disable_requires_enforcement'
                ], true)) {
                    $mutations[] = $entry;
                }
            }

            $backup = null;
            if ($backups !== null) {
                $name = basename($live) . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
                $backup = $backups . '/' . $name;
                if (!@mkdir($backup, 0700)) {
                    throw new RuntimeException('Could not create backup directory');
                }
            }
            $modified = [];
            $journal = $transactionRoot !== null ? $transactionRoot.'/deploy-'.$verifiedPelicanBackupUuid.'.jsonl' : null;
            $journalStream = $journal !== null ? @fopen($journal, 'x+b') : null;
            if ($journal !== null && ! $journalStream) { throw new RuntimeException('Deployment recovery journal already exists or is unavailable; do not replay apply.'); }
            $recorded = 0;
            $receipt = function (string $state, array $details = []) use ($journalStream, $verifiedPelicanBackupUuid, &$modified, &$recorded): void {
                if (! $journalStream) { return; }
                $line = json_encode(['details' => $details, 'state' => $state, 'backup_uuid' => $verifiedPelicanBackupUuid, 'touched' => array_slice($modified, $recorded)], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($journalStream, $line) !== strlen($line) || ! fflush($journalStream) || ! fsync($journalStream)) { throw new RuntimeException('Cannot persist deployment recovery journal'); }
                $recorded = count($modified);
            };
            $receipt('applying');
            try {
                if ($backup !== null) {
                    $this->copyTree($live, $backup);
                    $backupChecks = $this->inventory->hashPaths($backup, array_keys($local));
                    if ($backupChecks !== $local) {
                        throw new RuntimeException('Full backup verification failed');
                    }
                }

                $expectedMods = $policyContext['local_mod_files'] ?? [];
                usort($mutations, fn ($a, $b) => (($a['action'] === 'upgrade_mod') ? 0 : 1) <=> (($b['action'] === 'upgrade_mod') ? 0 : 1));
                foreach ($mutations as $entry) {
                    if ($entry['action'] === 'upgrade_mod') {
                        $transaction = new ModUpgradeTransaction;
                        $transaction->requireSnapshot($live, $expectedMods, $heartbeat);
                        foreach ($entry['mod_upgrade']['paths'] as $path) { if (! in_array($path, $modified, true)) { $modified[] = $path; } }
                        $transaction->apply($live, $stage, $entry, $receipt, $heartbeat);
                        unset($expectedMods[$entry['mod_upgrade']['installed']['path']]);
                        $expectedMods[$entry['mod_upgrade']['target']['path']] = $entry['mod_upgrade']['target']['sha256'];
                        continue;
                    }
                    $heartbeat?->__invoke();
                    $path = $entry['path'];
                    $dest = $live . '/' . $path;
                    $current = $this->inventory->hashPaths($live, [$path], $heartbeat)[$path] ?? null;
                    if ($current !== $entry['local_sha256']) {
                        throw new RuntimeException("File changed after planning: {$path}");
                    }
                    $modified[] = $path;
                    $receipt('applying');
                    if (in_array($entry['action'], [
                        'remove', 'remove_with_override', 'disable_requires_enforcement'
                    ], true)) {
                        if (file_exists($dest) && !@unlink($dest)) {
                            throw new RuntimeException("Could not remove: {$path}");
                        }
                        unset($expectedMods[$path]);
                        continue;
                    }
                    $this->atomicCopy($stage . '/' . $path, $dest, $live, $transactionRoot !== null);
                    if (UpdatePlanner::isMod($path)) { $expectedMods[$path] = $entry['target_sha256']; }
                    $after = $this->inventory->hashPaths($live, [$path], $heartbeat)[$path] ?? null;
                    if ($after !== $entry['target_sha256']) {
                        throw new RuntimeException("Post-install hash mismatch: {$path}");
                    }
                }
                if (array_filter($mutations, fn ($m) => $m['action'] === 'upgrade_mod')) { (new ModUpgradeTransaction)->requireSnapshot($live, $expectedMods, $heartbeat); }
                if ($backup !== null) {
                    $metadata = [
                        'schema' => 1,
                        'original_root' => $live,
                        'date' => gmdate(DATE_ATOM),
                        'changed_paths' => $modified,
                        'status' => 'completed',
                    ];
                    $written = @file_put_contents($backup . '/.arcturus-modpack-manager-backup.json',
                        json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                    if ($written === false) {
                        throw new RuntimeException('Could not save backup metadata');
                    }
                }
                $receipt('applied');
                return [
                    'backup' => $backup,
                    'external_backup_uuid' => $verifiedPelicanBackupUuid,
                    'modified' => $modified, 'plan' => $plan,
                ];
            } catch (Throwable $error) {
                if ($backup === null) {
                    // Do NOT attempt to restore a Pelican snapshot as ordinary
                    // files. The orchestrator must request Pelican's full restore.
                    throw new RuntimeException(
                        'Deployment failed; Pelican restore required (backup ' .
                        $verifiedPelicanBackupUuid . '): ' . $error->getMessage(),
                        previous: $error
                    );
                }
                // Restore every touched file from the verified pre-update snapshot.
                $failures = [];
                foreach (array_reverse($modified) as $path) {
                    try {
                        $original = $backup . '/' . $path;
                        $dest = $live . '/' . $path;
                        if (is_file($original)) {
                            $this->atomicCopy($original, $dest, $live);
                        } elseif (file_exists($dest) && !@unlink($dest)) {
                            throw new RuntimeException("Cannot remove: {$path}");
                        }
                    } catch (Throwable $rollbackError) {
                        $failures[] = "{$path}: {$rollbackError->getMessage()}";
                    }
                }
                if ($failures) {
                    throw new RuntimeException('Deployment failed and rollback is incomplete: ' .
                        implode('; ', $failures) . '. Original backup: ' . $backup, previous: $error);
                }
                throw new RuntimeException('Deployment aborted; touched files restored. Backup: ' .
                    $backup . '. Cause: ' . $error->getMessage(), previous: $error);
            }
        } finally {
            if (isset($journalStream) && is_resource($journalStream)) { fclose($journalStream); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Restore the entire saved server state after a failed boot.
     * Requires server to be STOPPED. Keeps the failed installation as a sibling
     * directory rather than destroying it. Mounted volume roots may not be
     * renameable; in that case nothing is changed.
     *
     * @return string Path to the displaced failed installation
     */
    public function restore(string $liveRoot, string $backupDirectory, bool $offlineConfirmed = false): string
    {
        if (!$offlineConfirmed) {
            throw new RuntimeException('Offline confirmation required before rollback');
        }
        $live = $this->root($liveRoot);
        $backup = $this->root($backupDirectory);
        if ($backup === $live || str_starts_with($backup, $live . '/')) {
            throw new RuntimeException('Backup cannot be within live server');
        }
        $metadataFile = $backup . '/.arcturus-modpack-manager-backup.json';
        if (!is_file($metadataFile) || is_link($metadataFile)) {
            throw new RuntimeException('Backup provenance metadata missing');
        }
        $data = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 ||
            ($data['original_root'] ?? null) !== $live ||
            ($data['status'] ?? null) !== 'completed') {
            throw new RuntimeException('Backup metadata mismatch or incomplete backup');
        }
        $parent = dirname($live);
        $suffix = bin2hex(random_bytes(6));
        $restored = $parent . '/.mpm-restored-' . $suffix;
        $failed = $parent . '/.mpm-failed-' . $suffix;
        if (!@mkdir($restored, 0700)) {
            throw new RuntimeException('Could not create restore staging directory');
        }
        try {
            $this->copyTree($backup, $restored);
            @unlink($restored . '/.arcturus-modpack-manager-backup.json');
            if (!@rename($live, $failed)) {
                throw new RuntimeException('Cannot rename live root (mounted volume?). No rollback applied');
            }
            if (!@rename($restored, $live)) {
                if (!@rename($failed, $live)) {
                    throw new RuntimeException("CRITICAL: Restore swap failed. Original live root: {$failed}");
                }
                throw new RuntimeException('Could not atomically activate restored snapshot; original root restored');
            }
            return $failed;
        } catch (Throwable $error) {
            // Partial restore staging is intentionally kept for forensic recovery.
            throw new RuntimeException('Restore failed: ' . $error->getMessage(), previous: $error);
        }
    }

    /** Copy files only; refuse links/special nodes, and never remove the original. */
    private function copyTree(string $source, string $destination): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            UpdatePlanner::assertPath(str_replace(DIRECTORY_SEPARATOR, '/', $relative));
            if ($entry->isLink()) {
                throw new RuntimeException("Symlink forbidden in backup: {$relative}");
            }
            $dest = $destination . '/' . $relative;
            if ($entry->isDir()) {
                if (!is_dir($dest) && !@mkdir($dest, 0700, true)) {
                    throw new RuntimeException("Could not create backup directory: {$relative}");
                }
            } elseif ($entry->isFile()) {
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0700, true)) {
                    throw new RuntimeException("Could not create backup parent: {$relative}");
                }
                if (!@copy($entry->getPathname(), $dest)) {
                    throw new RuntimeException("Backup copy failed: {$relative}");
                }
                if (hash_file('sha256', $entry->getPathname()) !== hash_file('sha256', $dest)) {
                    throw new RuntimeException("Backup hash mismatch: {$relative}");
                }
            } else {
                throw new RuntimeException("Unsupported entry: {$relative}");
            }
        }
    }

    private function atomicCopy(string $source, string $dest, string $root, bool $inheritPermissions = false): void
    {
        $relative = ltrim(substr($dest, strlen($root)), '/');
        UpdatePlanner::assertPath($relative);
        $parts = explode('/', $relative);
        $dir = $root;
        array_pop($parts);
        foreach ($parts as $segment) {
            $dir .= '/' . $segment;
            if (is_link($dir)) {
                throw new RuntimeException("Symlink in destination: {$relative}");
            }
            if (!is_dir($dir) && !@mkdir($dir, $inheritPermissions ? 0770 : 0750)) {
                throw new RuntimeException("Could not create destination directory: {$relative}");
            }
        }
        if (is_link($dest)) {
            throw new RuntimeException("Symlink destination: {$relative}");
        }
        $tmp = $dest . '.arcturus-modpack-manager-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (!@copy($source, $tmp)) {
                throw new RuntimeException("Copy failed: {$relative}");
            }
            $mode = @fileperms($source);
            if ($mode !== false && ! $inheritPermissions) {
                @chmod($tmp, $mode & 0777);
            } elseif ($mode !== false && ($mode & 0111)) {
                @chmod($tmp, (fileperms($tmp) & 0777) | 0110);
            }
            if (!@rename($tmp, $dest)) {
                throw new RuntimeException("Atomic move failed: {$relative}");
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function root(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException('Root symlink refused');
        }
        $canonical = realpath($path);
        if (!$canonical || !is_dir($canonical) || !is_readable($canonical)) {
            throw new RuntimeException('Directory must exist and be readable: ' . $path);
        }
        return rtrim($canonical, '/') ?: '/';
    }

    private function checkSeparate(string ...$paths): void
    {
        foreach ($paths as $i => $a) {
            foreach ($paths as $j => $b) {
                if ($i !== $j && ($a === $b || str_starts_with($a . '/', $b . '/') ||
                    str_starts_with($b . '/', $a . '/'))) {
                    throw new RuntimeException('Live, stage and backup directories must not overlap');
                }
            }
        }
    }
}
