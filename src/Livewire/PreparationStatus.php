<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Livewire;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\PreparationDiagnostic;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Poll a few small database rows only; never scan files or call a worker from rendering. */
final class PreparationStatus extends Component
{
    #[Locked]
    public int $managedServerId;

    public function mount(int $managedServerId): void
    {
        Access::requireAdmin();
        $this->managedServerId = $managedServerId;
    }

    public function render()
    {
        Access::requireAdmin();
        $server = ManagedServer::findOrFail($this->managedServerId, ['id', 'active_operation_id']);
        $event = $server->events()->whereIn('kind', ['baseline', 'prepare'])->latest('id')->first(['details', 'status']);
        $id = $server->active_operation_id ?: ($event?->details['operation_id'] ?? null);
        $operation = $id ? $server->operations()->find($id, ['id', 'kind', 'phase', 'node_operation_id', 'heartbeat_at', 'completed_at', 'cancel_requested_at', 'progress']) : null;
        $report = $operation?->progress ?? [];
        $detail = $report['diagnostic'] ?? ($event?->details['diagnostic'] ?? null);
        $diagnostic = $detail ? PreparationDiagnostic::normalize($detail) : null;
        $legacy = [];
        if ($operation && ! $report) {
            $legacy = Operation::find($operation->id, ['context'])?->context['progress'] ?? [];
        }
        return view('arcturus-modpack-manager::preparation-status', compact('operation', 'report', 'diagnostic', 'legacy'));
    }
}
