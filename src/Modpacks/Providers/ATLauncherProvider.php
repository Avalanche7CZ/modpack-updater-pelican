<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class ATLauncherProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    private function segment(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_. -]{1,128}$/D', $id) || in_array($id, ['.', '..'], true)) {
            throw new InvalidArgumentException('Invalid ATLauncher identifier');
        }

        return rawurlencode($id);
    }

    public function search(string $query, int $page = 0): array
    {
        $packs = $this->http->get('https://api.atlauncher.com/v1/packs/full/all')['data'] ?? [];

        return array_slice(array_values(array_filter($packs, fn ($p) => str_contains(strtolower($p['name']), strtolower($query)))), max(0, $page * 20), 20);
    }

    public function project(string $source): array
    {
        return $this->http->get('https://api.atlauncher.com/v1/pack/'.$this->segment($source))['data'] ?? throw new RuntimeException('ATLauncher pack unavailable');
    }

    public function versions(string $source): array
    {
        return array_map(fn ($v) => ['id' => $v['version'], 'name' => $v['version'], 'stable' => ! ($v['dev'] ?? false)], $this->project($source)['versions'] ?? []);
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $base = 'https://download.nodecdn.net/containers/atl/packs/'.$this->segment($source).'/versions/'.$this->segment($version).'/';
        $r = $this->http->get($base.'Configs.json');
        $downloads = [];
        $blockers = [];
        foreach ($r['mods'] ?? [] as $m) {
            if (($m['server'] ?? true) === false) {
                continue;
            }
            if (($m['download'] ?? '') === 'browser' || ! empty($m['optional']) || empty($m['url'])) {
                $blockers[] = 'Required ATLauncher selection/download is unavailable: '.($m['name'] ?? 'unknown');

                continue;
            }
            $hashes = array_intersect_key($m, array_flip(['sha256', 'sha512', 'sha1']));
            if (! $hashes) {
                $blockers[] = 'ATLauncher file has no supported upstream integrity hash: '.($m['name'] ?? 'unknown');
            }
            $url = ($m['download'] ?? '') === 'direct' ? $m['url'] : 'https://download.nodecdn.net/containers/atl/'.ltrim($m['url'], '/');
            $downloads[] = ['url' => $url, 'path' => 'mods/'.$m['file'], 'hashes' => $hashes];
        }
        if (! empty($r['configs'])) {
            $blockers[] = 'Configs.zip has no independently verified checksum in this metadata; provide a pinned custom recipe';
        }

        return new Release('atlauncher', $source, $version, $version, $downloads,
            ['minecraft' => $r['minecraft'] ?? null, 'loader' => $r['loader']['type'] ?? null,
                'java' => $r['java']['min'] ?? null, 'runtime_prepared' => false], $blockers);
    }
}
