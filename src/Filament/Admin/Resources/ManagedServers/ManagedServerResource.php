<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ListManagedServers;
use Arcturus\ModpackManager\Jobs\CheckRelease;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Models\ManagedServer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ManagedServerResource extends Resource
{
    protected static ?string $model = ManagedServer::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';
    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string { return 'Modpack Manager'; }
    public static function getNavigationLabel(): string { return 'Managed servers'; }
    public static function getModelLabel(): string { return 'managed server'; }
    public static function getPluralModelLabel(): string { return 'managed servers'; }

    public static function canViewAny(): bool
    {
        // Plugin is admin-panel only. No client/subuser permissions are inherited.
        return auth()->check() && (bool) auth()->user()->isRootAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Server & release source')
                ->description('Bind an existing Pelican server. Checking releases never restarts it.')
                ->columns(2)->schema([
                    TextInput::make('label')->label('Display name')->required()->maxLength(120),
                    Select::make('pelican_server_uuid')->label('Existing Pelican server')
                        ->required()->searchable()->native(false)
                        ->options(fn (): array => \App\Models\Server::query()
                            ->orderBy('name')->get(['uuid', 'name'])
                            ->mapWithKeys(fn ($server) => [
                                $server->uuid => $server->name . ' · ' . $server->uuid,
                            ])->all())
                        ->helperText('This binds an existing server. It does not create or reinstall it.')
                        ->unique(ignoreRecord: true),
                    Select::make('provider')->label('Provider')->required()
                        ->options([
                            'ftb' => 'FTB · Feed The Beast',
                            'github' => 'GitHub Releases · GTNH / TFG / custom',
                            'modrinth' => 'Modrinth',
                            'curseforge' => 'CurseForge (API key required)',
                            'custom' => 'Custom local manifest',
                        ])->default('github')->native(false),
                    TextInput::make('source')->label('Pack ID / owner/repo')
                        ->helperText('FTB/CurseForge: numeric ID · GitHub: owner/repo · Modrinth: slug · Custom: absolute manifest path')
                        ->maxLength(255)->required(),
                    TextInput::make('asset_pattern')->label('Server ZIP filename pattern')
                        ->helperText('GitHub only. Example: *Server*Java17*.zip — one matching file is required.')
                        ->maxLength(160),
                    TextInput::make('installed_version')->label('Installed upstream version')
                        ->helperText('Set the version you verified is currently installed.')
                        ->maxLength(120)->required(),
                ]),
            Section::make('Update policy')
                ->description('Automated deployment is disabled until a verified node worker is configured.')
                ->columns(2)->schema([
                    Select::make('policy')->required()->default('notify')->native(false)
                        ->options(['notify' => 'Notify only', 'approve' => 'Require admin approval',
                            'auto' => 'Auto (requires verified worker)']),
                    Toggle::make('enabled')->label('Monitor this server')->default(true),
                ]),
            Section::make('Local preview (advanced)')
                ->description('Optional single-host setup. The panel must have read-only access to the Wings volume. Leave blank for remote nodes.')
                ->collapsible()->collapsed()->columns(1)->schema([
                    TextInput::make('local_root')->label('Live server directory')->maxLength(2048)
                        ->placeholder('/var/lib/pelican/volumes/server-uuid')
                        ->helperText('Must be within the operator-configured MODPACK_MANAGER_ALLOWED_LIVE_ROOTS.'),
                    TextInput::make('base_manifest_path')->label('Verified installed-release manifest')->maxLength(2048),
                    TextInput::make('target_manifest_path')->label('Verified target-release manifest')->maxLength(2048),
                ]),
            Section::make('File protection')
                ->description('Rules apply in order. The last matching rule wins. The planner always protects world/runtime data.')
                ->schema([
                    Repeater::make('file_rules')->label('Rules')->default([])
                        ->columns(2)->schema([
                            TextInput::make('pattern')->label('Path / glob')
                                ->placeholder('config/Arcturus/**')->required()->maxLength(1024),
                            Select::make('mode')->label('Strategy')->required()->native(false)
                                ->options([
                                    'protect' => 'Keep local file',
                                    'merge' => 'Needs manual merge',
                                    'disable' => 'Keep matching mod disabled',
                                    'replace' => 'Explicitly replace local changes',
                                ]),
                        ])->addActionLabel('Add file rule')->reorderable()->collapsible(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('label')->label('Server')->searchable()->sortable()
                ->description(fn (ManagedServer $record) => strtoupper($record->provider) . ' · ' . $record->source),
            TextColumn::make('installed_version')->label('Installed')->badge()->color('gray'),
            TextColumn::make('available_version')->label('Latest')
                ->placeholder('Not checked')->badge()
                ->color(fn (ManagedServer $record) => $record->hasUpdate() ? 'warning' : 'success'),
            TextColumn::make('policy')->badge()->color(fn ($state) => match ($state) {
                'auto' => 'warning', 'approve' => 'info', default => 'gray',
            }),
            IconColumn::make('enabled')->boolean(),
            TextColumn::make('last_checked_at')->label('Checked')->since()->placeholder('Never'),
        ])->defaultSort('label')->recordActions([
            Action::make('checkNow')->label('Check now')->icon('heroicon-o-arrow-path')
                ->action(function (ManagedServer $record): void {
                    CheckRelease::dispatch($record->id);
                    Notification::make()->title('Update check queued')->success()->send();
                }),
            Action::make('preview')->label('Preview changes')->icon('heroicon-o-document-magnifying-glass')
                ->action(function (ManagedServer $record): void {
                    try {
                        $result = app(PreviewService::class)->generate($record);
                        Notification::make()->title('Dry-run complete')
                            ->body($result['blocked'] . ' unresolved changes · ' . $result['total'] . ' files compared')
                            ->color($result['blocked'] ? 'warning' : 'success')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Preview unavailable')
                            ->body($e->getMessage())->danger()->send();
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
            'edit' => EditManagedServer::route('/{record}/edit'),
        ];
    }
}
