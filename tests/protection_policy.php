<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\SafetyPaths;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$p = new UpdatePlanner;
$policy = new ProtectionPolicy;
$hash = fn ($s) => hash('sha256', $s);
$path = 'config/example.toml';
$base = [$path => $hash('old')];
$local = [$path => $hash('custom')];
$target = [$path => $hash('new')];
$a = [['scope' => 'exact', 'pattern' => $path, 'mode' => 'protect']];
$b = [['scope' => 'glob', 'pattern' => 'config/**', 'mode' => 'replace', 'priority' => 1000]];
$plan = $p->plan($base, $local, $target, [...$a, ...$b]);
check($plan['changes'][0]['action'] === 'preserve' && $plan['changes'][0]['matching_rules'] === [1, 2], 'Exact path did not beat a broad high-priority glob or show overlap');
check($p->plan($base, $local, $target, $b)['changes'][0]['action'] === 'replace_with_override', 'Server B policies did not remain independent');
check($p->plan($base, $local, $target, [])['blocked'] === 1, 'Unresolved customized config conflict accepted');
$rules = $policy->normalize([['scope' => 'directory', 'pattern' => 'config', 'mode' => 'protect'], ['scope' => 'directory', 'pattern' => 'config/sub', 'mode' => 'replace']]);
check($policy->effective('config/sub/a.toml', $rules)['mode'] === 'replace', 'Deep directory precedence failed');
$rules = $policy->normalize([['pattern' => '**', 'mode' => 'protect'], ['pattern' => '**', 'mode' => 'review'], ['pattern' => '**', 'mode' => 'replace', 'enabled' => false]]);
check($policy->effective($path, $rules)['mode'] === 'review', 'Ordering or disabled rule handling failed');
$rules[0]['priority'] = 1;
check($policy->effective($path, $rules)['mode'] === 'protect', 'Priority within equal scope failed');
foreach (['extension' => 'toml', 'mod_filename' => '*new*.jar', 'mod_id' => 'example', 'loader' => 'forge'] as $scope => $pattern) {
    $rules = $policy->normalize([['scope' => $scope, 'pattern' => $pattern, 'mode' => 'review']]);
    check($policy->effective($scope === 'extension' ? $path : 'mods/new-2.jar', $rules, 'example', 'forge')['mode'] === 'review', 'Selector failed: '.$scope);
}
$old = 'mods/old-1.jar';
$new = 'mods/new-2.jar';
$ids = ['base' => [$old => 'example'], 'target' => [$new => 'example'], 'local' => [$old.'.disabled' => 'example']];
$plan = $p->plan([$old => $hash('old')], [$old.'.disabled' => $hash('old')], [$new => $hash('new')], [['scope' => 'mod_id', 'pattern' => 'example', 'mode' => 'replace']], $ids);
check(array_column($plan['changes'], 'action', 'path')[$new] === 'skip_disabled', 'A replacement rule reactivated a disabled renamed mod');
$plan = $p->plan([$old => $hash('old')], [$old => $hash('old')], [$new => $hash('new')], [['scope' => 'mod_id', 'pattern' => 'example', 'mode' => 'protect']], $ids);
check(array_column($plan['changes'], 'action', 'path')[$new] === 'preserve', 'Mod ID policy did not survive rename');
$plan = $p->plan([], ['mods/extra.jar' => $hash('extra')], [], [['scope' => 'exact', 'pattern' => 'mods/extra.jar', 'mode' => 'remove']]);
check($plan['changes'][0]['action'] === 'remove_with_override' && $plan['changes'][0]['destructive'] && $plan['changes'][0]['approval_required'], 'Explicit removal of a local extra was not reviewed/destructive');
$plan = $p->plan($base, $base, [], [['scope' => 'glob', 'pattern' => '**', 'mode' => 'review', 'classification' => 'removed_upstream']]);
check($plan['changes'][0]['action'] === 'manual_review', 'Upstream deletion bypassed review template');
$plan = $p->plan(['mods/unknown-old.jar' => $hash('old')], [], ['mods/renamed.jar' => $hash('new')], [], ['target' => ['mods/renamed.jar' => 'known-target']]);
check($plan['blocked'] === 1 && array_column($plan['changes'], 'action', 'path')['mods/renamed.jar'] === 'unsafe_ambiguous_identity', 'Unknown removed mod was guessed unrelated to an identified target');
$plan = $p->plan(['mods/old.jar' => $hash('old')], ['mods/old.jar.disabled' => $hash('old')], ['mods/new.jar' => $hash('new')], [],
    ['base' => ['mods/old.jar' => 'core+bundled'], 'local' => ['mods/old.jar.disabled' => 'core+bundled'], 'target' => ['mods/new.jar' => 'core+different']]);
check(array_column($plan['changes'], 'action', 'path')['mods/new.jar'] === 'skip_disabled', 'Bundled metadata change reactivated a disabled mod');
$plan = $p->plan(['mods/old.jar' => $hash('old')], ['mods/old.jar.disabled' => $hash('old')], ['mods/new.jar' => $hash('new')],
    [['scope' => 'mod_id', 'pattern' => 'example', 'mode' => 'disable']],
    ['base' => ['mods/old.jar' => 'example'], 'local' => ['mods/old.jar.disabled' => 'example'], 'target' => ['mods/new.jar' => 'example']]);
check($plan['blocked'] === 0 && array_column($plan['changes'], 'action', 'path')['mods/old.jar.disabled'] === 'preserve_disabled', 'Keep disabled tried to disable an already-disabled file or blocked it');
$roots = SafetyPaths::roots("level-name=custom/maps\n");
foreach (['custom/maps/level.dat', 'custom/maps_nether/DIM-1/data/x', 'world/region/a.mca', 'unusual/DIM42/data/x', '.arcturus-modpack-manager/checkpoint'] as $file) {
    $plan = $p->plan([$file => $hash('old')], [$file => $hash('old')], [$file => $hash('new')], [['pattern' => '**', 'mode' => 'replace']], [], [], $roots);
    check($plan['changes'][0]['action'] === 'preserve' && $plan['changes'][0]['rule'] === 'immutable', 'Invariant overridden: '.$file);
}
foreach (['config/Paradigm/x.toml', 'config/arcturus.cfg', 'Crucible.jar', 'run.sh', 'server.properties', 'ops.txt'] as $file) {
    check($p->plan([$file => $hash('old')], [$file => $hash('old')], [$file => $hash('new')])['changes'][0]['action'] === 'update', 'Name-based hardcoded policy remains: '.$file);
}
foreach (['../escape', '/world', 'C:/data', 'config//x'] as $unsafe) {
    rejects(fn () => $policy->normalize([['pattern' => $unsafe, 'mode' => 'replace']]), 'Unsafe rule accepted');
}
check($policy->template('empty') === [], 'Empty template not supported');
foreach (ProtectionPolicy::templates() as $id => $label) {
    $policy->normalize($policy->template($id));
}
rejects(fn () => SafetyPaths::roots('level-name=../outside'), 'Unsafe custom world accepted');
rejects(fn () => SafetyPaths::roots('level-name=custom\\ world'), 'Escaped world was guessed');
echo "Server-scoped selectors, precedence, overlaps, disabled renames, explicit removals and dynamic world invariants passed\n";
