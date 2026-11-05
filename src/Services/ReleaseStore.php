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
}
