<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class Overview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Arcturus Modpack Manager';

    protected static ?string $slug = 'arcturus-modpack-manager';

    protected string $view = 'arcturus-modpack-manager::overview';

    public static function getNavigationGroup(): ?string
    {
        return 'Arcturus Modpack Manager';
    }

    public static function getNavigationLabel(): string
    {
        return 'Overview';
    }

    public static function canAccess(): bool
    {
        return Access::admin();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('adopt')->button()->icon('heroicon-o-plus')->label('Adopt Existing Server')->url(ManagedServerResource::getUrl('create'))];
    }

    public function table(Table $table): Table
    {
        return $table->query(ManagedServer::query()->with('pelicanServer'))->columns([
            TextColumn::make('label')->label('Server')->searchable()->sortable(),
            TextColumn::make('pelicanServer.name')->label('Pelican server')->searchable()->placeholder('Missing server'),
            TextColumn::make('runtime_report.state')->label('Last observed state')->badge()->placeholder('Unknown'),
            TextColumn::make('provider')->badge(),
            TextColumn::make('installed_version')->label('Installed')->wrap(),
            TextColumn::make('available_version')->label('Latest')->wrap()->placeholder('Not checked')
                ->color(fn ($record) => $record->hasUpdate() ? 'warning' : 'gray'),
            TextColumn::make('baseline_status')->label('Baseline')->badge(),
            TextColumn::make('policy')->badge(),
            TextColumn::make('backup_mode')->label('Backup'),
            TextColumn::make('last_checked_at')->label('Last successful check')->since()->placeholder('Never'),
            TextColumn::make('last_error')->label('Failure / blocked')->limit(80)->wrap()->placeholder('—'),
        ])->filters([SelectFilter::make('provider')->options(['ftb' => 'FTB', 'github' => 'GitHub',
            'modrinth' => 'Modrinth', 'curseforge' => 'CurseForge', 'atlauncher' => 'ATLauncher', 'custom' => 'Custom'])])
            ->recordUrl(fn ($record) => ManagedServerResource::getUrl('view', ['record' => $record]))
            ->defaultSort('label')->poll('15s');
    }
}
