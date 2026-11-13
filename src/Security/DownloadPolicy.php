<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

use InvalidArgumentException;

/** Exact host allowlists; redirects must be revalidated by the node downloader. */
final class DownloadPolicy
{
    public const HOSTS = ['github.com', 'api.github.com', 'objects.githubusercontent.com',
        'release-assets.githubusercontent.com', 'cdn.modrinth.com', 'api.modrinth.com',
        'api.feed-the-beast.com', 'files.feed-the-beast.com', 'api.modpacks.ch', 'piston-meta.mojang.com',
        'piston-data.mojang.com', 'libraries.minecraft.net', 'api.curseforge.com',
        'edge.forgecdn.net', 'mediafilez.forgecdn.net', 'api.atlauncher.com',
        'download.nodecdn.net', 'downloads.gtnewhorizons.com'];

    public function validate(string $url, array $hosts = self::HOSTS): string
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) ||
            ($p['scheme'] ?? '') !== 'https' || ! in_array($host, $hosts, true) ||
            isset($p['user']) || isset($p['pass']) || isset($p['fragment']) ||
            (isset($p['port']) && $p['port'] !== 443) || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Download URL is outside the HTTPS host policy');
        }

        return $url;
    }
}
