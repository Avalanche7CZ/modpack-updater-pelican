<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/** Filesystem/world invariants are independent of administrator update preferences. */
final class SafetyPaths
{
    public static function roots(string $properties = '', array $observed = []): array
    {
        $roots = ['world', 'world_nether', 'world_the_end', 'saves', 'dimensions', 'DIM-1', 'DIM1'];
        foreach (preg_split('/\r?\n/', $properties) as $line) {
            if (trim($line) === '' || preg_match('/^\s*[#!]/', $line)) {
                continue;
            }
            if (str_contains($line, '\\')) {
                throw new InvalidArgumentException('Escaped server.properties requires manual world-path verification');
            }
            if (preg_match('/^\s*level-name\s*(?:[=:]\s*|\s+)(.+?)\s*$/D', $line, $m)) {
                UpdatePlanner::assertPath($m[1]);
                foreach (['', '_nether', '_the_end'] as $suffix) {
                    $roots[] = $m[1].$suffix;
                }
            }
        }
        foreach ($observed as $root) {
            UpdatePlanner::assertPath($root);
            $roots[] = $root;
        }

        return array_values(array_unique($roots));
    }

    public static function reason(string $path, array $roots = []): ?string
    {
        foreach (self::roots('', $roots) as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return 'World or unscanned protected directory: '.$root;
            }
        }
        if (str_starts_with($path, '.arcturus-modpack-manager') || preg_match('~(?:^|/)(?:level\.dat(?:_old)?|session\.lock|region|playerdata|entities|poi|DIM-?[0-9]+)(?:/|$)~', $path)) {
            return 'World data or internal recovery metadata';
        }

        return null;
    }
}
