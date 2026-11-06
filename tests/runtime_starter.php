<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Services\RuntimeInspector;

$inspector = new RuntimeInspector();
$paths = ['start_server.sh', 'forge-auto-install.txt', 'server_starter.conf', 'minecraft_server.jar'];
$config = "# Forge Auto-Install Configuration\nminecraftVersion=1.20.1\nloaderType=Forge\nloaderVersion=47.4.13\n";
$texts = [
    'start_server.sh' => "#!/bin/bash\njava -Xmx6024M -Xms1024M -jar minecraft_server.jar nogui\n",
    'server_starter.conf' => "java_path=java\n",
    'forge-auto-install.txt' => $config,
];
$report = $inspector->inspect($paths, $texts, 'bash start_server.sh', 'ghcr.io/example/java_17', 'Generic Minecraft');
check($report['minecraft'] === '1.20.1' && $report['loader'] === 'forge'
    && $report['loader_version'] === '47.4.13', 'TFG Forge Server Starter metadata was not recognized');
check($report['loader_confidence'] === 'installer_config'
    && $report['java_confidence'] === 'image_hint'
    && $report['launch_method'] === 'script', 'TFG config/Java hints were incorrectly declared verified');
check(str_contains($report['pack_hint'], 'Forge Server Starter')
    && in_array('forge-auto-install.txt declares forge 47.4.13', $report['evidence'], true),
    'TFG configuration provenance missing');

// Earlier server-pack versions may contain unresolved placeholders.
// Never interpret them as a loader version.
$placeholder = $texts;
$placeholder['forge-auto-install.txt'] = "minecraftVersion=MINECRAFT_VERSION\nloaderType=LOADER_TYPE\nloaderVersion=LOADER_VERSION\n";
$unresolved = $inspector->inspect($paths, $placeholder, 'bash start_server.sh', 'java_17');
check($unresolved['loader'] === null && $unresolved['minecraft'] === null
    && $unresolved['loader_confidence'] === 'unknown', 'Placeholder metadata was trusted');

// NeoForge is valid only if explicitly named by the configuration; no
// inference from a generic jar name or egg is permitted.
$neoforge = $texts;
$neoforge['forge-auto-install.txt'] = "minecraftVersion=1.21.1\nloaderType=NeoForge\nloaderVersion=21.1.172\n";
$neo = $inspector->inspect($paths, $neoforge, 'bash start_server.sh', 'java_21');
check($neo['loader'] === 'neoforge' && $neo['loader_version'] === '21.1.172',
    'Explicit NeoForge starter configuration was not parsed');

// An existing exact launcher reference always wins over installer config;
// do not replace verified evidence with stale auto-install settings.
$paths[] = 'libraries/net/neoforged/neoforge/21.1.172/unix_args.txt';
$active = $inspector->inspect($paths, $texts,
    'java @libraries/net/neoforged/neoforge/21.1.172/unix_args.txt', 'java_21');
check($active['loader'] === 'neoforge' && $active['loader_confidence'] === 'file_evidence',
    'Stale Forge starter config overrode explicitly referenced active launcher');

echo "Forge Server Starter/TerraFirmaGreg runtime metadata tests passed\n";
