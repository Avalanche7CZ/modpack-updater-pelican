<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Services\ModUpgradePlanner;
use Arcturus\ModpackManager\Services\SafetyPaths;

/** Filename hints select reads only. Only embedded identity can bind a live JAR to a mod. */
final class ModDrift
{
    public const SCHEMA = 'native-mod-drift-v1';

    public static function targets(array $base, array $target, array $local, array $protected, array $disabled): array
    {
        $groups = [];
        foreach (['base' => $base, 'target' => $target] as $side => $manifest) {
            foreach ($manifest['files'] as $path => $hash) {
                if (! self::isTopLevelJar($path)) { continue; }
                $meta = $manifest['metadata'][$path] ?? null;
                $id = ModUpgradePlanner::verifiedIdentity($hash, $manifest['identities'][$path] ?? null, $meta);
                if ($id !== null) { $groups[$id][$side][$path] = $meta['loader']; }
            }
        }
        $result = []; $disabled = array_map('strtolower', $disabled);
        foreach ($groups as $id => $group) {
            $id = (string) $id;
            if (count($group['base'] ?? []) !== 1 || count($group['target'] ?? []) !== 1 ||
                array_intersect(explode('+', $id), $disabled)) { continue; }
            $old = array_key_first($group['base']); $new = array_key_first($group['target']);
            if (isset($local[$old]) || ($old === $new && $base['files'][$old] === $target['files'][$new]) ||
                $group['base'][$old] !== $group['target'][$new] || SafetyPaths::reason($old, $protected) !== null ||
                SafetyPaths::reason($new, $protected) !== null) { continue; }
            $hints = [...explode('+', $id), self::stem($old), self::stem($new)];
            $result[$id] = ['loader' => $group['base'][$old],
                'hints' => array_values(array_unique(array_filter($hints, fn ($hint) => strlen($hint) >= 2)))];
        }
        ksort($result);
        return $result;
    }

    public static function candidates(array $rows, array $targets, array $explicitMappings): array
    {
        // Index every separator prefix once, then look up known hints. Do not walk
        // subdirectories or hash the full mods directory.
        $index = [];
        foreach ($targets as $id => $target) {
            foreach ($target['hints'] as $hint) { $index[$hint][$id] = true; }
        }
        $result = [];
        foreach ($rows as $row) {
            $path = 'mods/'.$row['name'];
            if (! self::isTopLevelJar($path)) { continue; }
            $name = self::name($path); $ids = $index[$name] ?? [];
            preg_match_all('/[-_.]/', $name, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) { $ids += $index[substr($name, 0, $offset)] ?? []; }
            $mapped = strtolower($explicitMappings[$path] ?? '');
            if (isset($targets[$mapped])) { $ids[$mapped] = true; }
            if ($ids !== []) { $result[$path] = ['row' => $row, 'ids' => $ids]; }
        }
        ksort($result);
        return $result;
    }

    private static function isTopLevelJar(string $path): bool
    {
        return preg_match('~^mods/[^/]+\.jar(?:\.disabled)?$~iD', $path) === 1;
    }

    private static function name(string $path): string
    {
        return strtolower(preg_replace('/\.jar(?:\.disabled)?$/iD', '', basename($path)));
    }

    private static function stem(string $path): string
    {
        return preg_replace('/[-_.](?:v?\d|forge(?:[-_.]|$)|neoforge(?:[-_.]|$)).*$/iD', '', self::name($path));
    }
}
