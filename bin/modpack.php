#!/usr/bin/env php
<?php

declare(strict_types=1);

use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\ManifestJson;
use Arcturus\ModpackManager\Services\UpdatePlanner;

require_once __DIR__ . '/../src/Services/UpdatePlanner.php';
require_once __DIR__ . '/../src/Services/DirectoryInventory.php';
require_once __DIR__ . '/../src/Services/ManifestJson.php';

function usage(): never
{
    fwrite(STDERR, "Usage:\n  php bin/modpack.php manifest <unpacked-release-dir> <version>\n  php bin/modpack.php preview <base.json> <target.json> <live-server-dir> [rules.json]\n");
    exit(2);
}

try {
    $action = $argv[1] ?? '';
    $scanner = new DirectoryInventory();
    $manifest = new ManifestJson();
    if ($action === 'manifest' && $argc === 4) {
        echo $manifest->encode($argv[3], $scanner->hashTree($argv[2]));
    } elseif ($action === 'preview' && ($argc === 5 || $argc === 6)) {
        $base = $manifest->load($argv[2]);
        $target = $manifest->load($argv[3]);
        if ($base['files'] === [] || $target['files'] === []) {
            throw new InvalidArgumentException('Empty upstream manifest; refusing to build a destructive preview');
        }
        $paths = array_values(array_unique(array_merge(
            array_keys($base['files']), array_keys($target['files'])
        )));
        $local = $scanner->hashPaths($argv[4], $paths);
        $rules = [];
        if ($argc === 6) {
            $raw = @file_get_contents($argv[5]);
            if ($raw === false || strlen($raw) > 1048576) {
                throw new RuntimeException('Rules file unreadable or too large');
            }
            $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($json) || !isset($json['rules']) || !is_array($json['rules']) || !array_is_list($json['rules'])) {
                throw new InvalidArgumentException('Rules file must contain a rules array');
            }
            $rules = $json['rules'];
        }
        $plan = (new UpdatePlanner())->plan($base['files'], $local, $target['files'], $rules);
        echo json_encode([
            'base_version' => $base['version'],
            'target_version' => $target['version'],
            'notice' => 'DRY RUN ONLY — NO FILES CHANGED',
            ...$plan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } else {
        usage();
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
