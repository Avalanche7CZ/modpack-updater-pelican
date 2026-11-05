<?php
declare(strict_types=1);

foreach (['UpdatePlanner', 'DirectoryInventory', 'ManifestJson', 'LocalDeployment'] as $name) {
    require_once __DIR__ . '/../src/Services/' . $name . '.php';
}

use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\LocalDeployment;

function put(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create {$dir}");
    }
    file_put_contents($path, $content);
}
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function removeTree(string $path): void
{
    if (!file_exists($path)) { return; }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/modpack-deploy-test-' . bin2hex(random_bytes(6));
foreach (['base', 'stage', 'live', 'backups'] as $folder) { mkdir($root . '/' . $folder, 0700, true); }
$base = "$root/base";
$stage = "$root/stage";
$live = "$root/live";
$backups = "$root/backups";

try {
    put("$base/mods/A.jar", 'old');
    put("$base/mods/Removed.jar", 'removed');
    put("$base/mods/Angelica-1.jar", 'active');
    put("$base/config/local.cfg", 'upstream');
    put("$stage/mods/A.jar", 'new');
    put("$stage/mods/Added.jar", 'added');
    put("$stage/mods/Angelica-2.jar", 'new disabled');
    put("$stage/config/local.cfg", 'new upstream');
    put("$live/mods/A.jar", 'old');
    put("$live/mods/Removed.jar", 'removed');
    put("$live/mods/Angelica-1.jar", 'active');
    put("$live/config/local.cfg", 'custom');
    put("$live/mods/Extra.jar", 'local only');
    put("$live/world/level.dat", 'my world must survive');

    $scanner = new DirectoryInventory();
    $baseManifest = $scanner->hashTree($base);
    $targetManifest = $scanner->hashTree($stage);
    $rules = [
        ['pattern' => 'config/local.cfg', 'mode' => 'protect'],
        ['pattern' => 'mods/Angelica-*.jar', 'mode' => 'disable'],
    ];

    $worker = new LocalDeployment();
    try {
        $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules);
        throw new RuntimeException('Missing offline acknowledgement accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'stop must be explicitly confirmed'),
            'Unexpected refusal reason');
    }

    // Untrusted/incorrect source artifact must never modify live files.
    put("$stage/mods/A.jar", 'tampered');
    try {
        $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
        throw new RuntimeException('Tampered staging manifest accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Staged hash mismatch'),
            'Unexpected tampering refusal');
    }
    verify(file_get_contents("$live/mods/A.jar") === 'old', 'Live file modified on failure');
    put("$stage/mods/A.jar", 'new');

    $result = $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
    verify(file_get_contents("$live/mods/A.jar") === 'new', 'Mod was not updated');
    verify(file_get_contents("$live/mods/Added.jar") === 'added', 'New mod missing');
    verify(!file_exists("$live/mods/Removed.jar"), 'Removed upstream mod still present');
    verify(!file_exists("$live/mods/Angelica-1.jar"), 'Disabled old mod still active');
    verify(!file_exists("$live/mods/Angelica-2.jar"), 'Disabled new mod installed');
    verify(file_get_contents("$live/config/local.cfg") === 'custom', 'Custom config overwritten');
    verify(file_get_contents("$live/mods/Extra.jar") === 'local only', 'Local mod lost');
    verify(file_get_contents("$live/world/level.dat") === 'my world must survive', 'World modified');
    verify(file_get_contents($result['backup'] . '/world/level.dat') ===
        'my world must survive', 'World backup missing');
    verify(file_get_contents($result['backup'] . '/mods/A.jar') === 'old', 'Old mod not backed up');

    // Local changes must stop deployment before further mutations.
    put("$live/mods/A.jar", 'local changed');
    try {
        $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
        throw new RuntimeException('Local modifications overwritten');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Unresolved change'),
            'Unexpected conflict failure');
    }
    verify(file_get_contents("$live/mods/A.jar") === 'local changed',
        'Conflict did not preserve local mod');

    // A full rollback restores the exact pre-update snapshot, including world
    // and config files, without deleting the failed post-update installation.
    try {
        $worker->restore($live, $result['backup']);
        throw new RuntimeException('Unconfirmed rollback accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Offline confirmation'), 'Unsafe rollback refusal');
    }
    $displaced = $worker->restore($live, $result['backup'], true);
    verify(is_dir($displaced), 'Failed installation was not retained');
    verify(file_get_contents("$live/mods/A.jar") === 'old', 'Rollback did not restore old mod');
    verify(is_file("$live/mods/Removed.jar"), 'Rollback did not restore removed mod');
    verify(!file_exists("$live/mods/Added.jar"), 'Rollback kept added mod');
    verify(file_get_contents("$live/config/local.cfg") === 'custom', 'Rollback damaged local config');
    verify(file_get_contents("$live/world/level.dat") === 'my world must survive', 'Rollback damaged world');

    echo "Offline deployment tests passed\n";
} finally {
    removeTree($root);
}
