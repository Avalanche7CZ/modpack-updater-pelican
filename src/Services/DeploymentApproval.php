<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** One administrator approval consumes one exact plan and durable deployment intent. */
final class DeploymentApproval
{
    public function verifyRecovery(ManagedServer $server): Operation
    {
        Access::requireAdmin();
        if (auth()->id() === null) { throw new RuntimeException('An identified administrator must request recovery verification.'); }
        app(QueuePreparation::class)->requireLocalQueue();
        return DB::transaction(function () use ($server): Operation {
            $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
            $operation = $fresh->operations()->lockForUpdate()->findOrFail($fresh->active_operation_id);
            if ($operation->phase === 'recovery_queued') { return $operation; }
            if ($operation->kind !== 'deploy' || $operation->phase !== 'manual_intervention') { throw new RuntimeException('Only a stopped deployment recovery checkpoint can be verified.'); }
            $operation->update(['phase' => 'recovery_queued', 'completed_at' => null, 'heartbeat_at' => now(), 'error' => null, 'context' => [...$operation->context, 'previous_recovery_error' => $operation->error, 'recovery_actor_id' => auth()->id(), 'native_execution_id' => bin2hex(random_bytes(16))],
                'progress' => ['message' => 'Administrator requested verification of the manually restored original snapshot']]);
            DB::afterCommit(function () use ($operation): void {
                try { Bus::dispatch(new \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery($operation->id)); }
                catch (\Throwable) { $operation->update(['error' => 'Recovery verification queued; dispatch unavailable. Scheduler can dispatch this same verification receipt.']); }
            });
            return $operation;
        });
    }

    public function queue(ManagedServer $server, string $digest, string $requestId): Operation
    {
        Access::requireAdmin();
        if (auth()->id() === null) { throw new RuntimeException('An identified root administrator must approve deployment.'); }
        if (! preg_match('/^[a-f0-9]{64}$/D', $digest) || ! \Illuminate\Support\Str::isUuid($requestId)) { throw new RuntimeException('Invalid deployment approval receipt.'); }
        return $this->enqueue($server, $digest, $requestId, (int) auth()->id(), false);
    }

    public function queueAutomatic(ManagedServer $server, ?string $requestId = null): Operation
    {
        app(ScheduledMaintenance::class)->requireEnabled($server);
        return $this->enqueue($server, $server->preview_summary['plan_sha256'] ?? '', $requestId ?? (string) \Illuminate\Support\Str::uuid(), (int) $server->automation_actor_id, true);
    }

    private function enqueue(ManagedServer $server, string $digest, string $requestId, int $actorId, bool $automatic): Operation
    {
        return DB::transaction(function () use ($server, $digest, $requestId, $actorId, $automatic): Operation {
            $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
            $existing = $fresh->operations()->find($requestId);
            if ($existing) {
                if ($existing->kind !== 'deploy' || ($existing->context['plan_sha256'] ?? '') !== $digest || ($existing->context['actor_id'] ?? null) !== $actorId) {
                    throw new RuntimeException('Approval request already belongs to a different deployment.');
                }
                return $existing;
            }
            if ($automatic) { app(ScheduledMaintenance::class)->requireEnabled($fresh); }
            app(QueuePreparation::class)->requireLocalQueue();
            app(DeploymentGate::class)->requireReady($fresh);
            if (! hash_equals($fresh->preview_summary['plan_sha256'] ?? '', $digest)) { throw new RuntimeException('Plan changed while the approval dialog was open; review it again.'); }
            $snapshot = app(SameHostDeployment::class)->panelSnapshot($fresh->pelicanServer);
            if (! hash_equals($fresh->preview_summary['qualification']['panel_sha256'], hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) { throw new RuntimeException('Pelican launch settings changed since comparison; inspect and compare again.'); }
            $operation = app(OperationTracker::class)->create($fresh, 'deploy', ['actor_id' => $actorId, 'manual_approval' => true, 'automatic_approval' => $automatic,
                'plan_sha256' => $digest, 'from' => $fresh->installed_version, 'to' => $fresh->targetVersion(),
                'policy_revision' => $fresh->policy_revision, 'panel_snapshot' => $snapshot, 'panel_sha256' => $fresh->preview_summary['qualification']['panel_sha256'], 'protocol' => $fresh->preview_summary['qualification']['protocol']], $requestId);
            $fresh->update(['approved_plan_sha256' => $digest, 'approved_at' => now(), 'approved_by' => $actorId]);
            DB::afterCommit(function () use ($fresh, $operation, $digest, $automatic): void {
                try { Bus::dispatch(new DeployManagedServer($fresh->id, $automatic, $digest, $operation->id)); }
                catch (\Throwable) { $operation->update(['error' => 'Approval saved; queue dispatch unavailable. Scheduler may dispatch this same queued receipt. No server files changed.']); }
            });
            return $operation;
        });
    }
}
