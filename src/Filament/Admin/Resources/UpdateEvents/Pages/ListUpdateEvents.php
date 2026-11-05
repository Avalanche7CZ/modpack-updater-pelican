<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Admin\Resources\UpdateEvents\Pages;

use Arcturus\ModpackManager\Filament\Admin\Resources\UpdateEvents\UpdateEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListUpdateEvents extends ListRecords
{
    protected static string $resource = UpdateEventResource::class;
}
