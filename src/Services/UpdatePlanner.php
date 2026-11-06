<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/** A pure manifest diff: it NEVER mutates a server or claims a merge succeeded. */
final class UpdatePlanner
{
    /**
     * @param  array<string,string>  $base  Verified manifest of installed upstream version
     * @param  array<string,string>  $local  Hashes of existing files from the server
     * @param  array<string,string>  $target  Verified manifest of target upstream version
     * @param  list<array{pattern:string,mode:string}>  $rules  Server-scoped policy rules
     * @return array{changes:list<array<string,mixed>>,counts:array<string,int>,blocked:int,applicable:bool}
     */
    public function plan(array $base, array $local, array $target, array $rules = [], array $identities = [], array $disabled = [], array $safetyRoots = [], array $metadata = []): array
    {
        foreach (['base' => $base, 'local' => $local, 'target' => $target] as $label => $manifest) {
            foreach ($manifest as $path => $hash) {
                self::assertPath($path);
                if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/iD', $hash) !== 1) {
                    throw new InvalidArgumentException("Invalid SHA-256 in {$label}: {$path}");
                }
            }
        }
        $base = array_map('strtolower', $base);
        $local = array_map('strtolower', $local);
        $target = array_map('strtolower', $target);
        $policy = new ProtectionPolicy;
        $rules = $policy->normalize($rules);

        // Mod identity survives upstream renames. Ambiguous/missing identities block new JARs
        // whenever an upstream mod disappeared locally, including intentionally deleted mods.
        $baseIds = $identities['base'] ?? [];
        $targetIds = $identities['target'] ?? [];
        $localIds = $identities['local'] ?? [];
        if (count($disabled) > 256) {
            throw new InvalidArgumentException('Too many disabled mod identities');
        }
        foreach ($disabled as $id) {
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_.+-]{1,128}$/D', $id)) {
                throw new InvalidArgumentException('Invalid disabled mod identity');
            }
        }
        $disabledIds = array_fill_keys(array_map('strtolower', $disabled), true);
        $missingUnidentified = false;
        $ambiguousIds = [];
        foreach ([$baseIds, $targetIds, $localIds] as $mapping) {
            $seenIds = [];
            foreach ($mapping as $file => $id) {
                self::assertPath($file);
                if (! is_string($id) || $id === '' || strlen($id) > 1024) {
                    throw new InvalidArgumentException('Invalid mod identity mapping');
                }
                foreach (explode('+', strtolower($id)) as $componentId) {
                    if (isset($seenIds[$componentId])) {
                        $ambiguousIds[$id] = true;
                        $ambiguousIds[$seenIds[$componentId]] = true;
                    }
                    $seenIds[$componentId] = $id;
                }
            }
        }
        foreach ($base as $file => $hash) {
            if (self::isMod($file) && ! isset($local[$file])) {
                $id = $baseIds[$file] ?? null;
                if ($id) {
                    foreach (explode('+', strtolower($id)) as $componentId) {
                        $disabledIds[$componentId] = true;
                    }
                } else {
                    $missingUnidentified = true;
                }
            }
        }
        foreach ($localIds as $file => $id) {
            if (str_ends_with(strtolower($file), '.disabled')) {
                foreach (explode('+', strtolower($id)) as $componentId) {
                    $disabledIds[$componentId] = true;
                }
            }
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
            $identity = $targetIds[$path] ?? $baseIds[$path] ?? $localIds[$path] ?? null;
            $rawClass = $this->classification($path, $old, $current, $new, $current !== $old && $old !== null ? 'preserve_local_change' : '');
            $decision = $policy->effective($path, $rules, $identity, $metadata[$path]['loader'] ?? null, $rawClass);
            $mode = $decision['mode'];
            $invariant = SafetyPaths::reason($path, $safetyRoots);
            if ($invariant !== null || $mode === 'protect') {
                $action = 'preserve';
            } elseif ($current !== null && str_starts_with($path, 'mods/') && str_ends_with(strtolower($path), '.jar.disabled') && $mode !== 'remove') {
                $action = 'preserve_disabled';
            } elseif (self::isMod($path) && $identity && isset($ambiguousIds[$identity])) {
                $action = 'unsafe_ambiguous_identity';
            } elseif ($mode === 'disable' || (self::isMod($path) && $identity && array_intersect(explode('+', strtolower($identity)), array_keys($disabledIds)) !== [])) {
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

            if ($action === 'add' && self::isMod($path) && $missingUnidentified) {
                $action = 'unsafe_ambiguous_identity';
            }
            if ($invariant === null && $mode === 'remove' && ! in_array($action, ['unsafe_ambiguous_identity', 'skip_disabled'], true)) {
                $action = $current === null ? 'already_absent' : 'remove_with_override';
            }
            if ($invariant === null && $mode === 'replace' && ! in_array($action, ['unsafe_ambiguous_identity', 'skip_disabled', 'disable_requires_enforcement', 'preserve_disabled'], true)) {
                $action = $new === null ? ($current === null ? 'already_absent' : 'remove_with_override') : ($current === $new ? 'unchanged' : 'replace_with_override');
            }
            if ($mode === 'review' && ! in_array($action, ['unchanged', 'preserve', 'preserve_local_extra', 'preserve_disabled'], true)) {
                $action = 'manual_review';
            }
            if ($mode === 'ignore' && $old === null && $new === null) {
                $action = 'preserve_local_extra';
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
            $isBlocked = in_array($action, ['conflict', 'merge_required', 'unsafe_ambiguous_identity', 'manual_review'], true) ||
                ($action === 'disable_requires_enforcement' && ! $safeDisabledMod);
            $blocked += (int) $isBlocked;
            $changes[] = [
                'path' => $path,
                'action' => $action,
                'rule' => $invariant !== null ? 'immutable' : $mode,
                'rule_origin' => $invariant ?? $decision['origin'],
                'effective_rule' => $decision['effective_rule'],
                'matching_rules' => $decision['matching_rules'],
                'destructive' => in_array($action, ['update', 'remove', 'remove_with_override', 'replace_with_override', 'disable_requires_enforcement'], true),
                'approval_required' => $isBlocked || in_array($action, ['remove_with_override', 'replace_with_override'], true),
                'metadata' => $metadata[$path] ?? null,
                'identity' => $identity,
                'file_state' => $rawClass,
                'classification' => $this->classification($path, $old, $current, $new, $action),
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
    public static function isMod(string $path): bool
    {
        return str_starts_with($path, 'mods/') && str_ends_with(strtolower($path), '.jar');
    }

    private function classification(string $path, ?string $base, ?string $local, ?string $target, string $action): string
    {
        if ($action === 'preserve') {
            return 'protected';
        }
        if (in_array($action, ['skip_disabled', 'disable_requires_enforcement'], true) || str_ends_with(strtolower($path), '.disabled')) {
            return 'disabled_mod';
        }
        if (str_starts_with($action, 'unsafe_')) {
            return 'unsafe_or_ambiguous';
        }
        if (in_array($action, ['conflict', 'merge_required', 'manual_review'], true)) {
            return $local === null && $base !== null ? 'missing_or_removed_locally' : 'merge_conflict';
        }
        if ($base === null && $target === null) {
            return 'local_only';
        }
        if ($local === null && $base !== null) {
            return 'missing_or_removed_locally';
        }
        if ($local !== $base && $base !== null && str_starts_with($action, 'preserve')) {
            return 'locally_modified';
        }
        if ($base === null) {
            return 'new_upstream';
        }
        if ($target === null) {
            return 'removed_upstream';
        }

        return $base === $target ? 'unchanged_upstream' : 'updated_upstream';
    }

    /** Glob * and ? stay within one directory; ** supports nested directories. */
    public static function matches(string $pattern, string $path): bool
    {
        self::assertPath($pattern);
        self::assertPath($path);
        $re = '';
        for ($i = 0, $len = strlen($pattern); $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $i++;
                if (($pattern[$i + 1] ?? '') === '/') {
                    $i++;
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

        return preg_match('~^'.$re.'$~D', $path) === 1;
    }

    public static function assertPath(mixed $path): void
    {
        if (! is_string($path) || $path === '' || strlen($path) > 1024 ||
            str_starts_with($path, '/') || str_contains($path, '\\') ||
            str_contains($path, ':') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 ||
            preg_match('~(^|/)(?:\.{1,2}|)(?:/|$)~', $path) === 1) {
            throw new InvalidArgumentException('Unsafe relative path');
        }
    }
}
