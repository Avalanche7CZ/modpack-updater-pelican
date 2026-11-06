<?php

declare(strict_types=1);

$app = require __DIR__.'/bootstrap.php';

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Services\AdoptionService;
use Illuminate\Support\Facades\Http;

$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080,
    'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$server = new Server;
$server->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'startup' => 'bash run.sh', 'image' => 'java_21']);
$egg = new Egg;
$egg->forceFill(['name' => 'FTB server']);
$server->setRelation('node', $node);
$server->setRelation('egg', $egg);
$row = fn (string $name, bool $directory = true, bool $symlink = false) => ['name' => $name, 'directory' => $directory,
    'file' => ! $directory, 'symlink' => $symlink, 'size' => 200];
$coordinate = 'libraries/net/neoforged/neoforge';
$directories = [
    '' => [$row('run.sh', false), $row('libraries'), $row('world'), $row('mods')],
    'libraries' => [$row('net')],
    'libraries/net' => [$row('neoforged'), $row('unrelated')],
    'libraries/net/neoforged' => [$row('neoforge')],
    $coordinate => [$row('21.1.100'), $row('21.1.172'), $row('21.1.999', true, true)],
    $coordinate.'/21.1.100' => [$row('unix_args.txt', false)],
    $coordinate.'/21.1.172' => [$row('unix_args.txt', false)],
];
$contents = ['run.sh' => '#!/bin/sh'."\n".'java @user_jvm_args.txt @'.$coordinate.'/21.1.172/unix_args.txt "$@"',
    $coordinate.'/21.1.100/unix_args.txt' => '--fml.mcVersion 1.21.1',
    $coordinate.'/21.1.172/unix_args.txt' => '--launchTarget neoforgeserver --fml.neoForgeVersion 21.1.172 --fml.mcVersion 1.21.1'];
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
Http::preventStrayRequests();
Http::fake(function ($request) use ($directories, $contents, $headers) {
    check($request->method() === 'GET', 'Runtime inspection sent a mutating request');
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    $path = parse_url($request->url(), PHP_URL_PATH);
    if (str_ends_with($path, '/files/list-directory')) {
        $dir = ltrim($query['directory'] ?? '', '/');
        if (! array_key_exists($dir, $directories)) {
            throw new RuntimeException('Unexpected runtime directory: '.$dir);
        }

        return Http::response($directories[$dir], 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        $file = ltrim($query['file'] ?? '', '/');
        if (! array_key_exists($file, $contents)) {
            throw new RuntimeException('Unexpected configuration read: '.$file);
        }

        return Http::response($contents[$file], 200, $headers);
    }

    return Http::response(['state' => 'running'], 200, $headers);
});
$report = app(AdoptionService::class)->inspect($server);
check($report['state'] === 'running' && $report['loader'] === 'neoforge' && $report['loader_version'] === '21.1.172' && $report['minecraft'] === '1.21.1',
    'FTB-like remote inspection did not identify active NeoForge and Minecraft');
check($report['java_confidence'] === 'image_hint', 'Inspection pretended to execute or verify Java');
foreach (Http::recorded() as [$request, $response]) {
    check(! preg_match('~world|/mods|21\\.1\\.999|/power|/reinstall~', urldecode($request->url())), 'Inspection entered world/mod trees, followed a link or controlled power');
}
echo "Remote runtime inspection reads nested active arguments without worker, world access or power changes\n";
$GLOBALS['arcturus_integration_passed'] = true;
