<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\ConsoleIntegration;

use App\Models\Server;
use App\Enums\SubuserPermission;
use Arcturus\ModpackManager\Security\Access;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

final class ConsoleOutput extends Widget
{
    protected string $view = 'arcturus-modpack-manager::console-output';
    protected int|string|array $columnSpan = 'full';
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $server = Filament::getTenant();
        return $server instanceof Server && Access::view($server) && auth()->user()->can(SubuserPermission::WebsocketConnect, $server)
            && ConsoleIntegration::managed($server) !== null;
    }
}
