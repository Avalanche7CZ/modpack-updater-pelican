<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

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
    put("$base/config/managed/A.bin", 'old');
    put("$base/config/managed/Removed.bin", 'removed');
    put("$base/config/local.cfg", 'upstream');
    put("$stage/config/managed/A.bin", 'new');
    put("$stage/config/managed/Added.bin", 'added');
    put("$stage/config/local.cfg", 'new upstream');
    put("$live/config/managed/A.bin", 'old');
    put("$live/config/managed/Removed.bin", 'removed');
    put("$live/config/local.cfg", 'custom');
    put("$live/mods/Extra.jar", 'local only');
    put("$live/world/level.dat", 'my world must survive');

    $scanner = new DirectoryInventory();
    $baseManifest = $scanner->hashTree($base);
    $targetManifest = $scanner->hashTree($stage);
    $rules = [
        ['pattern' => 'config/local.cfg', 'mode' => 'protect'],
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
    put("$stage/config/managed/A.bin", 'tampered');
    try {
        $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
        throw new RuntimeException('Tampered staging manifest accepted');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Staged hash mismatch'),
            'Unexpected tampering refusal');
    }
    verify(file_get_contents("$live/config/managed/A.bin") === 'old', 'Live file modified on failure');
    put("$stage/config/managed/A.bin", 'new');

    $result = $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
    verify(file_get_contents("$live/config/managed/A.bin") === 'new', 'Managed file was not updated');
    verify(file_get_contents("$live/config/managed/Added.bin") === 'added', 'New managed file missing');
    verify(!file_exists("$live/config/managed/Removed.bin"), 'Removed upstream file still present');
    verify(file_get_contents("$live/config/local.cfg") === 'custom', 'Custom config overwritten');
    verify(file_get_contents("$live/mods/Extra.jar") === 'local only', 'Local mod lost');
    verify(file_get_contents("$live/world/level.dat") === 'my world must survive', 'World modified');
    verify(file_get_contents($result['backup'] . '/world/level.dat') ===
        'my world must survive', 'World backup missing');
    verify(file_get_contents($result['backup'] . '/config/managed/A.bin') === 'old', 'Old managed file not backed up');

    // Local changes must stop deployment before further mutations.
    put("$live/config/managed/A.bin", 'local changed');
    try {
        $worker->apply($live, $stage, $backups, $baseManifest, $targetManifest, $rules, true);
        throw new RuntimeException('Local modifications overwritten');
    } catch (RuntimeException $e) {
        verify(str_contains($e->getMessage(), 'Unresolved change'),
            'Unexpected conflict failure');
    }
    verify(file_get_contents("$live/config/managed/A.bin") === 'local changed',
        'Conflict did not preserve local file');

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
    verify(file_get_contents("$live/config/managed/A.bin") === 'old', 'Rollback did not restore old managed file');
    verify(is_file("$live/config/managed/Removed.bin"), 'Rollback did not restore removed managed file');
    verify(!file_exists("$live/config/managed/Added.bin"), 'Rollback kept added managed file');
    verify(file_get_contents("$live/config/local.cfg") === 'custom', 'Rollback damaged local config');
    verify(file_get_contents("$live/world/level.dat") === 'my world must survive', 'Rollback damaged world');

    // External-backup mode never duplicates a complete snapshot on the node.
    // The application must have waited for Pelican's successful backup callback.
    $pelicanUuid = 'aeb5f31d-40f0-4d8d-9abe-3c3bf8127f85';
    $beforeBackupDirectories = count(glob($backups . '/*', GLOB_ONLYDIR) ?: []);
    $external = $worker->apply(
        $live, $stage, '/does-not-exist-and-must-not-be-used',
        $baseManifest, $targetManifest, $rules, true, $pelicanUuid
    );
    verify($external['backup'] === null, 'External backup incorrectly created local snapshot');
    verify($external['external_backup_uuid'] === $pelicanUuid, 'Pelican backup UUID not returned');
    verify(count(glob($backups . '/*', GLOB_ONLYDIR) ?: []) === $beforeBackupDirectories,
        'External mode duplicated a local snapshot');
    verify(file_get_contents("$live/config/managed/A.bin") === 'new', 'External mode failed to update a managed file');
    verify(file_get_contents("$live/world/level.dat") === 'my world must survive',
        'External mode modified player world');

    echo "Offline deployment tests passed\n";
} finally {
    removeTree($root);
}
