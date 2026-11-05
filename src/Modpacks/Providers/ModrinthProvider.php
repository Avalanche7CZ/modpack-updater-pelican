<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class ModrinthProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    private function id(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            throw new InvalidArgumentException('Invalid Modrinth ID');
        }

        return rawurlencode($id);
    }

    public function search(string $query, int $page = 0): array
    {
        return $this->http->get('https://api.modrinth.com/v2/search?facets='.rawurlencode('[["project_type:modpack"]]').'&query='.rawurlencode($query).'&limit=20&offset='.max(0, $page * 20))['hits'] ?? [];
    }

    public function project(string $source): array
    {
        return $this->http->get('https://api.modrinth.com/v2/project/'.$this->id($source));
    }

    public function versions(string $source): array
    {
        return array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['version_number'], 'stable' => $r['version_type'] === 'release', 'date' => $r['date_published']],
            $this->http->get('https://api.modrinth.com/v2/project/'.$this->id($source).'/version'));
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $project = $this->project($source);
        $r = $this->http->get('https://api.modrinth.com/v2/version/'.$this->id($version));
        if (($r['project_id'] ?? null) !== ($project['id'] ?? null) || ($r['id'] ?? null) !== $version) {
            throw new RuntimeException('Modrinth version belongs to another project');
        }
        $files = array_values(array_filter($r['files'] ?? [], fn ($f) => str_ends_with($f['filename'], '.mrpack')));
        $blockers = [];
        $downloads = [];
        if (count($files) !== 1) {
            $blockers[] = 'No unique .mrpack artifact';
        } else {
            $f = $files[0];
            $downloads[] = ['url' => $f['url'], 'archive' => true,
                'hashes' => array_intersect_key($f['hashes'], array_flip(['sha256', 'sha512', 'sha1'])), 'size' => $f['size']];
        }
        if (($r['environment'] ?? '') === 'client_only') {
            $blockers[] = 'This release is client-only';
        }
        $runtime = ['minecraft' => count($r['game_versions'] ?? []) === 1 ? $r['game_versions'][0] : null,
            'loader' => count($r['loaders'] ?? []) === 1 ? $r['loaders'][0] : null, 'runtime_prepared' => false];

        return new Release('modrinth', $source, $version, $r['version_number'], $downloads, $runtime,
            $blockers, 'mrpack', 'https://modrinth.com/modpack/'.$this->id($source).'/version/'.$version, $r['changelog'] ?? '');
    }
}
