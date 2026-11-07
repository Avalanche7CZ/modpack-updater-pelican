<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

/** Select paths from upstream manifests before touching the live server. */
final class ManifestComparison
{
    public const SCHEMA = 'manifest-delta-v1';

    public static function paths(array $base, array $target, array $rules = [], array $identities = [], array $disabled = []): array
    {
        $policy = new ProtectionPolicy;
        $rules = $policy->normalize($rules);
        $paths = [];
        foreach (array_unique([...array_keys($base), ...array_keys($target)]) as $path) {
            UpdatePlanner::assertPath($path);
            $id = $identities['target'][$path] ?? $identities['base'][$path] ?? null;
            $mode = $policy->effective($path, $rules, $id)['mode'];
            if (($base[$path] ?? null) !== ($target[$path] ?? null) || in_array($mode, ['remove', 'disable', 'replace'], true) ||
                ($id !== null && array_intersect(explode('+', strtolower($id)), array_map('strtolower', $disabled)))) {
                $paths[$path] = true;
            }
        }
        // Explicit local file rules can target paths outside either upstream manifest.
        foreach ($rules as $rule) {
            if ($rule['enabled'] && $rule['scope'] === 'exact' && in_array($rule['mode'], ['remove', 'disable'], true)) { $paths[$rule['pattern']] = true; }
        }
        $result = array_keys($paths); sort($result, SORT_STRING);
        return $result;
    }

    public static function select(array $manifest, array $paths): array
    {
        return array_intersect_key($manifest, array_fill_keys($paths, true));
    }

    /** Only bytes that will actually be installed need staging verification. */
    public static function installs(array $changes): array
    {
        $paths = [];
        foreach (ModUpgradePlanner::mutations($changes) as $change) {
            if (in_array($change['action'], ['add', 'update', 'replace_with_override'], true)) { $paths[] = $change['path']; }
        }
        return array_values(array_unique($paths));
    }
}
