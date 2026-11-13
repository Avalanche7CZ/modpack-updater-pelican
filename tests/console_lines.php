<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\ConsoleIntegration\ConsoleLine;

$secret = "https://private.test/?token=secret\033[2J\r\nraw exception";
$line = fn ($phase, $progress = [], $evidence = []) => ConsoleLine::make('deploy', $phase, $progress, $evidence);
foreach (['queued', 'backing_up', 'applying', 'starting', 'restoring', 'restarting_original', 'manual_intervention'] as $phase) {
    $result = $line($phase, ['message' => $secret], ['error' => $secret]);
    check(str_starts_with($result['message'], '[Modpack] ') && ! str_contains($result['message'], 'secret') && ! preg_match('/[\x00-\x1f\x7f]/', $result['message']), 'Untrusted receipt data entered console');
}
check(str_contains($line('completed')['message'], 'unverified'), 'Closed receipt falsely confirmed startup');
check(str_contains($line('completed', [], ['startup_verified' => true])['message'], 'startup verified'), 'Verified success missing');
check(str_contains($line('restored')['message'], 'unverified'), 'Restore without proof falsely confirmed rollback');
check(! str_contains($line('restarting_original')['message'], 'Rollback verified'), 'Restore completion falsely confirmed Minecraft startup');
check(str_contains($line('restored', [], ['restore_verified' => true])['message'], 'Rollback verified'), 'Verified rollback missing');
check($line($secret) === null, 'Unknown phase was rendered');
$download = ConsoleLine::make('prepare', 'downloading', ['current' => ['phase' => 'downloading', 'bytes_done' => 420 * 1048576, 'bytes_total' => 542 * 1048576]], []);
check(str_contains($download['message'], '420.00 / 542.00 MiB'), 'Recorded download measurements missing');
foreach ([null, -1, 1001, '100', INF] as $bad) {
    check(! str_contains($line('applying', ['files_done' => $bad, 'files_total' => 1000])['message'], '/'), 'Invalid measurement rendered');
}
check(str_contains($line('applying', ['files_done' => 112, 'files_total' => 340])['message'], '112 / 340'), 'Recorded file progress missing');
echo "Console vocabulary, real measurements, secret exclusion and verified recovery semantics passed\n";

$verification = ConsoleLine::make('deploy', 'verifying_before_backup', ['files_done' => 8870, 'files_total' => 9208], []);
check(str_contains($verification['message'], '8 870 / 9 208'), 'Real verification progress missing');
