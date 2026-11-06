<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PreparationDiagnostic;
use Arcturus\ModpackManager\Services\PreparationException;
use Arcturus\ModpackManager\Services\PreparationProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Explicit retry of read-only comparison. Never resolves or downloads an upstream release. */
final class CompareInstallation implements ShouldQueue
{
    use Queueable;

    public int $timeout = LocalWorker::TIMEOUT + 30;
    public int $tries = 1;

    public function __construct(public readonly int $managedServerId, public readonly string $operationId) {}

    public function handle(AdoptionService $adoption, OperationTracker $tracker): void
    {
        $lock = Cache::lock('arcturus-modpack-manager-operation-'.$this->managedServerId, $this->timeout + 30);
        if (! $lock->get()) { $this->release(15); return; }
        $operation = null;
        try {
            $operation = Operation::where('managed_server_id', $this->managedServerId)->findOrFail($this->operationId);
            if ($operation->completed_at) { return; }
            $server = ManagedServer::findOrFail($this->managedServerId);
            $context = $operation->context;
            if ($operation->kind !== 'comparison' || $server->active_operation_id !== $operation->id ||
                $server->baseline_status !== 'verified' || $server->base_manifest_path !== ($context['baseline'] ?? null) ||
                $server->policy_revision !== ($context['policy_revision'] ?? null) ||
                $server->provider !== ($context['provider'] ?? null) || $server->source !== ($context['source'] ?? null) ||
                $server->installed_version !== ($context['version'] ?? null)) {
                throw new PreparationException(['code' => 'COMPARISON_STALE', 'phase' => 'comparing']);
            }
            if ($operation->cancel_requested_at) {
                throw new PreparationException(['code' => 'CANCELLED', 'phase' => 'comparing']);
            }
            $tracker->transition($operation, 'comparing');
            PreparationProgress::phase($operation, 'comparing', 'inventory_enumeration');
            $adoption->compare($server, $operation);
            $tracker->transition($operation, 'completed');
        } catch (\Throwable $error) {
            $detail = $error instanceof PreparationException ? $error->diagnostic : PreparationDiagnostic::normalize(['code' => 'COMPARISON_FAILED', 'phase' => 'comparing']);
            if ($operation && ! $operation->completed_at) {
                $operation->update(['error' => PreparationDiagnostic::message($detail), 'context' => [...$operation->context, 'diagnostic' => $detail]]);
                ManagedServer::whereKey($this->managedServerId)->where('active_operation_id', $operation->id)
                    ->whereIn('comparison_status', ['queued', 'running'])->update(['comparison_status' => 'failed', 'comparison_diagnostic' => json_encode($detail, JSON_THROW_ON_ERROR)]);
                $tracker->transition($operation, $detail['code'] === 'CANCELLED' ? 'cancelled' : 'failed');
            }
            if ($detail['code'] !== 'CANCELLED') { throw new PreparationException($detail); }
        } finally {
            $lock->release();
        }
    }
}
