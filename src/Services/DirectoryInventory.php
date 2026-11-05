<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;
use RuntimeException;

/** Read-only SHA-256 scanning. Never follows symbolic links. */
final class DirectoryInventory
{
    /** @param list<string> $paths @return array<string,string> */
    public function hashPaths(string $root, array $paths): array
    {
        $base = $this->requireRoot($root);
        $result = [];
        foreach ($paths as $path) {
            UpdatePlanner::assertPath($path);
            if (isset($result[$path])) {
                continue;
            }
            $file = $this->safePath($base, $path);
            if (! file_exists($file)) {
                continue;
            }
            if (! is_file($file)) {
                throw new RuntimeException("Not a regular file: {$path}");
            }
            clearstatcache(true, $file);
            $before = @stat($file);
            if ($before === false) {
                throw new RuntimeException("Cannot stat: {$path}");
            }
            $hash = @hash_file('sha256', $file);
            clearstatcache(true, $file);
            $after = @stat($file);
            if ($hash === false || $after === false ||
                $before['ino'] !== $after['ino'] || $before['size'] !== $after['size'] ||
                $before['mtime'] !== $after['mtime']) {
                throw new RuntimeException("File changed or could not be read: {$path}");
            }
            $result[$path] = $hash;
        }
        ksort($result);

        return $result;
    }

    /**
     * Scan a trusted unpacked upstream release (never a live world) into a manifest.
     *
     * @return array<string,string>
     */
    public function hashTree(string $root, int $maxFiles = 100000, int $maxBytes = 8589934592): array
    {
        if ($maxFiles < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Invalid limits');
        }
        $base = $this->requireRoot($root);
        $paths = [];
        $bytes = 0;
        $count = 0;
        $todo = [''];
        while ($todo !== []) {
            $relative = array_pop($todo);
            $dir = $base.($relative === '' ? '' : '/'.$relative);
            $children = @scandir($dir);
            if ($children === false) {
                throw new RuntimeException("Cannot list directory: {$relative}");
            }
            foreach ($children as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $path = ltrim($relative.'/'.$child, '/');
                UpdatePlanner::assertPath($path);
                $full = $this->safePath($base, $path);
                if (is_dir($full)) {
                    $todo[] = $path;
                } elseif (is_file($full)) {
                    $count++;
                    if ($count > $maxFiles) {
                        throw new RuntimeException('File count limit exceeded');
                    }
                    $size = @filesize($full);
                    if ($size === false || $size < 0 || $size > $maxBytes - $bytes) {
                        throw new RuntimeException('Scan size limit exceeded');
                    }
                    $bytes += $size;
                    $paths[] = $path;
                } else {
                    throw new RuntimeException("Unsupported filesystem entry: {$path}");
                }
            }
        }

        return $this->hashPaths($base, $paths);
    }

    private function requireRoot(string $root): string
    {
        if (is_link($root)) {
            throw new RuntimeException('Root must not be a symlink');
        }
        $real = realpath($root);
        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            throw new RuntimeException('Root must be an accessible directory');
        }

        return rtrim($real, '/') ?: '/';
    }

    private function safePath(string $root, string $relative): string
    {
        $full = $root;
        foreach (explode('/', $relative) as $segment) {
            $full .= '/'.$segment;
            if (is_link($full)) {
                throw new RuntimeException("Symlink rejected: {$relative}");
            }
        }

        return $full;
    }
}
