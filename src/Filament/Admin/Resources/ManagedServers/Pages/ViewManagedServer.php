<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Jobs\CheckRelease;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;
use Arcturus\ModpackManager\Remote\NodeWorker;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\MergeReview;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

final class ViewManagedServer extends ViewRecord
{
    protected static string $resource = ManagedServerResource::class;

    protected string $view = 'arcturus-modpack-manager::managed-server';

    public string $tab = 'overview';

    public string $pathSearch = '';

    public string $classification = '';

    public int $changePage = 1;

    #[Locked]
    public ?array $nodeCapabilities = null;

    #[Locked]
    public ?array $mergeReview = null;

    public function managed()
    {
        Access::requireAdmin();

        return $this->getRecord()->refresh();
    }

    public function selectTab(string $tab): void
    {
        Access::requireAdmin();
        abort_unless(in_array($tab, ['overview', 'updates', 'changes', 'protection', 'backups', 'history'], true), 422);
        $this->tab = $tab;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inspectRuntime')->label('Inspect runtime')->button()->color('gray')->icon('heroicon-o-magnifying-glass')
                ->visible(fn () => $this->tab === 'overview')
                ->action(function (): void {
                    Access::requireAdmin();
                    $record = $this->getRecord()->refresh();
                    $report = app(AdoptionService::class)->inspect($record->pelicanServer);
                    $record->update(['runtime_report' => $report]);
                    $this->nodeCapabilities = (new NodeWorker($record->pelican_server_uuid))->request('capabilities');
                }),
            Action::make('check')->button()->icon('heroicon-o-arrow-path')->label('Check releases')->color('gray')->visible(fn () => in_array($this->tab, ['overview', 'updates'], true))->action(function (): void {
                Access::requireAdmin();
                CheckRelease::dispatch($this->getRecord()->id);
                $this->queued('Release check queued');
            }),
            Action::make('baseline')->button()->icon('heroicon-o-arrow-down-tray')->label('Prepare installed baseline')->visible(fn () => $this->getRecord()->baseline_status !== 'verified')->requiresConfirmation()
                ->modalDescription('Fetch the exact installed upstream release into isolated node staging. The live server is untouched.')
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    PrepareUpdate::dispatch($this->getRecord()->id, true);
                    $this->queued('Baseline preparation queued');
                }),
            Action::make('adopt')->button()->icon('heroicon-o-check-circle')->label('Finish verified adoption')->visible(fn () => ! $this->getRecord()->adopted_at)->requiresConfirmation()
                ->disabled(fn () => $this->getRecord()->baseline_status !== 'verified' || (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    app(AdoptionService::class)->finish($this->getRecord());
                    $this->queued('Existing server adopted without installation');
                }),
            Action::make('monitor')->button()->icon('heroicon-o-eye')->label('Adopt for monitoring only')->requiresConfirmation()
                ->modalDescription('The upstream baseline remains unverified. Destructive updates stay blocked.')
                ->visible(fn () => ! $this->getRecord()->adopted_at)
                ->action(function (): void {
                    Access::requireAdmin();
                    app(AdoptionService::class)->finish($this->getRecord(), true);
                    $this->queued('Monitoring enabled; baseline unverified');
                }),
            Action::make('version')->button()->icon('heroicon-o-queue-list')->label('Select target release')->color('gray')->visible(fn () => $this->tab === 'updates')->schema([
                Select::make('version')->required()->searchable()->options(function (): array {
                    Access::requireAdmin();
                    $r = $this->getRecord();

                    return collect(app(ProviderRegistry::class)->get($r->provider)->versions($r->source))
                        ->mapWithKeys(fn ($v) => [(string) $v['id'] => $v['name'].' · '.$v['id']])->all();
                }),
            ])->action(function (array $data): void {
                Access::requireAdmin();
                $r = $this->getRecord()->refresh();
                abort_if((bool) $r->active_operation_id, 409);
                $release = app(ProviderRegistry::class)->get($r->provider)->release($r->source, (string) $data['version'], $r->asset_pattern);
                DB::transaction(function () use ($r, $release): void {
                    $fresh = ManagedServer::lockForUpdate()->findOrFail($r->id);
                    abort_if((bool) $fresh->active_operation_id, 409);
                    $fresh->update(['selected_version' => $release->version, 'target_metadata' => ['name' => $release->name,
                        'notes' => $release->notes, 'runtime' => $release->runtime, 'blockers' => $release->blockers,
                        'preparation_supported' => $release->canPrepare(), 'download_bytes' => $release->bytes()],
                        'target_manifest_path' => null, 'prepared_root' => null, 'preview_summary' => null, 'approved_plan_sha256' => null]);
                });
                $this->queued('Target release selected');
            }),
            Action::make('prepare')->button()->icon('heroicon-o-arrow-down-tray')->label('Prepare target')->visible(fn () => $this->tab === 'updates')->requiresConfirmation()
                ->disabled(fn () => ! $this->getRecord()->targetVersion() || (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    PrepareUpdate::dispatch($this->getRecord()->id);
                    $this->queued('Target preparation queued');
                }),
            Action::make('preview')->button()->icon('heroicon-o-document-magnifying-glass')->label('Compare files')->color('gray')->visible(fn () => in_array($this->tab, ['updates', 'changes'], true))->action(function (): void {
                Access::requireAdmin();
                app(PreviewService::class)->generate($this->getRecord());
                $this->tab = 'changes';
            }),
            EditAction::make(),
        ];
    }

    private function queued(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    public function resolve(string $path, string $mode): void
    {
        Access::requireAdmin();
        abort_unless(in_array($mode, ['protect', 'replace', 'review', 'disable'], true), 422);
        $record = $this->getRecord()->refresh();
        abort_if((bool) $record->active_operation_id, 409);
        $changes = $record->preview_summary['changes'] ?? [];
        abort_unless(collect($changes)->contains(fn ($c) => $c['path'] === $path), 422);
        UpdatePlanner::assertPath($path);
        // Escape glob metacharacters by rejecting them for exact-path decisions.
        abort_if(strpbrk($path, '*?') !== false, 422);
        $rules = $record->file_rules ?? [];
        $rules[] = ['pattern' => $path, 'mode' => $mode];
        $record->update(['file_rules' => $rules, 'approved_plan_sha256' => null, 'approved_at' => null]);
        app(PreviewService::class)->generate($record);
    }

    public function reviewMerge(string $path): void
    {
        Access::requireAdmin();
        $record = $this->getRecord()->refresh();
        abort_unless(collect($record->preview_summary['changes'] ?? [])->contains(fn ($c) => $c['path'] === $path), 422);
        $this->mergeReview = app(MergeReview::class)->properties($record, $path);
    }

    public function cancelPreparation(): void
    {
        Access::requireAdmin();
        $record = $this->getRecord()->refresh();
        $operation = $record->operations()->whereNull('completed_at')->whereIn('kind', ['baseline', 'prepare'])->firstOrFail();
        (new NodeWorker($record->pelican_server_uuid))->request('cancel', ['operation_id' => $operation->node_operation_id]);
        $this->queued('Cancellation requested; waiting for node confirmation');
    }

    public function resumePreparation(): void
    {
        Access::requireAdmin();
        $op = $this->getRecord()->operations()->whereNull('completed_at')->firstOrFail();
        abort_unless(in_array($op->kind, ['baseline', 'prepare'], true), 409);
        PrepareUpdate::dispatch($op->managed_server_id, $op->kind === 'baseline', $op->id);
        $this->queued('Preparation reconciliation queued');
    }

    public function changeRows(): array
    {
        $changes = $this->managed()->preview_summary['changes'] ?? $this->managed()->adoption_report['changes'] ?? [];
        $filtered = array_values(array_filter($changes, fn ($c) => $c['action'] !== 'unchanged' &&
            ($this->classification === '' || $c['classification'] === $this->classification) &&
            ($this->pathSearch === '' || str_contains(strtolower($c['path']), strtolower($this->pathSearch)))));
        $pages = max(1, (int) ceil(count($filtered) / 50));

        return ['total' => count($filtered), 'pages' => $pages, 'page' => min($pages, max(1, $this->changePage)),
            'rows' => array_slice($filtered, (min($pages, max(1, $this->changePage)) - 1) * 50, 50)];
    }
}
