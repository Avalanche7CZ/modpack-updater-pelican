<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/UpdatePlanner.php';

use Arcturus\ModpackManager\Services\UpdatePlanner;

function hashOf(string $value): string
{
    return hash('sha256', $value);
}

function assertAction(array $plan, string $path, string $expected): void
{
    foreach ($plan['changes'] as $change) {
        if ($change['path'] === $path) {
            if ($change['action'] !== $expected) {
                throw new RuntimeException("{$path}: expected {$expected}, got {$change['action']}");
            }
            return;
        }
    }
    throw new RuntimeException("Missing path: {$path}");
}

$p = new UpdatePlanner();
$a = hashOf('base');
$b = hashOf('target');
$c = hashOf('custom');

$plan = $p->plan(
    [
        'mods/A.jar' => $a,
        'mods/B.jar' => $a,
        'mods/C.jar' => $a,
        'config/custom.cfg' => $a,
        'config/merge.cfg' => $a,
        'mods/Disabled-1.0.jar' => $a,
        'world/level.dat' => $a,
    ],
    [
        'mods/A.jar' => $a,
        'mods/B.jar' => $c,
        'config/custom.cfg' => $c,
        'config/merge.cfg' => $c,
        'mods/Disabled-1.0.jar' => $a,
        'mods/Extra.jar' => $c,
        'world/level.dat' => $a,
    ],
    [
        'mods/A.jar' => $b,
        'mods/B.jar' => $b,
        'mods/New.jar' => $b,
        'config/custom.cfg' => $b,
        'config/merge.cfg' => $b,
        'mods/Disabled-1.0.jar' => $b,
        'world/level.dat' => $b,
    ],
    [
        ['pattern' => 'config/custom.cfg', 'mode' => 'protect'],
        ['pattern' => 'config/merge.cfg', 'mode' => 'merge'],
        ['pattern' => 'mods/Disabled-*.jar', 'mode' => 'disable'],
    ],
);

foreach ([
    'mods/A.jar' => 'update',
    'mods/B.jar' => 'conflict',
    'mods/C.jar' => 'already_absent',
    'mods/New.jar' => 'add',
    'mods/Extra.jar' => 'preserve_local_extra',
    'config/custom.cfg' => 'preserve',
    'config/merge.cfg' => 'merge_required',
    'mods/Disabled-1.0.jar' => 'disable_requires_enforcement',
    'world/level.dat' => 'preserve',
] as $path => $action) {
    assertAction($plan, $path, $action);
}

if ($plan['blocked'] !== 3) {
    throw new RuntimeException('Expected three blocked actions');
}

$override = $p->plan(['config/a.cfg' => $a], ['config/a.cfg' => $c], ['config/a.cfg' => $b], [
    ['pattern' => 'config/*.cfg', 'mode' => 'protect'],
    ['pattern' => 'config/a.cfg', 'mode' => 'replace'],
]);
assertAction($override, 'config/a.cfg', 'replace_with_override');

$preserve = $p->plan(['config/a.cfg' => $a], ['config/a.cfg' => $c], ['config/a.cfg' => $a]);
assertAction($preserve, 'config/a.cfg', 'preserve_local_change');

foreach (['../escape', '/absolute', 'mods/../../escape', 'mods\\windows.jar', 'mods//double.jar'] as $unsafe) {
    try {
        $p->plan([], [$unsafe => $a], []);
        throw new RuntimeException("Unsafe path accepted: {$unsafe}");
    } catch (InvalidArgumentException $expected) {
    }
}

try {
    $p->plan([], ['mods/unknown.jar' => 'not-a-hash'], []);
    throw new RuntimeException('Invalid SHA-256 accepted');
} catch (InvalidArgumentException $expected) {
}

echo "Planner tests passed.\n";
