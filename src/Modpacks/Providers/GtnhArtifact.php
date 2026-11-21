<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

use InvalidArgumentException;

final class GtnhArtifact
{
    public function __construct(
        public readonly string $version,
        public readonly string $variant,
        public readonly string $url,
        public readonly string $filename,
    ) {
        $java = $variant === 'java8' ? '8' : '17-(?:1[7-9]|[2-9][0-9]|[1-9][0-9]{2,18})';
        if (! isset(GtnhProvider::VARIANTS[$variant]) || ! GtnhProvider::versionId($version)
            || ! preg_match('~^GT_New_Horizons_'.preg_quote($version, '~').'_Server_Java_'.$java.'\.zip$~D', $filename)
            || ! in_array($url, array_map(fn ($path) => 'https://'.GtnhProvider::HOST.'/ServerPacks/'.$path.$filename, ['', 'betas/']), true)) {
            throw new InvalidArgumentException('Invalid exact official GTNH archive identity.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['variant'], $data['url'], $data['filename']);
    }

    public function identity(): array
    {
        return ['provider' => GtnhProvider::SOURCE, 'version' => $this->version, 'variant' => $this->variant,
            'url' => $this->url, 'filename' => $this->filename];
    }

    public function id(): string
    {
        return hash('sha256', json_encode($this->identity(), JSON_THROW_ON_ERROR));
    }

    public function reviewedPin(): ?array
    {
        // The bundled digests were reviewed for the canonical stable download URLs.
        if ($this->url !== 'https://'.GtnhProvider::HOST.'/ServerPacks/'.$this->filename) {
            return null;
        }
        $pin = GtnhArchivePins::FILES[$this->filename] ?? null;
        return $pin === null ? null : [...$pin, 'level' => 'reviewed_pin', 'provenance' => 'bundled_reviewed_https_measurement'];
    }
}
