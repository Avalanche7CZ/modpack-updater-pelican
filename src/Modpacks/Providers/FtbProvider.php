<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class FtbProvider implements ModpackProvider
{
    use LatestRelease;

    // Verified against FTBTeam/FTB-Server-Installer repos/ftb.go, not the obsolete /public endpoint.
    private const BASE = 'https://api.feed-the-beast.com/v1/modpacks';

    public function __construct(private readonly JsonClient $http, private readonly CurseForgeProvider $curseforge) {}

    private function id(string $id): string
    {
        if (! preg_match('/^[1-9][0-9]{0,9}$/D', $id)) {
            throw new InvalidArgumentException('FTB requires numeric IDs');
        }

        return $id;
    }

    public function search(string $query, int $page = 0): array
    {
        return $this->http->get(self::BASE.'/public/modpack/search/20?term='.rawurlencode($query))['packs'] ?? [];
    }

    public function project(string $source): array
    {
        return $this->http->get(self::BASE.'/modpack/'.$this->id($source));
    }

    public function versions(string $source): array
    {
        $versions = $this->project($source)['versions'] ?? [];
        usort($versions, fn ($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));

        return array_map(fn ($v) => ['id' => (string) $v['id'], 'name' => $v['name'], 'stable' => strtolower($v['type']) === 'release', 'date' => $v['released'] ?? null], $versions);
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $r = $this->http->get(self::BASE.'/modpack/'.$this->id($source).'/'.$this->id($version));
        if (($r['status'] ?? '') !== 'success' || (string) ($r['id'] ?? '') !== $version || (string) ($r['parent'] ?? '') !== $source || ! is_array($r['files'] ?? null)) {
            throw new RuntimeException('FTB exact release manifest unavailable');
        }
        $downloads = [];
        $blockers = [];
        $runtime = ['runtime_prepared' => false];
        foreach ($r['targets'] ?? [] as $t) {
            if ($t['name'] === 'minecraft') {
                $runtime['minecraft'] = $t['version'];
            }
            if ($t['type'] === 'modloader') {
                $runtime['loader'] = $t['name'];
                $runtime['loader_version'] = $t['version'];
            }
            if ($t['name'] === 'java') {
                $runtime['java'] = (int) $t['version'];
            }
        }
        foreach ($r['files'] as $f) {
            if (($f['clientonly'] ?? false) === true) {
                continue;
            }
            if (($f['optional'] ?? false) === true) {
                $blockers[] = 'Optional FTB file requires selection: '.$f['name'];

                continue;
            }
            $path = ($f['path'] ?? '');
            if (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }
            $path = ($path !== '' ? rtrim($path, '/').'/' : '').$f['name'];
            if (! empty($f['url'])) {
                $hashes = array_intersect_key($f['hashes'] ?? ['sha1' => $f['sha1'] ?? ''], array_flip(['sha256', 'sha512', 'sha1']));
                $downloads[] = ['path' => $path, 'url' => $f['url'], 'hashes' => $hashes, 'size' => (int) $f['size']];
            } elseif (isset($f['curseforge']['project'], $f['curseforge']['file'])) {
                $d = $this->curseforge->resolveFile((string) $f['curseforge']['project'], (string) $f['curseforge']['file']);
                $d['path'] = $path;
                $downloads[] = $d;
            } else {
                $blockers[] = 'Required FTB file has no permitted download: '.$f['name'];
            }
        }

        return new Release('ftb', $source, $version, $r['name'] ?? $version, $downloads, $runtime, $blockers,
            'files', 'https://www.feed-the-beast.com/modpacks/'.$source, $r['changelog'] ?? '');
    }
}
