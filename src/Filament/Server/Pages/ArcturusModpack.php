<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Filament\Server\Pages;

use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\Access;
use Filament\Facades\Filament;
use Filament\Pages\Page;

final class ArcturusModpack extends Page
{
    protected static ?string $slug = 'arcturus-modpack';

    protected static ?string $navigationLabel = 'Arcturus Modpack';

    protected static ?string $title = 'Arcturus Modpack';

    protected static ?int $navigationSort = 55;

    protected string $view = 'arcturus-modpack-manager::server-overview';

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return $server instanceof Server && Access::view($server);
    }

    public function state(): ?array
    {
        abort_unless(self::canAccess(), 403);
        $server = Filament::getTenant();
        $record = ManagedServer::where('pelican_server_uuid', $server->uuid)->first();
        if (! $record) {
            return null;
        }

        // No secrets, host paths, worker identifiers, provider recipes or administrator mutations.
        return ['name' => $record->label, 'provider' => $record->provider, 'installed' => $record->installed_version,
            'available' => $record->available_version, 'baseline' => $record->baseline_status,
            'checked' => $record->last_checked_at?->diffForHumans(), 'policy' => $record->policy,
            'operation' => $record->operations()->latest()->value('phase'),
            'blocked' => $record->preview_summary['blocked'] ?? null,
            'adopted' => $record->adopted_at !== null];
    }
}
