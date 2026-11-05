<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\LatestRelease;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class GitHubProvider implements ModpackProvider
{
    use LatestRelease;

    public function __construct(private readonly JsonClient $http) {}

    private function repo(string $source): string
    {
        if (! preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $source)) {
            throw new InvalidArgumentException('GitHub source must be owner/repository');
        }

        return 'https://api.github.com/repos/'.$source;
    }

    public function search(string $query, int $page = 0): array
    {
        return $this->http->get('https://api.github.com/search/repositories?q='.rawurlencode($query).'&per_page=20&page='.max(1, $page + 1))['items'] ?? [];
    }

    public function project(string $source): array
    {
        return $this->http->get($this->repo($source));
    }

    private function stable(array $release): bool
    {
        return ! ($release['draft'] ?? true) && ! ($release['prerelease'] ?? false) &&
            ! preg_match('/(?:^|[-_.])(?:nightly|snapshot|alpha|beta|rc)(?:[-_.0-9]|$)/i', $release['tag_name'] ?? '');
    }

    public function versions(string $source): array
    {
        $items = $this->http->get($this->repo($source).'/releases?per_page=100');

        return array_values(array_map(fn ($r) => ['id' => $r['tag_name'], 'name' => $r['name'] ?: $r['tag_name'],
            'stable' => $this->stable($r), 'date' => $r['published_at'] ?? null],
            array_filter($items, fn ($r) => ! ($r['draft'] ?? true))));
    }

    public function latest(string $source, ?string $asset = null): Release
    {
        $r = $this->http->get($this->repo($source).'/releases/latest');
        if ($this->stable($r)) {
            return $this->release($source, (string) $r['tag_name'], $asset);
        }
        // Some publishers label nightly builds as normal releases. Never promote those automatically.
        for ($page = 1; $page <= 10; $page++) {
            $items = $this->http->get($this->repo($source).'/releases?per_page=100&page='.$page);
            foreach ($items as $release) {
                if ($this->stable($release)) {
                    return $this->release($source, (string) $release['tag_name'], $asset);
                }
            }
            if (count($items) < 100) {
                break;
            }
        }
        throw new RuntimeException('No stable GitHub release found within the bounded release history; select an exact tag');
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $r = $this->http->get($this->repo($source).'/releases/tags/'.rawurlencode($version));
        if (($r['tag_name'] ?? '') !== $version || ($r['draft'] ?? true)) {
            throw new RuntimeException('GitHub exact release does not match');
        }
        $matches = array_values(array_filter($r['assets'] ?? [], fn ($a) => str_ends_with(strtolower($a['name'] ?? ''), '.zip') && $asset && fnmatch($asset, $a['name'], FNM_CASEFOLD)));
        $blockers = [];
        $downloads = [];
        if (count($matches) !== 1) {
            $blockers[] = 'Select one explicit server ZIP asset; matching assets: '.count($matches);
        } else {
            $a = $matches[0];
            $hashes = [];
            if (preg_match('/^sha256:([a-f0-9]{64})$/iD', $a['digest'] ?? '', $m)) {
                $hashes['sha256'] = strtolower($m[1]);
            }
            if (! $hashes) {
                $blockers[] = 'Release asset has no upstream SHA-256 digest. Supply an independently verified custom manifest.';
            }
            $downloads[] = ['url' => $a['browser_download_url'], 'archive' => true, 'hashes' => $hashes, 'size' => (int) $a['size']];
        }

        return new Release('github', $source, $version, $r['name'] ?: $version, $downloads, [], $blockers,
            'zip', $r['html_url'] ?? null, $r['body'] ?? '');
    }
}
