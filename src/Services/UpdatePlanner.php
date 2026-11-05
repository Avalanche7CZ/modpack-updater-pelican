<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/**
 * Deterministic, read-only update planner.
 *
 * Inputs are trusted upstream baseline/target SHA-256 manifests and an independently
 * scanned local inventory. Planning does NOT read, write or remove filesystem data.
 */
final class UpdatePlanner
{
    private const IMMUTABLE_PATHS = [
        'world', 'world/**', 'world_*', 'world_*/**',
        'logs', 'logs/**', 'crash-reports', 'crash-reports/**',
        'backups', 'backups/**', 'usercache.json', 'ops.json',
        'whitelist.json', 'banned-players.json', 'banned-ips.json',
        'server.properties', 'eula.txt', '.modpack-manager/**',
    ];

    /**
     * @param array<string,string> $base Original upstream manifest
     * @param array<string,string> $local Local scanned inventory
     * @param array<string,string> $target Target upstream manifest
     * @param list<array{pattern:string,mode:string}> $rules Last matching rule wins
     * @return array{changes:list<array<string,mixed>>,counts:array<string,int>,blocked:int}
     */
    public function plan(array $base, array $local, array $target, array $rules = []): array
    {
        foreach (['base' => $base, 'local' => $local, 'target' => $target] as $label => $manifest) {
            foreach ($manifest as $path => $hash) {
                $this->assertPath($path);
                if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/iD', $hash) !== 1) {
                    throw new InvalidArgumentException("Invalid SHA-256 in {$label}: {$path}");
                }
            }
        }

        foreach ($rules as $rule) {
            if (!isset($rule['pattern'], $rule['mode']) ||
                !in_array($rule['mode'], ['protect', 'disable', 'merge', 'replace'], true)) {
                throw new InvalidArgumentException('Invalid protection rule');
            }
            $this->assertPattern($rule['pattern']);
        }

        $paths = array_unique(array_merge(array_keys($base), array_keys($local), array_keys($target)));
        sort($paths, SORT_STRING);
        $changes = [];
        $counts = [];
        $blocked = 0;

        foreach ($paths as $path) {
            $old = $base[$path] ?? null;
            $current = $local[$path] ?? null;
            $new = $target[$path] ?? null;
            $mode = $this->modeFor($path, $rules);

            if ($mode === 'immutable' || $mode === 'protect') {
                $action = 'preserve';
            } elseif ($mode === 'disable') {
                // A deploy worker must also disable/quarantine an already active mod.
                $action = 'disable_requires_enforcement';
            } elseif ($old === null && $new === null) {
                $action = 'preserve_local_extra';
            } elseif ($old === $new) {
                $action = $current === $old ? 'unchanged' : 'preserve_local_change';
            } elseif ($old === null) {
                $action = $current === null ? 'add' : ($current === $new ? 'unchanged' : 'conflict');
            } elseif ($new === null) {
                $action = $current === null ? 'already_absent' : ($current === $old ? 'remove' : 'conflict');
            } elseif ($current === $new) {
                $action = 'unchanged';
            } elseif ($current === $old) {
                $action = 'update';
            } else {
                $action = 'conflict';
            }

            if ($action === 'conflict' && $mode === 'merge') {
                $action = 'merge_required';
            } elseif ($action === 'conflict' && $mode === 'replace') {
                $action = $new === null ? 'remove_with_override' : 'replace_with_override';
            }

            // Only an explicit override permits replacing a modified local file.
            $isBlocked = in_array($action, ['conflict', 'merge_required', 'disable_requires_enforcement'], true);
            $blocked += (int) $isBlocked;

            $changes[] = [
                'path' => $path,
                'action' => $action,
                'rule' => $mode,
                'base_sha256' => $old,
                'local_sha256' => $current,
                'target_sha256' => $new,
                'blocked' => $isBlocked,
            ];
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        ksort($counts);
        return ['changes' => $changes, 'counts' => $counts, 'blocked' => $blocked];
    }

    /** @param list<array{pattern:string,mode:string}> $rules */
    private function modeFor(string $path, array $rules): ?string
    {
        foreach (self::IMMUTABLE_PATHS as $pattern) {
            if (fnmatch($pattern, $path)) {
                return 'immutable';
            }
        }
        $mode = null;
        foreach ($rules as $rule) {
            if (fnmatch($rule['pattern'], $path)) {
                $mode = $rule['mode'];
            }
        }
        return $mode;
    }

    private function assertPath(mixed $path): void
    {
        if (!is_string($path) || $path === '' || str_starts_with($path, '/') ||
            str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) ||
            preg_match('~(^|/)(?:\.{1,2}|)(?:/|$)~', $path)) {
            throw new InvalidArgumentException('Unsafe relative path');
        }
    }

    private function assertPattern(mixed $pattern): void
    {
        $this->assertPath($pattern);
    }
}
