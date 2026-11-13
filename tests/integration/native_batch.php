<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\Wings;
use Arcturus\ModpackManager\Native\Transaction;
use Illuminate\Support\Facades\Http;

$node = (new Node)->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture', 'daemon_token_id' => 'fixture']);
$server = (new Server)->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']); $server->setRelation('node', $node);
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
$dirs = []; $data = []; $expected = [];
$row = fn ($name, $directory, $size = 0) => ['name' => $name, 'file' => ! $directory, 'directory' => $directory, 'symlink' => false, 'size' => $size, 'modified' => 'fixture', 'mode_bits' => '644'];
$dirs[''] = ['config' => $row('config', true)]; $dirs['config'] = ['nested' => $row('nested', true)];
for ($i = 0; $i < 2048; $i++) {
    $group = 'g'.intdiv($i, 128); $name = 'f'.$i.'.cfg'; $path = 'config/nested/'.$group.'/'.$name;
    $dirs['config/nested'][$group] = $row($group, true);
    $dirs['config/nested/'.$group][$name] = $row($name, false, 16);
    $data[$path] = str_pad((string) $i, 16, 'x'); $expected[$path] = hash('sha256', $data[$path]);
}
$expected['config/nested/g0/absent.cfg'] = null;
$counts = []; $mode = ''; $reads = 0; $maxChunk = 0;
$install = function () use (&$counts, &$mode, &$reads, &$maxChunk, &$dirs, &$data, $headers): void {
    $counts = ['list' => 0, 'contents' => 0]; $reads = 0;
    Http::swap(new class extends \Illuminate\Http\Client\Factory {
        // Benchmark counters above replace Laravel's unbounded test-only response recording.
        public function recordRequestResponsePair($request, $response) { return $this; }
    }); Http::preventStrayRequests();
    Http::fake(function ($request, array $options) use (&$counts, &$mode, &$reads, &$maxChunk, &$dirs, &$data, $headers) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/list-directory')) {
            $counts['list']++; $dir = trim($q['directory'], '/');
            if ($mode === 'denied' && $dir === 'config/nested') { return Http::response([], 403, $headers); }
            $rows = array_values($dirs[$dir] ?? []);
            if (in_array($mode, ['parent_link', 'parent_swap'], true) && $reads > 0 && $dir === 'config') {
                $rows[0][$mode === 'parent_link' ? 'symlink' : 'modified'] = $mode === 'parent_link' ? true : 'changed';
            }
            if ($dir === 'config/nested/g0' && $rows !== []) {
                if ($mode === 'missing_metadata') { unset($rows[0]['modified']); }
                if ($mode === 'leaf_link') { $rows[0]['symlink'] = true; }
                if ($mode === 'leaf_changed' && $reads > 0) { $rows[0]['modified'] = 'changed'; }
            }
            return Http::response($rows, 200, $headers);
        }
        $counts['contents']++; $reads++;
        check(($options['stream'] ?? false) === true && ($options['allow_redirects'] ?? true) === false && ($options['read_timeout'] ?? 0) === 5, 'Unbounded or buffered contents request');
        $path = ltrim($q['file'], '/');
        if ($mode === 'failure' && $reads === 2) { return Http::response('', 500, $headers); }
        if ($mode === 'large') {
            $left = 64 * 1048576;
            $stream = new \GuzzleHttp\Psr7\PumpStream(function ($length) use (&$left, &$maxChunk) {
                $maxChunk = max($maxChunk, $length); if ($left === 0) { return false; }
                $n = min($length, $left); $left -= $n; return str_repeat('x', $n);
            });
            return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, $headers, $stream));
        }
        $value = $data[$path];
        if ($mode === 'changed') { $value = str_repeat('z', strlen($value)); }
        if ($mode === 'truncated') { $value = substr($value, 1); }
        return Http::response($value, 200, $headers);
    });
};
$files = new Wings($server, app(DaemonFileRepository::class));
$install(); $start = hrtime(true);
foreach ($expected as $path => $hash) { check($files->hash($path) === $hash, 'Legacy fixture mismatch'); }
$old = $counts + ['seconds' => (hrtime(true) - $start) / 1e9];
$install(); $start = hrtime(true); $progress = [];
(new Transaction($files))->verify($expected, function ($done, $total) use (&$progress) { $progress[] = [$done, $total]; });
$new = $counts + ['seconds' => (hrtime(true) - $start) / 1e9];
check($new['list'] < $old['list'] / 10 && $new['contents'] === $old['contents'], 'Directory sharing did not reduce requests while retaining all hashes');
check(end($progress) === [2049, 2049], 'Proven absence not counted');
echo json_encode(['paths' => count($expected), 'serial_before' => $old, 'batch_after' => $new, 'contents_concurrency' => 1], JSON_PRETTY_PRINT)."\n";
$sample = array_slice($expected, 0, 3, true);
foreach (['missing_metadata', 'changed', 'leaf_changed', 'leaf_link', 'parent_link', 'parent_swap', 'truncated', 'failure', 'denied'] as $mode) {
    $install(); $reported = 0;
    rejects(fn () => (new Transaction($files))->verify($sample, function () use (&$reported) { $reported++; }), 'Unsafe batch accepted: '.$mode);
    check($reported === 0, 'Failed batch published partial verified progress');
}
$mode = ''; $install();
rejects(fn () => (new Transaction($files))->verify([array_key_first($sample) => null]), 'Unexpected existing file accepted');
rejects(fn () => (new Transaction($files))->verify(['config/nested/g0/missing.cfg' => str_repeat('a', 64)]), 'Unexpected missing file accepted');
// No metadata survives a call, even on the same Wings instance.
$install(); $files->hashBatch(array_keys($sample)); $n = $counts['list']; $files->hashBatch(array_keys($sample));
check($counts['list'] === 2 * $n, 'Metadata survived a batch boundary');
$mode = 'large'; $install();
$dirs['config/nested/g0']['large.bin'] = $row('large.bin', false, 64 * 1048576);
$ctx = hash_init('sha256'); for ($i = 0; $i < 64; $i++) { hash_update($ctx, str_repeat('x', 1048576)); }
$largeHash = hash_final($ctx); gc_collect_cycles(); memory_reset_peak_usage(); $baseMemory = memory_get_usage(true);
check($files->hashBatch(['config/nested/g0/large.bin'])['config/nested/g0/large.bin'] === $largeHash, 'Large file hash differs');
$growth = memory_get_peak_usage(true) - $baseMemory;
check($maxChunk <= 1048576 && $growth < 16 * 1048576, 'Large content was buffered in memory');
echo '64 MiB stream: peak growth '.$growth.' bytes; max requested read '.$maxChunk." bytes\n";
echo "Native bounded batch integrity/failure tests passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
