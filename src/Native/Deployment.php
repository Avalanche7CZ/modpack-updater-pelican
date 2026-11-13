<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\Backup;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PelicanBackupManager;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Arcturus\ModpackManager\Services\ScheduledMaintenance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Native backed-up transaction. An interrupted apply is never replayed. */
final class Deployment
{
    public function run(DeployManagedServer $job): void
    {
        $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$job->managedServerId, 14400);
        if (! $lock->get()) { return; }
        $operation = null; $execution = null; $stopped = false; $changed = false; $committed = false;
        $backup = null; $backupComplete = false; $before = []; $original = []; $expected = [];
        $power = app(PelicanPowerController::class); $backups = app(PelicanBackupManager::class);
        $heartbeat = function () use (&$operation, &$execution): void {
            if (! $operation) { return; }
            $fresh = $operation->fresh();
            if ($fresh->completed_at || ($execution !== null && ($fresh->context['native_execution_id'] ?? null) !== $execution)) { throw new RuntimeException('Native deployment receipt closed; retain its checkpoint.'); }
            $operation->update(['heartbeat_at' => now()]);
        };
        $checkpoint = function (array $row) use (&$operation, $heartbeat): void {
            $heartbeat();
            $operation->update(['context' => [...$operation->context, ...(array_key_exists('link_stash', $row) ? ['native_link_stash' => $row['link_stash']] : []), 'native_checkpoint' => $row,
                ...(isset($row['restore_confirmation']) ? ['native_restore_confirmation' => [...$row['restore_confirmation'], 'execution_id' => $operation->context['native_execution_id'] ?? null]] : []),
                ...(isset($row['boot_attempt']) ? ['native_boot_attempts' => [...($operation->context['native_boot_attempts'] ?? []), $row['boot_attempt']['id'] => [...$row['boot_attempt'], 'execution_id' => $operation->context['native_execution_id'] ?? null, 'restore_callback_id' => $operation->context['native_restore_confirmation']['callback_id'] ?? null]]] : []),
                'native_checkpoint_sequence' => (int) ($operation->context['native_checkpoint_sequence'] ?? 0) + 1]]);
        };
        $phase = function (string $value, string $message, array $context = []) use (&$operation, $heartbeat): void {
            $heartbeat(); $operation->update(['phase' => $value, 'progress' => ['message' => $message], 'context' => [...$operation->context, ...$context]]);
        };
        $bootFailure = function (string $role) use (&$operation, $heartbeat): \Closure {
            return function (array $evidence) use (&$operation, $heartbeat, $role): void {
                $heartbeat();
                $operation->update(['context' => [...$operation->context, 'native_'.$role.'_boot_failure' => $evidence]]);
            };
        };
        try {
            $managed = ManagedServer::findOrFail($job->managedServerId);
            if (! $job->operationId) { throw new RuntimeException('Native deployment requires a durable exact-plan approval receipt.'); }
            $operation = DB::transaction(function () use ($managed, $job): ?Operation {
                $row = $managed->operations()->lockForUpdate()->findOrFail($job->operationId);
                if ($row->completed_at || $row->phase !== 'queued') { return null; }
                if ($managed->active_operation_id !== $row->id || ($row->context['protocol'] ?? '') !== Qualification::PROTOCOL ||
                    ($row->context['manual_approval'] ?? false) !== true || ($row->context['plan_sha256'] ?? null) !== $job->approvedPlanSha256 ||
                    ($row->context['automatic_approval'] ?? false) !== $job->automatic || ! ($row->context['actor_id'] ?? null) || $row->cancel_requested_at) {
                    throw new RuntimeException('Native deployment receipt does not match approval or was cancelled.');
                }
                $row->update(['phase' => 'verifying', 'heartbeat_at' => now(), 'context' => [...$row->context, 'native_execution_id' => bin2hex(random_bytes(16))]]); return $row;
            });
            if (! $operation) { return; }
            $execution = $operation->context['native_execution_id'];
            if ($job->automatic) { app(ScheduledMaintenance::class)->requireEnabled($managed); }
            app(DeploymentGate::class)->require($managed, $job->approvedPlanSha256);
            $preview = app(PreviewService::class)->generate($managed, $operation);
            app(DeploymentGate::class)->require($managed->refresh(), $job->approvedPlanSha256);
            $server = $managed->pelicanServer;
            $files = new Wings($server, app(DaemonFileRepository::class), $heartbeat);
            $transaction = new Transaction($files);
            $verify = function (array $expected, string $name, string $message) use ($transaction, &$operation, $phase): void {
                $phase($name, $message);
                $operation->update(['progress' => ['message' => $message, 'files_done' => 0, 'files_total' => count($expected)]]);
                $last = microtime(true);
                $transaction->verify($expected, function (int $done, int $total) use (&$last, &$operation, $message): void {
                    if ($done !== $total && microtime(true) - $last < 5) { return; }
                    $operation->update(['progress' => ['message' => $message, 'files_done' => $done, 'files_total' => $total], 'heartbeat_at' => now()]);
                    $last = microtime(true);
                });
            };
            $stage = app(Qualification::class)->stage($managed);
            $target = app(ReleaseStore::class)->load($managed->target_manifest_path);
            $runtime = $preview['qualification']['runtime_plan'];
            $retainedLauncher = $runtime['retained_launcher_files'] ?? [];
            $original = $operation->context['panel_snapshot'];
            $expected = [...$original, 'startup' => $runtime['startup'], 'image' => $runtime['image']];
            app(Runtime::class)->requireSnapshot($server, $original);
            $scope = Transaction::scope($preview['changes']);
            $before = $scope['before']; $after = $scope['after']; $moves = $scope['moves'];
            $excluded = BackupExclusions::normalize($managed->backup_excluded_directories ?? BackupExclusions::DEFAULT_FOLDERS);
            $phase('stopping', 'Stopping Minecraft and confirming the native Wings maintenance guard', ['native_before' => $before, 'native_after' => $after, 'native_disabled_mod_moves' => $moves,
                'native_world_properties_sha256' => $preview['qualification']['properties_sha256'], 'runtime_original' => $original, 'runtime_expected' => $expected, 'native_original_runtime' => $preview['qualification']['current'],
                'native_retained_launcher' => $retainedLauncher, 'excluded_directories' => $excluded]);
            $stopped = true;
            $power->guardAndStop($server, $heartbeat);
            $verify($before, 'verifying_before_backup', 'Verifying files before backup');
            CrucibleRuntime::verify($files, $retainedLauncher);
            if ($files->hash('server.properties') !== $preview['qualification']['properties_sha256']) { throw new RuntimeException('World properties changed after approval; compare again.'); }
            // Capture a small independent recovery receipt, never worlds or a full inventory.
            $critical = $before;
            foreach (['server.properties', 'run.sh', 'startserver.sh', 'start.sh', 'start_server.sh', 'user_jvm_args.txt'] as $path) { $critical[$path] = $files->hash($path); }
            $phase('backing_up', 'Creating a locked native Pelican backup and waiting for its completion callback', ['native_restore_receipt' => $critical, 'backup_requested' => true]);
            $backup = $backups->initiate($server, 'Pre-update '.mb_substr($managed->label, 0, 70).' '.$managed->installed_version.' -> '.$target['version'], $excluded);
            $phase('backing_up', 'Waiting for the exact locked backup', ['backup_uuid' => $backup->uuid]);
            $managed->update(['last_backup_uuid' => $backup->uuid]);
            $backups->awaitSuccessful($backup, $excluded, (int) config('arcturus-modpack-manager.deployment_backup_timeout', 3600), $heartbeat);
            $backupComplete = true;
            $verify($critical, 'verifying_after_backup', 'Verifying files after backup');
            CrucibleRuntime::verify($files, $retainedLauncher);
            app(Runtime::class)->requireSnapshot($server, $original);
            $phase('applying', 'Transferring and verifying approved candidate files through Wings', ['backup_complete' => true, 'native_writes_started' => true]);
            $changed = true;
            $transaction->apply($operation->id, $stage, $before, $after, $preview['policy_context']['safety_roots'] ?? [], $checkpoint, $moves, app(\Arcturus\ModpackManager\Services\ApplyProgress::class)->reporter($operation));
            $transaction->verify($runtime['dependency_hashes']);
            CrucibleRuntime::verify($files, $retainedLauncher);
            if ($expected !== $original) { app(Runtime::class)->installSettings($server, $original, $runtime); }
            else { app(Runtime::class)->requireSnapshot($server, $original); }
            app(Runtime::class)->syncAndRequire($server, $expected);
            $phase('starting', 'Verifying a fresh Minecraft boot and the exact target runtime');
            $power->deploymentGuard($server, true);
            $actualRuntime = app(Boot::class)->start($server, $files, $operation->id, $runtime['target'], $checkpoint, $heartbeat, $bootFailure('target'));
            app(Runtime::class)->requireSnapshot($server, $expected);
            $event = DB::transaction(function () use ($managed, $target, $operation, $actualRuntime, $backup, $after) {
                $from = $managed->installed_version;
                $managed->forceFill(['baseline_provenance' => $managed->target_metadata['prepared'], 'runtime_report' => [...$managed->runtime_report, ...$actualRuntime],
                    'installed_version' => $target['version'], 'base_manifest_path' => $managed->target_manifest_path, 'target_manifest_path' => null,
                    'selected_version' => null, 'target_metadata' => null, 'prepared_root' => null, 'preview_summary' => null, 'last_preview_at' => null,
                    'last_success_at' => now(), 'last_error' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null])->save();
                app(OperationTracker::class)->transition($operation, 'completed', ['startup_verified' => true]);
                return $managed->events()->create(['kind' => 'deploy', 'status' => 'success', 'from_version' => $from, 'to_version' => $target['version'],
                    'message' => 'Native Wings update installed; fresh Minecraft runtime verified', 'details' => ['backup_uuid' => $backup->uuid,
                        'modified' => array_keys($after), 'startup_verified' => true, 'discord_notification_pending' => true]]);
            });
            $committed = true;
            try { $power->sync($server); } catch (Throwable $error) { report($error); $operation->update(['error' => 'Update committed; synchronize this server configuration in Wings. Do not replay deployment.']); }
            app(DiscordNotifier::class)->forEvent($event);
        } catch (Throwable $error) {
            report($error);
            if ($committed || ! $operation) { throw $error; }
            $restored = false; $recovery = '';
            if ($stopped) {
                try {
                    $phase('restoring', 'Keeping Minecraft stopped while verifying recovery prerequisites');
                    $power->guardAndStop($server, $heartbeat);
                    if ($changed && $backup && $backupComplete) {
                        // Wings restore clears suspension even on failure. Use an inert startup as a
                        // second barrier so a concurrent direct Wings start cannot launch Minecraft.
                        $current = app(\Arcturus\ModpackManager\Services\SameHostDeployment::class)->panelSnapshot($server->refresh());
                        if ($current !== $original && $current !== $expected) { throw new RuntimeException('Runtime settings changed outside this operation; manual review is required.'); }
                        $blocked = [...$current, 'startup' => 'printf "%s\\n" "Arcturus recovery verification pending"; exit 1'];
                        $phase('restoring', 'Installing the recovery startup barrier before native restore', ['runtime_recovery_barrier' => $blocked]);
                        app(Runtime::class)->installSettings($server, $current, $blocked);
                        app(Runtime::class)->syncAndRequire($server, $blocked);
                        $details = $power->details($server);
                        if (($details['configuration']['invocation'] ?? null) !== $blocked['startup']) { throw new RuntimeException('Wings did not acknowledge the recovery startup barrier.'); }
                        $phase('restoring', 'Restoring the exact native backup while preserving excluded backup folders', ['restore_event_watermark' => $backups->restoreWatermark()]);
                        $backups->restoreNativeAndWait($server, $backup, $excluded, $files, $checkpoint,
                            (int) config('arcturus-modpack-manager.deployment_restore_timeout', 3600), $heartbeat);
                        $transaction->verify($critical);
                        CrucibleRuntime::verify($files, $retainedLauncher);
                        app(Runtime::class)->restoreSettings($server, $original, $blocked);
                        app(Runtime::class)->syncAndRequire($server, $original);
                        $operation->update(['context' => [...$operation->context, 'native_original_files_verified' => ['backup_uuid' => $backup->uuid, 'confirmation' => $operation->context['native_restore_confirmation'] ?? null, 'scope_sha256' => hash('sha256', json_encode($critical, JSON_THROW_ON_ERROR)), 'verified_at' => microtime(true)]]]);
                        $phase('restarting_original', 'Verifying the original installation after confirmed native restore');
                        $power->deploymentGuard($server, true);
                        app(Boot::class)->start($server, $files, $operation->id.'-old', $preview['qualification']['current'], $checkpoint, $heartbeat, $bootFailure('rollback'));
                        app(Runtime::class)->requireSnapshot($server, $original);
                        $restored = true; $recovery = 'Exact locked backup, original runtime settings and fresh original boot verified.';
                    } else { $recovery = 'No game-file deployment began. Confirm any pending native backup before releasing maintenance.'; }
                } catch (Throwable $failure) {
                    report($failure); $recovery = 'Recovery requires manual intervention: '.$this->message($failure);
                }
                if (! $restored) { try { $phase('restoring', 'Recovery unverified; retaining the stopped checkpoint'); $power->guardAndStop($server); } catch (Throwable $failure) { report($failure); $recovery .= ' Wings offline state could not be confirmed; stop this server manually.'; } }
            }
            $message = mb_substr($this->message($error).' '.$recovery, 0, 1800);
            $managed->update(['last_error' => $message, 'last_preview_at' => null, 'approved_plan_sha256' => null, 'approved_at' => null, 'approved_by' => null]);
            $operation->update(['error' => $message]);
            app(OperationTracker::class)->transition($operation, $restored ? 'restored' : ($stopped ? 'manual_intervention' : 'cancelled'), ['restore_verified' => $restored]);
            if ($restored) { try { $power->sync($server); } catch (Throwable $failure) { report($failure); } }
            $event = $managed->events()->create(['kind' => 'deploy', 'status' => 'failed', 'message' => $message, 'details' => ['operation_id' => $operation->id,
                'backup_uuid' => $backup?->uuid, 'restore_verified' => $restored, 'critical' => $stopped && ! $restored, 'discord_notification_pending' => true]]);
            app(DiscordNotifier::class)->forEvent($event);
            throw new RuntimeException($message);
        } finally { $lock->release(); }
    }

    private function message(Throwable $error): string
    {
        return get_class($error) === RuntimeException::class ? $error->getMessage() : 'Native infrastructure error; inspect the private Panel log and operation checkpoint ('.class_basename($error).').';
    }
}
