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
            $key = preg_split('/[=:\s]/', ltrim($line), 2)[0];
            if (str_contains($key, '\\')) { throw new InvalidArgumentException('Escaped properties keys require manual world-path verification'); }
            // Java server.properties frequently contains escaped MOTDs, unicode
            // codes, passwords or unrelated escaped properties. Those cannot
            // influence the world directory. Only level-name is path-sensitive.
            if (preg_match('/^\s*level-name\s*(?:[=:]\s*|\s+)(.+?)\s*$/D', $line, $m)) {
                if (str_contains($m[1], '\\')) {
                    // Do not attempt to decode Java-properties escapes into a
                    // filesystem path without independently verifying it.
                    throw new InvalidArgumentException('Escaped level-name requires manual world-path verification');
                }
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
        if (strtolower(explode('/', $path)[0]) === 'data') { return 'Protected runtime data'; }
        if (preg_match('~(?:^|/)(?:\.[^/]+|world|world_nether|world_the_end|saves|dimensions|logs|crash-reports|backups?|\.env(?:\.[^/]*)?|\.ssh|\.aws|\.git|secrets|credentials|tokens|passwords)(?:/|$)|(?:\.pem|\.key|\.p12|\.keystore)$~i', $path)) {
            return 'Protected runtime data or secrets';
        }
        foreach (self::roots('', $roots) as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return 'World or unscanned protected directory: '.$root;
            }
        }
        if (str_starts_with($path, '.arcturus-modpack-manager') || preg_match('~(?:^|/)(?:level\.dat(?:_old)?|session\.lock|region|playerdata|entities|poi|DIM-?[0-9]+)(?:/|$)~i', $path)) {
            return 'World data or internal recovery metadata';
        }

        return null;
    }
}
