<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use Arcturus\ModpackManager\Contracts\ModpackProvider;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Modpacks\Release;
use InvalidArgumentException;
use RuntimeException;

final class GtnhProvider implements ModpackProvider
{
    public const SOURCE = 'gtnh';
    public const HOST = 'downloads.gtnewhorizons.com';
    public const INDEX = 'https://'.self::HOST.'/versions.json';
    public const VARIANTS = ['java8' => 'Java 8', 'java17plus' => 'Java 17+'];

    public function __construct(private readonly JsonClient $http) {}

    public function search(string $query, int $page = 0): array
    {
        return $page === 0 && (trim($query) === '' || stripos('GTNH GregTech New Horizons', trim($query)) !== false)
            ? [$this->project(self::SOURCE)] : [];
    }

    public function project(string $source): array
    {
        $this->requireSource($source);

        return ['id' => self::SOURCE, 'name' => 'GregTech: New Horizons'];
    }

    public function versions(string $source): array
    {
        return $this->list($this->catalog($source));
    }

    public function latest(string $source, ?string $asset = null): Release
    {
        $catalog = $this->catalog($source);
        foreach ($this->list($catalog) as $version) {
            if ($version['stable']) {
                return $this->resolve($version['id'], $catalog[$version['id']], $asset);
            }
        }
        throw new RuntimeException('No stable GTNH server release found');
    }

    public function release(string $source, string $version, ?string $asset = null): Release
    {
        $catalog = $this->catalog($source);
        if (! self::versionId($version) || ! is_array($catalog[$version] ?? null)) {
            throw new RuntimeException('GTNH catalog does not contain the exact requested server release');
        }

        return $this->resolve($version, $catalog[$version], $asset);
    }

    private function requireSource(string $source): void
    {
        if ($source !== self::SOURCE) {
            throw new InvalidArgumentException('GTNH uses the fixed official catalog; the source must be gtnh');
        }
    }

    private function catalog(string $source): array
    {
        $this->requireSource($source);
        $data = $this->http->get(self::INDEX);
        $catalog = $data['versions'] ?? $data;
        if (! is_array($catalog) || $catalog === [] || array_is_list($catalog)) {
            throw new RuntimeException('GTNH official version catalog is invalid');
        }

        return $catalog;
    }

    private static function versionId(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9][a-z0-9.-]{0,60})?$/iD', $version) === 1;
    }

    private function list(array $catalog): array
    {
        $versions = [];
        foreach ($catalog as $id => $entry) {
            if (! is_string($id) || ! self::versionId($id) || ! is_array($entry['server'] ?? null)) {
                continue;
            }
            $versions[] = ['id' => $id, 'name' => 'GTNH '.$id, 'date' => is_string($entry['releaseDate'] ?? null) ? $entry['releaseDate'] : null,
                'stable' => ! str_contains($id, '-') && ($entry['title'] ?? null) === 'Stable release'];
        }
        usort($versions, fn ($a, $b) => version_compare($b['id'], $a['id']));

        return $versions;
    }

    private function resolve(string $version, array $entry, ?string $variant): Release
    {
        if (! isset(self::VARIANTS[$variant ?? ''])) {
            throw new InvalidArgumentException('Select the GTNH server ZIP variant matching the installed server (Java 8 or Java 17+)');
        }
        $url = $entry['server'][$variant === 'java8' ? 'java8Url' : 'java17_2XUrl'] ?? null;
        $maxJava = $variant === 'java8' ? 8 : ($entry['maxJavaVersion'] ?? null);
        $runtime = ['minecraft' => '1.7.10', 'java' => $variant === 'java8' ? 8 : 17, 'java_max' => $maxJava];
        $javaSuffix = $variant === 'java8' ? '8' : '17-'.(is_int($maxJava) ? $maxJava : '');
        $filename = 'GT_New_Horizons_'.$version.'_Server_Java_'.$javaSuffix.'.zip';
        $stableUrl = 'https://'.self::HOST.'/ServerPacks/'.$filename;
        $betaUrl = 'https://'.self::HOST.'/ServerPacks/betas/'.$filename;
        $downloads = [];
        $blockers = [];
        if (! is_int($maxJava) || $maxJava < $runtime['java'] || ! in_array($url, [$stableUrl, $betaUrl], true)) {
            $blockers[] = 'GTNH catalog has no valid official server ZIP for the selected exact release and Java variant.';
        } else {
            // The upstream catalog lists URLs, not checksums. Pins are reviewed
            // independently of the worker and are never taken from live files.
            $pin = GtnhArchivePins::FILES[$filename] ?? null;
            if ($pin === null) {
                $blockers[] = 'No reviewed SHA-256 pin for this GTNH server ZIP. Use a verified custom recipe or update the plugin.';
            }
            $downloads[] = ['url' => $url, 'archive' => true, 'hashes' => $pin === null ? [] : ['sha256' => $pin['sha256']]]
                + ($pin === null ? [] : ['size' => $pin['size']]);
        }

        return new Release(self::SOURCE, self::SOURCE, $version, 'GTNH '.$version.' · '.self::VARIANTS[$variant],
            $downloads, $runtime, $blockers, 'zip', 'https://www.gtnewhorizons.com/version-history/');
    }
}
