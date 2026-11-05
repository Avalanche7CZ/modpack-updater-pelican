<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditManagedServer extends EditRecord
{
    protected static string $resource = ManagedServerResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
