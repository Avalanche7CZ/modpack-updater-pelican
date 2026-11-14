<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PelicanBackupManager;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use RuntimeException;
use Throwable;

/** Explicit administrator recovery of an interrupted native operation, never apply replay. */
final class RecoveryVerification
{
    public function run(Operation $operation, ?string $executionId = null): void
    {
        $executionId ??= $operation->context['native_execution_id'] ?? null;
        $managed = ManagedServer::lightweight()->findOrFail($operation->managed_server_id);
        $server = $managed->pelicanServer; $power = app(PelicanPowerController::class);
        $heartbeat = function () use ($operation, $executionId): void {
            if ($operation->fresh()->completed_at || ($operation->fresh()->context['native_execution_id'] ?? null) !== $executionId) { throw new RuntimeException('Recovery receipt closed. Keep the stopped checkpoint.'); }
            $operation->update(['heartbeat_at' => now()]);
        };
        $journal = function (array $row) use ($operation, $heartbeat): void {
            $heartbeat(); $operation->update(['context' => [...$operation->context, 'native_checkpoint' => $row,
                ...(isset($row['restore_confirmation']) ? ['native_restore_confirmation' => [...$row['restore_confirmation'], 'execution_id' => $operation->context['native_execution_id'] ?? null]] : []),
                ...(isset($row['boot_attempt']) ? ['native_boot_attempts' => [...($operation->context['native_boot_attempts'] ?? []), $row['boot_attempt']['id'] => [...$row['boot_attempt'], 'execution_id' => $operation->context['native_execution_id'] ?? null, 'restore_callback_id' => $operation->context['native_restore_confirmation']['callback_id'] ?? null]]] : []),
                ...(array_key_exists('link_stash', $row) ? ['native_link_stash' => $row['link_stash']] : [])]]);
        };
        $lock = new DeploymentLease($managed->id, $operation->id, 'restore'); $locked = false; $ownsRecovery = false;
        try {
            $locked = $lock->get();
            if (! $locked) { throw new RuntimeException('The previous deployment worker lease is still held. Keep the server stopped and wait for that lease to expire before recovery; never force-unlock an uncertain apply.'); }
            $operation->refresh(); $managed->refresh();
            if ($executionId === null || ($operation->context['native_execution_id'] ?? null) !== $executionId || !in_array($operation->phase, ['recovery_queued', 'checking_recovery'], true) || $operation->completed_at || ($operation->context['protocol'] ?? '') !== Qualification::PROTOCOL) { throw new RuntimeException('Recovery worker no longer owns an open queued recovery.'); }
            if ($managed->active_operation_id !== $operation->id || ! ($operation->context['recovery_actor_id'] ?? null)) { throw new RuntimeException('Native recovery receipt is not active or lacks administrator approval.'); }
            $ownsRecovery = true;
            RestorePolicy::requireRecoveryApproval($operation, $executionId);
            $operation->update(['phase' => 'checking_recovery', 'heartbeat_at' => now()]);
            $power->deploymentGuard($server, false);
            if ($power->state($server) !== 'exited' || $server->status !== null || $server->backups()->whereNull('completed_at')->exists()) {
                throw new RuntimeException('Confirm Wings offline state and completed backup/restore callbacks before native recovery.');
            }
            if ($operation->context['native_archive_pending'] ?? false) {
                throw new RuntimeException('Archive outcome is unknown. Confirm Wings archive work has ended and record operator clearance before recovery; no restore or stage deletion is allowed.');
            }
            $original = $operation->context['runtime_original'] ?? null;
            $runtime = $operation->context['native_original_runtime'] ?? null;
            $critical = $operation->context['native_restore_receipt'] ?? $operation->context['native_before'] ?? null;
            if (is_array($critical) && isset($operation->context['native_world_properties_sha256'])) { $critical['server.properties'] = $operation->context['native_world_properties_sha256']; }
            if (! is_array($original) || ! is_array($runtime) || ! is_array($critical)) { throw new RuntimeException('Original runtime or file receipt is missing. Retain the backup; this checkpoint requires manual inspection.'); }
            $files = new Wings($server, app(DaemonFileRepository::class), $heartbeat);
            $current = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
            $allowed = [$original, $operation->context['runtime_expected'] ?? null, $operation->context['runtime_recovery_barrier'] ?? null, $operation->context['manual_rollback_snapshot'] ?? null];
            if (! in_array($current, $allowed, true)) { throw new RuntimeException('Runtime settings changed outside this operation. Review them before recovering; no settings were overwritten.'); }
            if ($operation->context['native_writes_started'] ?? false) {
                if (! ($operation->context['backup_complete'] ?? false) || ! ($operation->context['backup_uuid'] ?? null)) { throw new RuntimeException('Native writes lack a verified backup receipt. Keep Minecraft stopped and inspect the locked backups manually.'); }
                $backup = $server->backups()->where('uuid', $operation->context['backup_uuid'])->firstOrFail();
                $barrier = [...$current, 'startup' => 'printf "%s\\n" "Arcturus recovery verification pending"; exit 1'];
                $operation->update(['context' => [...$operation->context, 'runtime_recovery_barrier' => $barrier, 'restore_files_verified' => false, 'recovery_startup_verified' => false]]);
                app(Runtime::class)->installSettings($server, $current, $barrier); app(Runtime::class)->syncAndRequire($server, $barrier);
                if (($power->details($server)['configuration']['invocation'] ?? null) !== $barrier['startup']) { throw new RuntimeException('Wings did not acknowledge the recovery startup barrier.'); }
                RestorePolicy::requireRecoveryApproval($operation->fresh(), $executionId);
                app(PelicanBackupManager::class)->restoreNativeAndWait($server, $backup, $operation->context['excluded_directories'] ?? [], $files, $journal,
                    (int) config('arcturus-modpack-manager.deployment_restore_timeout', 3600), $heartbeat, $operation->context['native_link_stash'] ?? null);
                (new Transaction($files))->verify($critical);
                app(Runtime::class)->restoreSettings($server, $original, $barrier);
            } else {
                (new Transaction($files))->verify($critical);
                app(Runtime::class)->requireSnapshot($server, $original);
            }
            CrucibleRuntime::verify($files, $operation->context['native_retained_launcher'] ?? []);
            $operation->update(['context' => [...$operation->context, 'restore_files_verified' => true, 'recovery_startup_verified' => false, 'native_original_files_verified' => ['backup_uuid' => $operation->context['backup_uuid'] ?? null, 'confirmation' => $operation->context['native_restore_confirmation'] ?? null, 'scope_sha256' => hash('sha256', json_encode($critical, JSON_THROW_ON_ERROR)), 'verified_at' => microtime(true)]]]);
            $attempt = (int) ($operation->context['native_recovery_attempt'] ?? 0) + 1;
            $operation->update(['phase' => 'recovery_starting', 'context' => [...$operation->context, 'native_recovery_attempt' => $attempt]]);
            app(Runtime::class)->syncAndRequire($server, $original);
            $power->deploymentGuard($server, true);
            app(Boot::class)->start($server, $files, $operation->node_operation_id.'-r'.base_convert((string) $attempt, 10, 36), $runtime, $journal, $heartbeat,
                function (array $evidence) use ($operation, $heartbeat, $attempt): void {
                    $heartbeat();
                    $operation->update(['context' => [...$operation->context, 'native_recovery_boot_failure' => [...$evidence, 'attempt' => $attempt]]]);
                });
            app(Runtime::class)->requireSnapshot($server, $original);
            $managed->update(['last_error' => 'Previous native update failed; original backup, runtime settings and fresh Minecraft boot have now been verified.']);
            $operation->update(['error' => null, 'progress' => ['message' => 'Original installation recovered and startup verified']]);
            app(OperationTracker::class)->transition($operation, 'restored', ['restore_verified' => true, 'recovery_startup_verified' => true]);
            try { $power->sync($server); } catch (Throwable $error) { report($error); $operation->update(['error' => 'Recovery completed; synchronize server configuration in Wings.']); }
            $event = $managed->events()->create(['kind' => 'rollback', 'status' => 'success', 'message' => 'Native recovery completed and original runtime verified',
                'details' => ['restore_verified' => true, 'operation_id' => $operation->id, 'discord_notification_pending' => true]]);
            app(DiscordNotifier::class)->forEvent($event);
        } catch (Throwable $error) {
            report($error);
            if (!$locked || !$ownsRecovery) { return; }
            if ($operation->fresh()->completed_at || ($operation->fresh()->context['native_execution_id'] ?? null) !== $executionId) { return; }
            $operation->update(['phase' => 'restoring']);
            try { $power->guardAndStop($server); } catch (Throwable $failure) { report($failure); }
            $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Native recovery infrastructure failed; retain the exact backup and stopped checkpoint and inspect the Panel log.';
            if (($operation->fresh()->context['restore_files_verified'] ?? false) === true) {
                $message = 'Original files and runtime settings restored; original Minecraft boot is NOT verified. '.$message;
            }
            $operation->update(['error' => $message]);
            if (RestorePolicy::automaticRestoreDisabled($operation->fresh())) { RestorePolicy::retainFailure($operation); }
            app(OperationTracker::class)->transition($operation, 'manual_intervention');
            app(DiscordNotifier::class)->criticalRecovery($managed, $message, $operation->id);
        } finally { if ($locked) { $lock->release(); } }
    }
}
