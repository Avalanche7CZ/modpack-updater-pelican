<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Services\ModUpgradePlanner;
use Arcturus\ModpackManager\Services\SafetyPaths;

/** Filename hints select reads only. Only embedded identity can bind a live JAR to a mod. */
final class ModDrift
{
    public const SCHEMA = 'native-mod-drift-v2';

    public static function targets(array $base, array $target, array $local, array $protected, array $disabled): array
    {
        $groups = [];
        foreach (['base' => $base, 'target' => $target] as $side => $manifest) {
            foreach ($manifest['files'] as $path => $hash) {
                if (! self::isTopLevelJar($path)) { continue; }
                $meta = $manifest['metadata'][$path] ?? null;
                $mapped = $manifest['identities'][$path] ?? null;
                $id = ModUpgradePlanner::verifiedIdentity($hash, $mapped, $meta);
                if ($id === null && $side === 'base') {
                    $id = self::legacyBaselineHint($mapped, $hash, $meta);
                }
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

    /** A saved upstream ID or explicit mapping can select a read, but never verifies the live JAR. */
    private static function legacyBaselineHint(?string $hint, string $hash, mixed $meta): ?string
    {
        if (! is_array($meta) || isset($meta['source']) || ! in_array($meta['loader'] ?? null, ['forge', 'neoforge'], true) ||
            $hint === null || strlen($hint) > 1024 ||
            (isset($meta['identity']) && $meta['identity'] !== $hint) ||
            (isset($meta['sha256']) && $meta['sha256'] !== strtolower($hash))) { return null; }
        $parts = explode('+', $hint);
        if (count($parts) > 256 || count(array_unique($parts)) !== count($parts)) { return null; }
        foreach ($parts as $part) { if (! preg_match('/^[a-z0-9_.-]{1,128}$/D', $part)) { return null; } }
        if (isset($meta['ids'])) {
            if (! is_array($meta['ids']) || ! array_is_list($meta['ids'])) { return null; }
            $ids = $meta['ids'];
            foreach ($ids as $id) { if (! is_string($id)) { return null; } }
            sort($ids, SORT_STRING); sort($parts, SORT_STRING);
            if ($ids !== $parts) { return null; }
        }
        return $hint;
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
