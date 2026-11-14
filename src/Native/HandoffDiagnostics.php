<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;

final class HandoffDiagnostics
{
    public static function available(ManagedServer $managed): bool
    {
        return $managed->transport === 'native' && $managed->active_operation_id && $managed->operations()->whereKey($managed->active_operation_id)
            ->whereIn('phase', ['manual_intervention', ManualStart::PHASE])->exists();
    }

    public static function report(ManagedServer $managed): array
    {
        $managed = ManagedServer::lightweight()->findOrFail($managed->id);
        $operation = $managed->active_operation_id ? $managed->operations()->find($managed->active_operation_id) : null;
        $code = [];
        foreach (['page' => ViewManagedServer::class, 'service' => ManualStart::class] as $label => $class) {
            $reflection = new \ReflectionClass($class); $path = $reflection->getFileName();
            $code[$label] = ['loaded_marker' => $reflection->getConstants()['CODE_ID'] ?? 'legacy-no-marker', 'disk_sha256' => hash_file('sha256', $path),
                'path' => $path, 'opcache_cached' => function_exists('opcache_is_script_cached') ? opcache_is_script_cached($path) : false];
        }
        $package = json_decode(file_get_contents(dirname(__DIR__, 2).'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        return ['managed_server_id' => $managed->id, 'transport' => $managed->transport, 'active_operation_id' => $managed->active_operation_id,
            'active_operation_found' => $operation !== null, 'phase' => $operation?->phase, 'handoff_visible_condition' => self::available($managed),
            'package_version_on_disk' => $package['version'], 'runtime' => ['sapi' => PHP_SAPI, 'php' => PHP_VERSION, 'pid' => getmypid(), 'at_utc' => gmdate('c')], 'code' => $code,
            'note' => 'CLI output does not prove what FPM loaded. Compare the authenticated web response, loaded markers and disk hashes with the verified release ZIP. No credentials or lock owner tokens are included.'];
    }
}
