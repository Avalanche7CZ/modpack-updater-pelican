<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\ExcludedFolderPreserver;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\RestoreInventory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Explicit operator recovery; Native restore requires execution-bound approval when automatic restore is disabled. */
final class VerifyDeploymentRecovery implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 10800;
    public ?string $executionId = null;
    public function __construct(public readonly string $operationId)
    {
        $this->executionId = Operation::find($operationId)?->context['native_execution_id'] ?? null;
    }

    public function handle(PelicanPowerController $power): void
    {
        $operation = DB::transaction(function (): ?Operation {
            $row = Operation::lockForUpdate()->findOrFail($this->operationId);
            if ($row->phase !== 'recovery_queued' || $row->completed_at) { return null; }
            if (($row->context['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL &&
                ($this->executionId === null || $this->executionId !== ($row->context['native_execution_id'] ?? null))) { return null; }
            if (($row->context['protocol'] ?? '') !== \Arcturus\ModpackManager\Native\Qualification::PROTOCOL) { $row->update(['phase' => 'checking_recovery', 'heartbeat_at' => now()]); }
            return $row;
        });
        if (! $operation) { return; }
        if (($operation->context['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL) { app(\Arcturus\ModpackManager\Native\RecoveryVerification::class)->run($operation, $this->executionId); return; }
        $server = ManagedServer::lightweight()->findOrFail($operation->managed_server_id);
        $requireOpen = function () use ($operation): void { if ($operation->fresh()->completed_at) { throw new RuntimeException('Recovery verification receipt is closed. Retain its stopped recovery checkpoint.'); } };
        $pulseAt = 0;
        $heartbeat = function () use ($operation, &$pulseAt, $requireOpen): void { if (time() - $pulseAt >= 10) { $requireOpen(); $operation->update(['heartbeat_at' => now()]); $pulseAt = time(); } };
        try {
            if ($server->active_operation_id !== $operation->id || ($operation->context['recovery_actor_id'] ?? null) === null) { throw new RuntimeException('Recovery verification receipt is not active.'); }
            $pelican = $server->pelicanServer;
            $power->deploymentGuard($pelican, false);
            if ($power->state($pelican) !== 'exited' || $pelican->status !== null || $pelican->backups()->whereNull('completed_at')->exists()) {
                throw new RuntimeException('Confirm Minecraft stopped and all Pelican backup/restore callbacks completed before verification.');
            }
            $entries = (new LocalWorker($server))->configuration(); $root = $entries[$server->pelican_server_uuid]['root'];
            $expected = $operation->context['restore_receipt'] ?? null;
            if (! $expected) { throw new RuntimeException('No scoped pre-update receipt was recorded. Retain evidence and request host-operator recovery; legacy full-volume fingerprints are not scanned.'); }
            $backupUuid = $operation->context['backup_uuid'] ?? null;
            $watermark = $operation->context['restore_event_watermark'] ?? null;
            if (! $backupUuid || ! is_int($watermark)) { throw new RuntimeException('No exact native restore callback checkpoint. Request host-operator recovery; keep Minecraft stopped.'); }
            $backup = $pelican->backups()->where('uuid', $backupUuid)->firstOrFail();
            app(\Arcturus\ModpackManager\Services\PelicanBackupManager::class)->requireRestored($pelican, $backup, $operation->context['excluded_directories'] ?? [], $watermark);
            $stash = $operation->context['preserved_stash'] ?? null;
            if ($stash) { app(ExcludedFolderPreserver::class)->moveBack($root, $stash); $operation->update(['context' => [...$operation->context, 'preserved_stash' => null]]); }
            app(RestoreInventory::class)->verify($root, $expected, $heartbeat);
            if (app(\Arcturus\ModpackManager\Services\SameHostDeployment::class)->panelFingerprint($pelican->refresh()) !== ($operation->context['panel_sha256'] ?? null)) { throw new RuntimeException('Restore the original Pelican startup/image/egg variables before verifying recovery.'); }
            $requireOpen();
            $operation->update(['phase' => 'recovery_starting', 'progress' => ['message' => 'Native restore callback, managed files and launcher verified; checking Minecraft startup']]);
            $power->deploymentGuard($pelican, true);
            $power->startAndVerify($pelican, $root, timeoutSeconds: (int) config('arcturus-modpack-manager.deployment_start_timeout', 900), heartbeat: $heartbeat);
            $requireOpen();
            $server->update(['last_error' => 'Previous update failed; the original offline snapshot and startup were verified after operator recovery.']);
            $operation->update(['error' => null, 'progress' => ['message' => 'Operator recovery verified; original Minecraft startup confirmed']]);
            app(OperationTracker::class)->transition($operation, 'restored', ['restore_verified' => true, 'recovery_startup_verified' => true]);
            try { $power->sync($pelican); } catch (Throwable) { $operation->update(['error' => 'Original snapshot recovered; sync server configuration to release maintenance.']); }
        } catch (Throwable $error) {
            if ($operation->fresh()->completed_at) { return; }
            $operation->update(['phase' => 'restoring']);
            try { $power->guardAndStop($server->pelicanServer, $heartbeat); } catch (Throwable) { /* Keep the durable recovery lock and show offline confirmation requirement. */ }
            $message = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Recovery verification infrastructure unavailable. Confirm Wings offline state and inspect the retained backup/journal.';
            $operation->update(['error' => $message]);
            app(OperationTracker::class)->transition($operation, 'manual_intervention');
            app(\Arcturus\ModpackManager\Services\DiscordNotifier::class)->criticalRecovery($server, $message, $operation->id);
        }
    }

    public function failed(?Throwable $error): void
    {
        $row = Operation::find($this->operationId);
        if (! $row || $row->completed_at) { return; }
        if (($row->context['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL &&
            ($this->executionId === null || $this->executionId !== ($row->context['native_execution_id'] ?? null))) { return; }
        $server = ManagedServer::lightweight()->findOrFail($row->managed_server_id);
        $lease = new \Arcturus\ModpackManager\Native\DeploymentLease($server->id, $row->id, 'restore_failure');
        if (!$lease->get()) { report(new RuntimeException('Recovery failure handler blocked by another worker lease: '.$row->id)); return; }
        try {
            $row->refresh(); $server->refresh();
            if (($row->context['protocol'] ?? '') === \Arcturus\ModpackManager\Native\Qualification::PROTOCOL && $this->executionId !== ($row->context['native_execution_id'] ?? null)) { return; }
            if ($row->completed_at || $server->active_operation_id !== $row->id || !in_array($row->phase, ['recovery_queued', 'checking_recovery', 'recovery_starting'], true)) { return; }
            $row->update(['phase' => 'restoring']);
            try { app(PelicanPowerController::class)->guardAndStop($server->pelicanServer); } catch (Throwable) { }
            $row->update(['error' => 'Recovery verification interrupted. Confirm Wings offline state and retain the locked backup; do not reapply the update.']);
            if (\Arcturus\ModpackManager\Native\RestorePolicy::automaticRestoreDisabled($row)) { \Arcturus\ModpackManager\Native\RestorePolicy::retainFailure($row); }
            app(OperationTracker::class)->transition($row, 'manual_intervention');
            app(\Arcturus\ModpackManager\Services\DiscordNotifier::class)->criticalRecovery($server, $row->error, $row->id);
        } finally { $lease->release(); }
    }
}
