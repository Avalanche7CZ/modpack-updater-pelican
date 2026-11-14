<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Native\ApplyBudget;
use Arcturus\ModpackManager\Native\DeploymentMetrics;

$clock = 0.0; $calls = 0;
// A nested read/verification unit must never extend a partly mutated outer batch.
$clockSource = function () use (&$clock): float { return $clock; };
$budget = new ApplyBudget(100, 10, $clockSource);
$budget->run(function () use ($budget, &$clock): void {
    check($budget->timeout(600) === 10.0, 'Request timeout escaped its unit'); $clock = 7;
    $budget->run(function () use ($budget): void { check($budget->timeout(600) === 3.0, 'Nested verification reset the unit deadline'); });
    $clock = 10; rejects(fn () => $budget->timeout(600), 'Expired request admitted'); $clock = 9;
});
$clock = 91;
rejects(fn () => $budget->run(function () use (&$calls): void { $calls++; }), 'Unit admitted without maximum-time reserve');
check($calls === 0, 'Budget exhaustion started work');
// The native request profile admits verified follow-up work regardless of cumulative elapsed time.
$requests = ApplyBudget::requestsOnly(); $clock = 3721;
$requests->run(function () use ($requests, &$clock, &$calls): void {
    check($requests->timeout(60) === 60.0, 'Performance elapsed time shortened an HTTP deadline');
    $clock += 3482;
    $requests->run(function () use ($requests, &$calls): void { $calls++; check($requests->timeout(600) === 600.0, 'Nested verified work inherited a phase reserve'); });
});
check($calls === 1, 'A verified follow-up batch was not admitted');
$metrics = new DeploymentMetrics; $metrics->phase('upload');
rejects(fn () => $metrics->measure('upload', function (): void { throw new RuntimeException('ambiguous'); }), 'Metrics swallowed a transfer failure');
check($metrics->snapshot()['work']['upload']['failed'] === 1, 'Failed transfer omitted from metrics');
echo "Apply budget admission, nested mutation deadlines and failed-call telemetry passed\n";
