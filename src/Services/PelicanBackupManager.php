<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use App\Enums\BackupStatus;
use App\Enums\ServerState;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DownloadLinkService;
use App\Services\Backups\InitiateBackupService;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Uses Pelican's own backup hosts (Wings or S3), limits, and restore callbacks.
 * All operations must be performed while the Minecraft server is stopped.
 */
final class PelicanBackupManager
{
    public function __construct(
        private readonly InitiateBackupService $initiator,
        private readonly DownloadLinkService $downloadLinks,
        private readonly DaemonBackupRepository $daemon,
        private readonly PelicanPowerController $power,
    ) {}

    public function requireReady(Server $server): void
    {
        if ($server->status !== null || ! $server->backup_limit || $server->backups()->nonFailed()->count() >= $server->backup_limit) {
            throw new RuntimeException('Pelican backup quota is full or the server is busy. Free a slot without deleting the recovery backup.');
        }
        $host = $server->node->backupHosts()->first() ?? \App\Models\BackupHost::doesntHave('nodes')->first();
        if (! $host || ! app(\App\Extensions\BackupAdapter\BackupAdapterService::class)->get($host->schema)) {
            throw new RuntimeException('Configure a working Pelican backup host/adapter before approving deployment.');
        }
        if ($host->schema !== 'wings') { throw new RuntimeException('Managed deployment currently qualifies the native Wings backup adapter. Other backup adapters remain blocked pending complete restore integration qualification.'); }
        if (! $server->user) { throw new RuntimeException('Server owner must be resolvable for authenticated backup restore.'); }
    }

    /** Start a locked, complete backup without evicting any existing backup. */
    public function initiate(Server $server, string $name, array $excludedFolders = []): Backup
    {
        $this->requireStopped($server);
        if ($server->refresh()->status !== null) {
            throw new RuntimeException('Pelican server is busy; cannot initiate backup');
        }

        // Pelican's own BackupController serializes creates on the parent row.
        // Use the same locking discipline so quota checks remain atomic.
        if ($excludedFolders !== []) {
            $files = app(ServerFilesFactory::class)->wings($server);
            BackupExclusions::requireSafeForWorld($excludedFolders, $files->read('server.properties'));
        }
        $patterns = BackupExclusions::patterns($excludedFolders);

        return DB::transaction(function () use ($server, $name, $patterns): Backup {
            Server::query()->whereKey($server->id)->lockForUpdate()->value('id');

            return $this->initiator
                ->setIgnoredFiles($patterns) // Only exact root backup folders, never world/config.
                ->setIsLocked(true)  // Protect pre-update snapshot from pruning.
                ->setIsScheduled(false)
                ->handle($server, $name, false); // Never delete another backup to make space.
        });
    }

    /** Wait for Wings' completion callback rather than treating HTTP 2xx as a backup. */
    public function awaitSuccessful(Backup $backup, array $excludedFolders = [], int $timeoutSeconds = 3600, ?\Closure $heartbeat = null): Backup
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $backup->refresh();
            if ($backup->completed_at !== null) {
                $this->requireCompleted($backup, BackupExclusions::patterns($excludedFolders));

                return $backup;
            }
            $heartbeat?->__invoke();
            sleep(1);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Pelican backup {$backup->uuid} did not finish before timeout; deployment cancelled");
    }

    /**
     * Request a FULL restore (truncate=true) and wait for the exact Wings
     * restore-complete / restore-failed callback for this backup.
     *
     * A restored Pelican server status of NULL is NOT proof of success:
     * Pelican clears this flag on both successful and failed restores.
     */
    public function restoreAndWait(Server $server, Backup $backup, array $excludedFolders = [], int $timeoutSeconds = 3600, ?string $preservedStash = null, ?\Closure $heartbeat = null): void
    {
        $this->requireStopped($server);
        if ($backup->server_id !== $server->id) {
            throw new RuntimeException('Refusing restore: backup belongs to a different server');
        }
        if ($excludedFolders !== []) {
            $managed = \Arcturus\ModpackManager\Models\ManagedServer::lightweight()->where('pelican_server_uuid', $server->uuid)->first();
            $root = $managed && isset($managed->baseline_provenance['transport'])
                ? app(SameHostDeployment::class)->paths($managed)['live']
                : config('arcturus-modpack-manager.local_server_roots.'.$server->uuid);
            if (! $root) {
                throw new RuntimeException('Automatic truncating restore is blocked: excluded directories cannot be preserved outside this remote Wings volume');
            }
            foreach (BackupExclusions::normalize($excludedFolders) as $folder) {
                if (file_exists($root.'/'.$folder) || is_link($root.'/'.$folder)) {
                    throw new RuntimeException('Excluded folders must be safely stashed before a truncating restore');
                }
            }
            if ($preservedStash !== null) {
                $journal = $preservedStash.'/.mpm-preserved.json';
                if (is_link($preservedStash) || is_link($journal) || ! is_file($journal)) {
                    throw new RuntimeException('Missing exclusion preservation receipt');
                }
                $proof = json_decode(file_get_contents($journal), true, flags: JSON_THROW_ON_ERROR);
                if (($proof['live_root'] ?? null) !== realpath($root) ||
                    realpath($preservedStash) === realpath($root) || str_starts_with(realpath($preservedStash), realpath($root).'/')) {
                    throw new RuntimeException('Exclusion preservation receipt does not match this server');
                }
            }
        }
        $this->requestRestoreAndWait($server, $backup, $excludedFolders, $timeoutSeconds, true, $heartbeat);
    }

    /** A full native restore which preserves approved excluded root directories in place. */
    public function restoreNativeAndWait(Server $server, Backup $backup, array $excludedFolders, \Arcturus\ModpackManager\Native\Wings $files,
        \Closure $journal, int $timeoutSeconds = 3600, ?\Closure $heartbeat = null, ?array $linkReceipt = null): void
    {
        $authorize = function () use ($server, $backup, $excludedFolders): void {
            $this->requireStopped($server);
            if ($backup->server_id !== $server->id || $server->refresh()->status !== null) { throw new RuntimeException('Native recovery backup identity or server operation changed.'); }
            $this->requireCompleted($backup->refresh(), BackupExclusions::patterns($excludedFolders));
            $this->power->deploymentGuard($server, false);
        };
        $authorize();
        $links = new \Arcturus\ModpackManager\Native\RootLinks($files);
        $linkReceipt = $links->preserve('.arcturus-recovery-'.$backup->uuid, $linkReceipt, $journal);
        (new \Arcturus\ModpackManager\Native\Recovery($files))->clearForRestore($excludedFolders, $authorize, $journal, $linkReceipt ? [$linkReceipt['path']] : []);
        $journal(['step' => 'native_restore_intent', 'backup_uuid' => $backup->uuid, 'truncate' => false]);
        $this->requestRestoreAndWait($server, $backup, $excludedFolders, $timeoutSeconds, false, $heartbeat);
        // Wings temporarily manages suspension during restore. Re-arm our guard explicitly.
        $this->power->guardAndStop($server, $heartbeat);
        $links->restore($linkReceipt, $journal);
        $journal(['step' => 'native_restore_callback_verified', 'backup_uuid' => $backup->uuid]);
    }

    private function requestRestoreAndWait(Server $server, Backup $backup, array $excludedFolders, int $timeoutSeconds, bool $truncate, ?\Closure $heartbeat): void
    {
        $this->requireCompleted($backup->refresh(), BackupExclusions::patterns($excludedFolders));
        if ($server->refresh()->status !== null) {
            throw new RuntimeException('Pelican server already has an active operation');
        }

        $owner = $server->user;
        if (! $owner) {
            throw new RuntimeException('Cannot resolve server owner for Pelican backup download token');
        }
        $url = $this->downloadLinks->handle($backup, $owner);
        // Capture the log watermark BEFORE requesting restore: late callbacks
        // from previous attempts must never count as success.
        $eventWatermark = $this->restoreWatermark();
        $server->update(['status' => ServerState::RestoringBackup]);
        // Throws on Wings error. If the status is uncertain, leave Pelican locked
        // for operator intervention instead of starting Minecraft over the restore.
        $this->daemon->setServer($server)->restore($backup, $url, $truncate)->throw();

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $event = ActivityLog::query()
                ->where('id', '>', $eventWatermark)
                ->whereIn('event', [
                    'server:backup.restore-complete',
                    'server.backup.restore-failed', // Current Pelican callback spelling.
                ])
                ->whereHas('subjects', fn ($query) => $query
                    ->where('subject_type', $backup->getMorphClass())
                    ->where('subject_id', $backup->id))
                ->orderByDesc('id')
                ->first();

            if (BackupSafetyGate::restoreFinished(
                $event?->event,
                $event !== null && $server->refresh()->status === null
            )) {
                $this->requireCompleted($backup->refresh(), BackupExclusions::patterns($excludedFolders));
                return;
            }
            $heartbeat?->__invoke();
            sleep(1);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            "Pelican did not confirm successful restore of {$backup->uuid}; leave server stopped"
        );
    }

    public function restoreWatermark(): int
    {
        return (int) (ActivityLog::query()->max('id') ?? 0);
    }

    /** Also used after an administrator completes a previously failed native restore. */
    public function requireRestored(Server $server, Backup $backup, array $excludedFolders, int $watermark): void
    {
        $this->requireStopped($server);
        if ($backup->server_id !== $server->id) { throw new RuntimeException('Recovery backup belongs to another server.'); }
        $this->requireCompleted($backup->refresh(), BackupExclusions::patterns($excludedFolders));
        $event = ActivityLog::query()->where('id', '>', $watermark)
            ->whereIn('event', ['server:backup.restore-complete', 'server.backup.restore-failed'])
            ->whereHas('subjects', fn ($query) => $query->where('subject_type', $backup->getMorphClass())->where('subject_id', $backup->id))
            ->orderByDesc('id')->first();
        if (! BackupSafetyGate::restoreFinished($event?->event, $event !== null && $server->refresh()->status === null)) {
            throw new RuntimeException('Pelican has not confirmed successful restore of the exact locked backup. Keep Minecraft stopped.');
        }
    }

    private function requireCompleted(Backup $backup, array $expectedIgnored): void
    {
        BackupSafetyGate::requireComplete(
            $backup->completed_at !== null,
            $backup->status === BackupStatus::Successful && (bool) $backup->is_successful,
            (bool) $backup->is_locked,
            (array) $backup->ignored_files,
            (int) $backup->bytes,
            is_string($backup->checksum) ? $backup->checksum : null,
            $expectedIgnored
        );
    }

    private function requireStopped(Server $server): void
    {
        if ($this->power->state($server) !== 'exited') {
            throw new RuntimeException('Wings must confirm the server is stopped for backup/restore');
        }
    }
}
