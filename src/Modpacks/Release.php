<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks;

use Arcturus\ModpackManager\Security\DownloadPolicy;
use Arcturus\ModpackManager\Security\ManagedPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use InvalidArgumentException;

/** Provider metadata and preparation capability are deliberately separate. */
final class Release
{
    public readonly array $blockers;

    public function __construct(
        public readonly string $provider, public readonly string $project,
        public readonly string $version, public readonly string $name,
        public readonly array $downloads = [], public readonly array $runtime = [],
        array $blockers = [], public readonly string $format = 'files',
        public readonly ?string $url = null, public readonly string $notes = '',
    ) {
        if ($project === '' || $version === '' || strlen($version) > 120 || count($downloads) > 100000) {
            throw new InvalidArgumentException('Invalid normalized release identity');
        }
        $paths = [];
        foreach ($downloads as $file) {
            if (! is_array($file) || (! is_string($file['url'] ?? null) && ! is_string($file['artifact_id'] ?? null)) || ! is_array($file['hashes'] ?? null)) {
                throw new InvalidArgumentException('Incomplete normalized download');
            }
            if (isset($file['url'])) {
                (new DownloadPolicy)->validate($file['url'], array_merge(DownloadPolicy::HOSTS,
                    function_exists('config') ? config('arcturus-modpack-manager.allowed_custom_hosts', []) : []));
            }
            if (isset($file['artifact_id']) && ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $file['artifact_id'])) {
                throw new InvalidArgumentException('Invalid trusted artifact ID');
            }
            if (isset($file['path'])) {
                UpdatePlanner::assertPath($file['path']);
                if (isset($paths[$file['path']])) {
                    throw new InvalidArgumentException('Duplicate normalized download path');
                }
                $paths[$file['path']] = true;
                if (! ManagedPaths::allowed($file['path'])) {
                    $blockers[] = 'File is outside the managed pack scope: '.$file['path'];
                }
            } elseif (! ($file['archive'] ?? false)) {
                throw new InvalidArgumentException('Download path missing');
            }
            foreach ($file['hashes'] as $algorithm => $hash) {
                $length = ['sha256' => 64, 'sha512' => 128, 'sha1' => 40][$algorithm] ?? 0;
                if (! $length || ! is_string($hash) || preg_match('/^[a-f0-9]{'.$length.'}$/iD', $hash) !== 1) {
                    throw new InvalidArgumentException('Invalid normalized checksum');
                }
            }
            if (isset($file['size']) && (! is_int($file['size']) || $file['size'] < 0)) {
                throw new InvalidArgumentException('Invalid normalized file size');
            }
        }
        $this->blockers = array_values(array_unique($blockers));
    }

    public function canPrepare(): bool
    {
        return $this->blockers === [] && $this->downloads !== [] &&
            count(array_filter($this->downloads, fn ($f) => $f['hashes'] === [])) === 0;
    }

    public function recipe(): array
    {
        return ['provider' => $this->provider, 'project' => $this->project, 'version' => $this->version,
            'downloads' => $this->downloads, 'runtime' => $this->runtime, 'format' => $this->format,
            'blockers' => $this->blockers];
    }

    public function bytes(): ?int
    {
        if (! $this->downloads || count(array_filter($this->downloads, fn ($f) => ! isset($f['size']))) > 0 ||
            in_array($this->format, ['mrpack', 'curseforge'], true)) {
            return null;
        }

        return array_sum(array_column($this->downloads, 'size'));
    }
}
