<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

final class ManagedPaths
{
    public const ROOTS = ['mods', 'config', 'configs', 'scripts', 'kubejs', 'libraries', 'defaultconfigs', 'configureddefaults', 'plugins', 'resources', 'patches', 'datapacks', 'ftbteambases', 'resourcepacks', 'shaderpacks', 'global_packs', 'paxi', 'openloader', 'schematics', 'assets', 'structures'];

    public static function allowed(string $path): bool
    {
        $parts = explode('/', $path);

        return ! in_array($parts[0], ['world', 'world_nether', 'world_the_end', 'backup', 'backups', 'logs',
            'crash-reports', 'saves', 'dimensions', 'data', 'DIM-1', 'DIM1'], true) &&
            ! str_starts_with($parts[0], '.arcturus-') &&
            (count($parts) === 1 || in_array($parts[0], self::ROOTS, true));
    }
}
