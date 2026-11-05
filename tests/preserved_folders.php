<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/BackupExclusions.php';
require_once __DIR__ . '/../src/Services/ExcludedFolderPreserver.php';

use Arcturus\ModpackManager\Services\ExcludedFolderPreserver;

function check(bool $value, string $description): void
{
    if (!$value) {
        throw new RuntimeException($description);
    }
}
function wipe(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                wipe($path . '/' . $name);
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

$root = sys_get_temp_dir() . '/mpm-exclude-test-' . bin2hex(random_bytes(6));
$live = $root . '/live';
$stashParent = $root . '/staging';
mkdir($live, 0700, true);
mkdir($stashParent, 0700, true);
mkdir($live . '/backup', 0700);
mkdir($live . '/backups', 0700);
mkdir($live . '/world', 0700);
file_put_contents($live . '/backup/old.zip', 'old-save');
file_put_contents($live . '/backups/old.zip', 'another-save');
file_put_contents($live . '/world/level.dat', 'my-world');

try {
    $preserver = new ExcludedFolderPreserver();
    $stash = $preserver->moveOut($live, $stashParent, ['backup', 'backups']);
    check($stash !== null && is_dir($stash), 'Preservation journal missing');
    check(!is_dir($live . '/backup') && !is_dir($live . '/backups'),
        'Excluded folders were not moved out of truncate target');
    check(file_get_contents($stash . '/backup/old.zip') === 'old-save', 'Backup content lost');
    check(file_get_contents($stash . '/backups/old.zip') === 'another-save', 'Backups content lost');

    // Simulated Wings truncate + server backup restore.
    unlink($live . '/world/level.dat');
    rmdir($live . '/world');
    mkdir($live . '/world');
    file_put_contents($live . '/world/level.dat', 'restored-old-world');
    $preserver->moveBack($live, $stash);
    check(file_get_contents($live . '/backup/old.zip') === 'old-save', 'First excluded folder lost');
    check(file_get_contents($live . '/backups/old.zip') === 'another-save', 'Second excluded folder lost');
    check(file_get_contents($live . '/world/level.dat') === 'restored-old-world', 'World restore damaged');
    check(!file_exists($stash), 'Successful preservation stash should be removed');

    // A conflict refuses overwrite instead of destroying saved data.
    $stash = $preserver->moveOut($live, $stashParent, ['backup']);
    mkdir($live . '/backup');
    try {
        $preserver->moveBack($live, $stash);
        throw new RuntimeException('Expected overwrite conflict');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'refusing overwrite'), 'Wrong conflict reason');
    }
    check(file_get_contents($stash . '/backup/old.zip') === 'old-save', 'Conflicted folder must remain safe');
    rmdir($live . '/backup');
    $preserver->moveBack($live, $stash);

    // Symlinks must not be moved off-server blindly.
    symlink($live . '/world', $live . '/symlink-backups');
    try {
        $preserver->moveOut($live, $stashParent, ['symlink-backups']);
        throw new RuntimeException('Symlink was accepted');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'symlink'), 'Wrong symlink failure');
    }
} finally {
    wipe($root);
}
echo "Excluded folders preservation tests passed\n";
