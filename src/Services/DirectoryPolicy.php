<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Security\ManagedPaths;
use InvalidArgumentException;

/** Upstream evidence is immutable; adoption decisions belong to one server. */
final class DirectoryPolicy
{
    public function normalize(array $decisions): array
    {
        if (count($decisions) > 128) {
            throw new InvalidArgumentException('At most 128 directory decisions are allowed');
        }
        $result = [];
        foreach ($decisions as $row) {
            $name = $row['path'] ?? '';
            $mode = $row['mode'] ?? '';
            ManagedPaths::reviewedAdditions([$name]);
            if (! in_array($mode, ['manage', 'keep', 'exclude'], true) || isset($result[$name])) {
                throw new InvalidArgumentException('Choose one decision per directory');
            }
            $result[$name] = ['path' => $name, 'mode' => $mode];
        }
        ksort($result);

        return array_values($result);
    }

    public function scope(ManagedServer $server): array
    {
        $scope = ['manage' => [], 'exclude' => []];
        foreach ($this->normalize($server->directory_decisions ?? []) as $row) {
            $scope[$row['mode'] === 'manage' ? 'manage' : 'exclude'][] = $row['path'];
        }

        return $scope;
    }

    public function rows(ManagedServer $server): array
    {
        $rows = [];
        foreach ([$server->base_manifest_path, $server->target_manifest_path] as $path) {
            // Legacy schema-1 local manifests have no verified discovery evidence.
            if (! $path || ! is_file($path) || dirname(realpath($path)) !== realpath(storage_path('app/arcturus-modpack-manager/manifests'))) { continue; }
            $manifest = app(ReleaseStore::class)->load($path);
            foreach ($manifest['directory_discovery'] ?? [] as $row) {
                $rows[$row['path']] = $row;
            }
            // Older retained manifests remain usable without a new download.
            foreach (array_keys($manifest['files']) as $file) {
                if (! str_contains($file, '/')) { continue; }
                $name = explode('/', $file)[0];
                $rows[$name] ??= ['path' => $name, 'classification' => in_array($name, ManagedPaths::ROOTS, true) ? 'standard' : 'review'];
            }
        }
        $saved = array_column($this->normalize($server->directory_decisions ?? []), 'mode', 'path');
        foreach ($rows as $name => &$row) {
            $row['mode'] = $saved[$name] ?? ($row['classification'] === 'standard' ? 'manage' : ($row['classification'] === 'protected' ? 'exclude' : 'review'));
        }
        unset($row);
        ksort($rows);

        return array_values($rows);
    }

    public function pending(ManagedServer $server): array
    {
        return array_column(array_filter($this->rows($server), fn ($row) => $row['mode'] === 'review'), 'path');
    }

    public function protectedRoots(ManagedServer $server, array ...$manifests): array
    {
        $modes = array_column($this->normalize($server->directory_decisions ?? []), 'mode', 'path');
        $roots = [];
        foreach ($manifests as $manifest) {
            foreach (array_keys($manifest['files']) as $path) {
                if (! str_contains($path, '/')) { continue; }
                $name = explode('/', $path)[0];
                if (($modes[$name] ?? (in_array($name, ManagedPaths::ROOTS, true) ? 'manage' : 'review')) !== 'manage') {
                    $roots[] = $name;
                }
            }
        }

        return array_values(array_unique($roots));
    }
}
