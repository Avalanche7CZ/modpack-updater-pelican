<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Runtime\RuntimePlan;
use Arcturus\ModpackManager\Services\RuntimeInspector;

$planner = new RuntimePlan;
$current = ['minecraft' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.1', 'java' => 21, 'java_confidence' => 'verified'];
$startup = 'java -Xms128M -XX:MaxRAMPercentage=95.0 @libraries/net/neoforged/neoforge/21.1.1/unix_args.txt nogui';
$reuse = $planner->make($current, $current, $startup, 'ghcr.io/pelican-eggs/yolks:java_21');
check($reuse['strategy'] === 'verified_installed_runtime_reuse' && $reuse['startup'] === $startup, 'Known runtime reuse changed startup');
$upgrade = $planner->make($current, [...$current, 'loader_version' => '21.1.2'], $startup, 'ghcr.io/pelican-eggs/yolks:java_21');
check(str_contains($upgrade['startup'], '/21.1.2/unix_args.txt') && str_contains($upgrade['startup'], '--installServer'), 'NeoForge migration must install dependencies before launching');
check(str_ends_with($upgrade['artifact']['url'], '/21.1.2/neoforge-21.1.2-installer.jar'), 'NeoForge installer coordinate differs from target');
check(RuntimePlan::minimumJava('26.1.2') === 25 && RuntimePlan::minimumJava('1.21.1') === 21 && RuntimePlan::minimumJava('1.20.1') === 17, 'Java floors are incorrect');
rejects(fn () => $planner->make($current, ['minecraft' => '26.1.2', 'loader' => 'neoforge', 'loader_version' => '26.1.2.121'], $startup, 'java_21'), 'Missing approved Java image must block');
rejects(fn () => $planner->make($current, [...$current, 'loader_version' => '21;curl bad'], $startup, 'java_21'), 'Shell metacharacters accepted as a loader version');
$inspector = new RuntimeInspector;
$boot = $inspector->fromStartupLog('Loading Minecraft 1.21.1 with Fabric Loader 0.16.14');
check($boot['loader'] === 'fabric' && $boot['minecraft'] === '1.21.1' && $boot['loader_version'] === '0.16.14', 'Fabric boot not recognized');
$boot = $inspector->fromStartupLog('--fml.mcVersion 26.1.2 --fml.neoForgeVersion 26.1.2.121');
check($boot['minecraft'] === '26.1.2' && $boot['loader'] === 'neoforge', 'Modern NeoForge boot not recognized');
check($inspector->javaVersion('openjdk version "25.0.1"') === 25 && $inspector->javaVersion('java version "1.8.0_442"') === 8, 'Java probe must distinguish Java 8 and 25');
check($inspector->fromStartupLog('unrelated console output')['loader'] === null, 'Unknown log invented a Forge runtime');
echo "Exact runtime planning and boot evidence tests passed\n";
