<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\ConsoleIntegration;

use App\Enums\HeaderActionPosition;
use App\Filament\Server\Pages\Console;
use App\Models\Server;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\ManagedServerResource;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;

final class ConsoleIntegration
{
    public static function managed(?Server $server): ?ManagedServer
    {
        return $server ? ManagedServer::lightweight()->where('pelican_server_uuid', $server->uuid)->first() : null;
    }

    public static function canUpdate(?Server $server): bool
    {
        return Access::admin() && Access::view($server) && self::managed($server) !== null;
    }

    public static function updateUrl(Server $server): string
    {
        abort_unless(self::canUpdate($server), 403);

        return ManagedServerResource::getUrl('view', ['record' => self::managed($server), 'tab' => 'updates'], panel: 'admin');
    }

    public static function action(): Action
    {
        return Action::make('arcturusUpdate')->label('Update')->color('gray')
            ->visible(fn () => self::canUpdate(Filament::getTenant()))
            ->authorize(fn () => self::canUpdate(Filament::getTenant()))
            ->action(function ($livewire): void {
                // Resolve the current tenant again on the backend. Navigation creates no operation.
                $livewire->redirect(self::updateUrl(Filament::getTenant()));
            });
    }

    public static function register(Panel $panel): void
    {
        Console::registerCustomHeaderActions(HeaderActionPosition::After, self::action());
        Console::registerCustomWidgets(\App\Enums\ConsoleWidgetPosition::AboveConsole, [ConsoleOutput::class]);
    }
}
