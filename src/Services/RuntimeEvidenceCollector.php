<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Contracts\ServerFiles;
use RuntimeException;

/** Bounded metadata/configuration reads. No recursive inventory, world reads, hashes or worker required. */
final class RuntimeEvidenceCollector
{
    private const CONFIGS = ['modrinth.index.json', 'manifest.json', 'modpack.json', 'server.properties',
        'fabric-server-launcher.properties', 'quilt-server-launcher.properties', 'run.sh', 'startserver.sh',
        'start.sh', 'settings.sh', 'variables.txt', 'unix_args.txt'];

    public function collect(ServerFiles $files): array
    {
        $paths = [];
        $texts = [];
        $warnings = [];
        $requests = 0;
        $entries = 0;
        $bytes = 0;
        $list = function (string $directory) use ($files, &$requests, &$entries): array {
            if (++$requests > 64) {
                throw new RuntimeException('Runtime inspection directory limit exceeded');
            }
            $rows = $files->listing($directory);
            $entries += count($rows);
            if ($entries > 4096) {
                throw new RuntimeException('Runtime inspection entry limit exceeded');
            }
            foreach ($rows as $row) {
                UpdatePlanner::assertPath($row['name'] ?? null);
            }

            return $rows;
        };
        $read = function (string $path) use ($files, &$texts, &$bytes, &$warnings): void {
            try {
                $content = $files->read($path, 1048576);
                if ($bytes + strlen($content) > 4194304) {
                    $warnings[] = 'Skipped optional runtime file (read budget reached): '.$path;

                    return;
                }
                $bytes += strlen($content);
                $texts[$path] = $content;
            } catch (\Throwable $error) {
                // Individual startup/config files may be unreadable, removed
                // during a restart or exceed the read cap. Record incomplete
                // evidence rather than breaking the administrator's Livewire page.
                $warnings[] = 'Could not read optional runtime file: '.$path;
            }
        };
        $root = $list('');
        foreach ($root as $row) {
            if (($row['symlink'] ?? true) || ! ($row['file'] ?? false)) {
                continue;
            }
            $paths[] = $row['name'];
            if (in_array($row['name'], self::CONFIGS, true)) {
                $read($row['name']);
            }
        }
        // Only descend these known loader coordinates, checking every parent for links.
        $children = [
            '' => ['libraries'], 'libraries' => ['net'], 'libraries/net' => ['minecraftforge', 'neoforged', 'fabricmc', 'quiltmc'],
            'libraries/net/minecraftforge' => ['forge'], 'libraries/net/neoforged' => ['neoforge', 'forge'],
            'libraries/net/fabricmc' => ['fabric-loader'], 'libraries/net/quiltmc' => ['quilt-loader'],
        ];
        $todo = [['', $root]];
        while ($todo !== []) {
            [$directory, $rows] = array_shift($todo);
            foreach ($rows as $row) {
                $name = $row['name'];
                if (($row['symlink'] ?? true) || ! ($row['directory'] ?? false)) {
                    continue;
                }
                if (isset($children[$directory])) {
                    if (! in_array($name, $children[$directory], true)) {
                        continue;
                    }
                } elseif (! preg_match('/^[A-Za-z0-9_.+-]{1,120}$/D', $name)) {
                    continue;
                }
                $path = $directory === '' ? $name : $directory.'/'.$name;
                try {
                    $rowsBelow = $list($path);
                } catch (\Throwable $error) {
                    // A stale library directory or an older Wings API response
                    // must not turn read-only inspection into an HTTP 500.
                    $warnings[] = 'Could not inspect optional launcher directory: '.$path;

                    continue;
                }
                if (isset($children[$path]) || isset($children[$directory])) {
                    $todo[] = [$path, $rowsBelow];

                    continue;
                }
                // This is a version directory. Do not descend into its children.
                foreach ($rowsBelow as $file) {
                    if (($file['symlink'] ?? true) || ! ($file['file'] ?? false)) {
                        continue;
                    }
                    if ($file['name'] === 'unix_args.txt' || str_ends_with(strtolower($file['name']), '.jar')) {
                        $runtimePath = $path.'/'.$file['name'];
                        $paths[] = $runtimePath;
                        if ($file['name'] === 'unix_args.txt') {
                            $read($runtimePath);
                        }
                    }
                }
            }
        }

        return [
            'paths' => array_values(array_unique($paths)),
            'texts' => $texts,
            'warnings' => array_values(array_unique($warnings)),
            'complete' => $warnings === [],
        ];
    }
}
