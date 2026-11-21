<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Modpacks\ProviderRegistry;

final class ReleaseDiscoverer
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function discover(ManagedServer $server): array
    {
        $provider = $this->providers->get($server->provider);
        $release = $provider->latest($server->source, $server->asset_pattern);
        $artifact = count($release->downloads) === 1 && ($release->downloads[0]['archive'] ?? false) ? $release->downloads[0] : null;

        $archiveTrust = app(GtnhArchiveTrust::class);
        $trust = $release->gtnhArtifact ? $archiveTrust->evidence($release->gtnhArtifact) : null;
        $sha = $release->gtnhArtifact && $archiveTrust->usable($trust, $server) ? $trust['sha256'] : ($artifact['hashes']['sha256'] ?? null);

        return ['version' => $release->version, 'asset_url' => $artifact['url'] ?? null,
            'sha256' => $sha, 'release_url' => $release->url,
            'metadata' => ['name' => $release->name, 'notes' => $release->notes, 'runtime' => $release->runtime,
                'preparation_supported' => ($release->canPrepare() || $release->canInspectArchive()) && ($trust['level'] ?? null) !== 'rejected', 'blockers' => $release->blockers,
                'download_bytes' => $trust['size'] ?? $release->bytes(), 'archive_trust' => $trust, 'deployable' => false]];
    }
}
