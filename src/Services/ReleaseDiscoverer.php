<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only release discovery; never downloads or executes a modpack.
 * All URLs are constructed from validated identifiers and fixed API hosts.
 */
final class ReleaseDiscoverer
{
    /** @return array{version:string,asset_url:?string,sha256:?string,release_url:?string} */
    public function discover(ManagedServer $server): array
    {
        return match ($server->provider) {
            'github' => $this->github($server->source, $server->asset_pattern),
            'modrinth' => $this->modrinth($server->source),
            'ftb' => $this->ftb($server->source),
            'custom' => $this->custom($server->source),
            default => throw new InvalidArgumentException('Unsupported modpack provider'),
        };
    }

    private function request(string $url): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => config('modpack-manager.user_agent', 'Pelican-Modpack-Manager/0.2'),
        ])->timeout((int) config('modpack-manager.release_timeout_seconds', 20))
            ->get($url)->throw();
        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('Provider did not return a JSON object');
        }
        return $json;
    }

    private function github(string $source, ?string $assetPattern): array
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $source)) {
            throw new InvalidArgumentException('GitHub source must be owner/repository');
        }
        $data = $this->request("https://api.github.com/repos/{$source}/releases/latest");
        $version = (string) ($data['tag_name'] ?? '');
        if ($version === '' || strlen($version) > 120) {
            throw new RuntimeException('No usable stable GitHub release tag');
        }
        $matching = [];
        foreach ($data['assets'] ?? [] as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            if (!str_ends_with(strtolower($name), '.zip')) {
                continue;
            }
            if ($assetPattern !== null && $assetPattern !== '' && !fnmatch($assetPattern, $name, FNM_CASEFOLD)) {
                continue;
            }
            $matching[] = $asset;
        }
        $asset = count($matching) === 1 ? $matching[0] : null;
        // Ambiguous assets are intentionally not deployable.
        $digest = (string) ($asset['digest'] ?? '');
        $sha = str_starts_with($digest, 'sha256:') ? substr($digest, 7) : null;
        if ($sha !== null && preg_match('/^[a-f0-9]{64}$/iD', $sha) !== 1) {
            $sha = null;
        }
        return [
            'version' => $version,
            'asset_url' => $asset ? (string) ($asset['browser_download_url'] ?? '') : null,
            'sha256' => $sha,
            'release_url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : null,
        ];
    }

    private function modrinth(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            throw new InvalidArgumentException('Invalid Modrinth project ID or slug');
        }
        $items = $this->request("https://api.modrinth.com/v2/project/{$id}/version");
        $version = null;
        foreach ($items as $item) {
            if (is_array($item) && ($item['version_type'] ?? '') === 'release') {
                $version = $item;
                break;
            }
        }
        if (!$version) {
            throw new RuntimeException('No stable Modrinth project version');
        }
        $files = array_values(array_filter($version['files'] ?? [], fn ($file) =>
            is_array($file) && preg_match('/server.*\.zip$/iD', (string) ($file['filename'] ?? '')) === 1
        ));
        $file = count($files) === 1 ? $files[0] : null;
        $sha = $file['hashes']['sha256'] ?? null;
        return [
            'version' => (string) ($version['version_number'] ?? $version['id']),
            'asset_url' => $file ? (string) ($file['url'] ?? '') : null,
            'sha256' => is_string($sha) && preg_match('/^[a-f0-9]{64}$/iD', $sha) ? $sha : null,
            'release_url' => 'https://modrinth.com/modpack/' . rawurlencode($id) . '/versions',
        ];
    }

    private function ftb(string $id): array
    {
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $id)) {
            throw new InvalidArgumentException('FTB source must be a numeric pack ID');
        }
        $data = $this->request("https://api.feed-the-beast.com/v1/modpacks/public/modpack/{$id}");
        $versions = $data['versions'] ?? [];
        if (!is_array($versions)) {
            throw new RuntimeException('Invalid FTB versions response');
        }
        foreach ($versions as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'release') {
                $version = (string) ($item['id'] ?? '');
                if ($version === '') {
                    continue;
                }
                // The FTB installer is mandatory: an API version is not a downloadable ZIP.
                return ['version' => $version, 'asset_url' => null, 'sha256' => null,
                    'release_url' => 'https://www.feed-the-beast.com/modpacks/' . $id];
            }
        }
        throw new RuntimeException('No stable FTB release found');
    }

    private function custom(string $source): array
    {
        $url = parse_url($source);
        $host = strtolower((string) ($url['host'] ?? ''));
        $allowed = array_map('strtolower', (array) config('modpack-manager.allowed_custom_hosts', []));
        if (($url['scheme'] ?? '') !== 'https' || !$host ||
            !in_array($host, $allowed, true) || isset($url['user']) || isset($url['pass'])) {
            throw new InvalidArgumentException('Custom manifest HTTPS host is not allow-listed');
        }
        // SSRF defense also requires disabling redirects and blocking nonpublic DNS resolutions
        // on the runtime network. Custom fetch is disabled rather than assuming those protections.
        throw new RuntimeException('Custom URL discovery is not enabled until network isolation is configured');
    }
}
