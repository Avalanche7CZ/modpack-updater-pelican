#!/usr/bin/env php
<?php
declare(strict_types=1);

use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\ManifestJson;
use Arcturus\ModpackManager\Services\UpdatePlanner;

foreach (['UpdatePlanner', 'DirectoryInventory', 'ManifestJson', 'LocalDeployment'] as $service) {
    require_once __DIR__ . '/../src/Services/' . $service . '.php';
}

function usage(): never
{
    fwrite(STDERR, <<<'HELP'
Usage:
  php bin/modpack.php manifest <unpacked-release-dir> <version>
  php bin/modpack.php preview <base.json> <target.json> <live-dir> [rules.json]
  php bin/modpack.php deploy <base.json> <target.json> <live-dir> <staged-dir> <backup-dir> <rules.json|-> --offline-confirmed
  php bin/modpack.php rollback <live-dir> <backup-snapshot-dir> --offline-confirmed

The deploy command MUST run directly on the Wings node after the server is stopped.
It does not stop/restart Minecraft or fetch releases. The full verified backup is
created outside the live directory before any file changes are made.

HELP
    );
    exit(2);
}

/** @return list<array{pattern:string,mode:string}> */
function loadRules(?string $path): array
{
    if ($path === null || $path === '-') {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        throw new RuntimeException('Rules file unreadable or exceeds 1 MiB');
    }
    $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($json) || !isset($json['rules']) ||
        !is_array($json['rules']) || !array_is_list($json['rules'])) {
        throw new InvalidArgumentException('Rules must be a JSON object with a rules array');
    }
    return $json['rules'];
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
            throw new InvalidArgumentException('Empty upstream manifest');
        }
        $paths = array_unique(array_merge(array_keys($base['files']), array_keys($target['files'])));
        $local = $scanner->hashPaths($argv[4], $paths);
        $plan = (new UpdatePlanner())->plan($base['files'], $local, $target['files'],
            loadRules($argv[5] ?? null));
        echo json_encode([
            'base_version' => $base['version'], 'target_version' => $target['version'],
            'notice' => 'DRY RUN ONLY — NO FILES CHANGED', ...$plan,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } elseif ($action === 'deploy' && $argc === 9 && $argv[8] === '--offline-confirmed') {
        $base = $manifest->load($argv[2]);
        $target = $manifest->load($argv[3]);
        $result = (new LocalDeployment())->apply(
            $argv[4], $argv[5], $argv[6], $base['files'], $target['files'],
            loadRules($argv[7]), true
        );
        echo json_encode([
            'status' => 'applied_offline',
            'notice' => 'Files applied. Check the Minecraft startup and migration before marking success.',
            'base_version' => $base['version'],
            'target_version' => $target['version'],
            'backup_directory' => $result['backup'],
            'modified_paths' => $result['modified'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } elseif ($action === 'rollback' && $argc === 5 && $argv[4] === '--offline-confirmed') {
        $failedRoot = (new LocalDeployment())->restore($argv[2], $argv[3], true);
        echo json_encode([
            'status' => 'restored_offline',
            'displaced_failed_installation' => $failedRoot,
            'notice' => 'Snapshot restored. Start the server manually and verify compatibility.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } else {
        usage();
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
