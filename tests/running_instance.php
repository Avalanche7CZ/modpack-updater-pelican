<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\RunningInstance;

$sample = fn (float $at, float $uptime) => RunningInstance::sample(['state' => 'running', 'utilization' => ['uptime' => $uptime]], $at, $at + 0.01);
$window = ['min' => 99.99, 'max' => 100.02];
$instance = RunningInstance::same($window, $sample(101, 1000));
$baseline = $instance;
foreach ([101.25, 101.5, 102, 103] as $at) {
    $instance = RunningInstance::same($instance, $sample($at, 1000));
    check(!RunningInstance::advanced($baseline, $instance), 'Repeated sample counted as new identity evidence');
    check($instance['min'] <= 100 && $instance['max'] >= 100, 'Cached uptime moved the birth window');
}
$instance = RunningInstance::same($instance, $sample(104, 4000));
check(RunningInstance::advanced($baseline, $instance), 'Real sampled jump was not recognized');
check($instance['min'] <= 100 && $instance['max'] >= 100, 'Sampled jump lost the original instance');
// No acquisition timestamp: growing cached samples may imply birth windows around -4 / +6 s.
$dockerWindow=['min'=>99.998,'max'=>100.002];
$cached=RunningInstance::same($dockerWindow,$sample(200,94000));
foreach ([[202,95800],[204,98200],[206,99900]] as [$at,$uptime]) {
    $cached=RunningInstance::same($cached,$sample($at,$uptime));
    check($cached['min']===$dockerWindow['min'] && $cached['max']===$dockerWindow['max'], 'Growing cached uptime excluded or widened the verified Docker window');
}
rejects(fn()=>RunningInstance::same($cached,$sample(208,1000)), 'Restart accepted after cached-uptime observations');
rejects(fn()=>RunningInstance::same($cached,$sample(250,130000)), 'Non-overlapping cache-age interval accepted with increasing uptime');

// Persisted receipts may be supplied after a newer observation; chronology must be preserved.
$reverse = RunningInstance::same($sample(105, 5000), $instance);
check($reverse['uptime'] === 5000.0, 'Persisted receipt replaced a newer observation');
rejects(fn () => RunningInstance::same($instance, $sample(105, 500)), 'Real uptime reset accepted');
rejects(fn () => RunningInstance::same($instance, $sample(120, 5000)), 'Later restart with increasing uptime accepted');
rejects(fn () => RunningInstance::same($baseline, $sample(112, 1000)), 'Permanently frozen sample accepted');
rejects(fn () => RunningInstance::sample(['state'=>'running'], 101, 101), 'Running without uptime accepted');
$legacy = RunningInstance::receipt(['min'=>103.99, 'max'=>104.02]);
check($legacy['min'] < 100 && $legacy['max'] === 104.02, 'Legacy inferred bound did not account for sampling age');
check(RunningInstance::receipt($instance) === $instance, 'New receipt was widened on reload');
check(RunningInstance::same($window, $sample(101,1000))['min'] === $window['min'], 'Authoritative start window widened');

$calls = 0; $before = microtime(true); $initial = $sample($before, 1000);
[, $confirmed] = RunningInstance::confirm($initial, function () use (&$calls, $sample, $before): array {
    $calls++; $at = microtime(true);
    return [[], $sample($at, $calls <= 3 ? 1000 : 1000 + ($at - $before) * 1000)];
}, microtime(true) + 2);
check($calls === 4 && RunningInstance::advanced($initial, $confirmed), 'Confirmation accepted a repeated HTTP resource sample');
$GLOBALS['test_config']['arcturus-modpack-manager.running_instance_sample_timeout'] = 1;
rejects(fn () => RunningInstance::confirm($sample(microtime(true),1000), fn () => [[], $sample(microtime(true),1000)], microtime(true)+2), 'Confirmation accepted frozen running resources');
echo "Sampled Wings running-instance identity tests passed\n";
