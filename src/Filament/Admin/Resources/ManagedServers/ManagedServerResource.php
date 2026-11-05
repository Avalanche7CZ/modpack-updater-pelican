<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ListManagedServers;
use Arcturus\ModpackManager\Jobs\CheckRelease;
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
        return auth()->check() && (bool) (auth()->user()->root_admin ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Server & release source')
                ->description('Bind an existing Pelican server. Checking releases never restarts it.')
                ->columns(2)->schema([
                    TextInput::make('label')->label('Display name')->required()->maxLength(120),
                    TextInput::make('pelican_server_uuid')->label('Pelican server UUID')
                        ->helperText('Use the existing server UUID; this does not create a new server.')
                        ->required()->regex('/^[A-Za-z0-9-]{8,64}$/D')->unique(ignoreRecord: true),
                    Select::make('provider')->label('Provider')->required()
                        ->options([
                            'ftb' => 'FTB · Feed The Beast',
                            'github' => 'GitHub Releases · GTNH / TFG / custom',
                            'modrinth' => 'Modrinth',
                            'custom' => 'Custom manifest (reserved)',
                        ])->default('github')->native(false),
                    TextInput::make('source')->label('Pack ID / owner/repo')
                        ->helperText('FTB: numeric pack ID · GitHub: owner/repo · Modrinth: project slug')
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
