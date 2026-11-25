<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Livewire;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhArtifact;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\GtnhArchiveTrust;
use Arcturus\ModpackManager\Services\PreparationDiagnostic;
use Arcturus\ModpackManager\Services\QueuePreparation;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/** Poll a few small database rows only; never scan files or call a worker from rendering. */
final class PreparationStatus extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use RestrictsFileUploadsToSchemaComponents;

    #[Locked]
    public int $managedServerId;

    #[Locked]
    public string $purpose = 'all';

    public function mount(int $managedServerId, string $purpose = 'all'): void
    {
        Access::requireAdmin();
        abort_unless(in_array($purpose, ['all', 'target'], true), 422);
        $this->managedServerId = $managedServerId;
        $this->purpose = $purpose;
    }

    #[\Livewire\Attributes\Renderless]
    public function exportDiagnostics(): string
    {
        Access::requireAdmin();
        $server = ManagedServer::findOrFail($this->managedServerId, ['id', 'active_operation_id', 'target_manifest_path', 'provider', 'transport']);
        $event = $server->events()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->latest('id')->first(['details']);
        $id = $server->active_operation_id ?: ($event?->details['operation_id'] ?? null);
        $operation = $id ? $server->operations()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->find($id) : null;
        abort_unless($operation, 404);
        $detail = $operation->progress['diagnostic'] ?? $operation->context['diagnostic'] ?? null;
        $detail = $detail ? PreparationDiagnostic::normalize($detail) : null;
        // An explicit allowlist: never export encrypted context, recipes, URLs, credentials or raw logs.
        return json_encode(['schema' => 1, 'operation_id' => $operation->id, 'worker_operation_id' => $operation->node_operation_id,
            'provider' => array_key_exists($server->provider, \Arcturus\ModpackManager\Modpacks\ProviderRegistry::PROVIDERS) ? $server->provider : 'unknown',
            'current_backend' => in_array($server->transport, ['native', 'local', 'wings'], true) ? $server->transport : 'unknown',
            'kind' => $operation->kind, 'state' => $operation->phase, 'diagnostic' => $detail ? [
                'code' => $detail['code'], 'phase' => $detail['phase'], 'description' => $detail['description'],
                'context' => array_intersect_key($detail['context'], array_flip(['http_status', 'errno', 'artifact_index', 'entry_index', 'file_index', 'file_id', 'host', 'reason'])),
            ] : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function approveArchiveAction(): Action
    {
        return $this->archiveAction('approveArchive', 'Approve archive', true);
    }

    public function rejectArchiveAction(): Action
    {
        return $this->archiveAction('rejectArchive', 'Reject archive', false);
    }

    private function archiveAction(string $name, string $label, bool $approve): Action
    {
        return Action::make($name)->button()->size('sm')->label($label)
            ->color($approve ? 'primary' : 'danger')
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalIcon($approve ? 'heroicon-o-shield-check' : 'heroicon-o-exclamation-triangle')
            ->modalDescription($approve
                ? 'Trust this exact archive on all managed servers and continue preparation on this server.'
                : 'Block preparation of this exact archive on all managed servers.')
            ->modalContent(fn (array $arguments) => view('arcturus-modpack-manager::archive-review', ['archive' => $arguments]))
            ->modalSubmitActionLabel($label)
            ->closeModalByClickingAway(false)
            ->action(fn (array $arguments) => $this->decideArchive($arguments, $approve));
    }

    private function decideArchive(array $archive, bool $approve): void
    {
        Access::requireAdmin();
        $this->resetErrorBag('archiveDecision');

        try {
            $continuation = app(QueuePreparation::class)->decideArchive($this->managedServerId,
                $archive['operation_id'], $archive['artifact_id'], $archive['sha256'], $archive['size'], $approve);
        } catch (Throwable $error) {
            $message = match (true) {
                $error instanceof ModelNotFoundException => 'This archive review is no longer available. Refresh the page for the current status.',
                get_class($error) === RuntimeException::class => $error->getMessage(),
                default => 'The save could not be confirmed. Refresh the page to check the recorded decision.',
            };
            if (! $error instanceof ModelNotFoundException && get_class($error) !== RuntimeException::class) {
                report($error);
            }
            $this->addError('archiveDecision', $message);
            Notification::make()->title('Archive decision could not be confirmed')->body($message)->danger()->send();
            return;
        }

        $this->dispatch('archive-decision-recorded')->to(ViewManagedServer::class);
        $notification = Notification::make()->title($approve ? 'Archive approved' : 'Archive rejected');
        if ($continuation?->fresh()?->error) {
            $notification->body('Preparation is waiting for queue dispatch.')->warning()->send();
        } else {
            $notification->body($approve ? 'Preparation will continue automatically.' : 'Preparation is blocked for this archive on all managed servers.')
                ->success()->send();
        }
    }

    public function render()
    {
        Access::requireAdmin();
        $server = ManagedServer::findOrFail($this->managedServerId, ['id', 'active_operation_id', 'target_manifest_path', 'gtnh_trust_mode']);
        $event = $server->events()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->latest('id')->first(['details', 'status']);
        $id = $server->active_operation_id ?: ($event?->details['operation_id'] ?? null);
        $operation = $id ? $server->operations()->find($id, ['id', 'kind', 'phase', 'node_operation_id', 'heartbeat_at', 'completed_at', 'cancel_requested_at', 'progress', 'error']) : null;
        // A historical baseline/adoption comparison is not readiness of a new target.
        // Keep failures visible, but never label a discarded/missing target stage Completed.
        if ($this->purpose === 'target' && ! $server->active_operation_id &&
            ($operation?->kind === 'baseline' || ($operation?->phase === 'completed' && ! $server->target_manifest_path))) {
            $operation = null;
            $event = null;
        }
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
        if (($report['runtime_preparation']['state'] ?? '') === 'blocked') { $presentation['status'] = 'Runtime blocked'; $presentation['color'] = 'warning'; }
        if ($operation?->kind === 'comparison') {
            $presentation['rows'] = array_values(array_filter($presentation['rows'], fn ($row) => $row['key'] === 'comparing'));
        }
        $archive = null; $approvalRequired = false;
        if (isset($report['gtnh_archive'])) {
            $artifact = GtnhArtifact::fromArray($report['gtnh_archive']);
            $trust = app(GtnhArchiveTrust::class);
            $pin = $trust->evidence($artifact);
            $archive = [...$artifact->identity(), 'artifact_id' => $artifact->id(), ...($pin ?? ['level' => 'unmeasured'])];
            $approvalRequired = $pin !== null && $pin['level'] !== 'rejected' && ! $trust->usable($pin, $server);
            if ($operation->phase === 'archive_review_required') {
                [$presentation['status'], $presentation['color']] = match ($archive['level']) {
                    'admin_reviewed' => ['Archive approved', 'success'],
                    'rejected' => ['Archive rejected', 'danger'],
                    default => $approvalRequired || $pin === null
                        ? ['Archive approval required', 'warning']
                        : ['Archive ready for preparation', 'success'],
                };
            }
        }
        return view('arcturus-modpack-manager::preparation-status', compact('operation', 'report', 'diagnostic', 'legacy', 'presentation', 'archive', 'approvalRequired'));
    }
}
