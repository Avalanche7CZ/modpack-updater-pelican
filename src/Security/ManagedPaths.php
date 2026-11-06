<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

final class ManagedPaths
{
    public const ROOTS = ['mods', 'config', 'configs', 'scripts', 'kubejs', 'libraries', 'defaultconfigs', 'configureddefaults', 'plugins', 'resources', 'patches', 'datapacks', 'ftbteambases', 'resourcepacks', 'shaderpacks', 'global_packs', 'paxi', 'openloader', 'schematics', 'assets', 'structures'];

    public static function reviewedAdditions(array $names): array
    {
        $reserved = ['world', 'world_nether', 'world_the_end', 'saves', 'dimensions', 'data', 'logs', 'crash-reports',
            'backup', 'backups', 'region', 'playerdata', 'entities', 'poi', 'level.dat', 'level.dat_old', 'session.lock'];
        if (! array_is_list($names) || count($names) > 128) {
            throw new \InvalidArgumentException('Additional managed directories must be a bounded list');
        }
        foreach ($names as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $name) ||
                in_array(strtolower($name), $reserved, true) || \Arcturus\ModpackManager\Services\SafetyPaths::reason($name) !== null || preg_match('/^DIM-?[0-9]+$/iD', $name)) {
                throw new \InvalidArgumentException('Additional managed directory is unsafe or reserved for runtime data');
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    public static function allowed(string $path, array $additional = []): bool
    {
        \Arcturus\ModpackManager\Services\UpdatePlanner::assertPath($path);
        if (\Arcturus\ModpackManager\Services\SafetyPaths::reason($path) !== null) { return false; }
        $parts = explode('/', $path);
        $additional = self::reviewedAdditions($additional);
        if (in_array($parts[0], $additional, true)) {
            if (\Arcturus\ModpackManager\Services\SafetyPaths::reason($path) !== null ||
                array_intersect(array_map('strtolower', $parts), ['logs', 'crash-reports', 'backups', 'backup'])) {
                return false;
            }
        }

        return ! in_array($parts[0], ['world', 'world_nether', 'world_the_end', 'backup', 'backups', 'logs',
            'crash-reports', 'saves', 'dimensions', 'data', 'DIM-1', 'DIM1'], true) &&
            ! str_starts_with($parts[0], '.arcturus-') &&
            (count($parts) === 1 || in_array($parts[0], array_merge(self::ROOTS, $additional), true));
    }
}
