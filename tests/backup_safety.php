<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Services/BackupSafetyGate.php';
use Arcturus\ModpackManager\Services\BackupSafetyGate;

function expectRejected(callable $fn, string $description): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException("Unsafe backup was accepted: {$description}");
}

$sha = 'sha256:' . str_repeat('a', 64);
BackupSafetyGate::requireComplete(true, true, true, [], 1024, $sha);
expectRejected(fn() => BackupSafetyGate::requireComplete(false, true, true, [], 1024, $sha), 'pending');
expectRejected(fn() => BackupSafetyGate::requireComplete(true, false, true, [], 1024, $sha), 'failed');
expectRejected(fn() => BackupSafetyGate::requireComplete(true, true, false, [], 1024, $sha), 'unlocked');
expectRejected(fn() => BackupSafetyGate::requireComplete(true, true, true, ['world'], 1024, $sha), 'partial');
expectRejected(fn() => BackupSafetyGate::requireComplete(true, true, true, [], 0, $sha), 'empty');
expectRejected(fn() => BackupSafetyGate::requireComplete(true, true, true, [], 1024, 'sha256:'), 'no checksum');

if (BackupSafetyGate::restoreFinished(null, true)) {
    throw new RuntimeException('A cleared server status without callback was accepted');
}
if (BackupSafetyGate::restoreFinished('server:backup.restore-complete', false)) {
    throw new RuntimeException('A callback without cleared server status was accepted');
}
if (!BackupSafetyGate::restoreFinished('server:backup.restore-complete', true)) {
    throw new RuntimeException('Confirmed completed restore was not accepted');
}
expectRejected(fn() => BackupSafetyGate::restoreFinished('server.backup.restore-failed', true), 'restore failure');

echo "Pelican backup safety gate tests passed\n";
