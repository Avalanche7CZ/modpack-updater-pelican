<?php
/** Version-renamed mods retain identity without weakening deletion, local or custom-rule safeguards. */
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
$p = new UpdatePlanner;
$h = fn ($x) => hash('sha256', $x);
$base = ['mods/example-1.jar' => $h('old')]; $target = ['mods/example-2.jar' => $h('new')];
$ids = ['base' => ['mods/example-1.jar' => 'example'], 'target' => ['mods/example-2.jar' => 'example'], 'local' => ['mods/example-1.jar' => 'example']];
$rows = fn ($plan) => array_column($plan['changes'], null, 'path');
$rules = (new ProtectionPolicy)->template('tfg_arcturus');
$plan = $p->plan($base, $base, $target, $rules, $ids); $r = $rows($plan);
check($plan['blocked'] === 0 && $r['mods/example-1.jar']['action'] === 'remove' && $r['mods/example-2.jar']['action'] === 'add', 'Normal verified replacement required deletion review');
check($r['mods/example-1.jar']['classification'] === 'updated_upstream' && $r['mods/example-2.jar']['replacement']['base_path'] === 'mods/example-1.jar', 'Replacement linkage missing');
foreach (['mods/example-1.jar', 'mods/example-2.jar'] as $path) {
    $protected = $rows($p->plan($base, $base, $target, [...$rules, ['scope' => 'exact', 'pattern' => $path, 'mode' => 'protect']], $ids));
    check($protected['mods/example-1.jar']['action'] === 'preserve' && $protected['mods/example-2.jar']['action'] === 'preserve', 'Exact custom protection left a duplicate or removed the working version');
}
foreach ([['scope' => 'mod_id', 'pattern' => 'example'], ['scope' => 'mod_filename', 'pattern' => 'example-*.jar']] as $selector) {
    $protected = $rows($p->plan($base, $base, $target, [...$rules, [...$selector, 'mode' => 'protect']], $ids));
    check($protected['mods/example-1.jar']['action'] === 'preserve' && $protected['mods/example-2.jar']['action'] === 'preserve', 'Existing mod protection was lost');
}
$custom = ['mods/example-1.jar' => $h('locally edited')];
$plan = $p->plan($base, $custom, $target, $rules, $ids);
check($plan['blocked'] === 2 && $rows($plan)['mods/example-2.jar']['action'] === 'conflict', 'Locally modified JAR received an additional upstream copy');
$use = $p->plan($base, $custom, $target, [...$rules, ['scope' => 'mod_id', 'pattern' => 'example', 'mode' => 'replace']], $ids);
check($use['blocked'] === 0 && $rows($use)['mods/example-1.jar']['action'] === 'remove_with_override', 'Explicit identity decision did not resolve the pair');
$keep = $rows($p->plan($base, $custom, $target, [...$rules, ['scope' => 'mod_id', 'pattern' => 'example', 'mode' => 'protect']], $ids));
check($keep['mods/example-1.jar']['action'] === 'preserve' && $keep['mods/example-2.jar']['action'] === 'preserve', 'Keep local re-added the target version');
$review = $p->plan($base, $base, $target, [...$rules, ['scope' => 'exact', 'pattern' => 'mods/example-1.jar', 'mode' => 'review']], $ids);
check($review['blocked'] === 2, 'Explicit custom review was bypassed by automatic pairing');
check($rows($p->plan($base, [], $target, [], $ids))['mods/example-2.jar']['action'] === 'skip_disabled', 'Deleted mod was re-enabled');
$unknown = $p->plan($base, $base, $target, $rules, []);
check($unknown['blocked'] > 0 && $rows($unknown)['mods/example-1.jar']['replacement'] === null, 'Filename similarity guessed an unverified identity');
$duplicate = $p->plan($base, $base, [...$target, 'mods/duplicate.jar' => $h('duplicate')], [], [...$ids, 'target' => [...$ids['target'], 'mods/duplicate.jar' => 'example']]);
check($duplicate['blocked'] > 0 && $rows($duplicate)['mods/example-1.jar']['replacement'] === null, 'Duplicate identities were paired');
$removed = $p->plan($base, $base, [], $rules, $ids);
check($removed['blocked'] === 1, 'Genuine upstream removal lost manual review');
$changed = $p->plan($base, $custom, ['mods/example-1.jar' => $h('changed upstream')], [], $ids);
check($changed['blocked'] === 1, 'Same-filename genuine local conflict was hidden');
// Reported shape: six removal reviews are normal version pairs; three local edits remain genuine conflicts.
$b=[]; $l=[]; $t=[]; $map=['base'=>[], 'local'=>[], 'target'=>[]];
for ($i=0;$i<6;$i++) { $old="mods/mod$i-1.jar"; $new="mods/mod$i-2.jar"; $b[$old]=$l[$old]=$h("old$i"); $t[$new]=$h("new$i"); $map['base'][$old]=$map['local'][$old]=$map['target'][$new]="mod$i"; }
for ($i=0;$i<3;$i++) { $file="mods/custom$i.jar"; $b[$file]=$h('base'); $l[$file]=$h('local'); $t[$file]=$h('target'); }
$plan=$p->plan($b,$l,$t,$rules,$map);
check(($plan['counts']['manual_review'] ?? 0) === 0 && $plan['blocked'] === 3, 'Normal replacement reviews or genuine local conflicts were misclassified');
echo "Verified mod replacement pairing, custom protections, disabled/ambiguous identities and genuine conflicts passed\n";
