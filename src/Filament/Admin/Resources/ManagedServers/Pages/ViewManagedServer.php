<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Filament\Forms\PolicyFields;
use Arcturus\ModpackManager\Jobs\CheckRelease;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Services\FilesystemBackendSettings;
use Arcturus\ModpackManager\Services\QueuePreparation;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\FilePolicyCatalog;
use Arcturus\ModpackManager\Services\MergeReview;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\ServerPolicyService;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

    public array $selectedPaths = [];

    public bool $modsOnly = true;

    #[Locked]
    public ?array $nodeCapabilities = null;

    #[Locked]
    public ?array $mergeReview = null;

    public function managed()
    {
        Access::requireAdmin();

        $record = $this->getRecord();
        $record->setRawAttributes(ManagedServer::lightweight()->findOrFail($record->id)->getAttributes(), true);
        $record->evidenceProjection = true;
        $record->unsetRelations();
        return $record;
    }

    public function selectTab(string $tab): void
    {
        Access::requireAdmin();
        abort_unless(in_array($tab, ['overview', 'updates', 'changes', 'mods', 'protection', 'backups', 'history'], true), 422);
        $this->tab = $tab;
        $this->changePage = 1;
        $this->classification = '';
        $this->pathSearch = '';
        $this->selectedPaths = [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('filesystemBackend')->label('Filesystem backend')->button()->color('gray')
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->fillForm(fn () => ['transport' => $this->getRecord()->transport ?? 'wings'])
                ->schema([Select::make('transport')->label('Preparation and inventory backend')->required()->options([
                    'wings' => 'Remote node worker', 'local' => 'Same-host local filesystem',
                ])->helperText('Local servers are discovered from the approved Wings node. Complete Local filesystem setup once; private UUID staging is created automatically.')])
                ->modalDescription('Changing backend invalidates baseline and plan verification. Existing protection rules and Minecraft files remain unchanged.')
                ->action(function (array $data): void {
                    try {
                        app(FilesystemBackendSettings::class)->save($this->getRecord(), $data['transport']);
                        $this->nodeCapabilities = null;
                        $this->queued('Filesystem backend validated; prepare the installed baseline');
                    } catch (\Throwable $error) {
                        Notification::make()->title('Filesystem backend unavailable')->body($error->getMessage())->danger()->send();
                    }
                }),
            Action::make('testFilesystem')->label('Test filesystem access')->button()->color('gray')
                ->action(function (): void {
                    Access::requireAdmin();
                    try {
                        $this->nodeCapabilities = app(ServerFilesFactory::class)->worker($this->getRecord())->request('capabilities');
                        $this->queued('Filesystem capability check passed; no server files scanned');
                    } catch (\Throwable $error) {
                        $this->nodeCapabilities = null;
                        Notification::make()->title('Filesystem access unavailable')->body($error->getMessage())->danger()->send();
                    }
                }),
            Action::make('directoryDecisions')->label('Review upstream directories')->button()->color('gray')
                ->visible(fn () => (bool) $this->getRecord()->base_manifest_path)
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->modalDescription('Verified upstream structure. Standard folders default to Manage. Unfamiliar folders require a decision. Keep local and Exclude prevent inventory reads and updates. Protected runtime paths cannot be managed. Saving invalidates previous approval; compare again without downloading.')
                ->fillForm(fn () => ['directories' => (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->rows($this->getRecord()), 'revision' => $this->getRecord()->policy_revision, 'request_id' => (string) \Illuminate\Support\Str::uuid()])
                ->schema([
                    \Filament\Forms\Components\Repeater::make('directories')->addable(false)->deletable(false)->reorderable(false)
                        ->itemLabel(fn (array $state) => ($state['path'] ?? '').' · '.($state['classification'] ?? ''))
                        ->schema([
                            Hidden::make('path'), Hidden::make('classification'),
                            Select::make('mode')->options(['review' => 'Choose a decision', 'manage' => 'Manage', 'keep' => 'Keep local', 'exclude' => 'Exclude'])
                                ->disabled(fn ($get) => $get('classification') === 'protected')->required(),
                        ]),
                    Hidden::make('revision'), Hidden::make('request_id'),
                ])->action(function (array $data): void {
                    $server = $this->getRecord()->refresh();
                    $decisions = array_values(array_map(fn ($row) => ['path' => $row['path'], 'mode' => $row['mode']],
                        array_filter($data['directories'] ?? [], fn ($row) => ($row['classification'] ?? '') !== 'protected' && ($row['mode'] ?? 'review') !== 'review')));
                    $operation = app(ServerPolicyService::class)->saveDirectories($server, $decisions, (int) $data['revision'], $data['request_id']);
                    $this->queued('Directory decisions saved; comparison operation '.$operation->id);
                }),
            Action::make('editPolicies')->label('Edit protection rules')->button()->color('gray')
                ->visible(fn () => $this->tab === 'protection')->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->fillForm(fn () => ['file_rules' => (new ProtectionPolicy)->normalize($this->getRecord()->file_rules ?? []), 'disabled_mods' => $this->getRecord()->disabled_mods ?? [], 'revision' => $this->getRecord()->policy_revision])
                ->schema([...PolicyFields::rules(), Hidden::make('revision')])
                ->modalSubmitActionLabel('Save rules and invalidate approval')
                ->action(function (array $data): void {
                    app(ServerPolicyService::class)->save($this->getRecord()->refresh(), $data['file_rules'] ?? [], $data['disabled_mods'] ?? [], (int) $data['revision']);
                    $this->recompare();
                }),
            Action::make('filePolicy')->label('Set policy for selected files')->button()
                ->visible(fn () => in_array($this->tab, ['mods', 'changes'], true))
                ->disabled(fn () => ! $this->selectedPaths || (bool) $this->getRecord()->active_operation_id)
                ->fillForm(fn () => ['revision' => $this->getRecord()->policy_revision])
                ->schema([
                    Select::make('mode')->label('Policy')->options(ProtectionPolicy::ACTIONS)->required()->live(),
                    Checkbox::make('confirm')->label('I reviewed dependencies and approve replacement, removal or disabling during a future update')
                        ->visible(fn ($get) => in_array($get('mode'), ['replace', 'remove', 'disable'], true))->accepted(),
                    Hidden::make('revision'),
                ])->modalDescription('Changes the saved policy only. Single-ID mod rules follow renamed JARs; ambiguous IDs use exact paths. A fresh deployment approval and backup remain required.')
                ->modalSubmitActionLabel('Save selected policies')
                ->action(function (array $data): void {
                    app(ServerPolicyService::class)->assign($this->getRecord()->refresh(), $this->selectedPaths, $data['mode'], (int) $data['revision']);
                    $this->selectedPaths = [];
                    $this->recompare();
                }),
            Action::make('inspectRuntime')->label('Inspect runtime')->button()->color('gray')->icon('heroicon-o-magnifying-glass')
                ->visible(fn () => $this->tab === 'overview')
                ->action(function (): void {
                    Access::requireAdmin();
                    $record = $this->getRecord()->refresh();
                    try {
                        $report = app(AdoptionService::class)->inspect($record->pelicanServer);
                        $record->update(['runtime_report' => $report]);
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->title('Runtime inspection failed')
                            ->body('Wings file metadata was unavailable. Check Pelican logs and node connectivity.')
                            ->danger()->send();

                        return;
                    }
                    try {
                        // Integrity backend failure must not invalidate the
                        // runtime evidence available through the normal Wings API.
                        $this->nodeCapabilities = app(ServerFilesFactory::class)->worker($record)->request('capabilities');
                    } catch (\Throwable $error) {
                        $this->nodeCapabilities = ['available' => false, 'reason' => 'Selected filesystem backend unavailable; use Test filesystem access'];
                    }
                    if (! ($report['inspection_complete'] ?? false) || ! empty($report['warnings'])) {
                        Notification::make()->title('Runtime evidence is incomplete')
                            ->body('Some launcher files or the container state could not be verified.')
                            ->warning()->send();
                    } else {
                        Notification::make()->title('Runtime inspection complete')->success()->send();
                    }
                }),
            Action::make('check')->button()->icon('heroicon-o-arrow-path')->label('Check releases')->color('gray')->visible(fn () => in_array($this->tab, ['overview', 'updates'], true))->action(function (): void {
                Access::requireAdmin();
                CheckRelease::dispatch($this->getRecord()->id);
                $this->queued('Release check queued');
            }),
            Action::make('reassociate')->label('Record a manual update')->button()->color('gray')->icon('heroicon-o-pencil-square')
                ->visible(fn () => $this->tab === 'overview' && $this->getRecord()->adopted_at !== null)
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->modalHeading('Record the release already installed manually')
                ->modalDescription('This changes the database association only. It clears the old baseline and approvals, then requires a new upstream comparison. No server files or runtime settings are changed.')
                ->modalSubmitActionLabel('Record release and invalidate baseline')
                ->schema([TextInput::make('version')->label('Exact installed upstream release ID / tag')->required()->maxLength(120)])
                ->action(function (array $data): void {
                    Access::requireAdmin();
                    app(AdoptionService::class)->reassociate($this->getRecord(), $data['version']);
                    $this->mergeReview = null;
                    $this->queued('Release association updated; prepare the exact baseline and review adoption again');
                }),
            Action::make('baseline')->button()->icon('heroicon-o-arrow-down-tray')->label('Prepare installed baseline')->visible(fn () => $this->getRecord()->baseline_status !== 'verified')->requiresConfirmation()
                ->modalDescription('Fetch the exact installed upstream release into isolated node staging. The live server is untouched.')
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    $this->queuePreparation(true);
                }),
            Action::make('retryComparison')->label('Retry installation comparison')->button()->icon('heroicon-o-arrow-path')
                ->visible(fn () => $this->getRecord()->baseline_status === 'verified' && (bool) $this->getRecord()->base_manifest_path)
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->fillForm(fn () => ['request_id' => (string) \Illuminate\Support\Str::uuid()])->schema([Hidden::make('request_id')])
                ->modalDescription('Reuse the verified upstream manifest with current directory decisions and protection rules. No download, extraction, staged hashing or live file changes.')
                ->action(function (array $data): void {
                    app(QueuePreparation::class)->comparison($this->getRecord(), false, $data['request_id']);
                    $this->queued('Installation comparison queued; verified upstream manifest reused');
                }),
            Action::make('adopt')->button()->icon('heroicon-o-check-circle')->label('Finish verified adoption')->visible(fn () => ! $this->getRecord()->adopted_at)->requiresConfirmation()
                ->disabled(fn () => $this->getRecord()->baseline_status !== 'verified' || $this->getRecord()->comparison_status !== 'complete' || (bool) $this->getRecord()->active_operation_id)
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
                        'notes' => $release->notes, 'release_url' => $release->url, 'runtime' => $release->runtime, 'blockers' => $release->blockers,
                        'preparation_supported' => $release->canPrepare(), 'download_bytes' => $release->bytes()],
                        'target_manifest_path' => null, 'prepared_root' => null, 'preview_summary' => null, 'approved_plan_sha256' => null]);
                });
                $this->queued('Target release selected');
            }),
            Action::make('prepare')->button()->icon('heroicon-o-arrow-down-tray')->label('Prepare target')->visible(fn () => $this->tab === 'updates')->requiresConfirmation()
                ->disabled(fn () => ! $this->getRecord()->targetVersion() || (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    $this->queuePreparation(false);
                }),
            Action::make('preview')->button()->icon('heroicon-o-document-magnifying-glass')->label('Compare files')->color('gray')->visible(fn () => in_array($this->tab, ['updates', 'changes'], true))
                ->fillForm(fn () => ['request_id' => (string) \Illuminate\Support\Str::uuid()])->schema([Hidden::make('request_id')])
                ->action(function (array $data): void {
                Access::requireAdmin();
                app(QueuePreparation::class)->comparison($this->getRecord(), true, $data['request_id']);
                $this->tab = 'changes';
            }),
            EditAction::make(),
        ];
    }

    private function queuePreparation(bool $baseline): void
    {
        try {
            app(QueuePreparation::class)->queue($this->getRecord(), $baseline);
            $this->queued($baseline ? 'Baseline preparation queued' : 'Target preparation queued');
        } catch (\Throwable $error) {
            Notification::make()->title('Preparation unavailable')->body($error->getMessage())->persistent()->danger()->send();
        }
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
        abort_unless(isset((new FilePolicyCatalog)->selected($record, [$path])[$path]), 422);
        UpdatePlanner::assertPath($path);
        // Escape glob metacharacters by rejecting them for exact-path decisions.
        abort_if(strpbrk($path, '*?') !== false, 422);
        app(ServerPolicyService::class)->assign($record, [$path], $mode, $record->policy_revision);
        $this->recompare();
    }

    private function recompare(): void
    {
        $this->mergeReview = null;
        $record = $this->getRecord()->refresh();
        try {
            if ($record->base_manifest_path && $record->target_manifest_path) {
                app(QueuePreparation::class)->comparison($record, true);
            } elseif ($record->baseline_status === 'verified' && $record->base_manifest_path) {
                app(QueuePreparation::class)->comparison($record);
            }
            $this->queued('Server policies saved; previous approval invalidated');
        } catch (\Throwable $error) {
            Notification::make()->title('Policy saved; comparison unavailable')->body('Previous approval is invalid. '.($error instanceof \Arcturus\ModpackManager\Services\PreparationException ? $error->getMessage() : 'Retry installation comparison using the existing verified baseline.'))->warning()->send();
        }
    }

    public function fileRows(): array
    {
        return (new FilePolicyCatalog)->page($this->managed(), $this->changePage, $this->modsOnly, $this->classification, $this->pathSearch);
    }

    public function inventoryWarnings(): array
    {
        $id = $this->getRecord()->id;
        return ['skipped_symlinks' => \Arcturus\ModpackManager\Services\EvidenceView::warnings($id, 'skipped_symlinks'),
            'unreadable_paths' => \Arcturus\ModpackManager\Services\EvidenceView::warnings($id, 'unreadable_paths')];
    }

    public function reviewMerge(string $path): void
    {
        Access::requireAdmin();
        $record = $this->getRecord()->refresh();
        abort_unless(isset((new FilePolicyCatalog)->selected($record, [$path])[$path]), 422);
        $this->mergeReview = app(MergeReview::class)->properties($record, $path);
    }

    public function cancelPreparation(): void
    {
        Access::requireAdmin();
        $record = $this->getRecord()->refresh();
        $operation = $record->operations()->whereNull('completed_at')->whereIn('kind', ['baseline', 'prepare', 'comparison'])->first();
        if (! $operation) { $this->queued('No active preparation to cancel'); return; }
        $operation->update(['cancel_requested_at' => now()]);
        if ($operation->phase !== 'queued') {
            try { app(ServerFilesFactory::class)->worker($record)->request('cancel', ['operation_id' => $operation->node_operation_id]); }
            catch (\Throwable) { /* Intent remains visible; the queue will reconcile it. */ }
        }
        $this->queued('Cancellation requested; waiting for node confirmation');
    }

    public function resumePreparation(): void
    {
        Access::requireAdmin();
        $op = $this->getRecord()->operations()->whereNull('completed_at')->firstOrFail();
        abort_unless(in_array($op->kind, ['baseline', 'prepare', 'comparison'], true), 409);
        if ($op->kind === 'comparison') {
            \Arcturus\ModpackManager\Jobs\CompareInstallation::dispatch($op->managed_server_id, $op->id);
        } else { PrepareUpdate::dispatch($op->managed_server_id, $op->kind === 'baseline', $op->id); }
        $this->queued('Preparation reconciliation queued');
    }

    public function changeRows(): array
    {
        return \Arcturus\ModpackManager\Services\EvidenceView::changePage($this->getRecord()->id, $this->changePage, $this->pathSearch, $this->classification);
    }
}
