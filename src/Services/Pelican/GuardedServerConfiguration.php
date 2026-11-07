<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services\Pelican;

use App\Models\Server;
use App\Services\Servers\ServerConfigurationStructureService;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Illuminate\Support\Facades\Schema;

/** Standard Wings config fields, scoped to one durable administrator-approved deployment. */
final class GuardedServerConfiguration extends ServerConfigurationStructureService
{
    protected function returnFormat(Server $server): array
    {
        $configuration = parent::returnFormat($server);
        if (! Schema::hasTable('arcturus_modpack_servers')) { return $configuration; }
        $managed = ManagedServer::where('pelican_server_uuid', $server->uuid)->first(['id', 'active_operation_id']);
        $operation = $managed?->active_operation_id ? $managed->operations()->find($managed->active_operation_id) : null;
        if (! $operation || $operation->kind !== 'deploy' || ($operation->context['manual_approval'] ?? false) !== true ||
            ($operation->context['protocol'] ?? '') !== SameHostDeployment::PROTOCOL ||
            in_array($operation->phase, ['queued', 'verifying', 'completed', 'restored', 'cancelled'], true)) { return $configuration; }
        $configuration['crash_detection_enabled'] = false;
        $configuration['suspended'] = ! in_array($operation->phase, ['starting', 'restarting_original', 'recovery_starting'], true);
        return $configuration;
    }
}
