<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use InvalidArgumentException;

/**
 * Only whole, top-level backup directories are allowed.
 * No arbitrary gitignore patterns: ignored files must be preservable when
 * Pelican's full restore truncates the Wings server directory.
 */
final class BackupExclusions
{
    public const DEFAULT_FOLDERS = ['backup', 'backups'];

    /** @param array<mixed> $folders @return list<string> */
    public static function normalize(array $folders): array
    {
        if (count($folders) > 16) {
            throw new InvalidArgumentException('At most 16 backup directories may be excluded');
        }

        $result = [];
        foreach ($folders as $name) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Each backup exclusion must be a folder name');
            }
            $name = trim($name);
            if ($name === '' || strlen($name) > 100 ||
                preg_match('/^[\pL\pN][\pL\pN ._-]*$/uD', $name) !== 1) {
                throw new InvalidArgumentException(
                    'Exclusions must be top-level folder names, not paths, globs or negation rules'
                );
            }

            $lower = mb_strtolower($name, 'UTF-8');
            if (str_starts_with($lower, 'world') || in_array($lower, [
                'mods', 'config', 'configs', 'scripts', 'kubejs',
                'saves', 'libraries', 'defaultconfigs', 'data',
                'dimensions', 'plugins', 'server', 'logs', 'dim-1', 'dim1', 'advancements', 'stats', 'playerdata', '.',
                '..',
            ], true)) {
                throw new InvalidArgumentException("Cannot exclude critical Minecraft folder {$name}");
            }
            if (! in_array($name, $result, true)) {
                $result[] = $name;
            }
        }

        return $result;
    }

    public static function requireSafeForWorld(array $folders, string $properties): void
    {
        $worlds = ['world'];
        foreach (preg_split('/\r?\n/', $properties) as $line) {
            if (preg_match('/^\s*[#!]/', $line) || trim($line) === '') {
                continue;
            }
            if (str_contains($line, '\\')) {
                throw new InvalidArgumentException('Escaped server.properties requires manual exclusion verification; use a complete backup');
            }
            if (preg_match('/^\s*level-name\s*[=:]\s*(.+?)\s*$/D', $line, $m)) {
                $worlds[] = explode('/', $m[1])[0];
            }
        }
        foreach (self::normalize($folders) as $folder) {
            if (in_array(mb_strtolower($folder), array_map('mb_strtolower', $worlds), true)) {
                throw new InvalidArgumentException('The configured world directory cannot be excluded from recovery backups');
            }
        }
    }

    /** @param array<mixed> $folders @return list<string> */
    public static function patterns(array $folders): array
    {
        $names = self::normalize($folders);
        if ($names === []) {
            // Wings otherwise silently falls back to .pelicanignore, which
            // might exclude world data from a supposed full recovery backup.
            return ['# arcturus-modpack-manager: do not inherit .pelicanignore'];
        }

        return array_map(static fn (string $name): string => '/'.$name.'/', $names);
    }
}
