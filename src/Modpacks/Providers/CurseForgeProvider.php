<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Contracts\RequiredFileResolver;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class CurseForgeProvider implements ModpackProvider, RequiredFileResolver
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

        return ['url' => $f['downloadUrl'], 'hashes' => $hashes, 'size' => (int) $f['fileLength'], 'path' => 'mods/'.$f['fileName'],
            'project_id' => $source, 'file_id' => $version,
            'required_projects' => array_map(fn ($d) => (string) $d['modId'], array_values(array_filter($f['dependencies'] ?? [], fn ($d) => ($d['relationType'] ?? null) === 3)))];
    }

    public function requirements(array $manifest): array
    {
        if (($manifest['manifestType'] ?? null) !== 'minecraftModpack' || ($manifest['manifestVersion'] ?? null) !== 1 ||
            ! is_array($manifest['files'] ?? null) || ! array_is_list($manifest['files']) || count($manifest['files']) > 20000) {
            throw new InvalidArgumentException('Unsupported or oversized CurseForge manifest');
        }
        $requirements = [];
        $seen = [];
        foreach ($manifest['files'] as $file) {
            if (($file['required'] ?? null) !== true) {
                throw new RuntimeException('Optional CurseForge file requires explicit administrator selection');
            }
            $project = $this->id((string) ($file['projectID'] ?? ''));
            $version = $this->id((string) ($file['fileID'] ?? ''));
            if (isset($seen[$project])) {
                throw new RuntimeException('Duplicate CurseForge project in required manifest');
            }
            $seen[$project] = true;
            $requirements[] = ['project_id' => $project, 'file_id' => $version];
        }

        return $requirements;
    }

    public function resolveBatch(array $manifest, array $resolved = [], int $limit = 3): array
    {
        $requirements = $this->requirements($manifest);
        if ($limit < 1 || $limit > 3 || count($resolved) > count($requirements)) {
            throw new InvalidArgumentException('Invalid required-file resolution checkpoint');
        }
        foreach ($resolved as $index => $file) {
            if (($file['project_id'] ?? null) !== $requirements[$index]['project_id'] || ($file['file_id'] ?? null) !== $requirements[$index]['file_id']) {
                throw new RuntimeException('CurseForge resolution checkpoint does not match the required manifest');
            }
        }
        $end = min(count($requirements), count($resolved) + $limit);
        for ($index = count($resolved); $index < $end; $index++) {
            $reference = $requirements[$index];
            $file = $this->resolveFile($reference['project_id'], $reference['file_id']);
            if (! str_ends_with(strtolower($file['path']), '.jar')) {
                throw new RuntimeException('Required CurseForge artifact is not an identifiable mod JAR; explicit server normalization required');
            }
            $resolved[] = $file;
        }
        // Validate paths, duplicate filenames, checksums and hosts before sending any download recipe.
        if ($resolved) {
            new Release('curseforge', 'required-files', 'resolved', 'Required files', $resolved);
        }
        $complete = count($resolved) === count($requirements);
        if ($complete) {
            $projects = array_column($requirements, 'project_id');
            foreach ($resolved as $file) {
                foreach ($file['required_projects'] as $dependency) {
                    if (! in_array($dependency, $projects, true)) {
                        throw new RuntimeException('Required CurseForge dependency has no pinned file in the pack manifest: '.$dependency);
                    }
                }
            }
        }

        return ['files' => $resolved, 'complete' => $complete, 'total' => count($requirements)];
    }

    /** Completeness is mandatory; queue ticks use resolveBatch to retain bounded checkpoints. */
    public function resolveManifest(array $manifest): array
    {
        $files = [];
        do {
            $result = $this->resolveBatch($manifest, $files);
            $files = $result['files'];
        } while (! $result['complete']);

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
        $format = $serverId ? 'zip' : 'curseforge';
        if (! $serverId) {
            $d = $this->resolveFile($source, $version);
            unset($d['path']);
            $d['archive'] = true;
            $downloads[] = $d;
        } else {
            $d = $this->resolveFile($source, $serverId);
            unset($d['path']);
            $d['archive'] = true;
            $downloads[] = $d;
        }

        return new Release('curseforge', $source, $version, $f['displayName'] ?? $version, $downloads, ['runtime_prepared' => false], $blockers, $format,
            'https://www.curseforge.com/minecraft/modpacks/'.$source.'/files/'.$version);
    }
}
