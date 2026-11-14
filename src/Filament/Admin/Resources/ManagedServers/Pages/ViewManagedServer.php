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
use Arcturus\ModpackManager\Services\KubeJsDiff;
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
use Livewire\Attributes\Renderless;

final class ViewManagedServer extends ViewRecord
{
    public const CODE_ID = 'manual-handoff-leases-v2';
    protected static string $resource = ManagedServerResource::class;

    protected string $view = 'arcturus-modpack-manager::managed-server';

    #[\Livewire\Attributes\Url]
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

    #[Locked]
    public ?array $fileDiff = null;

    #[Locked]
    public ?string $evidenceState = null;

    private function evidenceStateFor($record): string
    {
        return hash('sha256', json_encode([$record->policy_revision, $record->comparison_status, $record->active_operation_id, (string) $record->updated_at], JSON_THROW_ON_ERROR));
    }

    public function pollEvidence(): void
    {
        Access::requireAdmin();
        if ($this->tab === 'updates' && $this->refreshExpiredComparison()) { return; }
        $record = ManagedServer::query()->select(['id', 'policy_revision', 'comparison_status', 'active_operation_id', 'updated_at'])->findOrFail($this->getRecord()->id);
        $working = $record->active_operation_id && ! $record->operations()->whereKey($record->active_operation_id)->whereNotNull('completed_at')->exists();
        if ($working || $this->evidenceState === $this->evidenceStateFor($record)) { $this->skipRender(); }
    }

    /** Refresh expired readiness in the queue, once; never auto-retry failed comparisons. */
    private function refreshExpiredComparison(): bool
    {
        $record = ManagedServer::lightweight()->findOrFail($this->getRecord()->id);
        if ($record->active_operation_id || $record->comparison_status !== 'complete' ||
            ! $record->target_manifest_path || ! ($record->preview_summary['applicable'] ?? false) ||
            ! $record->last_preview_at || $record->last_preview_at->gt(now()->subMinutes(15))) { return false; }
        try {
            app(QueuePreparation::class)->comparison($record, true);
        } catch (\Throwable $error) {
            // Keep a queue/configuration failure visible without polling repeated jobs.
            report($error);
            return false;
        }
        return true;
    }

    public function managed()
    {
        Access::requireAdmin();

        $record = $this->getRecord();
        $record->setRawAttributes(ManagedServer::lightweight()->findOrFail($record->id)->getAttributes(), true);
        $this->evidenceState = $this->evidenceStateFor($record);
        $record->evidenceProjection = true;
        $record->unsetRelations();
        return $record;
    }

    public function selectTab(string $tab): void
    {
        Access::requireAdmin();
        abort_unless(in_array($tab, ['overview', 'updates', 'changes', 'mods', 'protection', 'backups', 'history'], true), 422);
        $this->fileDiff = null;
        $this->mergeReview = null;
        $this->tab = $tab;
        $this->changePage = 1;
        $this->classification = '';
        $this->pathSearch = '';
        $this->selectedPaths = [];
        if ($tab === 'updates') { $this->refreshExpiredComparison(); }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('handoffManualStart')->label('Hand over manual start')->color('warning')
                ->visible(fn () => \Arcturus\ModpackManager\Native\HandoffDiagnostics::available(ManagedServer::lightweight()->findOrFail($this->getRecord()->id)))
                ->schema([TextInput::make('reference')->label('Successful recovery reference UUID (optional)')->nullable()])
                ->requiresConfirmation()->modalDescription('Release only this plugin’s power/suspension overlay after exact restored/deployed file verification. Keep Minecraft stopped until handoff finishes. The checkpoint and locked backup remain active; this does not start or restore anything and does not declare recovery successful.')
                ->action(function (array $data): void {
                    Access::requireAdmin();
                    try {
                        $service = app(\Arcturus\ModpackManager\Native\ManualStart::class); $id = $this->getRecord()->active_operation_id;
                        $reference = ($data['reference'] ?? '') !== '' ? $data['reference'] : null;
                        $review = $service->handoff($id, (int) auth()->id(), false, null, $reference);
                        $result = $service->handoff($id, (int) auth()->id(), true, $review['proof'], $reference);
                        $this->queued($result['message']);
                    } catch (\Throwable $error) { report($error); Notification::make()->title('Manual start handoff not confirmed')->body($error instanceof \RuntimeException ? $error->getMessage() : 'Inspect the private Panel log. The checkpoint remains active.')->danger()->send(); }
                }),
            Action::make('verifyManualRunning')->label('Verify running server')->color('warning')
                ->visible(fn () => $this->getRecord()->transport === 'native' && $this->getRecord()->active_operation_id && $this->getRecord()->operations()->whereKey($this->getRecord()->active_operation_id)->where('phase', \Arcturus\ModpackManager\Native\ManualStart::PHASE)->exists())
                ->requiresConfirmation()->modalDescription('Verify a fresh successful boot of this running instance and approve the actual Startup, image and launcher/args used. Close only this operation after expected pack/runtime and file checks pass. No restart or restore is sent.')
                ->action(function (): void {
                    Access::requireAdmin();
                    try {
                        $service = app(\Arcturus\ModpackManager\Native\ManualStart::class); $id = $this->getRecord()->active_operation_id;
                        $review = $service->verify($id, (int) auth()->id());
                        $result = $service->verify($id, (int) auth()->id(), true, $review['proof']);
                        $this->queued($result['message']);
                    } catch (\Throwable $error) { report($error); Notification::make()->title('Startup remains unverified')->body($error instanceof \RuntimeException ? $error->getMessage() : 'Inspect the private Panel log. No restart or restore was sent.')->warning()->send(); }
                }),
            Action::make('verifyRunningOriginal')->label('Verify running original installation')->color('warning')
                ->visible(fn () => $this->getRecord()->transport === 'native' && $this->getRecord()->active_operation_id && $this->getRecord()->operations()->whereKey($this->getRecord()->active_operation_id)->where('kind', 'deploy')->where('phase', 'manual_intervention')->exists())
                ->requiresConfirmation()->modalDescription('Verify the exact original backup, configuration, scoped files and current startup evidence. No stop, start, restore or log rotation. Close only a proven original recovery and synchronize Wings.')
                ->action(function (): void {
                    Access::requireAdmin();
                    try {
                        $result = app(\Arcturus\ModpackManager\Native\RunningOriginal::class)->run($this->getRecord()->active_operation_id, true, (int)auth()->id());
                        $this->queued($result['message']);
                    } catch (\Throwable $error) { report($error); Notification::make()->title('Running original verification not completed')->body(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Inspect the private Panel log. If recovery is restored, retry only the configuration sync.')->danger()->send(); }
                }),
            Action::make('syncRunningOriginal')->label('Retry verified recovery sync')->color('warning')
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    if ($record->transport !== 'native' || $record->active_operation_id) { return false; }
                    $operation = $record->operations()->where('kind', 'deploy')->where('phase', 'restored')->latest()->first();
                    try { $context = $operation?->context ?? []; }
                    catch (\Illuminate\Contracts\Encryption\DecryptException) { return false; }
                    return ($context['running_original_sync_pending'] ?? false) === true;
                })
                ->action(function (): void {
                    Access::requireAdmin();
                    try {
                        $operation = $this->getRecord()->operations()->where('kind', 'deploy')->where('phase', 'restored')->latest()->firstOrFail();
                        $result = app(\Arcturus\ModpackManager\Native\RunningOriginal::class)->run($operation->id, true, (int)auth()->id(), true); $this->queued($result['message']); }
                    catch (\Throwable $error) { report($error); Notification::make()->title('Recovery sync not confirmed')->body(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Configuration sync remains pending. No power or restore was requested.')->danger()->send(); }
                }),
            Action::make('verifyRecovery')->label(fn () => $this->getRecord()->transport === 'native' ? 'Recover original installation' : 'Verify restored original snapshot')->button()->color('warning')
                ->visible(fn () => $this->getRecord()->active_operation_id && $this->getRecord()->operations()->whereKey($this->getRecord()->active_operation_id)->where('kind', 'deploy')->whereIn('phase', ['manual_intervention', \Arcturus\ModpackManager\Native\ManualStart::PHASE])->exists())
                ->requiresConfirmation()->modalDescription(fn () => $this->getRecord()->transport === 'native' ? 'Restore the exact recorded native backup, preserve excluded backup directories and the retained launcher link, restore original launch settings, and verify a fresh Minecraft boot. An unverified server stays stopped. The update is never reapplied.' : 'First restore the exact locked Pelican backup while Minecraft is stopped. This verifies the original scoped receipt, returns any preserved excluded folders, and tests the original startup. It never reapplies the update.')
                ->action(function (): void {
                    try {
                        if ($this->getRecord()->operations()->whereKey($this->getRecord()->active_operation_id)->where('phase', \Arcturus\ModpackManager\Native\ManualStart::PHASE)->exists()) {
                            app(\Arcturus\ModpackManager\Native\ManualStart::class)->requestRollback($this->getRecord()->active_operation_id, (int) auth()->id());
                        } else { app(\Arcturus\ModpackManager\Services\DeploymentApproval::class)->verifyRecovery($this->getRecord()); }
                        $this->queued('Original snapshot verification queued; recovery checkpoint stays locked until verification succeeds');
                    } catch (\Throwable $error) {
                        Notification::make()->title('Recovery verification was not queued')->body(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Check the current recovery checkpoint and database/queue connectivity.')->danger()->send();
                    }
                }),
            Action::make('filesystemBackend')->label('Filesystem backend')->button()->color('gray')->visible(fn () => $this->tab === 'overview')
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->fillForm(fn () => ['transport' => $this->getRecord()->transport ?? 'wings'])
                ->schema([Select::make('transport')->label('Update backend')->required()->options([
                    'native' => 'Native Pelican / Wings', 'wings' => 'Legacy remote worker', 'local' => 'Legacy same-host filesystem',
                ])->helperText('Native Pelican / Wings needs no host volume permissions or extra API token. Legacy backends retain their original setup requirements.')])
                ->modalDescription('Switching to Native Pelican / Wings keeps verified upstream baselines and protection policies. Prepare the target again before deployment.')
                ->action(function (array $data): void {
                    try {
                        app(FilesystemBackendSettings::class)->save($this->getRecord(), $data['transport']);
                        $this->nodeCapabilities = null;
                        $this->queued($data['transport'] === 'native' ? 'Native backend selected; prepare the target again' : 'Filesystem backend validated; prepare the installed baseline');
                    } catch (\Throwable $error) {
                        Notification::make()->title('Filesystem backend unavailable')->body($error->getMessage())->danger()->send();
                    }
                }),
            Action::make('deploymentAccess')->label('Legacy update access')->button()->color('gray')
                ->visible(fn () => $this->tab === 'updates' && $this->getRecord()->transport === 'local')
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->modalHeading('Legacy host permission setup')
                ->modalDescription('Native Pelican / Wings avoids this setup; switch backend in Overview. For the legacy local backend only, run this on the Wings host as an administrator. Only this UUID and reviewed managed directories receive update access; worlds and player data are excluded. No file hashes or ownership changes. PHP runs as www-data.')
                ->fillForm(function (): array {
                    $server = $this->getRecord()->pelicanServer;
                    $node = $server->node;
                    $approval = app(\Arcturus\ModpackManager\Local\LocalNode::class)->configuration();
                    $account = posix_getpwuid($approval['uid']);
                    if (! $account) { throw new \RuntimeException('Approved queue account does not exist on this host.'); }
                    $command = 'sudo install -o root -g root -m 0755 '.escapeshellarg(plugin_path('arcturus-modpack-manager', 'node-worker/setup-local-node.py')).' /usr/local/sbin/arcturus-local-node-setup'."\n".
                        'sudo /usr/bin/python3 /usr/local/sbin/arcturus-local-node-setup --panel '.escapeshellarg(base_path()).
                        ' --user '.escapeshellarg($account['name']).' --node '.(int) $node->id.' --volume-root '.escapeshellarg($node->daemon_base).
                        ' --staging-parent '.escapeshellarg($approval['staging_parent']).
                        ' --deployment-server '.escapeshellarg($server->uuid);
                    $additions = array_filter((new \Arcturus\ModpackManager\Services\DirectoryPolicy)->normalize($this->getRecord()->directory_decisions ?? []),
                        fn ($row) => $row['mode'] === 'manage' && ! in_array($row['path'], \Arcturus\ModpackManager\Security\ManagedPaths::ROOTS, true));
                    foreach ($additions as $row) { $command .= ' --additional-managed-directory '.escapeshellarg($row['path']); }
                    if ($additions) { $command .= ' --replace'; }
                    return ['commands' => $command];
                })
                ->schema([\Filament\Forms\Components\Textarea::make('commands')->label('Host command')->readOnly()->rows(7)])
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('testFilesystem')->label('Test filesystem access')->button()->color('gray')->visible(fn () => $this->tab === 'overview')
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
            Action::make('directoryDecisions')->label(fn () => $this->tab === 'updates' ? 'Review new directories' : 'Review upstream directories')->button()->color('gray')->modalSubmitActionLabel('Save and continue')
                ->visible(fn () => in_array($this->tab, ['overview', 'updates'], true) && (bool) $this->getRecord()->base_manifest_path && ($this->tab !== 'updates' || (bool) (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->pending($this->getRecord())))
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
                ->modalDescription('Verified upstream structure. Standard folders default to Manage. Unfamiliar folders require a decision. Keep local and Exclude prevent inventory reads and updates. Protected runtime paths cannot be managed. Saved decisions are reused for future releases. Saving automatically queues comparison using the prepared files.')
                ->fillForm(fn () => ['directories' => array_values(array_filter((new \Arcturus\ModpackManager\Services\DirectoryPolicy)->rows($this->getRecord()), fn ($row) => $this->tab !== 'updates' || $row['mode'] === 'review')), 'revision' => $this->getRecord()->policy_revision, 'request_id' => (string) \Illuminate\Support\Str::uuid()])
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
                    if ($this->tab === 'updates') {
                        $saved = array_column((new \Arcturus\ModpackManager\Services\DirectoryPolicy)->normalize($server->directory_decisions ?? []), null, 'path');
                        foreach ($decisions as $decision) { $saved[$decision['path']] = $decision; }
                        $decisions = array_values($saved);
                    }
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
                ->visible(fn () => $this->tab === 'overview' && $this->getRecord()->baseline_status === 'verified' && $this->getRecord()->comparison_status !== 'complete' && (bool) $this->getRecord()->base_manifest_path)
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
            Action::make('prepare')->button()->icon('heroicon-o-arrow-down-tray')->label('Prepare update')->modalDescription('Download and verify the selected server pack, then compare its changes with your installation. Preparation does not stop Minecraft or apply changes.')->visible(fn () => $this->tab === 'updates')->requiresConfirmation()
                ->disabled(fn () => ! $this->getRecord()->targetVersion() || (bool) $this->getRecord()->active_operation_id)
                ->action(function (): void {
                    Access::requireAdmin();
                    $this->queuePreparation(false);
                }),
            Action::make('preview')->button()->icon('heroicon-o-document-magnifying-glass')->label('Refresh comparison')->color('gray')->visible(fn () => $this->tab === 'changes' && (bool) $this->getRecord()->target_manifest_path)
                ->disabled(fn () => (bool) $this->getRecord()->active_operation_id)
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

    #[Renderless]
    public function resolve(string $path, string $mode, ?int $revision = null, ?string $requestId = null, ?int $position = null): void
    {
        Access::requireAdmin();
        try {
            if (! in_array($mode, ['protect', 'replace', 'review', 'disable'], true)) { throw new \RuntimeException('Choose a supported file decision.'); }
            UpdatePlanner::assertPath($path);
            $record = ManagedServer::lightweight()->findOrFail($this->getRecord()->id);
            $operation = app(ServerPolicyService::class)->decide($record, $path, $mode, $revision ?? $record->policy_revision, $requestId ?? (string) \Illuminate\Support\Str::uuid(), $position);
            $message = ($mode === 'protect' ? 'Keep local' : ($mode === 'replace' ? 'Use upstream' : 'Policy')).' saved for '.$path.'. Comparison queued; no live files changed.';
            if ($operation->error) { $message = 'Decision saved for '.$path.'. Comparison is awaiting queue dispatch; use Reconcile preparation or check the scheduler and queue worker.'; }
            $this->mergeReview = null; $this->fileDiff = null;
            $this->dispatch('file-decision-result', path: $path, success: true, message: $message, operation: $operation->id);
            Notification::make()->title('File decision saved')->body($message)->success()->send();
        } catch (\Throwable $error) {
            $message = get_class($error) === \RuntimeException::class || $error instanceof \InvalidArgumentException
                ? $error->getMessage() : 'Decision was not saved. Check database/queue connectivity and refresh File Changes before retrying.';
            $this->dispatch('file-decision-result', path: $path, success: false, message: $message);
            Notification::make()->title('File decision was not saved')->body($message)->danger()->send();
        }
    }

    private function recompare(): void
    {
        $this->mergeReview = null;
        $this->fileDiff = null;
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

    public function updatedPathSearch(): void { $this->changePage = 1; }

    public function updatedClassification(): void { $this->changePage = 1; }

    public function reviewFileDiff(string $path): void
    {
        Access::requireAdmin();
        $this->fileDiff = null;
        try {
            $record = $this->getRecord()->refresh();
            abort_if((bool) $record->active_operation_id, 409);
            $this->fileDiff = app(KubeJsDiff::class)->review($record, $path);
        } catch (\Throwable) {
            Notification::make()->title('Text diff unavailable')->body('Use Keep local or Use upstream after reviewing the file. Retained staging, readable UTF-8 text up to 64 KiB, and an unchanged comparison snapshot are required.')->warning()->send();
        }
    }

    public function reviewMerge(string $path): void
    {
        Access::requireAdmin();
        $record = $this->getRecord()->refresh();
        abort_unless(isset((new FilePolicyCatalog)->selected($record, [$path])[$path]), 422);
        abort_if(str_starts_with($path, 'kubejs/'), 422);
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

    public function applyUpdateAction(): Action
    {
        return Action::make('applyUpdate')->label('Update with backup')->button()->color('danger')
            ->visible(fn () => $this->tab === 'updates')->disabled(fn () => ! $this->canApplyUpdate())
            ->fillForm(fn () => ['digest' => $this->getRecord()->preview_summary['plan_sha256'] ?? '', 'request_id' => (string) \Illuminate\Support\Str::uuid()])
            ->schema([Hidden::make('digest'), Hidden::make('request_id'),
                Checkbox::make('manual_start')->label('Po nasazení spustím server ručně')->default(false)->visible(fn () => $this->getRecord()->transport === 'native'),
                Checkbox::make('confirm')->label('I reviewed this exact file and runtime plan, including any startup/image changes, and approve stopping Minecraft, creating a locked Pelican backup, applying the staged update and verifying startup, or retaining an active checkpoint until I verify my manual start when selected. A failed update may require restoring the full offline backup.')->required()->accepted(),
            ])->modalHeading('Approve this exact manual update')
            ->modalDescription(fn () => ($this->getRecord()->preview_summary['from'] ?? '').' → '.($this->getRecord()->preview_summary['to'] ?? '').'. Plan: '.($this->getRecord()->preview_summary['plan_sha256'] ?? 'unavailable'))
            ->modalSubmitActionLabel('Approve and queue update')
            ->action(function (array $data): void {
                Access::requireAdmin();
                try {
                    $operation = app(\Arcturus\ModpackManager\Services\DeploymentApproval::class)->queue($this->getRecord(), $data['digest'], $data['request_id'], (bool) ($data['manual_start'] ?? false));
                    $this->queued('Manual update approved; deployment operation '.$operation->id);
                } catch (\Throwable $error) {
                    Notification::make()->title('Update was not queued')->body(get_class($error) === \RuntimeException::class ? $error->getMessage() : 'Deployment approval failed. Verify database/queue connectivity and compare the current plan again.')->danger()->send();
                }
            });
    }

    public function canChooseCrucibleRuntime(): bool
    {
        $record = $this->getRecord();

        return Access::admin() && $this->tab === 'updates' && $record->transport === 'native'
            && $record->provider === 'gtnh' && $record->source === 'gtnh'
            && $record->baseline_status === 'verified' && $record->target_manifest_path !== null
            && ! $record->active_operation_id;
    }

    public function crucibleRuntimeAction(): Action
    {
        $label = fn () => $this->getRecord()->keep_existing_crucible_runtime
            ? 'Clear Crucible runtime selection' : 'Keep existing Crucible runtime';

        return Action::make('crucibleRuntime')->label($label)->color('gray')
            ->visible(fn () => $this->canChooseCrucibleRuntime())
            ->requiresConfirmation()->modalHeading($label)
            ->modalDescription('Refreshes comparison with the existing staged GTNH pack and keeps your file decisions. It does not stop Minecraft or start the update.')
            ->fillForm(fn () => ['revision' => $this->getRecord()->policy_revision, 'keep' => ! $this->getRecord()->keep_existing_crucible_runtime])
            ->schema([Hidden::make('revision'), Hidden::make('keep')])
            ->action(function (array $data): void {
                Access::requireAdmin();
                if (! $this->canChooseCrucibleRuntime()) { return; }
                $record = $this->getRecord();
                try {
                    app(ServerPolicyService::class)->save($record, $record->file_rules ?? [], $record->disabled_mods ?? [],
                        (int) $data['revision'], ['keep_existing_crucible_runtime' => (bool) $data['keep']]);
                    $this->recompare();
                } catch (\Throwable $error) {
                    Notification::make()->title('Runtime choice was not saved')->body(get_class($error) === \RuntimeException::class
                        ? $error->getMessage() : 'Reload this server before changing the runtime selection.')->danger()->send();
                }
            });
    }

    public function canApplyUpdate(): bool
    {
        if (! Access::admin()) { return false; }
        $record = $this->getRecord();
        if ($record->active_operation_id) { return false; }
        try { app(\Arcturus\ModpackManager\Services\DeploymentGate::class)->requireReady($record); return true; }
        catch (\Throwable) { return false; }
    }

    public function changeRows(): array
    {
        return \Arcturus\ModpackManager\Services\EvidenceView::changePage($this->getRecord()->id, $this->changePage, $this->pathSearch, $this->classification);
    }
}
