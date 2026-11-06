<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers;

use App\Models\Server;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ListManagedServers;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Filament\Forms\DiscordFields;
use Arcturus\ModpackManager\Filament\Forms\PolicyFields;
use Arcturus\ModpackManager\Jobs\CheckRelease;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\QueuePreparation;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

final class ManagedServerResource extends Resource
{
    protected static ?string $model = ManagedServer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'arcturus-managed-modpacks';

    public static function getNavigationGroup(): ?string
    {
        return 'Arcturus Modpack Manager';
    }

    public static function getNavigationLabel(): string
    {
        return 'Managed servers';
    }

    public static function getModelLabel(): string
    {
        return 'managed server';
    }

    public static function getPluralModelLabel(): string
    {
        return 'managed servers';
    }

    public static function canViewAny(): bool
    {
        return Access::admin();
    }

    public static function canView($record): bool
    {
        return Access::admin();
    }

    public static function canCreate(): bool
    {
        return Access::admin();
    }

    public static function canEdit($record): bool
    {
        return Access::admin() && ! $record->active_operation_id;
    }

    public static function canDelete($record): bool
    {
        return Access::admin() && ! $record->active_operation_id && ! $record->operations()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Server & release source')
                ->description('Edit the existing server and release association. Changing server, source, version or ZIP pattern requires a new verified baseline; live files and saved protection rules are preserved.')
                ->columns(2)->schema([
                    TextInput::make('label')->label('Display name')->required()->maxLength(120),
                    Select::make('pelican_server_uuid')->label('Existing Pelican server')
                        ->required()->searchable()->native(false)
                        ->options(fn (): array => Server::query()
                            ->orderBy('name')->get(['uuid', 'name'])
                            ->mapWithKeys(fn ($server) => [
                                $server->uuid => $server->name.' · '.$server->uuid,
                            ])->all())
                        ->helperText('This binds an existing server. It does not create or reinstall it.')
                        ->unique(ignoreRecord: true)->exists('servers', 'uuid'),
                    Select::make('provider')->label('Provider')->required()
                        ->options([
                            'ftb' => 'FTB · Feed The Beast',
                            'github' => 'GitHub Releases · GTNH / TFG / custom',
                            'modrinth' => 'Modrinth',
                            'curseforge' => 'CurseForge (API key required)',
                            'atlauncher' => 'ATLauncher',
                            'custom' => 'Custom verified recipe',
                        ])->default('github')->native(false),
                    TextInput::make('source')->label('Pack ID / owner/repo')
                        ->helperText('FTB/CurseForge: numeric ID · GitHub: owner/repo · Modrinth: slug · Custom: verified recipe HTTPS URL')
                        ->maxLength(255)->required(),
                    TextInput::make('asset_pattern')->label('Server ZIP filename pattern')
                        ->helperText('GitHub only. Example: *Server*Java17*.zip — one matching file is required.')
                        ->maxLength(160),
                    TextInput::make('installed_version')->label('Installed upstream version')
                        ->helperText('Set the version you verified is currently installed.')
                        ->maxLength(120)->required(),
                ]),
            Section::make('Filesystem backend')->visible(fn ($record) => $record !== null)->schema([
                Select::make('transport')->label('Preparation / inventory backend')->required()->native(false)
                    ->options(['wings' => 'Remote node worker', 'local' => 'Same-host local filesystem'])
                    ->helperText('Same-host access uses the approved node setup and trusted server UUID. Backend changes require a new baseline; paths and permissions cannot be granted from this form.'),
            ]),
            Section::make('Update policy')
                ->description('Deployment remains disabled pending transaction and restore qualification, including for same-host installations.')
                ->columns(2)->schema([
                    Select::make('policy')->required()->default('notify')->native(false)
                        ->options(['notify' => 'Notify only', 'approve' => 'Require admin approval',
                            'auto' => 'Legacy automatic policy (deployment remains gated)']),
                    Toggle::make('enabled')->label('Monitor this server')->default(true),
                    Toggle::make('deploy_enabled')->label('Allow deployment for this server')->default(false)->disabled()
                        ->helperText('Deployment is gated while remote transactions and restore behavior are not qualified.'),
                ]),
            DiscordFields::section(),
            Section::make('Backups')->columns(1)->schema([
                Select::make('backup_mode')->label('Backup backend')->required()->native(false)
                    ->default('pelican')
                    ->options([
                        'pelican' => 'Pelican Backups (recommended)',
                        'filesystem' => 'Legacy filesystem snapshots',
                    ])->helperText('Pelican uses the existing Wings/S3 backup host and locks the pre-update backup.'),
                TagsInput::make('backup_excluded_directories')
                    ->label('Exclude folders from Pelican backup')
                    ->placeholder('Add folder, press Enter')
                    ->helperText('Top-level folder names only, e.g. backup and backups. No globs or paths. Excluded folders are preserved separately during full restore.')
                    ->default(['backup', 'backups'])
                    ->afterStateHydrated(function (TagsInput $component, $state): void {
                        if ($state === null) {
                            $component->state(['backup', 'backups']);
                        }
                    })
                    ->rules(['array', 'max:16'])
                    ->columnSpanFull(),
            ]),
            Section::make('File protection')
                ->description('Rules are scoped to this server. World data and path safety always take precedence.')
                ->schema([
                    ...PolicyFields::rules(),
                    Repeater::make('mod_identity_mappings')->label('Administrator identity mappings')->default([])->columns(3)->schema([
                        Select::make('scope')->options(['base' => 'Installed upstream', 'target' => 'Target upstream', 'local' => 'Installed local file'])->required(),
                        TextInput::make('path')->required()->maxLength(1024),
                        TextInput::make('identity')->required()->maxLength(128),
                    ])->helperText('Map renamed or metadata-less JARs to the same stable mod identity. Mappings are checked against the actual file inventories.'),

                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('latestOperationEvent'))->columns([
            TextColumn::make('label')->label('Server')->searchable()->sortable()
                ->description(fn (ManagedServer $record) => strtoupper($record->provider).' · '.$record->source),
            TextColumn::make('runtime_report.state')->label('Last observed state')->badge()->placeholder('Unknown'),
            TextColumn::make('baseline_status')->label('Upstream baseline')->badge()->color(fn ($state) => $state === 'verified' ? 'success' : 'warning'),
            TextColumn::make('comparison_status')->label('Installation comparison')->badge()->formatStateUsing(fn (ManagedServer $record) => $record->comparisonLabel())
                ->color(fn ($state) => match ($state) { 'complete' => 'success', 'failed' => 'danger', default => 'warning' }),
            TextColumn::make('installed_version')->label('Installed')->badge()->color('gray'),
            TextColumn::make('available_version')->label('Latest')
                ->placeholder('Not checked')->badge()
                ->color(fn (ManagedServer $record) => $record->hasUpdate() ? 'warning' : 'success'),
            TextColumn::make('operation_status')->label('Operation')->badge()->color(fn ($state) => in_array($state, ['Failed', 'Comparison failed'], true) ? 'danger' : 'gray'),
            TextColumn::make('failure_summary')->label('Blocked / failed')->wrap()->limit(100)->placeholder('—'),
            TextColumn::make('backup_mode')->label('Backup')->badge()->color('gray'),
            TextColumn::make('last_backup_uuid')->label('Latest backup UUID')->placeholder('—')->copyable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('policy')->badge()->color(fn ($state) => match ($state) {
                'auto' => 'warning', 'approve' => 'info', default => 'gray',
            }),
            IconColumn::make('enabled')->boolean(),
            TextColumn::make('last_checked_at')->label('Checked')->since()->placeholder('Never'),
        ])->filters([
            SelectFilter::make('provider')->options(['github' => 'GitHub', 'ftb' => 'FTB', 'modrinth' => 'Modrinth',
                'curseforge' => 'CurseForge', 'atlauncher' => 'ATLauncher', 'custom' => 'Custom']),
            SelectFilter::make('baseline_status')->options(['verified' => 'Upstream verified', 'unverified' => 'Upstream unverified']),
            TernaryFilter::make('enabled'),
        ])->toolbarActions([
            BulkAction::make('checkSelected')->label('Check selected releases')->action(function (Collection $records): void {
                Access::requireAdmin();
                foreach ($records as $record) {
                    if ($record->enabled) {
                        CheckRelease::dispatch($record->id);
                    }
                }
                Notification::make()->title('Release checks queued')->success()->send();
            })->deselectRecordsAfterCompletion(),
        ])->defaultSort('label')->recordUrl(fn (ManagedServer $record) => self::getUrl('view', ['record' => $record]))->recordActions([
            Action::make('details')->icon('heroicon-o-eye')->label('Details')->url(fn (ManagedServer $record) => self::getUrl('view', ['record' => $record])),
            Action::make('checkNow')->label('Check now')->icon('heroicon-o-arrow-path')
                ->action(function (ManagedServer $record): void {
                    Access::requireAdmin();
                    CheckRelease::dispatch($record->id);
                    Notification::make()->title('Update check queued')->success()->send();
                }),
            Action::make('preview')->label('Preview changes')->icon('heroicon-o-document-magnifying-glass')
                ->action(function (ManagedServer $record): void {
                    Access::requireAdmin();
                    try {
                        $result = app(PreviewService::class)->generate($record);
                        Notification::make()->title('Dry-run complete')
                            ->body($result['blocked'].' unresolved changes · '.$result['total'].' files compared')
                            ->color($result['blocked'] ? 'warning' : 'success')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Preview unavailable')
                            ->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('prepare')->label('Prepare release')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (ManagedServer $record) => $record->hasUpdate())
                ->requiresConfirmation()
                ->modalDescription('Download the new server pack into staging. This does not restart the server.')
                ->action(function (ManagedServer $record): void {
                    Access::requireAdmin();
                    try {
                        app(QueuePreparation::class)->queue($record);
                        Notification::make()->title('Preparation queued')->success()->send();
                    } catch (\Throwable $error) {
                        Notification::make()->title('Preparation unavailable')->body($error->getMessage())->danger()->send();
                    }
                }),
            EditAction::make(),
            DeleteAction::make()->requiresConfirmation(),
        ])->emptyStateHeading('No modpacks connected')
            ->emptyStateDescription('Add an existing Pelican server to begin checking releases.')
            ->emptyStateIcon('heroicon-o-server-stack');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManagedServers::route('/'),
            'create' => CreateManagedServer::route('/create'),
            'view' => ViewManagedServer::route('/{record}'),
            'edit' => EditManagedServer::route('/{record}/edit'),
        ];
    }
}
