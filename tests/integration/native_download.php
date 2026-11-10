<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Native\Preparation;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Security\DownloadPolicy;
use Symfony\Component\Process\Process;

$root = sys_get_temp_dir().'/arcturus-native-tls-'.bin2hex(random_bytes(8)); mkdir($root, 0700);
$fixture = new Process(['/usr/bin/python3', '-B', __DIR__.'/fixtures/native_tls/server.py', $root]);
$fixture->start();
$environment = [];
$managed = ManagedServer::create(['label' => 'Native download wiring', 'pelican_server_uuid' => (string) \Illuminate\Support\Str::uuid(),
    'provider' => 'custom', 'source' => 'native-download-fixture', 'installed_version' => '1', 'transport' => 'wings']);
$stale = ManagedServer::findOrFail($managed->id);
app(\Arcturus\ModpackManager\Services\FilesystemBackendSettings::class)->save($managed, 'native');
check($managed->transport === 'native', 'Backend selection left its caller stale');
check(app(ServerFilesFactory::class)->worker($stale) instanceof Preparation, 'A stale model routed a persisted native record to the old worker');
try {
    $deadline = microtime(true) + 10;
    while (! is_file($root.'/routing.json') && microtime(true) < $deadline && $fixture->isRunning()) { usleep(10000); }
    check(is_file($root.'/routing.json'), 'TLS fixture failed to start: '.$fixture->getErrorOutput());
    $route = json_decode(file_get_contents($root.'/routing.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['SSL_CERT_FILE' => $root.'/cert.pem', 'PYTHONPATH' => __DIR__.'/fixtures/native_tls', 'ARCTURUS_TEST_TLS_ROUTING' => $root.'/routing.json'] as $key => $value) {
        $environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null]; putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
    }
    config(['arcturus-modpack-manager.allowed_custom_hosts' => []]);
    $worker = app(ServerFilesFactory::class)->worker(ManagedServer::findOrFail($managed->id));
    check($worker instanceof Preparation, 'Persisted native transport did not construct Native Preparation');
    $entry = $worker->configuration()[$managed->pelican_server_uuid];
    check($entry['allowed_hosts'] === DownloadPolicy::HOSTS, 'Native default configuration lost official hosts');
    $prepare = function (string $url, ?string $hash = null) use ($worker, $route): array {
        return $worker->request('prepare', ['operation_id' => bin2hex(random_bytes(16)), 'recipe' => ['schema' => 2,
            'provider' => 'custom', 'project' => 'native-download-fixture', 'version' => '2', 'format' => 'zip',
            'downloads' => [['url' => $url, 'archive' => true, 'hashes' => ['sha256' => $hash ?? $route['sha256']]]]]]);
    };
    foreach (['https://github.com/archive', 'https://github.com/redirect', 'https://files.feed-the-beast.com/archive', 'https://cdn.modrinth.com/archive'] as $url) {
        $result = $prepare($url);
        check($result['state'] === 'completed', 'Official native download failed: '.json_encode($result));
        $manifest = $worker->request('manifest', ['operation_id' => $result['operation_id']]);
        check($manifest['files']['config/options.cfg'] === hash('sha256', 'verified upstream'), 'Downloaded ZIP was not verified/extracted/manifested');
    }
    $before = file_get_contents($root.'/requests.json');
    check($prepare('https://github.com/redirect')['state'] === 'completed', 'Verified artifact could not be reused');
    check(file_get_contents($root.'/requests.json') === $before, 'Identical verified artifact was re-downloaded');
    $cacheUrl = 'https://github.com/archive?cache=integrity-fixture';
    $cached = $prepare($cacheUrl);
    file_put_contents($entry['staging'].'/'.$cached['operation_id'].'/download-0', 'corrupt retained archive');
    $beforeRepair = json_decode(file_get_contents($root.'/requests.json'), true)['/archive'];
    check($prepare($cacheUrl)['state'] === 'completed', 'A corrupt retained artifact permanently blocked a fresh verified download');
    check(json_decode(file_get_contents($root.'/requests.json'), true)['/archive'] === $beforeRepair + 1, 'Corrupt bytes were trusted instead of freshly downloaded');
    check(count(glob($entry['staging'].'/'.$cached['operation_id'].'/download-0.unverified-*')) === 1, 'Corrupt artifact evidence was lost');
    $brokenReceipt = $entry['staging'].'/'.str_repeat('b', 32);
    mkdir($brokenReceipt, 0700); file_put_contents($brokenReceipt.'/operation.json', '{invalid receipt');
    check($prepare('https://github.com/archive?cache=after-malformed-receipt')['state'] === 'completed', 'An unrelated malformed cache receipt blocked preparation');
    check(file_get_contents($brokenReceipt.'/operation.json') === '{invalid receipt', 'Malformed receipt evidence was deleted');
    // Required-file resolution uses the same production Native configuration and HTTPS policy.
    foreach (['https://edge.forgecdn.net/dependency' => true, 'https://github.com/bad' => false] as $dependency => $allowed) {
        $id = bin2hex(random_bytes(16));
        $pending = $worker->request('prepare', ['operation_id' => $id, 'recipe' => ['schema' => 2, 'provider' => 'curseforge', 'project' => '1', 'version' => '2', 'format' => 'curseforge',
            'downloads' => [['url' => 'https://edge.forgecdn.net/client', 'archive' => true, 'hashes' => ['sha256' => $route['client_sha256']]]]]]);
        check($pending['state'] === 'awaiting_resolution', 'Native client manifest did not request dependency resolution');
        $result = $worker->request('resolve', ['operation_id' => $id, 'requirements_sha256' => $pending['requirements_sha256'], 'files' => [
            ['project_id' => '1', 'file_id' => '2', 'path' => 'mods/resolved.jar', 'url' => $dependency, 'hashes' => ['sha256' => $route['jar_sha256']]],
        ]]);
        check($result['state'] === ($allowed ? 'completed' : 'failed'), 'Resolved dependency bypassed or lost native download policy: '.json_encode($result));
        if ($allowed) {
            $resolved = $worker->request('manifest', ['operation_id' => $id]);
            check($resolved['files']['mods/resolved.jar'] === $route['jar_sha256'], 'Resolved dependency is missing from the verified manifest');
        } else { check(($result['diagnostic']['context']['reason'] ?? '') === 'REDIRECT_HOST_NOT_ALLOWED', 'Resolved dependency lost its policy reason'); }
    }
    config(['arcturus-modpack-manager.allowed_custom_hosts' => ['custom.example.test', 'private.example.test']]);
    $hosts = $worker->configuration()[$managed->pelican_server_uuid]['allowed_hosts'];
    check(! array_diff(DownloadPolicy::HOSTS, $hosts) && in_array('custom.example.test', $hosts, true), 'Custom hosts replaced official policy');
    check($prepare('https://custom.example.test/archive')['state'] === 'completed', 'Approved custom host failed');
    check($prepare('https://github.com/retry')['state'] === 'completed', 'Gateway retry lost official redirect policy');
    foreach (['bad' => 'REDIRECT_HOST_NOT_ALLOWED', 'private' => 'NON_PUBLIC_ADDRESS', 'downgrade' => 'HTTPS_REQUIRED', 'loop' => 'REDIRECT_LIMIT', 'retry-bad' => 'REDIRECT_HOST_NOT_ALLOWED'] as $path => $reason) {
        $result = $prepare('https://github.com/'.$path);
        check($result['state'] === 'failed' && $result['diagnostic']['code'] === 'DOWNLOAD_POLICY', 'Unsafe download was not denied: '.$path.' '.json_encode($result));
        check(($result['diagnostic']['context']['reason'] ?? '') === $reason, 'Specific policy reason was lost');
        $display = \Arcturus\ModpackManager\Services\PreparationDiagnostic::normalize($result['diagnostic']);
        check(($display['context']['reason'] ?? '') === $reason && isset($display['context']['host']), 'PHP dropped sanitized worker policy evidence');
        check(! str_contains(json_encode($result['diagnostic']), 'fixture-secret'), 'Signed URL leaked into public diagnostics');
    }
    $badHash = $prepare('https://github.com/archive', str_repeat('0', 64));
    check($badHash['state'] === 'failed' && $badHash['diagnostic']['code'] === 'CHECKSUM_MISMATCH', 'Checksum verification was bypassed');
    $managed->update(['source' => 'https://custom.example.test/recipe']);
    \Illuminate\Support\Facades\DB::table('servers')->insert(['uuid' => $managed->pelican_server_uuid, 'name' => 'Native pipeline fixture', 'node_id' => 1, 'owner_id' => 1,
        'startup' => 'java @unix_args.txt nogui', 'image' => 'fixture:java_21', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
    $app->instance(\Arcturus\ModpackManager\Modpacks\JsonClient::class, new class($route['sha256']) extends \Arcturus\ModpackManager\Modpacks\JsonClient {
        public function __construct(private string $hash) {}
        public function get(string $url, array $headers = []): array { return ['schema' => 2, 'version' => '1', 'format' => 'zip', 'downloads' => [['url' => 'https://github.com/redirect', 'archive' => true, 'hashes' => ['sha256' => $this->hash]]]]; }
    });
    \Illuminate\Support\Facades\Http::fake(function ($request) {
        $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/files/list-directory')) {
            $rows = ($q['directory'] ?? '/') === '/' ? [['name' => 'server.properties', 'file' => true, 'directory' => false, 'symlink' => false, 'size' => 17]] : [];
            return \Illuminate\Support\Facades\Http::response($rows, 200, $headers);
        }
        if (str_ends_with($path, '/files/contents')) { check(($q['file'] ?? '') === '/server.properties', 'Preparation read an unrelated server file'); return \Illuminate\Support\Facades\Http::response("level-name=world\n", 200, $headers); }
        return \Illuminate\Support\Facades\Http::response(['state' => 'running'], 200, $headers);
    });
    $operation = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($managed, 'baseline', ['provider' => 'custom', 'source' => $managed->source, 'version' => '1']);
    \Arcturus\ModpackManager\Jobs\PrepareUpdate::dispatch($managed->id, true, $operation->id);
    app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
    check($operation->fresh()->phase === 'completed' && $managed->fresh()->baseline_status === 'verified', 'Persisted native preparation did not complete through the real queue: '.$operation->fresh()->error);
    check($managed->fresh()->baseline_provenance['transport'] === 'native', 'Queue preparation used a different backend');
    echo "Native PHP configuration -> fixed LocalWorker -> pinned HTTPS/TLS Python download, redirects, SSRF denials, checksums, ZIP extraction, manifest and verified cache reuse passed\n";
    $GLOBALS['arcturus_integration_passed'] = true;
} finally {
    foreach ($environment as $key => [$value, $env, $server]) { putenv($value === false ? $key : $key.'='.$value); unset($_ENV[$key], $_SERVER[$key]); if ($env !== null) { $_ENV[$key] = $env; } if ($server !== null) { $_SERVER[$key] = $server; } }
    $fixture->stop(0);
    $native = dirname((new Preparation($managed))->configuration()[$managed->pelican_server_uuid]['staging']);
    foreach ([$root, $native] as $directory) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); } rmdir($directory);
    }
}
