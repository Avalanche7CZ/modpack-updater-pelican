<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/BackupExclusions.php';
require_once __DIR__ . '/../src/Services/BackupSafetyGate.php';

use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\BackupSafetyGate;

function expectFailure(callable $check, string $case): void
{
    try {
        $check();
    } catch (InvalidArgumentException|RuntimeException $error) {
        return;
    }
    throw new RuntimeException('Expected failure: ' . $case);
}

$expected = ['/backup/', '/backups/'];
if (BackupExclusions::patterns(BackupExclusions::DEFAULT_FOLDERS) !== $expected) {
    throw new RuntimeException('Root backup folders are not anchored');
}
if (BackupExclusions::normalize(['backup', 'backups', 'backup']) !== ['backup', 'backups']) {
    throw new RuntimeException('Folder names were not deduplicated');
}
if (BackupExclusions::patterns([]) !== ['# modpack-manager: do not inherit .pelicanignore']) {
    throw new RuntimeException('No exclusions should never inherit .pelicanignore');
}
foreach (['../world', 'world', 'world_nether', 'mods', 'config',
    'backup/**', '!world', '/backup', '.hidden', 'backups/old',
    "backups\nworld", '  ', 'world_save', 'saves'] as $invalid) {
    expectFailure(fn() => BackupExclusions::normalize([$invalid]), $invalid);
}

$digest = 'sha1:' . str_repeat('a', 40);
BackupSafetyGate::requireComplete(true, true, true, $expected, 10000, $digest, $expected);
expectFailure(
    fn() => BackupSafetyGate::requireComplete(true, true, true, ['/world/'], 10000, $digest, $expected),
    'Backup ignored unexpected world data'
);
expectFailure(
    fn() => BackupSafetyGate::requireComplete(true, true, true, [], 10000, $digest, $expected),
    'Wings did not honor backup exclusions'
);
expectFailure(
    fn() => BackupSafetyGate::requireComplete(false, true, true, $expected, 10000, $digest, $expected),
    'Incomplete backup'
);

echo "Backup exclusions tests passed\n";
