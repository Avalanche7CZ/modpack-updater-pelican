<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\UpdateEvents;

use Arcturus\ModpackManager\Filament\Admin\Resources\UpdateEvents\Pages\ListUpdateEvents;
use Arcturus\ModpackManager\Models\UpdateEvent;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class UpdateEventResource extends Resource
{
    protected static ?string $model = UpdateEvent::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string { return 'Modpack Manager'; }
    public static function getNavigationLabel(): string { return 'Update history'; }
    public static function getModelLabel(): string { return 'update event'; }
    public static function getPluralModelLabel(): string { return 'update history'; }

    public static function canViewAny(): bool
    {
        return auth()->check() && (bool) auth()->user()->isRootAdmin();
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
            TextColumn::make('managedServer.label')->label('Server')->searchable()->sortable(),
            TextColumn::make('kind')->badge()->sortable(),
            TextColumn::make('status')->badge()
                ->color(fn (string $state) => match ($state) {
                    'success' => 'success', 'failed' => 'danger',
                    'warning' => 'warning', default => 'gray',
                }),
            TextColumn::make('from_version')->label('From')->placeholder('—'),
            TextColumn::make('to_version')->label('To')->placeholder('—'),
            TextColumn::make('message')->label('Details')->wrap()->limit(160),
        ])->filters([
            SelectFilter::make('status')->options([
                'success' => 'Success', 'failed' => 'Failed',
                'warning' => 'Warning', 'info' => 'Info',
            ]),
            SelectFilter::make('kind')->options([
                'release' => 'Release', 'check' => 'Check',
                'backup' => 'Backup', 'preview' => 'Preview', 'prepared' => 'Prepared',
                'prepare' => 'Preparation failed', 'deploy' => 'Deployment',
            ]),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListUpdateEvents::route('/')];
    }
}
