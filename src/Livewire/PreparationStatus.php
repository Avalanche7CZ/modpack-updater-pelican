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

    #[\Livewire\Attributes\Renderless]
    public function exportDiagnostics(): string
    {
        Access::requireAdmin();
        $server = ManagedServer::findOrFail($this->managedServerId, ['id', 'active_operation_id']);
        $event = $server->events()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->latest('id')->first(['details']);
        $id = $server->active_operation_id ?: ($event?->details['operation_id'] ?? null);
        $operation = $id ? $server->operations()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->find($id) : null;
        abort_unless($operation, 404);
        $detail = $operation->progress['diagnostic'] ?? $operation->context['diagnostic'] ?? null;
        $detail = $detail ? PreparationDiagnostic::normalize($detail) : null;
        // An explicit allowlist: never export encrypted context, recipes, URLs, credentials or raw logs.
        return json_encode(['schema' => 1, 'operation_id' => $operation->id, 'worker_operation_id' => $operation->node_operation_id,
            'kind' => $operation->kind, 'state' => $operation->phase, 'diagnostic' => $detail ? [
                'code' => $detail['code'], 'phase' => $detail['phase'], 'description' => $detail['description'],
                'context' => array_intersect_key($detail['context'], array_flip(['http_status', 'errno', 'artifact_index', 'entry_index', 'file_index', 'file_id'])),
            ] : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function render()
    {
        Access::requireAdmin();
        $server = ManagedServer::findOrFail($this->managedServerId, ['id', 'active_operation_id']);
        $event = $server->events()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->latest('id')->first(['details', 'status']);
        $id = $server->active_operation_id ?: ($event?->details['operation_id'] ?? null);
        $operation = $id ? $server->operations()->find($id, ['id', 'kind', 'phase', 'node_operation_id', 'heartbeat_at', 'completed_at', 'cancel_requested_at', 'progress']) : null;
        $report = $operation?->progress ?? [];
        $detail = $report['diagnostic'] ?? ($event?->details['diagnostic'] ?? null);
        $diagnostic = $detail ? PreparationDiagnostic::normalize($detail) : null;
        $legacy = [];
        if ($operation && ! $report) {
            $legacy = Operation::find($operation->id, ['context'])?->context['progress'] ?? [];
        }
        $presentation = \Arcturus\ModpackManager\Services\PreparationProgressView::make($report,
            $operation?->phase ?? 'queued', $operation?->completed_at !== null,
            $operation?->cancel_requested_at !== null, time());
        if ($operation?->kind === 'comparison') {
            $presentation['rows'] = array_values(array_filter($presentation['rows'], fn ($row) => $row['key'] === 'comparing'));
        }
        return view('arcturus-modpack-manager::preparation-status', compact('operation', 'report', 'diagnostic', 'legacy', 'presentation'));
    }
}
