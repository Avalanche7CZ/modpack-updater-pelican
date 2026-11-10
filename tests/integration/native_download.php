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
    'provider' => 'custom', 'source' => 'native-download-fixture', 'installed_version' => '1', 'transport' => 'native']);
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
