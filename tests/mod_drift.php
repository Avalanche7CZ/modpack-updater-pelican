<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\ModDrift;
use Arcturus\ModpackManager\Services\ModUpgradePlanner;

foreach (['forge' => 'META-INF/mods.toml', 'neoforge' => 'META-INF/neoforge.mods.toml'] as $loader => $source) {
    $old = "mods/example-$loader-1.0.jar";
    $new = "mods/example-$loader-2.0.jar";
    $actual = "example-$loader-0.9.jar";
    $before = hash('sha256', 'release 1'); $after = hash('sha256', 'release 2');
    $base = ['files' => [$old => $before], 'identities' => [$old => 'example'],
        'metadata' => [$old => ['loader' => $loader, 'version' => '1.0']]];
    $target = ['files' => [$new => $after], 'identities' => [$new => 'example'],
        'metadata' => [$new => ['loader' => $loader, 'source' => $source, 'identity' => 'example', 'ids' => ['example'], 'sha256' => $after]]];
    $select = fn ($base, $target) => ModDrift::targets($base, $target, [], [], []);
    $selected = $select($base, $target);
    check(isset(ModDrift::candidates([['name' => $actual]], $selected, [])['mods/'.$actual]), 'A legacy baseline did not discover the differently versioned JAR');
    check(ModUpgradePlanner::verifiedIdentity($before, 'example', $base['metadata'][$old]) === null, 'Legacy hints were trusted as proof of installed bytes');
    check(ModDrift::targets($base, $target, [], [], ['example']) === [], 'An explicitly disabled mod became a replacement candidate');
    check(ModDrift::targets($base, $target, [], ['mods'], []) === [], 'A protected mods folder became a replacement candidate');
    foreach (['identity' => 'other', 'ids' => ['other'], 'sha256' => $after, 'source' => 'unverified/source', 'loader' => 'fabric'] as $field => $value) {
        $conflicting = $base;
        $conflicting['metadata'][$old][$field] = $value;
        check($select($conflicting, $target) === [], 'Contradictory legacy metadata was accepted: '.$field);
    }
    $unverified = $target; unset($unverified['metadata'][$new]['source']);
    check($select($base, $unverified) === [], 'Legacy metadata was accepted for both sides of a replacement');
}

echo "Legacy Forge/NeoForge manifest hints discover alternate JARs without weakening live or target proof\n";
