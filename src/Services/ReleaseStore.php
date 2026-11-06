<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;

/** Only worker-produced upstream snapshots enter this HMAC-authenticated store. */
final class ReleaseStore
{
    public function put(array $manifest): string
    {
        if (strlen((string) config('app.key')) < 32) {
            throw new RuntimeException('A configured Panel APP_KEY is required for authenticated release evidence');
        }
        (new BaselineGuard)->require($manifest, $manifest['provider'], $manifest['project'], $manifest['version']);
        $raw = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id = hash('sha256', $raw);
        $root = storage_path('app/arcturus-modpack-manager/manifests');
        if (! is_dir($root) && ! mkdir($root, 0700, true)) {
            throw new RuntimeException('Cannot create release store');
        }
        $envelope = json_encode(['manifest' => $manifest,
            'signature' => hash_hmac('sha256', $raw, (string) config('app.key'))], JSON_THROW_ON_ERROR);
        $path = $root.'/'.$id.'.json';
        if (! is_file($path)) {
            $temp = $path.'.'.bin2hex(random_bytes(8));
            if (file_put_contents($temp, $envelope, LOCK_EX) === false) {
                throw new RuntimeException('Cannot persist release evidence');
            }
            chmod($temp, 0600);
            if (! rename($temp, $path)) {
                throw new RuntimeException('Cannot activate release evidence');
            }
        }

        return $path;
    }

    public function load(string $path): array
    {
        if (strlen((string) config('app.key')) < 32) {
            throw new RuntimeException('A configured Panel APP_KEY is required for authenticated release evidence');
        }
        $root = realpath(storage_path('app/arcturus-modpack-manager/manifests'));
        $canonical = realpath($path);
        if (! $root || ! $canonical || is_link($path) || dirname($canonical) !== $root || filesize($path) > 33554432) {
            throw new RuntimeException('Manifest is outside the authenticated upstream store; prepare the exact release first');
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $raw = json_encode($data['manifest'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! hash_equals(hash_hmac('sha256', $raw, (string) config('app.key')), $data['signature'] ?? '')) {
            throw new RuntimeException('Release evidence signature is invalid');
        }

        return $data['manifest'];
    }

    /** Verify the existing envelope in bounded chunks, then read only directory evidence. */
    public function directories(string $path): array
    {
        $root = realpath(storage_path('app/arcturus-modpack-manager/manifests'));
        $canonical = realpath($path);
        $key = (string) config('app.key');
        if (strlen($key) < 32 || ! $root || ! $canonical || is_link($path) || dirname($canonical) !== $root || filesize($path) > 33554432) {
            throw new RuntimeException('Directory evidence is outside the authenticated upstream store');
        }
        $file = fopen($path, 'rb');
        if (! $file) { throw new RuntimeException('Cannot read upstream directory evidence'); }
        try {
            $prefix = '{"manifest":';
            $tailLength = strlen(',"signature":"'.str_repeat('0', 64).'"}');
            if (fread($file, strlen($prefix)) !== $prefix || fseek($file, -$tailLength, SEEK_END) !== 0) {
                throw new RuntimeException('Invalid upstream evidence envelope');
            }
            $tail = fread($file, $tailLength);
            if (! preg_match('/^,"signature":"([a-f0-9]{64})"}$/D', $tail, $match)) {
                throw new RuntimeException('Invalid upstream evidence signature');
            }
            $remaining = filesize($path) - strlen($prefix) - $tailLength;
            fseek($file, strlen($prefix));
            $hash = hash_init('sha256', HASH_HMAC, $key); $carry = '';
            while ($remaining > 0) {
                $chunk = fread($file, min(65536, $remaining));
                if ($chunk === false || $chunk === '') { throw new RuntimeException('Truncated upstream evidence'); }
                $remaining -= strlen($chunk); $chunk = $carry.$chunk; $carry = '';
                if (str_ends_with($chunk, '\\')) { $carry = '\\'; $chunk = substr($chunk, 0, -1); }
                hash_update($hash, str_replace('\\/', '/', $chunk));
            }
            hash_update($hash, $carry);
            if (! hash_equals($match[1], hash_final($hash))) { throw new RuntimeException('Release evidence signature is invalid'); }
        } finally { fclose($file); }
        $rows = [];
        foreach (JsonStream::file($path)->entries(['manifest', 'directory_discovery']) as $row) { $rows[$row['path']] = $row; }
        if (! $rows) {
            foreach (JsonStream::file($path)->entries(['manifest', 'files']) as $file => $hash) {
                if (! str_contains($file, '/')) { continue; }
                $name = explode('/', $file)[0];
                $rows[$name] ??= ['path' => $name, 'classification' => in_array($name, \Arcturus\ModpackManager\Security\ManagedPaths::ROOTS, true) ? 'standard' : 'review'];
            }
        }
        return array_values($rows);
    }

}
