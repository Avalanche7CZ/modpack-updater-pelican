<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services\Pelican;

use App\Repositories\Daemon\DaemonServerRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Native\Qualification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Prevent native Panel power actions from racing a durable deployment checkpoint. */
final class GuardedDaemonServerRepository extends DaemonServerRepository
{
    private static array $permitted = [];

    public static function controlledStart(string $uuid, \Closure $action): mixed
    {
        if (isset(self::$permitted[$uuid])) { throw new RuntimeException('Nested deployment start is not allowed.'); }
        self::$permitted[$uuid] = true;
        try { return $action(); } finally { unset(self::$permitted[$uuid]); }
    }

    public function power(string $action): Response
    {
        if (in_array($action, ['start', 'restart'], true) && ! isset(self::$permitted[$this->server->uuid]) && Schema::hasTable('arcturus_modpack_servers')) {
            $managed = ManagedServer::lightweight()->where('pelican_server_uuid', $this->server->uuid)->first();
            $operation = $managed?->active_operation_id ? $managed->operations()->find($managed->active_operation_id) : null;
            if ($operation && $operation->kind === 'deploy' && ($operation->context['protocol'] ?? '') === Qualification::PROTOCOL) {
                throw new RuntimeException('Modpack deployment or recovery owns this server. Inspect its operation before starting Minecraft.');
            }
        }
        return parent::power($action);
    }
}
