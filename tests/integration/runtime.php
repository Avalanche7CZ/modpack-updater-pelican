<?php

declare(strict_types=1);

$app = require __DIR__.'/bootstrap.php';

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Services\AdoptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Factory;

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
// Some Wings builds accept "." instead of "/" for their root; only the
// failed read-only 400/404 root listing may be retried. Still detect loader.
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($directories, $contents, $headers) {
    check($request->method() === 'GET', 'Wings runtime fallback sent a mutating request');
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    $path = parse_url($request->url(), PHP_URL_PATH);
    if (str_ends_with($path, '/files/list-directory')) {
        $dir = $query['directory'] ?? '';
        if ($dir === '/') {
            return Http::response(['error' => 'Root spelling unsupported'], 404, $headers);
        }
        $key = $dir === '.' ? '' : ltrim($dir, '/');
        check(array_key_exists($key, $directories), 'Unexpected fallback directory');
        return Http::response($directories[$key], 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        $file = ltrim($query['file'] ?? '', '/');
        check(array_key_exists($file, $contents), 'Unexpected fallback runtime file');
        return Http::response($contents[$file], 200, $headers);
    }
    return Http::response(['state' => 'running'], 200, $headers);
});
$alternate = app(AdoptionService::class)->inspect($server);
check($alternate['loader'] === 'neoforge' && $alternate['minecraft'] === '1.21.1',
    'Alternate Wings root lookup lost valid runtime evidence');
check($alternate['inspection_complete'] === false &&
    str_contains(implode(' ', $alternate['warnings']), 'alternate directory spelling'),
    'Root fallback must be transparent and mark results partial');

// A legitimate modpack can contain a file with a colon that is outside our
// safe inspection scope. Do not follow or operate on the file; skip it.
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($directories, $contents, $headers, $row) {
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    $path = parse_url($request->url(), PHP_URL_PATH);
    if (str_ends_with($path, '/files/list-directory')) {
        $key = ltrim($query['directory'] ?? '', '/');
        check(array_key_exists($key, $directories), 'Unexpected directory in unsafe filename case');
        $entries = $directories[$key];
        if ($key === '') {
            $entries[] = $row('bad:filename', false);
        }
        return Http::response($entries, 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        $key = ltrim($query['file'] ?? '', '/');
        check(array_key_exists($key, $contents), 'Unexpected runtime file in unsafe filename case');
        return Http::response($contents[$key], 200, $headers);
    }
    return Http::response(['state' => 'running'], 200, $headers);
});
$unusual = app(AdoptionService::class)->inspect($server);
check($unusual['loader'] === 'neoforge' && $unusual['minecraft'] === '1.21.1',
    'An unrelated unsafe filename blocked runtime identification');
check($unusual['inspection_complete'] === false &&
    str_contains(implode(' ', $unusual['warnings']), 'outside the safe inspection scope'),
    'Ignored unsafe names must produce a visible incomplete inspection warning');

// HTTP 403 must never retry an alternate path, expose node secrets, or
// invent an installed runtime. Pelican server metadata remains available.
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($headers) {
    check($request->method() === 'GET', 'Failed runtime lookup modified server');
    $path = parse_url($request->url(), PHP_URL_PATH);
    if (str_ends_with($path, '/files/list-directory')) {
        return Http::response(['error' => 'Access denied'], 403, $headers);
    }
    return Http::response(['state' => 'running'], 200, $headers);
});
$blocked = app(AdoptionService::class)->inspect($server);
check($blocked['loader'] === null && $blocked['minecraft'] === null &&
    $blocked['inspection_complete'] === false, 'HTTP 403 pretended loader verification succeeded');
check($blocked['diagnostic'] === ['stage' => 'wings_root_listing', 'code' => 'HTTP_403'] &&
    $blocked['evidence_source'] === 'panel_settings_only' &&
    $blocked['launch_method'] === 'script' && $blocked['image'] === 'java_21' &&
    $blocked['java_confidence'] === 'image_hint',
    'Wings root failure erased Panel startup/image metadata or diagnostic');
check(! str_contains(json_encode($blocked), 'fixture-token'), 'Node token leaked into inspection report');
check(Http::recorded()->filter(fn ($pair) =>
    str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/files/list-directory'))->count() === 1,
    'HTTP 403 must never trigger a root retry');

echo "Remote runtime inspection reads nested active arguments without worker, world access or power changes\n";
$GLOBALS['arcturus_integration_passed'] = true;
