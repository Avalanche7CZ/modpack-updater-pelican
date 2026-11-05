<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class CurseForgeProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    private function id(string $id): string
    {
        if (! preg_match('/^[1-9][0-9]{0,10}$/D', $id)) {
            throw new InvalidArgumentException('CurseForge requires numeric IDs');
        }

        return $id;
    }

    public function get(string $path): array
    {
        $key = config('arcturus-modpack-manager.curseforge_api_key');
        if (! $key) {
            throw new RuntimeException('Configure the CurseForge API credential in plugin settings');
        }

        return $this->http->get('https://api.curseforge.com/v1/'.$path, ['x-api-key' => $key])['data'] ?? [];
    }

    public function search(string $query, int $page = 0): array
    {
        return $this->get('mods/search?gameId=432&classId=4471&pageSize=20&index='.max(0, $page * 20).'&searchFilter='.rawurlencode($query));
    }

    public function project(string $source): array
    {
        return $this->get('mods/'.$this->id($source));
    }

    public function versions(string $source): array
    {
        return array_map(fn ($f) => ['id' => (string) $f['id'], 'name' => $f['displayName'], 'stable' => $f['releaseType'] === 1, 'date' => $f['fileDate']],
            $this->get('mods/'.$this->id($source).'/files?pageSize=50'));
    }

    public function resolveFile(string $source, string $version): array
    {
        $f = $this->get('mods/'.$this->id($source).'/files/'.$this->id($version));
        if ((string) ($f['modId'] ?? '') !== $source || (string) ($f['id'] ?? '') !== $version || ! ($f['isAvailable'] ?? false)) {
            throw new RuntimeException('Required CurseForge file is missing or belongs to another project');
        }
        if (! ($f['downloadUrl'] ?? null)) {
            throw new RuntimeException('Author does not permit API distribution of a required CurseForge file');
        }
        $hashes = [];
        foreach ($f['hashes'] ?? [] as $h) {
            if (($h['algo'] ?? null) === 1) {
                $hashes['sha1'] = $h['value'];
            }
        }
        if (! $hashes) {
            throw new RuntimeException('Required CurseForge file has no supported upstream checksum');
        }

        return ['url' => $f['downloadUrl'], 'hashes' => $hashes, 'size' => (int) $f['fileLength'], 'path' => 'mods/'.$f['fileName']];
    }

    /** Complete resolution: a missing/optional/blocked file is never counted as prepared. */
    public function resolveManifest(array $manifest): array
    {
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack' || ($manifest['manifestVersion'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)) {
            throw new InvalidArgumentException('Unsupported CurseForge manifest');
        }
        $files = [];
        foreach ($manifest['files'] as $f) {
            if (($f['required'] ?? null) !== true) {
                throw new RuntimeException('Optional CurseForge file requires explicit administrator selection');
            }
            $files[] = $this->resolveFile((string) $f['projectID'], (string) $f['fileID']);
        }

        return $files;
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $f = $this->get('mods/'.$this->id($source).'/files/'.$this->id($version));
        if ((string) ($f['modId'] ?? '') !== $source || (string) ($f['id'] ?? '') !== $version) {
            throw new RuntimeException('CurseForge release identity mismatch');
        }
        $blockers = [];
        $downloads = [];
        $serverId = ($f['isServerPack'] ?? false) ? $version : (isset($f['serverPackFileId']) ? (string) $f['serverPackFileId'] : null);
        if (! $serverId) {
            $blockers[] = 'No official server pack. Resolve the client manifest and all required files before staging.';
        } else {
            $d = $this->resolveFile($source, $serverId);
            unset($d['path']);
            $d['archive'] = true;
            $downloads[] = $d;
        }

        return new Release('curseforge', $source, $version, $f['displayName'] ?? $version, $downloads, [], $blockers, 'zip',
            'https://www.curseforge.com/minecraft/modpacks/'.$source.'/files/'.$version);
    }
}
