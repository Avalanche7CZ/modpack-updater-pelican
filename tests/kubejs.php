<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Services\KubeJsDiff;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$hash = fn ($text) => hash('sha256', $text);
$old = $hash('old'); $new = $hash('new'); $custom = $hash('custom');
$cases = [
    'server_scripts/unchanged.js' => [$old, $old, $new, 'update'],
    'server_scripts/new.js' => [null, null, $new, 'add'],
    'server_scripts/obsolete.js' => [$old, $old, null, 'remove'],
    'server_scripts/local-only.js' => [null, $custom, null, 'preserve_local_extra'],
    'server_scripts/local-edit.js' => [$old, $custom, $old, 'preserve_local_change'],
    'server_scripts/conflict.js' => [$old, $custom, $new, 'conflict'],
    'server_scripts/deleted-custom.js' => [$old, $custom, null, 'conflict'],
    'server_scripts/collision.js' => [null, $custom, $new, 'conflict'],
    'server_scripts/already-new.js' => [$old, $new, $new, 'unchanged'],
    'data/pack/recipes/item.json' => [$old, $old, $new, 'update'],
    'assets/pack/template.nbt' => [$old, $custom, $new, 'conflict'],
    'protected.js' => [$old, $old, $new, 'preserve'],
    'removed-protected.js' => [$old, $old, null, 'preserve'],
    'server_scripts/playerdata/id.dat' => [$old, $old, $new, 'preserve'],
];
foreach (['tfg', 'tfg_arcturus'] as $preset) {
    $rules = (new ProtectionPolicy)->template($preset);
    $rules[] = ['scope' => 'exact', 'pattern' => 'kubejs/protected.js', 'mode' => 'protect'];
    $rules[] = ['scope' => 'exact', 'pattern' => 'kubejs/removed-protected.js', 'mode' => 'protect'];
    foreach ($cases as $name => [$base, $local, $target, $expected]) {
        $path = 'kubejs/'.$name;
        $plan = (new UpdatePlanner)->plan($base ? [$path => $base] : [], $local ? [$path => $local] : [], $target ? [$path => $target] : [], $rules);
        check($plan['changes'][0]['action'] === $expected, $preset.' KubeJS ownership incorrect: '.$path);
    }
    $path = 'kubejs/server_scripts/conflict.js';
    foreach (['protect' => 'preserve', 'replace' => 'replace_with_override'] as $choice => $expected) {
        $plan = (new UpdatePlanner)->plan([$path => $old], [$path => $custom], [$path => $new], [...$rules, ['scope' => 'exact', 'pattern' => $path, 'mode' => $choice]]);
        check($plan['changes'][0]['action'] === $expected && $plan['blocked'] === 0, 'KubeJS conflict choice not applied');
    }
}
$diff = new KubeJsDiff;
$review = $diff->text("// same\nold recipe\n", "// same\nnew recipe\n");
check(str_contains($review, '-old recipe') && str_contains($review, '+new recipe'), 'Readable two-way script diff missing');
check(str_contains($diff->text(str_repeat("old\n", 1000), str_repeat("new\n", 1000)), 'truncated'), 'Large diff not bounded');
foreach (["\0NBT", "\xff", str_repeat('x', 65537)] as $binary) {
    rejects(fn () => $diff->text($binary, ''), 'Binary/oversize diff accepted');
}
check(KubeJsDiff::supports('kubejs/data/pack/recipes/item.json') && ! KubeJsDiff::supports('kubejs/assets/pack/structure.nbt'), 'Text/binary support incorrect');
echo "TFG KubeJS upstream ownership, local/protected preservation, explicit choices and bounded text-only diff passed\n";
