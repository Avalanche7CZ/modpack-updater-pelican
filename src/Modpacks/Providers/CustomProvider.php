<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use Arcturus\ModpackManager\Remote\CapabilityUnavailable;
use RuntimeException;

/** An operator-owned schema-2 recipe pins exact HTTPS artifacts and their upstream hashes. */
final class CustomProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    public function search(string $query, int $page = 0): array
    {
        throw new CapabilityUnavailable('Custom recipes have no public search index; enter the operator-approved recipe or catalog URL');
    }

    public function project(string $source): array
    {
        if (str_starts_with($source, 'https://')) {
            return $this->http->get($source);
        }
        $root = realpath((string) config('arcturus-modpack-manager.custom_manifest_directory'));
        $path = realpath($source);
        if (! $root || ! $path || ! str_starts_with($path, $root.'/') || is_link($source) || ! is_file($path) || filesize($path) > 16777216) {
            throw new RuntimeException('Custom recipe must be in the operator-owned manifest directory');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function releases(array $data): array
    {
        if (($data['schema'] ?? null) !== 2) {
            throw new RuntimeException('Custom recipes and catalogs must use schema 2');
        }
        $items = isset($data['releases']) ? $data['releases'] : [$data];
        if (! is_array($items) || ! array_is_list($items) || ! $items || count($items) > 1000) {
            throw new RuntimeException('Custom release catalog must contain 1-1000 releases');
        }
        $seen = [];
        foreach ($items as $release) {
            $version = $release['version'] ?? null;
            if (! is_string($version) || trim($version) === '' || strlen($version) > 120 || isset($seen[$version]) || ! is_array($release['downloads'] ?? null) || ! $release['downloads']) {
                throw new RuntimeException('Custom release catalog has missing, duplicate or incomplete release entries');
            }
            $seen[$version] = true;
        }
        if (isset($data['latest'])) {
            if (! is_string($data['latest']) || ! isset($seen[$data['latest']])) {
                throw new RuntimeException('Custom catalog latest pointer has no exact release');
            }
            usort($items, fn ($a, $b) => ($b['version'] === $data['latest']) <=> ($a['version'] === $data['latest']));
        }

        return $items;
    }

    public function versions(string $source): array
    {
        return array_map(fn ($release) => ['id' => $release['version'], 'name' => $release['name'] ?? $release['version'],
            'stable' => ($release['stable'] ?? true) === true], $this->releases($this->project($source)));
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        foreach ($this->releases($this->project($source)) as $release) {
            if ($release['version'] !== $version) {
                continue;
            }

            return new Release('custom', $source, $version, $release['name'] ?? $version,
                $release['downloads'], $release['runtime'] ?? [], [], $release['format'] ?? 'files');
        }
        throw new RuntimeException('Custom catalog does not contain the exact requested upstream release');
    }
}
