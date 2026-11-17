<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Filament\Forms\ReleaseSourceFields;
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

    public function fleetSummary(): array
    {
        Access::requireAdmin();

        return (array) ManagedServer::query()->toBase()->selectRaw('COUNT(*) AS servers,
            COUNT(CASE WHEN available_version IS NOT NULL AND available_version <> installed_version THEN 1 END) AS updates,
            COUNT(active_operation_id) AS active')->first();
    }

    public function table(Table $table): Table
    {
        return $table->query(ManagedServer::query()->lightweight()->with(['pelicanServer', 'latestOperationEvent']))->columns([
            TextColumn::make('label')->label('Server')->searchable()->sortable(),
            TextColumn::make('pelicanServer.name')->label('Pelican server')->searchable()->placeholder('Missing server')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('runtime_report.state')->label('Last observed state')->badge()->placeholder('Unknown')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('provider')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('installed_version')->label('Installed')->wrap(),
            TextColumn::make('available_version')->label('Latest')->wrap()->placeholder('Not checked')
                ->color(fn ($record) => $record->hasUpdate() ? 'warning' : 'gray'),
            TextColumn::make('baseline_status')->label('Upstream baseline')->badge()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('comparison_status')->label('Installation comparison')->badge()->formatStateUsing(fn (ManagedServer $record) => $record->comparisonLabel())
                ->color(fn ($state) => match ($state) { 'complete' => 'success', 'failed' => 'danger', default => 'warning' })->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('operation_status')->label('Activity')->badge()->color(fn ($state) => match ($state) {
                'Failed', 'Comparison failed' => 'danger', 'Waiting for recovery', 'Waiting for manual boot' => 'warning',
                'In progress' => 'primary', 'Completed' => 'success', default => 'gray',
            }),
            TextColumn::make('policy')->badge(),
            TextColumn::make('backup_mode')->label('Backup')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('last_checked_at')->label('Last successful check')->since()->placeholder('Never')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('failure_summary')->label('Failure / blocked')->limit(80)->wrap()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([SelectFilter::make('provider')->options(ReleaseSourceFields::PROVIDERS)])
            ->recordUrl(fn ($record) => ManagedServerResource::getUrl('view', ['record' => $record]))
            ->defaultSort('label');
    }
}
