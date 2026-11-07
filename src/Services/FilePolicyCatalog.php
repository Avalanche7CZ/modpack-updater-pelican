<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;

/** Persisted, timestamped inventory evidence; never a fake live scan. */
final class FilePolicyCatalog
{
    private ?array $targets = null;
    private ?int $targetServer = null;

    private function rawRows(ManagedServer $server): \Generator
    {
        $seen = [];
        foreach (JsonStream::column($server->id, EvidenceView::reportColumn($server->id))->entries(['changes']) as $change) {
            $seen[$change['path']] = true;
            foreach ($change['mod_upgrade']['paths'] ?? [] as $member) { $seen[$member] = true; }
            yield $change;
        }
        foreach (JsonStream::column($server->id, 'file_inventory')->entries(['files']) as $path => $hash) {
            if (! isset($seen[$path])) { yield ['path' => $path, 'local_sha256' => $hash]; }
        }
    }

    public function page(ManagedServer $server, int $page = 1, bool $modsOnly = false, string $classification = '', string $search = ''): array
    {
        $count = 0; $rows = []; $page = max(1, $page); $offset = ($page - 1) * 50;
        foreach ($this->rawRows($server) as $change) {
            $path = $change['path'];
            if (($modsOnly && ! (str_starts_with($path, 'mods/') && preg_match('/\.jar(?:\.disabled)?$/i', $path))) ||
                ($classification !== '' && ($change['file_state'] ?? $change['classification'] ?? 'not_compared') !== $classification)) { continue; }
            if ($search !== '') {
                $name = ($change['metadata']['name'] ?? '').' '.implode(' ', $change['mod_upgrade']['paths'] ?? []); $identity = $change['identity'] ?? '';
                if (! str_contains(strtolower($path.' '.$identity.' '.$name), strtolower($search))) { continue; }
            }
            if ($count >= $offset && count($rows) < 50) { $rows[] = $this->row($server, $change); }
            $count++;
        }
        $pages = max(1, (int) ceil($count / 50));
        if ($page > $pages) { return $this->page($server, $pages, $modsOnly, $classification, $search); }
        return ['rows' => $rows, 'total' => $count, 'pages' => $pages, 'page' => $page];
    }

    public function selected(ManagedServer $server, array $paths, array $positions = []): array
    {
        $wanted = array_fill_keys($paths, true); $rows = [];
        foreach ($positions as $path => $index) {
            if (! isset($wanted[$path]) || ! is_int($index) || $index < 0 || $index > 100000) { throw new \RuntimeException('Invalid comparison row; refresh File Changes.'); }
            $change = EvidenceView::value($server->id, EvidenceView::reportColumn($server->id), '$.changes['.$index.']');
            if (($change['path'] ?? null) !== $path) { throw new \RuntimeException('Comparison changed; refresh File Changes before deciding.'); }
            $rows[$path] = $this->row($server, $change, false);
        }
        if (count($rows) === count($wanted)) { return $rows; }
        foreach ($this->rawRows($server) as $change) {
            if (isset($wanted[$change['path']])) {
                $rows[$change['path']] = $this->row($server, $change, false);
                if (count($rows) === count($wanted)) { break; }
            }
        }
        return $rows;
    }

    public function rows(ManagedServer $server): \Generator
    {
        foreach ($this->rawRows($server) as $change) { yield $this->row($server, $change); }
    }

    private function row(ManagedServer $server, array $change, bool $includeTarget = true): array
    {
        $path = $change['path'];
        $snapshot = ['files' => [$path => $change['local_sha256'] ?? null],
            'identities' => [$path => array_key_exists('identity', $change) ? $change['identity'] : EvidenceView::value($server->id, 'file_inventory', EvidenceView::path('identities', $path))],
            'metadata' => [$path => array_key_exists('metadata', $change) ? $change['metadata'] : EvidenceView::value($server->id, 'file_inventory', EvidenceView::path('metadata', $path))]];
        $rules = (new ProtectionPolicy)->normalize($server->file_rules ?? []);
        $upgrade = $change['mod_upgrade'] ?? null;
        $memberPaths = $upgrade['paths'] ?? [$path];
        $directoryRoots = (new DirectoryPolicy)->protectedRoots($server, ['files' => array_fill_keys($memberPaths, '')]);
        $identity = $change['identity'] ?? $snapshot['identities'][$path] ?? null;
        $meta = $change['metadata'] ?? $snapshot['metadata'][$path] ?? [];
        $classification = $change['file_state'] ?? $change['classification'] ?? 'not_compared';
        $decision = (new ProtectionPolicy)->effective($path, $rules, $identity, $meta['loader'] ?? null, $classification);
        $invariant = ($change['rule'] ?? '') === 'immutable' ? $change['rule_origin'] : SafetyPaths::reason($path, $directoryRoots);
        foreach ($memberPaths as $member) { $invariant ??= SafetyPaths::reason($member, $directoryRoots); }
        $targetPath = $upgrade['target']['path'] ?? (isset($change['target_sha256']) ? $path : null);
        if (! $upgrade && $includeTarget && $identity !== null) {
            if ($this->targets === null || $this->targetServer !== $server->id) {
                $this->targets = []; $this->targetServer = $server->id;
                foreach (JsonStream::column($server->id, EvidenceView::reportColumn($server->id))->entries(['changes']) as $target) {
                    if (isset($target['identity'], $target['target_sha256']) && count($this->targets[$target['identity']] ?? []) < 2) { $this->targets[$target['identity']][] = $target['path']; }
                }
            }
            if (count($this->targets[$identity] ?? []) === 1) { $targetPath = $this->targets[$identity][0]; }
        }
        return [...$decision, 'mod_upgrade' => $upgrade, 'kind' => $change['kind'] ?? 'file', 'path' => $path, 'identity' => $identity, 'is_mod' => str_starts_with($path, 'mods/') && preg_match('/\.jar(?:\.disabled)?$/i', $path) === 1,
            'target_path' => $targetPath,
            'target_version' => $upgrade ? ($upgrade['target']['version'] ?? null) : ($includeTarget ? EvidenceView::value($server->id, 'file_inventory', EvidenceView::path('target_metadata', $targetPath ?? $path).'.version') : null),
            'name' => $meta['name'] ?? null, 'version' => $meta['version'] ?? null,
            'loader' => $meta['loader'] ?? null, 'classification' => $classification, 'invariant' => $invariant,
            'action' => $change['action'] ?? 'not_compared', 'local_sha256' => $snapshot['files'][$path] ?? $change['local_sha256'] ?? null,
            'target_sha256' => $change['target_sha256'] ?? null, 'base_sha256' => $change['base_sha256'] ?? null,
            'disabled' => ($change['action'] ?? '') === 'keep_mod_disabled' || str_ends_with(strtolower($path), '.disabled') || ($identity !== null && array_intersect(explode('+', strtolower($identity)), array_map('strtolower', $server->disabled_mods ?? [])) !== []) || $decision['mode'] === 'disable',
            'identity_status' => $upgrade ? (($upgrade['identity_verified'] ?? false) ? 'Upstream manifest and file hashes' : 'Identity review required; replacement disabled') : (($change['action'] ?? '') === 'unsafe_ambiguous_identity' ? 'Ambiguous identity; review mappings' : ($identity === null ? 'File tracked by upstream manifest' : (str_contains($identity, '+') ? 'Multiple metadata IDs; use exact rule or mapping' : 'Metadata / administrator mapping'))),
        ];
    }
}
