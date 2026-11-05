<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListManagedServers extends ListRecords
{
    protected static string $resource = ManagedServerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Adopt Existing Server')];
    }
}
