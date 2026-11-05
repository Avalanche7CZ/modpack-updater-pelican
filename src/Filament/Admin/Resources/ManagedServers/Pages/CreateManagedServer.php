<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateManagedServer extends CreateRecord
{
    protected static string $resource = ManagedServerResource::class;
}
