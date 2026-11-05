<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use RuntimeException;

/** An operator-owned schema-2 recipe pins exact HTTPS artifacts and their upstream hashes. */
final class CustomProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    public function search(string $query, int $page = 0): array
    {
        return [];
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

    public function versions(string $source): array
    {
        $d = $this->project($source);

        return [['id' => $d['version'], 'name' => $d['version'], 'stable' => true]];
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $d = $this->project($source);
        if (($d['schema'] ?? null) !== 2 || ($d['version'] ?? null) !== $version || empty($d['downloads'])) {
            throw new RuntimeException('Custom recipe must use schema 2, match the exact version and pin downloadable files');
        }

        return new Release('custom', $source, $version, $d['name'] ?? $version, $d['downloads'], $d['runtime'] ?? [], [], $d['format'] ?? 'files');
    }
}
