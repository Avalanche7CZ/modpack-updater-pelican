<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services\Pelican;

use App\Models\Server;
use App\Services\Servers\ServerConfigurationStructureService;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

/** Standard Wings config fields, scoped to one durable administrator-approved deployment. */
final class GuardedServerConfiguration extends ServerConfigurationStructureService
{
    protected function returnFormat(Server $server): array
    {
        $configuration = parent::returnFormat($server);
        if (! Schema::hasTable('arcturus_modpack_servers')) { return $configuration; }
        $managed = ManagedServer::where('pelican_server_uuid', $server->uuid)->first(['id', 'active_operation_id']);
        $operation = $managed?->active_operation_id ? $managed->operations()->find($managed->active_operation_id) : null;
        if (! $operation || $operation->kind !== 'deploy') { return $configuration; }
        try {
            $context = $operation->context ?? [];
        } catch (DecryptException) {
            // Missing recovery evidence must fail closed: never let a damaged
            // deployment receipt break Wings configuration sync or permit a start.
            // The original operation and its server lock remain untouched.
            $configuration['crash_detection_enabled'] = false;
            $configuration['suspended'] = true;
            return $configuration;
        }
        if (\Arcturus\ModpackManager\Native\ManualStart::controlsReleased($operation)) { return $configuration; }
        if (($context['manual_approval'] ?? false) !== true ||
            ! in_array($context['protocol'] ?? '', [SameHostDeployment::PROTOCOL, \Arcturus\ModpackManager\Native\Qualification::PROTOCOL], true) ||
            in_array($operation->phase, ['queued', 'verifying', 'completed', 'restored', 'cancelled'], true)) { return $configuration; }
        $configuration['crash_detection_enabled'] = false;
        $configuration['suspended'] = ! in_array($operation->phase, ['starting', 'restarting_original', 'recovery_starting'], true);
        return $configuration;
    }
}
