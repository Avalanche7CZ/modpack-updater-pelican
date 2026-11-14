<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\{Wings, PreparedStaging, Transaction, DeploymentMetrics, ArchiveOutcomeUnknown};
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

$process = null; $live = getenv('WINGS_BENCH_CONFIG');
if (! $live) { $process = new Process(['python3', '-u', __DIR__.'/fixtures/bulk_wings.py']); $process->start(); }
$stage = sys_get_temp_dir().'/arcturus-bulk-input-'.bin2hex(random_bytes(8)); mkdir($stage, 0700);
$remoteRoot = null;
try {
    if ($live) {
        $connection = json_decode(file_get_contents($live), true, 16, JSON_THROW_ON_ERROR);
        check(($connection['disposable'] ?? false) === true && ($connection['confirm_uuid'] ?? null) === ($connection['uuid'] ?? ''), 'Explicit disposable-server identity confirmation is required');
        $node = (new Node)->forceFill($connection['node']);
        $server = (new Server)->forceFill(['uuid' => $connection['uuid']]); $server->setRelation('node', $node);
        $details = app(\App\Repositories\Daemon\DaemonServerRepository::class)->setServer($server)->getDetails(5);
        check(in_array($details['state'] ?? '', ['offline', 'exited'], true) && ($details['configuration']['suspended'] ?? null) === true, 'Real Wings benchmark requires an ALREADY stopped, suspended disposable server');
    } else {
        $process->waitUntil(fn ($type, $output) => str_contains($output, "\n"));
        $fixture = json_decode(trim($process->getOutput()), true, 8, JSON_THROW_ON_ERROR); $remoteRoot = $fixture['root'];
        $port = $fixture['port'];
        $node = (new Node)->forceFill(['scheme' => 'http', 'fqdn' => '127.0.0.1', 'daemon_connect' => $port, 'daemon_token' => 'fixture', 'daemon_token_id' => 'fixture']);
        $server = (new Server)->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']); $server->setRelation('node', $node);
    }
    $prefix = 'bulk-'.bin2hex(random_bytes(6));
    $metrics = new DeploymentMetrics; $wings = new Wings($server, app(DaemonFileRepository::class), null, $metrics);
    $count = max(32, (int) (getenv('BULK_BENCH_COUNT') ?: 32)); $jarBytes = (int) (getenv('BULK_BENCH_JAR_BYTES') ?: 1048576);
    $before = []; $after = []; $bytes = 0;
    mkdir($stage.'/config'); mkdir($stage.'/config/'.$prefix); mkdir($stage.'/mods'); mkdir($stage.'/mods/'.$prefix);
    $block = random_bytes(1048576);
    for ($i = 0; $i < $count; $i++) {
        $jar = $i % 32 === 0;
        $path = $jar ? 'mods/'.$prefix.'/'.$i.'.jar' : 'config/'.$prefix.'/g'.intdiv($i, 128).'/'.$i.'.cfg';
        if (! is_dir(dirname($stage.'/'.$path))) { mkdir(dirname($stage.'/'.$path), 0700, true); }
        $f = fopen($stage.'/'.$path, 'wb');
        if ($jar) { for ($n = 0; $n < $jarBytes; $n += strlen($block)) { fwrite($f, substr($block, 0, min(strlen($block), $jarBytes - $n))); } }
        else { fwrite($f, str_repeat('config-'.$i."\n", 8)); }
        fclose($f); $bytes += filesize($stage.'/'.$path); $before[$path] = null; $after[$path] = hash_file('sha256', $stage.'/'.$path);
    }
    unset($block);
    $t = microtime(true); $prepared = new PreparedStaging($stage, $after); $preparation = microtime(true) - $t;
    $results = []; $progress = function ($step, $done, $total) use (&$metrics) { $metrics->phase($step); };
    $dbCheckpoints = 0; $sqlQueries = 0;
    if (getenv('BULK_BENCH_DB')) {
        \Illuminate\Support\Facades\Schema::create('webhook_configurations', function (\Illuminate\Database\Schema\Blueprint $t) { $t->id(); $t->string('scope'); $t->json('events'); $t->softDeletes(); });
        \Illuminate\Support\Facades\Schema::create('arcturus_modpack_operations', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->string('id')->primary(); $t->string('phase'); $t->longText('context'); $t->text('progress')->nullable();
            $t->timestamp('completed_at')->nullable(); $t->timestamp('heartbeat_at')->nullable(); $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::listen(function () use (&$sqlQueries) { $sqlQueries++; });
    }
    $strategies = getenv('BULK_COMPARE_BASELINE') ? ['baseline', 'parallel', 'archive'] : ['parallel', 'archive'];
    foreach ($strategies as $strategy) {
        $metrics = new DeploymentMetrics; $wings = new Wings($server, app(DaemonFileRepository::class), null, $metrics);
        if ($remoteRoot && getenv('BULK_EXISTING')) {
            foreach ($after as $path => $hash) {
                if (! is_dir(dirname($remoteRoot.'/'.$path))) { mkdir(dirname($remoteRoot.'/'.$path), 0700, true); }
                copy($stage.'/'.$path, $remoteRoot.'/'.$path); $f = fopen($remoteRoot.'/'.$path, 'r+b'); fwrite($f, 'X'); fclose($f); chmod($remoteRoot.'/'.$path, 0755);
                $before[$path] = hash_file('sha256', $remoteRoot.'/'.$path);
            }
        }
        $journal = []; $statsBefore = $live ? null : Http::get('http://127.0.0.1:'.$port.'/stats')->json();
        $receipt = null; $sqlQueries = 0; $dbCheckpoints = 0;
        if (getenv('BULK_BENCH_DB')) {
            $id = bin2hex(random_bytes(16)); $execution = bin2hex(random_bytes(16));
            $op = \Arcturus\ModpackManager\Models\Operation::withoutEvents(fn () => \Arcturus\ModpackManager\Models\Operation::create([
                'id' => $id, 'phase' => 'applying', 'context' => ['native_execution_id' => $execution, 'native_before' => $before, 'native_after' => $after]]));
            $receipt = new \Arcturus\ModpackManager\Native\DeploymentReceipt($op, $execution, $metrics);
            $wings = new Wings($server, app(DaemonFileRepository::class), fn (bool $force = false) => $receipt->heartbeat($force), $metrics);
        }
        $record = function ($row) use (&$journal, $receipt, &$dbCheckpoints) {
            $journal[] = $row;
            if ($receipt) { $receipt->save(fn ($context) => ['context' => [...$context, 'native_checkpoint' => $row]]); $dbCheckpoints++; }
        };
        $sqlQueries = 0; $started = microtime(true);
        if ($strategy === 'baseline') {
            foreach (['Files', 'BatchFiles', 'MutationBatches', 'StagingUploads', 'SymlinkPath', 'TextChangedDuringInspection', 'WingsConnectionException', 'Wings', 'Transaction'] as $name) {
                $text = shell_exec('git show 339d3fe:src/Native/'.$name.'.php'); check(is_string($text), 'Baseline source missing');
                eval(substr(str_replace('namespace Arcturus\\ModpackManager\\Native;', 'namespace Arcturus\\ModpackManager\\BulkBaseline;', $text), 5));
            }
            class_alias(DeploymentMetrics::class, 'Arcturus\\ModpackManager\\BulkBaseline\\DeploymentMetrics');
            $old = new \Arcturus\ModpackManager\BulkBaseline\Wings($server, app(DaemonFileRepository::class), $receipt ? fn (bool $force = false) => $receipt->heartbeat($force) : null, $metrics);
            (new \Arcturus\ModpackManager\BulkBaseline\Transaction($old))->apply(bin2hex(random_bytes(16)), $stage, $before, $after, [], $record, [], $progress);
        } else {
            (new Transaction($wings))->apply(bin2hex(random_bytes(16)), $stage, $before, $after, [], $record, [], $progress, $prepared, $strategy);
        }
        $elapsed = microtime(true) - $started;
        $statsAfter = $live ? null : Http::get('http://127.0.0.1:'.$port.'/stats')->json();
        $diff = fn ($field) => $live ? null : array_map(fn ($key) => $statsAfter[$field][$key] - ($statsBefore[$field][$key] ?? 0), array_combine(array_keys($statsAfter[$field]), array_keys($statsAfter[$field])));
        $results[$strategy] = ['seconds' => $elapsed, 'requests' => $diff('requests'), 'bytes' => $diff('bytes'), 'journal_checkpoints' => count($journal), 'db_checkpoints' => $receipt ? $dbCheckpoints : null, 'sql_queries' => $receipt ? $sqlQueries : null, 'metrics' => $metrics->snapshot()];
        fwrite(STDERR, 'Completed '.$strategy.' '.$elapsed." seconds\n");
        $wings->delete('config/'.$prefix); $wings->delete('mods/'.$prefix);
    }
    if ($live) {
        echo json_encode(['environment' => 'REAL Wings, stopped suspended disposable server; no backup or boot', 'files' => $count, 'input_bytes' => $bytes, 'php_peak_memory_bytes' => memory_get_peak_usage(true), 'bundle_preparation_seconds' => $preparation, 'offline_seconds' => null, 'results' => $results], JSON_PRETTY_PRINT)."\n";
        $GLOBALS['arcturus_integration_passed'] = true;
        return;
    }
    $stats = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    check($stats['peak_reads'] > 1 && $stats['peak_reads'] <= 8, 'Parallel transport did not overlap or exceeded its bound');
    $sampleAfter = array_slice($after, 0, 32, true); $sampleBefore = array_fill_keys(array_keys($sampleAfter), null);
    $sample = new PreparedStaging($stage, $sampleAfter);
    foreach (['parallel', 'archive'] as $faultVerification) {
    foreach (['corrupt', 'missing', 'extra', 'interrupted', 'unknown', 'space', ...($faultVerification === 'archive' ? ['compress_unknown', 'readback_extra'] : [])] as $fault) {
        Http::get('http://127.0.0.1:'.$port.'/fault', ['value' => $fault]); $journal = []; $id = bin2hex(random_bytes(16));
        $record = function ($row) use (&$journal) { $journal[] = $row; };
        $budget = in_array($fault, ['unknown', 'compress_unknown'], true) ? new \Arcturus\ModpackManager\Native\ApplyBudget(microtime(true) + 60, 1) : null;
        $wings->budget($budget);
        rejects(fn () => (new Transaction($wings, $budget))->apply($id, $stage, $sampleBefore, $sampleAfter, [], $record, [], null, $sample, $faultVerification), 'Unsafe bulk stage accepted: '.$fault);
        $wings->budget(null);
        check(! array_filter($journal, fn ($r) => in_array($r['step'], ['apply_batch_intent', 'retire_batch_intent', 'replace_batch_intent'], true)), 'Failed bulk staging mutated live files');
        check(end($journal)['step'] === 'bundle_intent' || $fault === 'extra', 'Uncertain archive lost its intent');
    }
    }
    Http::get('http://127.0.0.1:'.$port.'/fault', ['value' => '']);
    Http::get('http://127.0.0.1:'.$port.'/fault', ['value' => 'partial']); $journal = [];
    $record = function ($row) use (&$journal) { $journal[] = $row; };
    $prior = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    rejects(fn () => (new Transaction($wings))->apply(bin2hex(random_bytes(16)), $stage, $sampleBefore, $sampleAfter, [], $record, [], null, $sample), 'Partial rename accepted');
    $later = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    check($later['requests']['rename'] - $prior['requests']['rename'] === 1 && end($journal)['step'] === 'apply_batch_intent', 'Partial rename replayed or committed');
    Http::get('http://127.0.0.1:'.$port.'/fault', ['value' => '']);
    // Lose the lease after the upload, before Wings is allowed to launch background extraction.
    foreach (['config/'.$prefix, 'mods/'.$prefix] as $path) { if ($wings->stat($path) !== null) { $wings->delete($path); } }
    $id = bin2hex(random_bytes(16)); $journal = [];
    $prior = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    $locked = new Wings($server, app(DaemonFileRepository::class), function (bool $force = false) use ($port, $prior) {
        if ($force && Http::get('http://127.0.0.1:'.$port.'/stats')->json('requests.write') > $prior['requests']['write']) { throw new RuntimeException('lease lost'); }
    });
    $record = function ($row) use (&$journal) { $journal[] = $row; };
    rejects(fn () => (new Transaction($locked))->apply($id, $stage, $sampleBefore, $sampleAfter, [], $record, [], null, $sample), 'Lost lease continued');
    $later = Http::get('http://127.0.0.1:'.$port.'/stats')->json();
    check($later['requests']['write'] - $prior['requests']['write'] === 1 && $later['requests']['decompress'] === $prior['requests']['decompress'] && $later['requests']['rename'] === $prior['requests']['rename'] && end($journal)['step'] === 'bundle_intent', 'Lost lease launched or replayed a mutation');
    $output = ['environment' => 'disk-backed loopback HTTP CONTRACT fixture; no real Wings, backup or boot', 'database' => getenv('BULK_BENCH_DB') ? 'encrypted Eloquent DeploymentReceipt on in-memory SQLite' : null, 'files' => $count, 'input_bytes' => $bytes, 'php_peak_memory_bytes' => memory_get_peak_usage(true), 'bundle_preparation_seconds' => $preparation, 'bundles' => count($prepared->bundles), 'peak_parallel_reads' => $stats['peak_reads'], 'offline_seconds' => null, 'results' => $results];
    if ($file = getenv('BULK_BENCH_OUTPUT')) { file_put_contents($file, json_encode($output, JSON_PRETTY_PRINT)."\n"); }
    echo json_encode($output, JSON_PRETTY_PRINT)."\nBulk integrity and uncertainty failures passed\n";
    $GLOBALS['arcturus_integration_passed'] = true;
} finally {
    $process?->stop();
    $delete = function ($dir) use (&$delete) { foreach (scandir($dir) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $p = $dir.'/'.$name; is_dir($p) && ! is_link($p) ? $delete($p) : unlink($p); } rmdir($dir); };
    $delete($stage); if ($remoteRoot && is_dir($remoteRoot)) { $delete($remoteRoot); }
}
