<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/UpdatePlanner.php';

use Arcturus\ModpackManager\Services\UpdatePlanner;

function h(string $s): string { return hash('sha256', $s); }
function eq(mixed $want, mixed $got, string $message = ''): void {
    if ($want !== $got) { throw new RuntimeException("{$message}: expected " . var_export($want, true) . ', got ' . var_export($got, true)); }
}
function act(array $plan, string $path): string {
    foreach ($plan['changes'] as $entry) { if ($entry['path'] === $path) { return $entry['action']; } }
    throw new RuntimeException("Path missing: {$path}");
}
function fails(callable $f, string $label): void {
    try { $f(); } catch (InvalidArgumentException|RuntimeException $e) { return; }
    throw new RuntimeException("Expected exception: {$label}");
}

$p = new UpdatePlanner();
$base = [
    'mods/update.jar' => h('a'), 'mods/conflict.jar' => h('a'),
    'mods/old.jar' => h('a'), 'config/protected.cfg' => h('a'),
    'config/merge.cfg' => h('a'), 'mods/Angelica-1.jar' => h('a'),
    'world/level.dat' => h('a'), 'server.properties' => h('a'),
];
$local = [
    'mods/update.jar' => h('a'), 'mods/conflict.jar' => h('custom'),
    'config/protected.cfg' => h('custom'), 'config/merge.cfg' => h('custom'),
    'mods/Angelica-1.jar' => h('a'), 'mods/custom.jar' => h('custom'),
    'world/level.dat' => h('a'), 'server.properties' => h('custom'),
];
$target = [
    'mods/update.jar' => h('b'), 'mods/conflict.jar' => h('b'),
    'mods/add.jar' => h('b'), 'config/protected.cfg' => h('b'),
    'config/merge.cfg' => h('b'), 'mods/Angelica-2.jar' => h('b'),
    'world/level.dat' => h('b'), 'server.properties' => h('b'),
];
$rules = [
    ['pattern' => 'config/protected.cfg', 'mode' => 'protect'],
    ['pattern' => 'config/merge.cfg', 'mode' => 'merge'],
    ['pattern' => 'mods/Angelica-*.jar', 'mode' => 'disable'],
    ['pattern' => 'server.properties', 'mode' => 'replace'],
];
$plan = $p->plan($base, $local, $target, $rules);
foreach ([
    'mods/update.jar' => 'update', 'mods/conflict.jar' => 'conflict',
    'mods/old.jar' => 'already_absent', 'mods/add.jar' => 'add',
    'config/protected.cfg' => 'preserve', 'config/merge.cfg' => 'merge_required',
    'mods/Angelica-1.jar' => 'disable_requires_enforcement',
    'mods/Angelica-2.jar' => 'skip_disabled',
    'mods/custom.jar' => 'preserve_local_extra',
    'world/level.dat' => 'preserve', 'server.properties' => 'preserve',
] as $path => $expected) {
    eq($expected, act($plan, $path), $path);
}
eq(3, $plan['blocked'], 'blocked count');
eq(false, $plan['applicable'], 'applicable flag');

$replace = $p->plan(['config/a.cfg' => h('a')], ['config/a.cfg' => h('custom')], ['config/a.cfg' => h('b')], [
    ['pattern' => 'config/*.cfg', 'mode' => 'protect'],
    ['pattern' => 'config/a.cfg', 'mode' => 'replace'],
]);
eq('replace_with_override', act($replace, 'config/a.cfg'), 'override');
$preserve = $p->plan(['config/a.cfg' => h('a')], ['config/a.cfg' => h('custom')], ['config/a.cfg' => h('a')]);
eq('preserve_local_change', act($preserve, 'config/a.cfg'), 'unchanged upstream but customized');
$deleted = $p->plan(['mods/missing.jar' => h('a')], [], ['mods/missing.jar' => h('b')]);
eq('conflict', act($deleted, 'mods/missing.jar'), 'locally removed mod should not be re-enabled');

foreach (['../escape', '/absolute', 'mods/../../escape', 'mods\\windows.jar', 'mods//double.jar', 'C:/windows', 'mods/./foo'] as $unsafe) {
    fails(fn() => $p->plan([], [$unsafe => h('a')], []), $unsafe);
}
fails(fn() => $p->plan([], ['mods/a.jar' => 'not-a-hash'], []), 'hash');
eq(true, UpdatePlanner::matches('config/**/*.cfg', 'config/a/b/hi.cfg'), 'recursive glob');
eq(true, UpdatePlanner::matches('config/**/*.cfg', 'config/hi.cfg'), 'zero-level recursive glob');
eq(false, UpdatePlanner::matches('config/*.cfg', 'config/a/hi.cfg'), 'single-level glob');

echo "Planner tests passed\n";
