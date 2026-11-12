<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\Wings;
use Illuminate\Support\Facades\Http;

$node = new Node; $node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$server = new Server; $server->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']); $server->setRelation('node', $node);
$data = ['candidate.jar' => "PK\x00\x01binary\xff"];
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
$noopRename = false; $truncated = false; $symlink = false; $writes = 0;
Http::preventStrayRequests();
Http::fake(function ($request, array $options) use (&$data, &$noopRename, &$truncated, &$symlink, &$writes, $headers) {
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    $path = parse_url($request->url(), PHP_URL_PATH);
    if (str_ends_with($path, '/files/list-directory')) {
        return Http::response(array_map(fn ($name) => ['name' => $name, 'file' => true, 'directory' => false, 'symlink' => $symlink && $name === 'candidate.jar', 'size' => strlen($data[$name])], array_keys($data)), 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        $value = $data[ltrim($query['file'], '/')];
        return Http::response($truncated ? substr($value, 0, 3) : $value, 200, $headers);
    }
    if (str_ends_with($path, '/files/write')) {
        check($request->method() === 'POST', 'Wrong native upload method');
        check(is_callable($options['progress'] ?? null), 'Long transfer has no operation heartbeat');
        if (ltrim($query['file'], '/') === 'fenced.jar') {
            // Guzzle may own and close the upload stream when an abort unwinds.
            $body = $options['body'] ?? null;
            if ($body instanceof \Psr\Http\Message\StreamInterface) { $body->close(); }
            elseif (is_resource($body)) { fclose($body); }
        }
        $options['progress'](0, 0, 131072, 0);
        check((int) $request->header('Content-Length')[0] === strlen($request->body()), 'Binary upload Content-Length differs from body');
        $data[ltrim($query['file'], '/')] = $request->body(); $writes++;
        return Http::response('', 204, $headers);
    }
    if (str_ends_with($path, '/files/rename')) {
        if (! $noopRename) { $from = $request['files'][0]['from']; $to = $request['files'][0]['to']; $data[$to] = $data[$from]; unset($data[$from]); }
        return Http::response('', 204, $headers);
    }
    if (str_ends_with($path, '/files/delete')) { foreach ($request['files'] as $p) { unset($data[$p]); } return Http::response('', 204, $headers); }
    throw new RuntimeException('Unexpected Wings request '.$request->method().' '.$path);
});
$files = new Wings($server, app(DaemonFileRepository::class));
check($files->hash('candidate.jar') === hash('sha256', $data['candidate.jar']), 'Binary candidate was not streamed correctly');
check($files->hash('absent.jar') === null, 'Absent candidate was invented');
$temp = tempnam(sys_get_temp_dir(), 'arcturus-binary-'); file_put_contents($temp, str_repeat("\xff\x00", 65536));
try {
    $files->upload($temp, 'uploaded.jar', hash_file('sha256', $temp));
    check($writes === 1 && $data['uploaded.jar'] === file_get_contents($temp), 'Authenticated native binary upload failed');
    $writesBeforeFence = $writes;
    $fenced = new Wings($server, app(DaemonFileRepository::class), fn () => throw new RuntimeException('Fixture execution fenced'));
    try { $fenced->upload($temp, 'fenced.jar', hash_file('sha256', $temp)); throw new RuntimeException('A fenced upload continued writing'); }
    catch (RuntimeException $error) { check($error->getMessage() === 'Fixture execution fenced', 'Stream cleanup hid the actual transfer fence'); }
    check($writes === $writesBeforeFence && ! isset($data['fenced.jar']), 'Progress fencing did not abort the transfer');
    $files->rename('uploaded.jar', 'installed.jar');
    $noopRename = true;
    rejects(fn () => $files->rename('installed.jar', 'not-installed.jar'), 'A no-op 204 rename was trusted');
    $truncated = true;
    rejects(fn () => $files->hash('candidate.jar'), 'Truncated response was accepted');
    $truncated = false; $symlink = true;
    rejects(fn () => $files->hash('candidate.jar'), 'Symlink file was read');
    try { $files->hash('candidate.jar'); throw new RuntimeException('Missing link diagnostic'); }
    catch (\Arcturus\ModpackManager\Native\SymlinkPath $link) { check($link->path === 'candidate.jar', 'Protected link path is ambiguous'); }
    $files->delete('candidate.jar'); // Safe leaf symlink removal during root recovery.
    check(! isset($data['candidate.jar']), 'Leaf link could not be removed for recovery');
} finally { unlink($temp); }
// A linked ancestor is reported before opening any descendant or target.
Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
Http::fake(function ($request) use ($headers) {
    check(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/list-directory'), 'Linked ancestor target was accessed');
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
    check(($query['directory'] ?? '') === '/', 'Linked ancestor was traversed');
    return Http::response([['name' => 'linked', 'file' => false, 'directory' => true, 'symlink' => true, 'size' => 0]], 200, $headers);
});
try { (new Wings($server, app(DaemonFileRepository::class)))->hash('linked/secret.txt'); throw new RuntimeException('Linked ancestor was accepted'); }
catch (\Arcturus\ModpackManager\Native\SymlinkPath $link) { check($link->path === 'linked', 'Wrong protected ancestor'); }

foreach ([false, true] as $permanent) {
    Http::swap(new \Illuminate\Http\Client\Factory);
    $attempts = 0;
    Http::fake(function () use (&$attempts, $permanent, $headers) {
        $attempts++;
        if ($permanent || $attempts < 3) {
            $cause = new \GuzzleHttp\Exception\ConnectException('connection refused', new \GuzzleHttp\Psr7\Request('GET', 'https://wings.fixture.test'));
            throw new \Illuminate\Http\Client\ConnectionException('connection refused', 0, $cause);
        }
        return Http::response([], 200, $headers);
    });
    try {
        check((new Wings($server, app(DaemonFileRepository::class)))->listing() === [], 'Recovered listing differs');
        check(! $permanent, 'Permanent transport failure was swallowed');
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        check($permanent, 'Transient transport failure did not recover');
    }
    check($attempts === 3, 'Directory retry was not bounded to three attempts');
}
echo "Pelican rc.2 native Wings binary transfer, truncation, symlink and no-op rename integration passed (HTTP fixtures)\n";
$GLOBALS['arcturus_integration_passed'] = true;
