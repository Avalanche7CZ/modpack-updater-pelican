<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/** A pure manifest diff: it NEVER mutates a server or claims a merge succeeded. */
final class UpdatePlanner
{
    /** Runtime data is never package-owned, including with an explicit replace rule. */
    private const RUNTIME_DIRS = [
        'world', 'world_nether', 'world_the_end', 'logs', 'crash-reports',
        'backups', '.modpack-manager', 'DIM-1', 'DIM1', 'saves',
    ];
    private const RUNTIME_FILES = [
        'server.properties', 'eula.txt', 'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json', 'usercache.json',
        'session.lock',
    ];

    /**
     * @param array<string,string> $base Verified manifest of installed upstream version
     * @param array<string,string> $local Hashes of existing files from the server
     * @param array<string,string> $target Verified manifest of target upstream version
     * @param list<array{pattern:string,mode:string}> $rules Last matching rule wins
     * @return array{changes:list<array<string,mixed>>,counts:array<string,int>,blocked:int,applicable:bool}
     */
    public function plan(array $base, array $local, array $target, array $rules = []): array
    {
        foreach (['base' => $base, 'local' => $local, 'target' => $target] as $label => $manifest) {
            foreach ($manifest as $path => $hash) {
                self::assertPath($path);
                if (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/iD', $hash) !== 1) {
                    throw new InvalidArgumentException("Invalid SHA-256 in {$label}: {$path}");
                }
            }
        }
        if (count($rules) > 256) {
            throw new InvalidArgumentException('Too many protection rules');
        }
        foreach ($rules as $rule) {
            if (!is_array($rule) || !isset($rule['pattern'], $rule['mode']) ||
                !in_array($rule['mode'], ['protect', 'disable', 'merge', 'replace'], true)) {
                throw new InvalidArgumentException('Invalid protection rule');
            }
            self::assertPath($rule['pattern']);
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
            $mode = $this->ruleFor($path, $rules);

            if ($mode === 'immutable' || $mode === 'protect') {
                $action = 'preserve';
            } elseif ($mode === 'disable') {
                // A present old JAR is not silently considered disabled. Explicit
                // enforcement is required before any deployment may proceed.
                $action = $current === null ? 'skip_disabled' : 'disable_requires_enforcement';
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

            // A node worker can safely enforce an explicit disabled-mod rule
            // for JARs under mods/. It cannot enforce arbitrary file exclusions.
            $safeDisabledMod = $action === 'disable_requires_enforcement' &&
                preg_match('~^mods/(?:[^/]+/)*[^/]+\\.jar$~iD', $path) === 1;
            $isBlocked = in_array($action, ['conflict', 'merge_required'], true) ||
                ($action === 'disable_requires_enforcement' && !$safeDisabledMod);
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
        return ['changes' => $changes, 'counts' => $counts,
            'blocked' => $blocked, 'applicable' => $blocked === 0];
    }

    /** @param list<array{pattern:string,mode:string}> $rules */
    private function ruleFor(string $path, array $rules): ?string
    {
        $parts = explode('/', $path);
        if (in_array($parts[0], self::RUNTIME_DIRS, true) ||
            in_array($path, self::RUNTIME_FILES, true) ||
            preg_match('/^world_[^\/]+(?:\/|$)/D', $path) === 1) {
            return 'immutable';
        }
        $mode = null;
        foreach ($rules as $rule) {
            if (self::matches($rule['pattern'], $path)) {
                $mode = $rule['mode'];
            }
        }
        return $mode;
    }

    /** Glob * and ? stay within one directory; ** supports nested directories. */
    public static function matches(string $pattern, string $path): bool
    {
        self::assertPath($pattern);
        self::assertPath($path);
        $re = '';
        for ($i = 0, $len = strlen($pattern); $i < $len; ++$i) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                ++$i;
                if (($pattern[$i + 1] ?? '') === '/') {
                    ++$i;
                    $re .= '(?:.*/)?';
                } else {
                    $re .= '.*';
                }
            } elseif ($c === '*') {
                $re .= '[^/]*';
            } elseif ($c === '?') {
                $re .= '[^/]';
            } else {
                $re .= preg_quote($c, '~');
            }
        }
        return preg_match('~^' . $re . '$~D', $path) === 1;
    }

    public static function assertPath(mixed $path): void
    {
        if (!is_string($path) || $path === '' || strlen($path) > 1024 ||
            str_starts_with($path, '/') || str_contains($path, '\\') ||
            str_contains($path, ':') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 ||
            preg_match('~(^|/)(?:\.{1,2}|)(?:/|$)~', $path) === 1) {
            throw new InvalidArgumentException('Unsafe relative path');
        }
    }
}
