<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use RuntimeException;

/** Offline full-volume recovery fingerprint, including worlds/custom files and link identities; never follows links. */
final class RestoreInventory
{
    public function requireReadable(string $root, array $excluded, ?\Closure $heartbeat = null): void
    {
        $count = 0;
        $walk = function (string $directory) use (&$walk, &$count, $root, $excluded, $heartbeat): void {
            if (! is_readable($directory) || ! is_executable($directory)) { throw new RuntimeException('Offline recovery verification needs read/traverse access to the full UUID volume, including world/player data. Ask the host operator for read-only verification ACLs.'); }
            $names = scandir($directory);
            if ($names === false) { throw new RuntimeException('Cannot enumerate recovery verification scope.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || ($directory === $root && in_array($name, $excluded, true))) { continue; }
                if (++$count > 2000000) { throw new RuntimeException('Recovery verification file limit exceeded.'); }
                $heartbeat?->__invoke(); $path = $directory.'/'.$name;
                if (is_link($path)) { continue; }
                if (is_dir($path)) { $walk($path); }
                elseif (! is_file($path) || ! is_readable($path)) { throw new RuntimeException('Recovery verification found unreadable or special files. Provision read-only verification access before approval.'); }
            }
        };
        $walk($root);
    }

    public function fingerprint(string $root, array $excluded, ?\Closure $heartbeat = null): array
    {
        if (realpath($root) !== $root || ! is_dir($root) || is_link($root)) { throw new RuntimeException('Recovery inventory root is unsafe.'); }
        $hash = hash_init('sha256'); $count = 0;
        $walk = function (string $directory) use (&$walk, &$count, $root, $excluded, $hash, $heartbeat): void {
            $names = scandir($root.($directory === '' ? '' : '/'.$directory));
            if ($names === false) { throw new RuntimeException('Cannot enumerate recovery inventory.'); }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..' || ($directory === '' && in_array($name, $excluded, true))) { continue; }
                $path = ltrim($directory.'/'.$name, '/');
                $full = $root.'/'.$path; $before = lstat($full);
                if ($before === false || ++$count > 2000000) { throw new RuntimeException('Recovery inventory is unavailable or exceeds the file limit.'); }
                $heartbeat?->__invoke();
                if (is_link($full)) { $kind = 'link'; $value = readlink($full); }
                elseif (is_dir($full)) { hash_update($hash, json_encode(['dir', $path], JSON_THROW_ON_ERROR)."\n"); $walk($path); continue; }
                elseif (is_file($full)) {
                    $kind = 'file'; $content = hash_init('sha256'); $stream = fopen($full, 'rb');
                    if (! $stream) { throw new RuntimeException('Cannot read recovery inventory file.'); }
                    try { while (! feof($stream)) { $chunk = fread($stream, 1048576); if ($chunk === false) { throw new RuntimeException('Cannot hash recovery file.'); } hash_update($content, $chunk); $heartbeat?->__invoke(); } }
                    finally { fclose($stream); }
                    $value = hash_final($content);
                    clearstatcache(true, $full); $after = lstat($full);
                    if (! $after || array_intersect_key($before, array_flip(['ino', 'size', 'mtime'])) !== array_intersect_key($after, array_flip(['ino', 'size', 'mtime']))) { throw new RuntimeException('Recovery inventory changed while offline.'); }
                } else { throw new RuntimeException('Recovery inventory contains unsupported special files.'); }
                hash_update($hash, json_encode([$kind, $path, $value], JSON_THROW_ON_ERROR)."\n");
            }
        };
        $walk('');
        return ['sha256' => hash_final($hash), 'entries' => $count];
    }
}
