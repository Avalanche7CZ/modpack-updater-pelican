<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;

/** Persisted, timestamped inventory evidence; never a fake live scan. */
final class FilePolicyCatalog
{
    public function rows(ManagedServer $server): array
    {
        $snapshot = $server->file_inventory ?? [];
        $changes = $server->preview_summary['changes'] ?? $server->adoption_report['changes'] ?? [];
        $byPath = array_column($changes, null, 'path');
        $paths = array_unique(array_merge(array_keys($snapshot['files'] ?? []), array_keys($byPath)));
        $rules = (new ProtectionPolicy)->normalize($server->file_rules ?? []);
        $targetsById = [];
        foreach ($changes as $change) {
            if (($change['identity'] ?? null) !== null && ($change['target_sha256'] ?? null) !== null) {
                $targetsById[$change['identity']][] = $change;
            }
        }
        $rows = [];
        foreach ($paths as $path) {
            $change = $byPath[$path] ?? [];
            $identity = $change['identity'] ?? $snapshot['identities'][$path] ?? null;
            $meta = $change['metadata'] ?? $snapshot['metadata'][$path] ?? [];
            $classification = $change['file_state'] ?? $change['classification'] ?? 'not_compared';
            $decision = (new ProtectionPolicy)->effective($path, $rules, $identity, $meta['loader'] ?? null, $classification);
            $invariant = SafetyPaths::reason($path, $snapshot['excluded_roots'] ?? []);
            $targetCandidates = $targetsById[$identity ?? ''] ?? [];
            $targetChange = count($targetCandidates) === 1 ? $targetCandidates[0] : null;
            $rows[] = [...$decision, 'path' => $path, 'identity' => $identity, 'is_mod' => str_starts_with($path, 'mods/') && preg_match('/\.jar(?:\.disabled)?$/i', $path) === 1,
                'target_path' => $targetChange['path'] ?? (isset($change['target_sha256']) ? $path : null),
                'target_version' => $snapshot['target_metadata'][$targetChange['path'] ?? $path]['version'] ?? null,
                'name' => $meta['name'] ?? null, 'version' => $meta['version'] ?? null,
                'loader' => $meta['loader'] ?? null, 'classification' => $classification, 'invariant' => $invariant,
                'action' => $change['action'] ?? 'not_compared', 'local_sha256' => $snapshot['files'][$path] ?? $change['local_sha256'] ?? null,
                'target_sha256' => $change['target_sha256'] ?? null, 'base_sha256' => $change['base_sha256'] ?? null,
                'disabled' => str_ends_with(strtolower($path), '.disabled') || ($identity !== null && array_intersect(explode('+', strtolower($identity)), array_map('strtolower', $server->disabled_mods ?? [])) !== []) || $decision['mode'] === 'disable',
                'identity_status' => ($change['action'] ?? '') === 'unsafe_ambiguous_identity' ? 'Ambiguous identity; review mappings' : ($identity === null ? 'Ambiguous / unavailable' : (str_contains($identity, '+') ? 'Multiple metadata IDs; use exact rule or mapping' : 'Metadata / administrator mapping')),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $rows;
    }
}
