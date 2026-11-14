<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use App\Models\Backup;
use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\ExcludedFolderPreserver;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\ManifestJson;
use Arcturus\ModpackManager\Services\PelicanBackupManager;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Arcturus\ModpackManager\Services\RestoreInventory;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Production writes are gated on release verification and an OFFLINE backup.
 * Default backup backend: Pelican's own Wings/S3 backup infrastructure.
 */
final class DeployManagedServer implements ShouldQueue
{
    use Queueable;

    // Backup + staging verification + boot, or restore + second boot, can be slow.
    public int $timeout = 10800;

    public int $tries = 1;

    public function __construct(
        public readonly int $managedServerId,
        public readonly bool $automatic = false,
        public readonly ?string $approvedPlanSha256 = null,
        public readonly ?string $operationId = null
    ) {}

    public function handle(
        PreviewService $previewer,
        ManifestJson $manifests,
        LocalDeployment $deployer,
        PelicanPowerController $power,
        PelicanBackupManager $backups,
        DirectoryInventory $inventory,
        ExcludedFolderPreserver $preserver,
        DiscordNotifier $discord
    ): void {
        if (ManagedServer::findOrFail($this->managedServerId)->transport === 'native') { app(\Arcturus\ModpackManager\Native\Deployment::class)->run($this); return; }
        if ($this->operationId === null && ! config('arcturus-modpack-manager.enable_deploy', false)) {
            throw new RuntimeException('Deploy feature not enabled by operator');
        }
        $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$this->managedServerId, 14400);
        if (! $lock->get()) {
            if ($this->operationId !== null) { return; }
            throw new RuntimeException('Another deploy already holds this server lock');
        }

        $operation = null;
        $stopAttempted = false;
        $committed = false;
        $pulseAt = 0;
        $heartbeat = function () use (&$operation, &$pulseAt): void { if ($operation && time() - $pulseAt >= 10) { if ($operation->fresh()->completed_at) { throw new RuntimeException('Deployment receipt is closed; retain its recovery checkpoint and do not continue apply.'); } $operation->update(['heartbeat_at' => now()]); $pulseAt = time(); } };
        $progress = function (string $phase, string $message, array $context = []) use (&$operation): void {
            if (! $operation) { return; }
            if ($operation->fresh()->completed_at) { throw new RuntimeException('Deployment receipt is closed; retain its recovery checkpoint and do not continue apply.'); }
            $operation->update(['phase' => $phase, 'progress' => ['message' => $message], 'context' => array_replace($operation->context ?? [], $context), 'heartbeat_at' => now()]);
        };
        try {
            $managed = ManagedServer::findOrFail($this->managedServerId);
            if ($this->operationId !== null) {
                $operation = DB::transaction(function () use ($managed): ?Operation {
                    $row = $managed->operations()->lockForUpdate()->findOrFail($this->operationId);
                    if ($row->completed_at || $row->phase !== 'queued') { return null; }
                    if ($managed->active_operation_id !== $row->id || ($row->context['manual_approval'] ?? false) !== true || ($row->context['plan_sha256'] ?? null) !== $this->approvedPlanSha256 || ($row->context['actor_id'] ?? null) === null || ($row->context['automatic_approval'] ?? false) !== $this->automatic) { throw new RuntimeException('Deployment approval receipt does not match the active operation.'); }
                    $row->update(['phase' => 'verifying', 'heartbeat_at' => now()]);
                    return $row;
                });
                if (! $operation) { return; }
            } elseif (isset($managed->baseline_provenance['transport'])) { throw new RuntimeException('Same-host deployment requires a durable administrator approval receipt.'); }
            app(DeploymentGate::class)->require($managed, $this->approvedPlanSha256);
            if (! $managed->enabled || ($operation === null && ! $managed->deploy_enabled)) {
                throw new RuntimeException('Server deployment disabled');
            }
            if ($this->automatic) { app(\Arcturus\ModpackManager\Services\ScheduledMaintenance::class)->requireEnabled($managed); }
            $backupMode = $managed->backup_mode ?? 'pelican';
            if (! in_array($backupMode, ['pelican', 'filesystem'], true)) {
                throw new RuntimeException('Unknown backup backend; aborting');
            }
            if (! $managed->local_root || ! $managed->prepared_root ||
                ! $managed->base_manifest_path || ! $managed->target_manifest_path ||
                ($backupMode === 'filesystem' && ! $managed->backup_root)) {
                throw new RuntimeException('Missing live path, release manifests, staging, or snapshot directory');
            }

            $excludedFolders = $backupMode === 'pelican'
                ? BackupExclusions::normalize($managed->backup_excluded_directories ?? BackupExclusions::DEFAULT_FOLDERS)
                : [];
            $pelican = Server::query()->where('uuid', $managed->pelican_server_uuid)->firstOrFail();
            $original = $power->state($pelican);
            if (! in_array($original, ['running', 'exited'], true)) {
                throw new RuntimeException('Unsafe Pelican server state: '.$original);
            }
            $progress('verifying', 'Rechecking exact plan, stage, runtime, permissions and backup capacity');
            $preview = $previewer->generate($managed, $operation);
            app(DeploymentGate::class)->require($managed->refresh(), $this->approvedPlanSha256);
            if ($preview['blocked'] > 0) {
                throw new RuntimeException('Cannot deploy with unresolved dry-run conflicts');
            }
            if ($preview['to'] !== $managed->targetVersion() ||
                $preview['from'] !== $managed->installed_version) {
                throw new RuntimeException('Release changed since approval');
            }

            $base = app(ReleaseStore::class)->load($managed->base_manifest_path);
            $target = app(ReleaseStore::class)->load($managed->target_manifest_path);
            $sameHost = $operation ? app(SameHostDeployment::class)->paths($managed) : null;
            $stage = $sameHost['stage'] ?? realpath($managed->prepared_root);
            $stageParent = $sameHost['transactions'] ?? realpath((string) $managed->staging_parent);
            if (! $stage || ! $stageParent ||
                ! str_starts_with($stage, rtrim($stageParent, '/').'/') ||
                ! is_dir($stage) || is_link($managed->prepared_root)) {
                throw new RuntimeException('Prepared stage was moved or is outside configured staging parent');
            }

            $managed->events()->create([
                'kind' => 'deploy', 'status' => 'info',
                'from_version' => $base['version'], 'to_version' => $target['version'],
                'message' => 'Starting update using '.$backupMode.' backup backend',
            ]);

            foreach (array_unique([...array_keys($base['files']), ...array_keys($target['files'])]) as $path) {
                foreach ($excludedFolders as $excluded) {
                    if (str_starts_with($path, $excluded.'/')) { throw new RuntimeException('Cannot omit backup folder owned by modpack manifest: '.$excluded); }
                }
            }
            $restoreReceipt = null;

            $pelicanBackup = null;
            $filesystemBackup = null;
            $preservedStash = null;
            $backupInitiated = false;
            $backupCompleted = false;
            $deploymentStarted = false;
            $restored = false;
            try {
                $progress('stopping', 'Stopping Minecraft; waiting for Wings offline confirmation');
                $stopAttempted = true;
                if ($operation) { $power->deploymentGuard($pelican, false); }
                $power->stop($pelican, heartbeat: $heartbeat);
                if ($backupMode === 'pelican') {
                    $progress('snapshotting', 'Verifying approved file changes and original launcher hashes; complete data backup is handled by Wings');
                    $restoreReceipt = app(RestoreInventory::class)->capture($managed->local_root, $preview['changes'], $preview['qualification']['launch_hashes'] ?? [], $preview['policy_context']['safety_roots'] ?? [], $heartbeat, $preview['qualification']['launch_links'] ?? []);
                    $progress('backing_up', 'Creating a locked Pelican backup; waiting for its exact completion callback', ['restore_receipt' => $restoreReceipt, 'excluded_directories' => $excludedFolders]);
                }
                if ($backupMode === 'pelican') {
                    // Pelican uses configured Wings/S3 hosts, quota and retention.
                    // No override: reaching backup quota stops the update instead
                    // of deleting someone's existing backup.
                    $name = 'Pre-update '.mb_substr($managed->label, 0, 70).
                        ' '.$base['version'].' -> '.$target['version'];
                    $backupInitiated = true;
                    $progress('backing_up', 'Pelican backup request initiated; no deployment before callback verification', ['backup_requested' => true]);
                    $pelicanBackup = $backups->initiate($pelican, $name, $excludedFolders);
                    $progress('backing_up', 'Waiting for completed, locked, checksummed backup', ['backup_uuid' => $pelicanBackup->uuid]);
                    $managed->forceFill(['last_backup_uuid' => $pelicanBackup->uuid])->save();
                    $managed->events()->create([
                        'kind' => 'backup', 'status' => 'info',
                        'from_version' => $base['version'], 'to_version' => $target['version'],
                        'message' => 'Pelican locked backup started',
                        'details' => ['backup_uuid' => $pelicanBackup->uuid, 'backend' => 'pelican'],
                    ]);
                    $backups->awaitSuccessful($pelicanBackup, $excludedFolders, timeoutSeconds: (int) config('arcturus-modpack-manager.deployment_backup_timeout', 3600), heartbeat: $heartbeat);
                    $backupCompleted = true;
                    $managed->events()->create([
                        'kind' => 'backup', 'status' => 'success',
                        'message' => 'Pelican confirmed completed locked backup',
                        'details' => [
                            'backup_uuid' => $pelicanBackup->uuid,
                            'bytes' => (int) $pelicanBackup->bytes,
                            'host' => $pelicanBackup->backupHost?->schema,
                            'excluded_directories' => $excludedFolders,
                        ],
                    ]);
                }

                if ($restoreReceipt !== null) { app(RestoreInventory::class)->verify($managed->local_root, $restoreReceipt, $heartbeat); }
                if ($operation) {
                    app(SameHostDeployment::class)->requireSpace($managed->local_root, $stage, $preview['changes']);
                    $progress('applying', 'Applying only approved staged changes; protected and local-only content is preserved', ['backup_complete' => true, 'journal' => $sameHost['transactions'].'/deploy-'.$pelicanBackup->uuid.'.jsonl']);
                }
                $deploymentStarted = true;
                $result = $deployer->apply(
                    $managed->local_root,
                    $stage,
                    $backupMode === 'filesystem' ? $managed->backup_root : '',
                    $base['files'], $target['files'], $managed->file_rules ?? [],
                    true,
                    $pelicanBackup?->uuid,
                    $preview['policy_context'] ?? [],
                    $preview['changes'],
                    $sameHost['transactions'] ?? null, $heartbeat
                );
                $filesystemBackup = $result['backup'];
                if ($operation && app(SameHostDeployment::class)->panelFingerprint($pelican->refresh()) !== $preview['qualification']['panel_sha256']) { throw new RuntimeException('Pelican startup/image/egg variables changed during deployment. Restore the approved settings before recovery.'); }
                $progress('starting', 'Starting Minecraft and waiting for a fresh Done marker');
                if ($operation) { $power->deploymentGuard($pelican, true); }
                $power->startAndVerify($pelican, $managed->local_root, timeoutSeconds: (int) config('arcturus-modpack-manager.deployment_start_timeout', 900), heartbeat: $heartbeat);
                if ($original === 'exited') {
                    $power->stop($pelican);
                }

                $releaseUrl = $managed->target_metadata['release_url'] ?? ($managed->available_version === $target['version'] ? $managed->available_release_url : null);
                $successEvent = DB::transaction(function () use ($managed, $target, $base, $backupMode, $pelicanBackup, $filesystemBackup, $result, $releaseUrl, $preview, $operation) {
                    $managed->forceFill([
                        'baseline_provenance' => $managed->target_metadata['prepared'] ?? $managed->baseline_provenance,
                        'runtime_report' => array_replace($managed->runtime_report ?? [], $preview['qualification']['current'] ?? []),
                        'installed_version' => $target['version'],
                        'base_manifest_path' => $managed->target_manifest_path,
                        'target_manifest_path' => null,
                        'selected_version' => null, 'target_metadata' => null,
                        'prepared_root' => null,
                        'preview_summary' => null,
                        'last_preview_at' => null,
                        'last_success_at' => now(),
                        'last_error' => null,
                        'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null,
                    ])->save();
                    $event = $managed->events()->create([
                        'kind' => 'deploy', 'status' => 'success',
                        'from_version' => $base['version'], 'to_version' => $target['version'],
                        'message' => 'Update installed; Minecraft startup marker confirmed',
                        'details' => [
                            'backup_backend' => $backupMode,
                            'backup_uuid' => $pelicanBackup?->uuid,
                            'filesystem_backup' => $filesystemBackup,
                            'modified' => $result['modified'], 'startup_verified' => true,
                            'release_url' => $releaseUrl, 'discord_notification_pending' => true,
                        ],
                    ]);
                    if ($operation) {
                        $operation->update(['progress' => ['message' => 'Minecraft startup verified; update completed']]);
                        app(OperationTracker::class)->transition($operation, 'completed', ['startup_verified' => true]);
                    }
                    return $event;
                });
                $committed = true;
                if ($operation) {
                    try { $power->sync($pelican); }
                    catch (Throwable) { $operation->update(['error' => 'Update completed; maintenance release could not be confirmed. Sync this server configuration in Wings. Do not replay apply.']); }
                }
                $discord->forEvent($successEvent);
            } catch (Throwable $error) {
                if ($committed) { throw $error; }
                $managed->refresh();
                if ($operation && $operation->fresh()->phase === 'completed' && $managed->installed_version === $target['version']) { throw new RuntimeException('Update completion was committed; inspect its recorded result. Do not replay apply or restore.'); }
                $progress('restoring', 'Update failed; stopping Minecraft before recovery. Keep the locked backup and retained stages.');
                $recoveryRuntimeVerified = false;
                $recovery = 'not attempted';
                try {
                    if ($operation) { $power->guardAndStop($pelican, $heartbeat); }
                    elseif ($stopAttempted) { $power->stop($pelican, heartbeat: $heartbeat); }
                    if ($deploymentStarted) {
                        $power->stop($pelican, heartbeat: $heartbeat);
                        if ($backupMode === 'pelican' && $pelicanBackup && $backupCompleted) {
                            // Pelican truncate=true deletes *everything* in the
                            // live directory, including folders intentionally
                            // omitted from its backup. Move those folders to a
                            // sibling staging directory using rename first.
                            $preservedStash = $preserver->moveOut(
                                $managed->local_root, $managed->staging_parent, $excludedFolders
                            );
                            $progress('restoring', 'Restoring the exact locked backup; waiting for its restore-complete callback', ['preserved_stash' => $preservedStash, 'restore_event_watermark' => $backups->restoreWatermark()]);
                            $backups->restoreAndWait($pelican, $pelicanBackup, $excludedFolders, timeoutSeconds: (int) config('arcturus-modpack-manager.deployment_restore_timeout', 3600), preservedStash: $preservedStash, heartbeat: $heartbeat);
                            if ($preservedStash !== null) {
                                $preserver->moveBack($managed->local_root, $preservedStash);
                                $preservedStash = null;
                                $progress('restoring', 'Excluded folders returned after confirmed restore', ['preserved_stash' => null]);
                            }
                            if ($restoreReceipt !== null) { app(RestoreInventory::class)->verify($managed->local_root, $restoreReceipt, $heartbeat); }
                            $restored = true;
                            $recovery = 'Pelican full restore confirmed ('.$pelicanBackup->uuid.')';
                        } elseif ($backupMode === 'filesystem' && $filesystemBackup !== null) {
                            $failedInstallation = $deployer->restore(
                                $managed->local_root, $filesystemBackup, true
                            );
                            $restored = true;
                            $recovery = 'local snapshot restored; failed install at '.$failedInstallation;
                        } else {
                            // A filesystem copy error is normally file-rolled-back
                            // by LocalDeployment, but a failed rollback is possible.
                            // Fail closed: do not restart without a verifiable snapshot.
                            $recovery = 'No confirmed snapshot for automatic recovery; server left stopped';
                        }
                    } elseif ($backupInitiated && ! $backupCompleted) {
                        $recovery = 'Pelican backup incomplete; server left stopped';
                    } else {
                        $recovery = 'No game files modified';
                    }

                    if ($original === 'running' &&
                        (! $deploymentStarted && ! $backupInitiated || $restored)) {
                        if ($operation && app(SameHostDeployment::class)->panelFingerprint($pelican->refresh()) !== $operation->context['panel_sha256']) { throw new RuntimeException('Restore the original Pelican startup/image/egg variables before restarting the verified original snapshot. Server left stopped.'); }
                        $progress('restarting_original', 'Snapshot verified; checking the original Minecraft installation startup');
                        if ($operation) { $power->deploymentGuard($pelican, true); }
                        $power->startAndVerify($pelican, $managed->local_root, timeoutSeconds: (int) config('arcturus-modpack-manager.deployment_start_timeout', 900), heartbeat: $heartbeat);
                        $recoveryRuntimeVerified = true;
                    }
                } catch (Throwable $rollbackError) {
                    $recovery = 'RECOVERY FAILED: '.$this->failureMessage($rollbackError).
                        ($preservedStash !== null
                            ? ' | Excluded folders preserved at: '.$preservedStash
                            : '');
                    $progress('restoring', 'Recovery failed; retaining the stopped maintenance checkpoint');
                    try { if ($operation) { $power->guardAndStop($pelican, $heartbeat); } else { $power->stop($pelican, heartbeat: $heartbeat); } } catch (Throwable) { $recovery .= ' | Cannot confirm maintenance guard or stopped state; check Wings and stop the server before any recovery.'; }
                    report(new RuntimeException('Arcturus recovery needs manual intervention; inspect the deployment operation and locked backup.'));
                }
                $message = mb_substr($this->failureMessage($error).' | '.$recovery, 0, 1800);
                $managed->forceFill(['last_error' => $message, 'last_preview_at' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null])->save();
                $failureEvent = $managed->events()->create([
                    'kind' => 'deploy', 'status' => 'failed',
                    'from_version' => $base['version'], 'to_version' => $target['version'],
                    'message' => $message,
                    'details' => [
                        'backup_backend' => $backupMode,
                        'backup_uuid' => $pelicanBackup?->uuid,
                        'filesystem_backup' => $filesystemBackup,
                        'operation_id' => $operation?->id, 'recovery' => $recovery, 'critical' => $operation !== null && ! ($restored && $recoveryRuntimeVerified),
                        'excluded_directories' => $excludedFolders, 'discord_notification_pending' => true,
                    ],
                ]);
                $discord->forEvent($failureEvent);
                if ($restored && $recoveryRuntimeVerified) {
                    $rollbackEvent = $managed->events()->create(['kind' => 'rollback', 'status' => 'success',
                        'from_version' => $base['version'], 'to_version' => $target['version'],
                        'message' => 'Pre-update installation restored; see deployment history for runtime status',
                        'details' => ['restore_verified' => true, 'discord_notification_pending' => true]]);
                    $discord->forEvent($rollbackEvent);
                }
                if ($operation) {
                    $operation->update(['error' => $message]);
                    app(OperationTracker::class)->transition($operation, $restored && $recoveryRuntimeVerified ? 'restored' : 'manual_intervention', ['recovery' => $recovery, 'restore_verified' => $restored]);
                    try { $power->sync($pelican); } catch (Throwable) { /* Checkpoint/result remains authoritative; never reapply. */ }
                }
                throw new RuntimeException('Update failed: '.$message);
            }
        } catch (Throwable $error) {
            if ($operation && ! $operation->fresh()->completed_at) {
                $message = $stopAttempted ? 'Deployment interrupted after stop was requested. Keep the server stopped; inspect the operation, locked backup UUID and retained journal. Never replay apply blindly.' : 'Deployment preflight rejected: '.$this->failureMessage($error).'. Compare and approve again. No game files were changed.';
                if (! $committed) { ManagedServer::whereKey($this->managedServerId)->update(['last_error' => $message, 'last_preview_at' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]); }
                $operation->update(['error' => $message]);
                app(OperationTracker::class)->transition($operation, $committed ? 'completed' : ($stopAttempted ? 'manual_intervention' : 'cancelled'));
            }
            throw new RuntimeException($this->failureMessage($error));
        } finally {
            $lock->release();
        }
    }
    private function failureMessage(Throwable $error): string
    {
        if (get_class($error) === RuntimeException::class) { return mb_substr($error->getMessage(), 0, 1500); }
        if ($error instanceof \Illuminate\Http\Client\RequestException) { return 'Wings returned HTTP '.$error->response->status().'; inspect node permissions and the exact backup/restore callback.'; }
        if ($error instanceof \Illuminate\Http\Client\ConnectionException) { return 'Wings connection interrupted. Confirm node state and the backup/restore outcome before recovery.'; }
        return 'Deployment infrastructure failed ('.class_basename($error).' at '.basename($error->getFile()).':'.$error->getLine().'). Inspect the recorded phase, queue/database connectivity and Wings backup/restore state.';
    }

    public function failed(?Throwable $error): void
    {
        if (! $this->operationId) { return; }
        $operation = Operation::where('managed_server_id', $this->managedServerId)->find($this->operationId);
        if (! $operation || $operation->completed_at) { return; }
        $failureLease = null;
        if (($operation->context['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL) {
            $failureLease = Cache::lock('arcturus-modpack-manager-deploy-'.$this->managedServerId, 14400);
            if (! $failureLease->get()) { report(new RuntimeException('Native failure callback deferred: another worker owns the deployment lease. Retain its checkpoint.')); return; }
        }
        try {
            $operation->refresh();
            if ($operation->completed_at || ($failureLease !== null &&
                (ManagedServer::lightweight()->findOrFail($this->managedServerId)->active_operation_id !== $operation->id ||
                    in_array($operation->phase, ['recovery_queued', 'checking_recovery', 'recovery_starting'], true)))) { return; }
            if (in_array($operation->phase, ['queued', 'verifying'], true)) {
                $message = 'Queue worker failed before deployment began. No Minecraft files were changed; compare and approve again.';
                $operation->update(['error' => $message]);
                ManagedServer::whereKey($this->managedServerId)->update(['last_error' => $message, 'last_preview_at' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
                app(OperationTracker::class)->transition($operation, 'cancelled');
                return;
            }
            $server = ManagedServer::lightweight()->findOrFail($this->managedServerId);
            $message = 'Worker interrupted. Do not replay the deployment. Inspect the locked backup and retained deployment journal before recovery.';
            $operation->update(['context' => [...$operation->context, 'interrupted_phase' => $operation->phase], 'phase' => 'restoring']);
            try { app(PelicanPowerController::class)->guardAndStop($server->pelicanServer); }
            catch (Throwable) { $message .= ' Wings could not confirm offline state; stop the server manually first.'; }
            $operation->update(['error' => $message]);
            $server->update(['last_error' => $message, 'last_preview_at' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
            app(OperationTracker::class)->transition($operation, 'manual_intervention');
            app(DiscordNotifier::class)->criticalRecovery($server, $message, $operation->id);
        } finally { $failureLease?->release(); }
    }

}
