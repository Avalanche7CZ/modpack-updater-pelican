<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/** Pure, server-scoped rules. Specificity precedes priority, then later row wins. */
final class ProtectionPolicy
{
    public const ACTIONS = ['protect' => 'Keep local', 'replace' => 'Use upstream', 'disable' => 'Keep disabled',
        'remove' => 'Remove (dependency review required)', 'review' => 'Review', 'merge' => 'Merge / manual resolution',
        'ignore' => 'Keep local extras', 'follow' => 'Follow upstream'];

    public const SCOPES = ['exact' => 'Exact path', 'directory' => 'Directory', 'glob' => 'Glob',
        'extension' => 'Extension / type', 'mod_id' => 'Mod ID', 'mod_filename' => 'Mod filename', 'loader' => 'Modloader metadata'];

    public const CLASSIFICATIONS = ['local_only', 'locally_modified', 'new_upstream', 'updated_upstream',
        'removed_upstream', 'missing_or_removed_locally', 'unchanged_upstream', 'disabled_mod', 'merge_conflict'];

    public function normalize(array $rules): array
    {
        if (count($rules) > 256) {
            throw new InvalidArgumentException('At most 256 protection rules are allowed');
        }
        $out = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! is_string($rule['pattern'] ?? null) || ! isset(self::ACTIONS[$rule['mode'] ?? ''])) {
                throw new InvalidArgumentException('Invalid protection rule');
            }
            $pattern = trim($rule['pattern']);
            $scope = $rule['scope'] ?? (strpbrk($pattern, '*?') === false ? 'exact' : 'glob');
            if (! isset(self::SCOPES[$scope])) {
                throw new InvalidArgumentException('Invalid matching scope');
            }
            UpdatePlanner::assertPath($pattern);
            if ($scope === 'exact' && strpbrk($pattern, '*?') !== false) {
                throw new InvalidArgumentException('Exact paths cannot contain glob characters');
            }
            if (in_array($scope, ['mod_id', 'loader', 'extension'], true) && ! preg_match('/^[A-Za-z0-9_.+-]{1,128}$/D', $pattern)) {
                throw new InvalidArgumentException('Invalid metadata selector');
            }
            $classification = $rule['classification'] ?? null;
            if ($classification === '') {
                $classification = null;
            }
            if ($classification !== null && ! in_array($classification, self::CLASSIFICATIONS, true)) {
                throw new InvalidArgumentException('Invalid file classification');
            }
            if (strlen($rule['comment'] ?? '') > 500 || ! is_numeric($rule['priority'] ?? 0) || abs((int) ($rule['priority'] ?? 0)) > 1000) {
                throw new InvalidArgumentException('Invalid rule priority or comment');
            }
            $out[] = ['scope' => $scope, 'pattern' => $pattern, 'mode' => $rule['mode'],
                'priority' => (int) ($rule['priority'] ?? 0), 'enabled' => (bool) ($rule['enabled'] ?? true),
                'classification' => $classification, 'comment' => $rule['comment'] ?? ''];
        }

        return $out;
    }

    public function effective(string $path, array $rules, ?string $identity = null, ?string $loader = null, ?string $classification = null): array
    {
        $matches = [];
        foreach ($rules as $index => $rule) {
            if (! $rule['enabled'] || ($rule['classification'] !== null && $rule['classification'] !== $classification)) {
                continue;
            }
            $pattern = $rule['pattern'];
            $match = match ($rule['scope']) {
                'exact' => $path === $pattern,
                'directory' => $path === $pattern || str_starts_with($path, $pattern.'/'),
                'extension' => strtolower(pathinfo(preg_replace('/\.disabled$/i', '', $path), PATHINFO_EXTENSION)) === strtolower(ltrim($pattern, '.')),
                'mod_id' => $identity !== null && in_array(strtolower($pattern), explode('+', strtolower($identity)), true),
                'mod_filename' => (UpdatePlanner::isMod($path) || str_ends_with($path, '.jar.disabled')) && UpdatePlanner::matches($pattern, basename($path)),
                'loader' => $loader !== null && strtolower($loader) === strtolower($pattern),
                default => UpdatePlanner::matches($pattern, $path),
            };
            if (! $match) {
                continue;
            }
            $rank = match ($rule['scope']) {
                'exact' => 700, 'mod_id' => 600, 'mod_filename' => 500, 'directory' => 400, 'glob' => 300, 'loader' => 200, default => 100
            };
            $matches[] = ['index' => $index, 'rule' => $rule, 'rank' => [$rank, $rule['scope'] === 'directory' ? strlen($pattern) : 0, $rule['priority'], $index]];
        }
        usort($matches, fn ($a, $b) => $b['rank'] <=> $a['rank']);
        $winner = $matches[0] ?? null;

        return ['mode' => $winner['rule']['mode'] ?? null, 'origin' => $winner ? 'Server rule #'.($winner['index'] + 1) : 'Three-way comparison',
            'effective_rule' => $winner['rule'] ?? null, 'matching_rules' => array_map(fn ($m) => $m['index'] + 1, $matches)];
    }

    public static function templates(): array
    {
        return ['empty' => 'Empty', 'conservative' => 'Conservative', 'upstream' => 'Mostly upstream-managed',
            'custom' => 'Custom mod-heavy', 'gtnh' => 'GTNH / Crucible', 'tfg' => 'TFG Modern',
            'arcturus' => 'Arcturus Network — custom mods', 'tfg_arcturus' => 'TFG Modern + Arcturus Network'];
    }

    public function template(string $name): array
    {
        if (! isset(self::templates()[$name])) {
            throw new InvalidArgumentException('Unknown policy template');
        }
        if ($name === 'empty') {
            return [];
        }
        $rules = [['scope' => 'exact', 'pattern' => 'server.properties', 'mode' => 'protect'],
            ['scope' => 'glob', 'pattern' => '*.sh', 'mode' => 'review'],
            ['scope' => 'glob', 'pattern' => '*.bat', 'mode' => 'review'],
            ['scope' => 'glob', 'pattern' => '**', 'classification' => 'removed_upstream', 'mode' => 'review']];
        foreach (['ops.json', 'ops.txt', 'whitelist.json', 'white-list.txt', 'banned-players.json', 'banned-ips.json', 'eula.txt'] as $file) {
            $rules[] = ['scope' => 'exact', 'pattern' => $file, 'mode' => 'protect'];
        }
        foreach (['user_jvm_args.txt', 'unix_args.txt', 'variables.txt'] as $file) {
            $rules[] = ['scope' => 'exact', 'pattern' => $file, 'mode' => 'review'];
        }
        if ($name !== 'upstream') {
            foreach (['config', 'configs', 'defaultconfigs'] as $directory) {
                $rules[] = ['scope' => 'directory', 'pattern' => $directory, 'classification' => 'locally_modified', 'mode' => 'protect'];
            }
        }
        if ($name === 'custom') {
            $rules[] = ['scope' => 'directory', 'pattern' => 'mods', 'classification' => 'local_only', 'mode' => 'ignore'];
        }
        if ($name === 'gtnh') {
            $rules[] = ['scope' => 'glob', 'pattern' => '*Crucible*.jar', 'mode' => 'review'];
        }
        if (in_array($name, ['tfg', 'tfg_arcturus'], true)) {
            $rules[] = ['scope' => 'directory', 'pattern' => 'kubejs', 'mode' => 'review'];
        }
        if (in_array($name, ['arcturus', 'tfg_arcturus'], true)) {
            // Editable presets only: none of these names are global invariant
            // protections. Do not put these in the core update planner.
            // Glob matching is case-sensitive; preserve both common jar cases.
            foreach ([
                'mods/*paradigm*.jar', 'mods/*Paradigm*.jar',
                'mods/*arcturustoken*.jar', 'mods/*ArcturusToken*.jar',
            ] as $pattern) {
                $rules[] = ['scope' => 'glob', 'pattern' => $pattern,
                    'mode' => 'protect', 'comment' => 'Arcturus custom mod; keep installed local version'];
            }
            foreach ([
                'config/paradigm', 'config/Paradigm',
                'config/paradigminsights', 'config/paradigmvote',
                'config/arcturustokens', 'config/ArcturusTokens',
            ] as $directory) {
                $rules[] = ['scope' => 'directory', 'pattern' => $directory,
                    'mode' => 'protect', 'comment' => 'Arcturus custom configuration'];
            }
            $rules[] = ['scope' => 'exact', 'pattern' => 'config/arcturustokens.properties',
                'mode' => 'protect', 'comment' => 'ArcturusTokens server configuration'];
        }

        return $this->normalize($rules);
    }
}
