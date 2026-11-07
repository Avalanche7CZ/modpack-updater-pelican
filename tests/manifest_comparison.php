<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\ManifestComparison;
use Arcturus\ModpackManager\Services\UpdatePlanner;
use Arcturus\ModpackManager\Services\LocalDeployment;

$hash = fn ($s) => hash('sha256', $s);
$base = $target = [];
for ($i = 0; $i < 22000; $i++) { $base['kubejs/assets/generated/'.$i.'.json'] = $target['kubejs/assets/generated/'.$i.'.json'] = $hash('unchanged'); }
$base['mods/old.jar'] = $hash('old'); $target['mods/new.jar'] = $hash('new');
$base['config/example.cfg'] = $hash('original'); $target['config/example.cfg'] = $hash('updated');
$paths = ManifestComparison::paths($base, $target);
check($paths === ['config/example.cfg', 'mods/new.jar', 'mods/old.jar'], 'Unchanged pack paths entered the comparison');
$planner = new UpdatePlanner;
$plan = $planner->plan(ManifestComparison::select($base, $paths), ['mods/old.jar' => $hash('old'), 'config/example.cfg' => $hash('original')], ManifestComparison::select($target, $paths));
check($plan['blocked'] === 0 && ($plan['counts']['add'] ?? 0) === 1 && ($plan['counts']['remove'] ?? 0) === 1 && ($plan['counts']['update'] ?? 0) === 1, 'JAR delta requires optional embedded metadata');
$extra = $planner->plan(['mods/old.jar' => $hash('old')], ['mods/old.jar' => $hash('old'), 'mods/unknown-custom.jar' => $hash('custom')], ['mods/new.jar' => $hash('new')]);
check($extra['blocked'] === 0 && array_column($extra['changes'], 'action', 'path')['mods/unknown-custom.jar'] === 'preserve_local_extra', 'Unrecognized local JAR blocked unrelated manifest replacements');
$held = $planner->plan(['mods/old.jar' => $hash('old')], ['mods/old.jar' => $hash('custom')], ['mods/new.jar' => $hash('new')], [['pattern' => 'mods/old.jar', 'mode' => 'protect']]);
check($held['blocked'] === 1 && array_column($held['changes'], 'action', 'path')['mods/new.jar'] === 'manual_review', 'Keeping an unidentified old mod silently added a possible duplicate');
$root = sys_get_temp_dir().'/manifest-delta-'.bin2hex(random_bytes(8));
foreach (['live/mods', 'live/config', 'stage/mods', 'stage/config'] as $dir) { mkdir($root.'/'.$dir, 0700, true); }
file_put_contents($root.'/live/mods/old.jar', 'old'); file_put_contents($root.'/stage/mods/new.jar', 'new');
file_put_contents($root.'/live/config/example.cfg', 'original'); file_put_contents($root.'/stage/config/example.cfg', 'updated');
file_put_contents($root.'/live/mods/Paradigm.jar', 'custom');
$pulses = 0;
try {
    $result = (new LocalDeployment)->apply($root.'/live', $root.'/stage', '', $base, $target, [], true,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ['comparison_paths' => $paths], $plan['changes'], null, function () use (&$pulses) { $pulses++; });
    check(count($result['modified']) === 3 && $pulses < 50, 'Deployment re-read unrelated pack paths');
    check(! is_file($root.'/live/mods/old.jar') && file_get_contents($root.'/live/mods/new.jar') === 'new', 'Old/new JAR delta did not apply');
    check(file_get_contents($root.'/live/mods/Paradigm.jar') === 'custom', 'Untracked custom mod changed');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
echo "Manifest delta: 22000 unchanged paths skipped, metadata-free mod upgrade, custom mod preservation and bounded deployment passed\n";
