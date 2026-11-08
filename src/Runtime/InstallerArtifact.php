<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Runtime;

use Arcturus\ModpackManager\Security\DownloadPolicy;
use Arcturus\ModpackManager\Security\LimitedStream;
use Arcturus\ModpackManager\Security\PublicAddress;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Only exact official Forge/NeoForge installer coordinates, never an administrator-supplied executable URL. */
class InstallerArtifact
{
    public const HOSTS = ['maven.neoforged.net', 'maven.minecraftforge.net'];
    public function url(array $target): string
    {
        $loader = $target['loader'] ?? ''; $mc = $target['minecraft'] ?? ''; $version = $target['loader_version'] ?? '';
        RuntimePlan::minimumJava($mc);
        if (! in_array($loader, ['forge', 'neoforge'], true) || ! is_string($version) || ! preg_match('/^[0-9][A-Za-z0-9_.+-]{0,100}$/D', $version) ||
            ($loader === 'forge' && version_compare($mc, '1.17', '<'))) { throw new RuntimeException('Only modern Forge/NeoForge installer preparation is supported.'); }
        $coordinate = $loader === 'forge' ? $mc.'-'.$version : $version;
        return $loader === 'forge' ? 'https://maven.minecraftforge.net/net/minecraftforge/forge/'.$coordinate.'/forge-'.$coordinate.'-installer.jar'
            : 'https://maven.neoforged.net/releases/net/neoforged/neoforge/'.$coordinate.'/neoforge-'.$coordinate.'-installer.jar';
    }

    public function obtain(array $target, string $cache): array
    {
        $url = $this->url($target); $algorithm = null; $checksum = null;
        foreach (['sha256' => 64, 'sha1' => 40] as $candidate => $length) {
            $raw = $this->get($url.'.'.$candidate, 4096, true);
            if ($raw === null) { continue; }
            if (! preg_match('/^\s*([a-fA-F0-9]{'.$length.'})(?:\s|$)/D', $raw, $match)) { throw new RuntimeException('Official installer checksum response is malformed.'); }
            $algorithm = $candidate; $checksum = strtolower($match[1]); break;
        }
        if ($checksum === null) { throw new RuntimeException('Official Maven provides no supported installer checksum.'); }
        if (! is_dir($cache) && ! mkdir($cache, 0700, true)) { throw new RuntimeException('Cannot create private installer cache.'); }
        if (is_link($cache) || realpath($cache) !== $cache || fileperms($cache) & 0077 || fileowner($cache) !== posix_geteuid()) { throw new RuntimeException('Installer cache must be private and owned by the queue account.'); }
        $path = $cache.'/'.hash('sha256', $url.' '.$algorithm.' '.$checksum).'.jar';
        if (is_link($path)) { throw new RuntimeException('Installer cache entry cannot be a symlink.'); }
        if (! is_file($path) || hash_file($algorithm, $path) !== $checksum) {
            $temp = $cache.'/.'.bin2hex(random_bytes(16));
            try {
                $this->get($url, 134217728, false, $temp);
                if (hash_file($algorithm, $temp) !== $checksum) { throw new RuntimeException('Official installer checksum mismatch.'); }
                chmod($temp, 0600);
                if (! rename($temp, $path)) { throw new RuntimeException('Cannot commit verified installer cache entry.'); }
            } finally { if (is_file($temp)) { unlink($temp); } }
        }
        return ['path' => $path, 'url' => $url, 'sha256' => hash_file('sha256', $path), 'hashes' => [$algorithm => $checksum]];
    }

    /** Bounded TLS transfer. Redirects and user-controlled hosts are not accepted. */
    protected function get(string $url, int $limit, bool $optional, ?string $path = null): ?string
    {
        (new DownloadPolicy)->validate($url, self::HOSTS);
        PublicAddress::resolve(parse_url($url, PHP_URL_HOST)); // Reject non-public DNS before any request.
        $stream = $path ? fopen($path, 'x+b') : fopen('php://temp/maxmemory:4096', 'w+b');
        if (! $stream) { throw new RuntimeException('Cannot create a bounded installer download stream.'); }
        $sink = new LimitedStream(Utils::streamFor($stream), $limit);
        try {
            $response = Http::withoutRedirecting()->connectTimeout(10)->timeout(300)
                ->withOptions(['sink' => $sink])->get($url);
            if ($optional && $response->status() === 404) { return null; }
            if (! $response->successful()) { throw new RuntimeException('Official installer download failed (HTTP '.$response->status().').'); }
            if ($path !== null) { return ''; }
            $sink->rewind(); return $sink->getContents();
        } finally { $sink->close(); }
    }
}
