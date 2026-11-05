<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Prepares a verified server pack outside the live server root.
 * Never runs an arbitrary script from an archive.
 */
final class PrepareRelease
{
    public function __construct(
        private readonly DirectoryInventory $inventory,
        private readonly ManifestJson $manifests,
    ) {}

    /** @return array{root:string,manifest:string,artifact_sha256:?string} */
    public function prepare(ManagedServer $server): array
    {
        if (!$server->available_version || !$server->staging_parent) {
            throw new RuntimeException('Missing detected release or staging parent');
        }
        $staging = realpath($server->staging_parent);
        if (!$staging || is_link($server->staging_parent) || !is_dir($staging)) {
            throw new RuntimeException('Staging parent must be an existing real directory');
        }
        $allow = (array) config('arcturus-modpack-manager.allowed_live_roots', []);
        $live = realpath((string) $server->local_root);
        if (!$live || !is_dir($live) || is_link($server->local_root)) {
            throw new RuntimeException('Live root must exist for update preparation');
        }
        // Preparation and backup must never share live server data.
        if ($staging === $live || str_starts_with($staging . '/', $live . '/') ||
            str_starts_with($live . '/', $staging . '/')) {
            throw new RuntimeException('Staging directory overlaps live server');
        }
        $allowed = false;
        foreach ($allow as $dir) {
            $base = realpath($dir);
            if ($base && ($live === $base || str_starts_with($live, rtrim($base, '/') . '/'))) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new RuntimeException('Live root is not within ARCTURUS_MODPACK_MANAGER_ALLOWED_LIVE_ROOTS');
        }

        $work = $staging . '/arcturus-mpm-' . $server->id . '-' . bin2hex(random_bytes(6));
        $filesDir = $work . '/files';
        if (!@mkdir($filesDir, 0700, true)) {
            throw new RuntimeException('Cannot create staging directory');
        }
        try {
            $artifactSha = null;
            if ($server->provider === 'ftb') {
                $this->installFtb($server, $filesDir);
            } else {
                if (!$server->available_asset_url) {
                    throw new RuntimeException(
                        'No unambiguous server ZIP found. Choose an explicit server ZIP asset or use manual staging.'
                    );
                }
                $archive = $work . '/serverpack.zip';
                $artifactSha = $this->download($server, $archive);
                $this->extractZip($archive, $filesDir);
                @unlink($archive);
            }
            $actualRoot = $this->effectiveRoot($filesDir);
            $files = $this->inventory->hashTree(
                $actualRoot, 100000, (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592)
            );
            if (!$files) {
                throw new RuntimeException('Extracted server pack is empty');
            }
            $manifestPath = $work . '/target.manifest.json';
            if (@file_put_contents($manifestPath,
                $this->manifests->encode($server->available_version, $files)) === false) {
                throw new RuntimeException('Could not store immutable target manifest');
            }
            return ['root' => $actualRoot, 'manifest' => $manifestPath,
                'artifact_sha256' => $artifactSha];
        } catch (Throwable $e) {
            // Incomplete work is kept in the dedicated staging folder for diagnosis.
            // No live server files were touched.
            throw new RuntimeException('Preparation failed (' . $work . '): ' . $e->getMessage(), previous: $e);
        }
    }

    private function installFtb(ManagedServer $server, string $directory): void
    {
        $binary = (string) config('arcturus-modpack-manager.ftb_installer_binary', '');
        if ($binary === '' || !str_starts_with($binary, '/') ||
            is_link($binary) || !is_file($binary) || !is_executable($binary)) {
            throw new RuntimeException('Configure a trusted executable ARCTURUS_MODPACK_MANAGER_FTB_INSTALLER_BINARY');
        }
        if (!ctype_digit($server->source) || !ctype_digit($server->available_version)) {
            throw new RuntimeException('Invalid FTB pack/version IDs');
        }
        $command = [
            $binary, '-pack', $server->source, '-version', $server->available_version,
            '-dir', $directory, '-auto', '-validate', '-no-java',
        ];
        $process = new Process($command, $directory);
        $process->setTimeout(1800);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('FTB installer exited with code ' . $process->getExitCode() .
                ': ' . substr($process->getErrorOutput(), -1500));
        }
    }

    private function download(ManagedServer $server, string $destination): string
    {
        $url = (string) $server->available_asset_url;
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $trusted = match ($server->provider) {
            'github' => $host === 'github.com',
            'modrinth' => in_array($host, [
                'cdn.modrinth.com', 'cdn-raw.modrinth.com', 'cdn.modrinthcdn.com',
            ], true),
            default => false,
        };
        if (($parts['scheme'] ?? '') !== 'https' || !$trusted ||
            isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Untrusted modpack download host');
        }
        $response = Http::withHeaders([
            'User-Agent' => config('arcturus-modpack-manager.user_agent'),
        ])->timeout(1800)->withOptions(['sink' => $destination])->get($url);
        $response->throw();
        $limit = (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592);
        $bytes = @filesize($destination);
        if ($bytes === false || $bytes < 1 || $bytes > $limit) {
            throw new RuntimeException('Artifact exceeds configured byte limit');
        }
        $sha = hash_file('sha256', $destination);
        $expected = $server->available_asset_sha256;
        if ($expected !== null && !hash_equals(strtolower($expected), strtolower($sha))) {
            throw new RuntimeException('Downloaded artifact SHA-256 does not match provider digest');
        }
        // For unsigned GitHub ZIPs, the digest is now recorded but not authenticated.
        return $sha;
    }

    private function extractZip(string $archive, string $directory): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for ZIP server packs');
        }
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Could not open server pack archive');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 100000) {
                throw new RuntimeException('Invalid number of ZIP entries');
            }
            $total = 0;
            $seen = [];
            $max = (int) config('arcturus-modpack-manager.max_archive_bytes', 8589934592);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $raw = $zip->getNameIndex($i);
                if (!$stat || !is_string($raw)) {
                    throw new RuntimeException('Unreadable ZIP entry');
                }
                $directoryEntry = str_ends_with($raw, '/');
                $path = $directoryEntry ? substr($raw, 0, -1) : $raw;
                UpdatePlanner::assertPath($path);
                if (isset($seen[$path])) {
                    throw new RuntimeException("Duplicate ZIP path: {$path}");
                }
                $seen[$path] = true;
                $zip->getExternalAttributesIndex($i, $opsys, $attrs);
                $type = ($attrs >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    throw new RuntimeException("ZIP contains symlink or special entry: {$path}");
                }
                $size = (int) ($stat['size'] ?? -1);
                if ($size < 0 || $size > $max - $total) {
                    throw new RuntimeException('ZIP exceeds uncompressed size limit');
                }
                $total += $size;
                $dest = $directory . '/' . $path;
                if ($directoryEntry) {
                    if (!is_dir($dest) && !@mkdir($dest, 0750, true)) {
                        throw new RuntimeException("Cannot create directory: {$path}");
                    }
                    continue;
                }
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true)) {
                    throw new RuntimeException("Cannot create parent for: {$path}");
                }
                $stream = $zip->getStream($raw);
                if ($stream === false) {
                    throw new RuntimeException("Cannot open ZIP entry: {$path}");
                }
                $out = @fopen($dest, 'x');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException("Refusing to overwrite extracted path: {$path}");
                }
                $copied = stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                if ($copied !== $size) {
                    throw new RuntimeException("Truncated archive entry: {$path}");
                }
                $mode = ($attrs >> 16) & 0777;
                @chmod($dest, ($mode & 0111) ? 0750 : 0640);
            }
        } finally {
            $zip->close();
        }
    }

    private function effectiveRoot(string $dir): string
    {
        $children = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        if (count($children) === 1 && is_dir($dir . '/' . $children[0]) &&
            !in_array(strtolower($children[0]), [
                'mods', 'config', 'scripts', 'libraries', 'defaultconfigs', 'kubejs',
            ], true)) {
            return $dir . '/' . $children[0];
        }
        return $dir;
    }
}
