<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

/** Optional grouping by upstream mod identity; file ownership comes from verified manifests. */
final class ModUpgradePlanner
{
    public const SCHEMA = 'manifest-mod-upgrade-v2';

    /** Metadata must describe these exact JAR bytes, rather than a provider project or administrator alias. */
    public static function verifiedIdentity(string $hash, ?string $mapped, mixed $metadata): ?string
    {
        if (! is_array($metadata)) { return null; }
        $source = $metadata['source'] ?? null;
        if (! in_array($source, ['META-INF/mods.toml', 'META-INF/neoforge.mods.toml'], true) ||
            ($metadata['loader'] ?? null) !== ($source === 'META-INF/mods.toml' ? 'forge' : 'neoforge') ||
            ! is_string($metadata['sha256'] ?? null) || ! hash_equals(strtolower($hash), $metadata['sha256']) || empty($metadata['ids'])) { return null; }
        $ids = $metadata['ids'];
        if (! is_array($ids) || count($ids) > 256) { return null; }
        foreach ($ids as $id) { if (! is_string($id) || ! preg_match('/^[a-z0-9_.-]{1,128}$/D', $id)) { return null; } }
        if (count(array_unique($ids)) !== count($ids)) { return null; }
        $ids = array_values($ids); sort($ids, SORT_STRING); $identity = implode('+', $ids);
        if ($identity !== ($metadata['identity'] ?? null) || ($mapped !== null && strtolower($mapped) !== $identity)) { return null; }
        return $identity;
    }

    /** @return array{changes:array,consumed:array,verified:array,ambiguous:array} */
    public function plan(array $base, array $local, array $target, array $identities, array $evidence, array $rules, array $disabled, array $safetyRoots): array
    {
        $inventories = ['base' => $base, 'local' => $local, 'target' => $target];
        $groups = []; $verified = []; $components = []; $ambiguous = [];
        foreach ($inventories as $scope => $files) {
            foreach ($files as $path => $hash) {
                if (! UpdatePlanner::isMod($path) && ! (str_starts_with($path, 'mods/') && str_ends_with(strtolower($path), '.jar.disabled'))) { continue; }
                $meta = $evidence[$scope][$path] ?? [];
                if (! is_array($meta)) { $meta = []; }
                // Identical bytes inherit embedded identity evidence from an authenticated upstream JAR.
                if ($scope === 'local' && ! $meta) {
                    foreach (['base', 'target'] as $upstream) {
                        if (($inventories[$upstream][$path] ?? null) === $hash) { $meta = $evidence[$upstream][$path] ?? []; if (is_array($meta) && $meta) { break; } }
                    }
                }
                if (! is_array($meta)) { $meta = []; }
                $id = self::verifiedIdentity($hash, $identities[$scope][$path] ?? null, $meta);
                if ($id === null && $scope !== 'local') { $id = $identities[$scope][$path] ?? null; }
                if ($id === null && $scope === 'local') {
                    foreach (['base', 'target'] as $upstream) {
                        if (($inventories[$upstream][$path] ?? null) === $hash) {
                            $id = $identities[$upstream][$path] ?? null;
                            if ($id !== null) { $meta = $evidence[$upstream][$path] ?? []; break; }
                        }
                    }
                }
                if ($id !== null && ! preg_match('/^[a-z0-9_.+-]{1,1024}$/D', $id)) { $id = null; }
                if ($id === null) { continue; }
                $verified[$scope][$path] = $id;
                $groups[$id][$scope][$path] = ['path' => $path, 'sha256' => $hash, 'version' => $meta['version'] ?? null,
                    'versions' => $meta['versions'] ?? [], 'loader' => $meta['loader'] ?? null, 'source' => $meta['source'] ?? 'upstream-manifest'];
                foreach (explode('+', $id) as $component) {
                    if (isset($components[$scope][$component])) { $ambiguous[$id] = $ambiguous[$components[$scope][$component]] = true; }
                    $components[$scope][$component] = $id;
                }
            }
        }
        // A changed embedded-ID set or overlapping bundle is not an automatic one-to-one match.
        foreach ($components as $scope => $mapping) {
            foreach ($mapping as $component => $id) {
                foreach ($components as $other) {
                    if (isset($other[$component]) && $other[$component] !== $id) { $ambiguous[$id] = $ambiguous[$other[$component]] = true; }
                }
            }
        }
        $changes = []; $consumed = []; $policy = new ProtectionPolicy;
        $disabled = array_map('strtolower', $disabled);
        foreach ($groups as $id => $group) {
            if (empty($group['base']) || empty($group['target'])) { continue; }
            $baseline = count($group['base']) === 1 ? reset($group['base']) : null;
            $destination = count($group['target']) === 1 ? reset($group['target']) : null;
            $installed = count($group['local'] ?? []) === 1 ? reset($group['local']) : null;
            if ($baseline && $destination && $baseline['path'] === $destination['path'] && $baseline['sha256'] === $destination['sha256']) { continue; }
            $paths = array_values(array_unique(array_merge(array_keys($group['base']), array_keys($group['local'] ?? []), array_keys($group['target']))));
            // A changed file at the old path without matching embedded metadata is a review, never a guessed match.
            if (! $installed && $baseline && isset($local[$baseline['path']])) {
                $installed = ['path' => $baseline['path'], 'sha256' => $local[$baseline['path']], 'version' => null, 'versions' => [], 'loader' => null];
                $paths[] = $installed['path'];
                $verified['local'][$installed['path']] = $id;
            }
            $paths = array_values(array_unique($paths)); sort($paths, SORT_STRING);
            $problem = null;
            if (! empty($evidence['drift_review'][$id])) { $problem = 'A possible installed JAR could not be verified. Review '.implode(', ', array_slice($evidence['drift_review'][$id], 0, 3)).'. No version was selected.'; }
            elseif (! $baseline || ! $destination || count($group['local'] ?? []) > 1 || isset($ambiguous[$id])) { $problem = 'Duplicated or overlapping embedded mod IDs. Review all candidate JARs; no version was selected.'; }
            elseif ($baseline['loader'] !== null && $destination['loader'] !== null && $baseline['loader'] !== $destination['loader']) { $problem = 'Mod loader metadata changes. Prepare and qualify a loader migration separately.'; }
            elseif ($installed && ($verified['local'][$installed['path']] ?? null) !== $id) { $problem = 'The installed JAR cannot be associated with this upstream operation. Review this replacement.'; }
            elseif ($destination && isset($local[$destination['path']]) && ($installed['path'] ?? null) !== $destination['path']) { $problem = 'The target filename is already occupied. Review installed mod identities before upgrading.'; }
            $alreadyTarget = $installed && $destination && $installed['path'] === $destination['path'] && $installed['sha256'] === $destination['sha256'];
            $modified = ! $alreadyTarget && $installed !== null && $baseline !== null &&
                ($installed['sha256'] !== $baseline['sha256'] || $installed['path'] !== $baseline['path']);
            $class = $modified ? 'locally_modified' : 'updated_upstream';
            $decisions = []; $invariant = null; $protected = false; $review = false; $replace = true; $disable = false;
            foreach ($paths as $path) {
                $invariant ??= SafetyPaths::reason($path, $safetyRoots);
                $decision = $policy->effective($path, $rules, $id, $destination['loader'] ?? null, $class);
                $decisions[] = $decision; $protected = $protected || $decision['mode'] === 'protect';
                $review = $review || in_array($decision['mode'], ['review', 'merge', 'remove'], true);
                $replace = $replace && $decision['mode'] === 'replace'; $disable = $disable || $decision['mode'] === 'disable';
            }
            $disabledIdentity = array_intersect(explode('+', $id), $disabled) !== [];
            $reason = $problem;
            if ($invariant !== null || $protected) { $action = 'keep_mod'; $class = 'protected'; $reason = $invariant ?? 'An existing rule protects a member of this mod upgrade. Keep the installed mod; do not add a second version.'; }
            elseif ($problem !== null) { $action = 'mod_identity_review'; $class = 'unsafe_or_ambiguous'; }
            elseif (! $installed || str_ends_with(strtolower($installed['path']), '.disabled')) { $action = 'keep_mod_disabled'; $class = 'disabled_mod'; $reason = $installed ? 'Preserving the verified disabled JAR; the target is not re-enabled.' : 'No active JAR was verified for this mod. The target is not installed automatically.'; }
            elseif ($disabledIdentity || $disable) { $action = 'mod_upgrade_review'; $class = 'disabled_mod'; $reason = 'This mod is configured as disabled but an active JAR remains. Disable it explicitly before considering an upgrade.'; }
            elseif ($review) { $action = 'mod_upgrade_review'; $reason = 'An explicit member rule requires review. Choose a policy for this whole mod operation.'; }
            elseif ($installed['path'] === $destination['path'] && $installed['sha256'] === $destination['sha256']) { $action = 'unchanged'; $class = 'unchanged_upstream'; $reason = 'The verified target is already installed.'; }
            elseif ($modified && ! $replace) { $action = 'mod_upgrade_conflict'; $reason = 'The installed JAR path or contents differ from the verified baseline. Keep the installed mod or upgrade the entire mod to the verified target.'; }

            else { $action = 'upgrade_mod'; $reason = $modified ? 'Explicitly approved replacement of a locally modified mod.' : 'Installed JAR matches the verified baseline. Replace it with the verified target as one offline transaction.'; }
            $blocked = in_array($action, ['mod_identity_review', 'mod_upgrade_review', 'mod_upgrade_conflict'], true);
            $members = [];
            if ($action === 'upgrade_mod') {
                if ($installed['path'] !== $destination['path']) { $members[] = ['path' => $installed['path'], 'action' => 'remove', 'local_sha256' => $installed['sha256'], 'target_sha256' => null]; }
                $members[] = ['path' => $destination['path'], 'action' => 'install', 'local_sha256' => $local[$destination['path']] ?? null, 'target_sha256' => $destination['sha256']];
            }
            $canonical = $baseline['path'] ?? array_key_first($group['base']);
            $upgrade = ['schema' => self::SCHEMA, 'identity' => $id, 'identity_verified' => $problem === null, 'upstream_choice_allowed' => $problem === null && ! $disabledIdentity && ! $disable,
                'baseline' => $baseline, 'installed' => $installed, 'target' => $destination,
                'candidates' => $group, 'paths' => $paths, 'locally_modified' => $modified, 'reason' => $reason, 'members' => $members];
            $changes[] = ['path' => $canonical, 'kind' => 'mod_upgrade', 'action' => $action, 'mod_upgrade' => $upgrade, 'identity' => $id,
                'metadata' => ['name' => $id, 'version' => $installed['version'] ?? null, 'loader' => $destination['loader'] ?? null],
                'rule' => $invariant !== null ? 'immutable' : ($protected ? 'protect' : ($replace ? 'replace' : null)),
                'rule_origin' => $invariant ?? 'Embedded mod identity; member protection rules', 'effective_rule' => null,
                'matching_rules' => array_values(array_unique(array_merge(...array_column($decisions, 'matching_rules')))),
                'destructive' => $action === 'upgrade_mod', 'approval_required' => $blocked || $action === 'upgrade_mod',
                'file_state' => $class, 'classification' => $class, 'base_sha256' => $baseline['sha256'] ?? null,
                'local_sha256' => $installed['sha256'] ?? null, 'target_sha256' => $destination['sha256'] ?? null, 'blocked' => $blocked];
            foreach ($paths as $path) { $consumed[$path] = true; }
        }
        return ['changes' => $changes, 'consumed' => $consumed, 'verified' => $verified, 'ambiguous' => $ambiguous];
    }

    /** Expand only the already approved physical mutations; UI and approval retain one logical row. */
    public static function mutations(array $changes): array
    {
        $result = [];
        foreach ($changes as $change) {
            if (($change['kind'] ?? null) === 'mod_upgrade') {
                foreach ($change['mod_upgrade']['members'] ?? [] as $member) { $result[] = [...$member, 'action' => $member['action'] === 'install' ? 'add' : 'remove']; }
            } else { $result[] = $change; }
        }
        return $result;
    }
}
