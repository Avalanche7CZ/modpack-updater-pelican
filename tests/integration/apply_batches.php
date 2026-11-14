<?php

declare(strict_types=1);
if (getenv('APPLY_BENCH_DB')) { require __DIR__.'/fixtures/deployment-bootstrap.php'; }
else { $app = require __DIR__.'/bootstrap.php'; }
require __DIR__.'/fixtures/native_payloads.php';
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\Wings;
use Arcturus\ModpackManager\Native\Transaction;
use Illuminate\Support\Facades\Http;

$node = (new Node)->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture', 'daemon_token_id' => 'fixture']);
$server = (new Server)->forceFill(['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']); $server->setRelation('node', $node);
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
$wireBytes = []; $postWrite = null;
$state = []; $dirs = []; $modes = []; $stats = []; $step = 'initial'; $fault = ''; $renames = 0; $intents = [];
$fake = function ($request) use (&$state, &$dirs, &$modes, &$stats, &$step, &$fault, &$renames, &$intents, &$wireBytes, &$postWrite, $headers) {
    $route = basename(parse_url($request->url(), PHP_URL_PATH));
    $stats[$step][$route] = ($stats[$step][$route] ?? 0) + 1;
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
    if ($route === 'list-directory') {
        $prefix = trim($q['directory'], '/'); $prefix = $prefix === '' ? '' : $prefix.'/'; $rows = [];
        foreach ([...array_keys($state), ...array_keys($dirs)] as $path) {
            if (!str_starts_with($path, $prefix)) continue;
            $tail = substr($path, strlen($prefix)); if ($tail === '' || str_contains($tail, '/')) continue;
            $rows[$tail] = ['name' => $tail, 'file' => isset($state[$path]), 'directory' => isset($dirs[$path]), 'symlink' => false,
                'size' => isset($state[$path]) ? strlen($state[$path]) : 0, 'modified' => 'stable', 'mode_bits' => decoct($modes[$path] ?? 0644)];
        }
        return Http::response(array_values($rows), 200, $headers);
    }
    if ($route === 'contents') { $body = $state[ltrim($q['file'], '/')]; $wireBytes['contents'] = ($wireBytes['contents'] ?? 0) + strlen($body); return Http::response($body, 200, $headers); }
    if ($route === 'write') {
        $path = ltrim($q['file'], '/');
        $last = end($intents);
        check(($last['step'] === 'upload_intent') || ($last['step'] === 'upload_batch_intent' && isset($last['files'][$path])), 'Upload was not journaled before mutation');
        $wireBytes['write'] = ($wireBytes['write'] ?? 0) + strlen($request->body());
        if ($fault === 'upload_partial' && count(array_filter(array_keys($state), fn ($p) => str_starts_with($p, '.arcturus-update-'))) >= 1) { return Http::response('', 500, $headers); }
        $state[$path] = $fault === 'upload_noop' ? '' : ($fault === 'upload_corrupt' ? str_repeat('z', strlen($request->body())) : $request->body()); $postWrite?->__invoke(); return Http::response('', 204, $headers); }
    if ($route === 'create-directory') { $dirs[trim($request['path'].'/'.$request['name'], '/')] = true; return Http::response('', 204, $headers); }
    if ($route === 'chmod') {
        check(count($request['files']) <= 32, 'Oversized chmod batch');
        check($intents !== [], 'chmod without a durable intent');
        if (count($request['files']) > 1) {
            $last = end($intents); $intended = []; foreach ($request['files'] as $file) { $intended[$file['file']] = octdec($file['mode']); }
            check($last['step'] === 'chmod_batch_intent' && $last['modes'] === $intended, 'Full chmod batch was not journaled before the request');
        }
        foreach ($request['files'] as $i => $file) { if ($fault === 'chmod_noop') break; $modes[$file['file']] = octdec($file['mode']); }
        return Http::response('', 204, $headers);
    }
    if ($route === 'rename') {
        check(count($request['files']) <= 16, 'Oversized rename batch');
        $renames++; check($intents !== [], 'rename without a durable intent');
        if (count($request['files']) > 1) {
            $last = end($intents); $intended = []; foreach ($request['files'] as $move) { $intended[$move['from']] = $move['to']; }
            check(in_array($last['step'], ['retire_batch_intent', 'replace_batch_intent'], true) && $last['moves'] === $intended, 'Full rename batch was not journaled before the request');
        }
        foreach ($request['files'] as $i => $move) {
            if ($fault === 'rename_noop' || ($fault === 'partial' && $i === 1)) break;
            if ($fault === 'timeout' && $i === 1) return Http::response('', 500, $headers);
            check(isset($state[$move['from']]) && !isset($state[$move['to']]), 'Overlapping rename');
            $state[$move['to']] = $state[$move['from']]; $modes[$move['to']] = $modes[$move['from']] ?? 0644;
            unset($state[$move['from']], $modes[$move['from']]);
        }
        return Http::response('', 204, $headers);
    }
    if ($route === 'delete') {
        foreach ($request['files'] as $root) {
            foreach (array_keys($state) as $path) if($path === $root || str_starts_with($path, $root.'/')) unset($state[$path]);
            foreach (array_keys($dirs) as $path) if($path === $root || str_starts_with($path, $root.'/')) unset($dirs[$path]);
        }
        return Http::response('', 204, $headers);
    }
    throw new RuntimeException('Unexpected fixture route '.$route);
};
$reset = function () use (&$state, &$dirs, &$modes, &$stats, &$step, &$renames, &$intents, &$wireBytes, $fake) {
    $wireBytes = []; $state = []; $dirs = ['config' => true, 'config/shared' => true, 'mods' => true]; $modes = []; $stats = []; $step = 'initial'; $renames = 0; $intents = [];
    Http::swap(new class extends \Illuminate\Http\Client\Factory { public function recordRequestResponsePair($request, $response) { return $this; } });
    Http::preventStrayRequests(); Http::fake($fake);
};
$stage = sys_get_temp_dir().'/apply-benchmark-'.bin2hex(random_bytes(6)); mkdir($stage); mkdir($stage.'/config'); mkdir($stage.'/config/shared');
$count = (int) (getenv('APPLY_BENCH_COUNT') ?: 128);
$before = []; $after = [];
try {
    $payloads = nativePayloads($count, (bool) getenv('APPLY_BENCH_MIXED'));
    foreach ($payloads['new'] as $path => $body) {
        if (! is_dir(dirname($stage.'/'.$path))) { mkdir(dirname($stage.'/'.$path), 0700, true); }
        file_put_contents($stage.'/'.$path, $body);
        $before[$path] = hash('sha256', $payloads['old'][$path]); $after[$path] = hash('sha256', $body);
    }
    for ($i=0; $i<32; $i++) { $path='config/shared/remove'.$i.'.cfg'; $before[$path]=hash('sha256','remove'); $after[$path]=null; }
    $load = function () use ($reset, &$state, &$modes, $payloads) {
        $reset(); $state = $payloads['old']; foreach ($state as $path => $_) { $modes[$path] = 0755; }
        for($i=0;$i<32;$i++) $state['config/shared/remove'.$i.'.cfg']='remove';
    };
    $journal = function ($row) use (&$intents, &$report) { $intents[] = $row; if ($row['step'] === 'stage_intent') { $report('stage_setup'); } };
    $results = [];
    $sqlCount = 0;
    if (getenv('APPLY_BENCH_DB')) { \Illuminate\Support\Facades\DB::listen(function ($query) use (&$sqlCount) { $sqlCount++; }); }
    $baseline = getenv('APPLY_BASELINE') ?: '8d3a107';
    check((bool) preg_match('/^[a-f0-9]{7,40}$/D', $baseline), 'Baseline must be an immutable commit hash');
    foreach (getenv('APPLY_COMPARE_BASELINE') ? [$baseline,'current'] : ['current'] as $version) {
        $load(); $previous='initial'; $clock=hrtime(true); $elapsed=[]; $progress=[];
        $report = function($name,$done=0,$total=0) use (&$step,&$previous,&$clock,&$elapsed,&$progress,&$intents) {
            $now=hrtime(true); $elapsed[$previous]=($elapsed[$previous]??0)+($now-$clock)/1e9; $clock=$now; $step=$previous=$name;
            $progress[$name]=[$done,$total];
            if ($name==='stage_verification') check($intents===[], 'Initial progress arrived after recovery checkpoint');
        };
        $metrics = new \Arcturus\ModpackManager\Native\DeploymentMetrics;
        $heartbeat = null; $sqlCount = 0;
        if (getenv('APPLY_BENCH_DB')) {
            $managed = \Arcturus\ModpackManager\Models\ManagedServer::firstOrCreate(['pelican_server_uuid' => $server->uuid], ['label' => 'Applying fixture', 'provider' => 'github', 'source' => 'fixture', 'installed_version' => 'old', 'policy' => 'notify']);
            $operation = \Arcturus\ModpackManager\Models\Operation::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'managed_server_id' => $managed->id, 'kind' => 'deploy', 'phase' => 'applying', 'context' => ['native_execution_id' => 'fixture', 'native_before' => $before, 'native_after' => $after]]);
            $receipt = new \Arcturus\ModpackManager\Native\DeploymentReceipt($operation, 'fixture', $metrics);
            if ($version === 'current') {
                $heartbeat = fn (bool $force = false) => $receipt->heartbeat($force);
                $saveProgress = app(\Arcturus\ModpackManager\Services\ApplyProgress::class)->reporter($operation, $metrics, $receipt);
                $journal = function ($row) use (&$intents, &$report, $receipt): void {
                    $intents[] = $row;
                    $receipt->save(fn ($context) => ['context' => [...$context, 'native_checkpoint' => $row, 'native_checkpoint_sequence' => ($context['native_checkpoint_sequence'] ?? 0) + 1]]);
                    if ($row['step'] === 'stage_intent') { $report('stage_setup'); }
                };
            } else {
                // The exact main Deployment heartbeat/checkpoint DB work, measured around its calls.
                $heartbeat = function () use ($operation, $metrics): void {
                    $metrics->measure('db-heartbeat', function () use ($operation): void {
                        $fresh = $operation->fresh(); check(! $fresh->completed_at && $fresh->context['native_execution_id'] === 'fixture', 'Lost baseline ownership');
                        $operation->update(['heartbeat_at' => now()]);
                    });
                };
                $journal = function ($row) use ($operation, $metrics, $heartbeat, &$intents, &$report): void {
                    $intents[] = $row;
                    $metrics->measure('db-checkpoint', function () use ($operation, $heartbeat, $row): void {
                        $heartbeat(); $operation->update(['context' => [...$operation->context, 'native_checkpoint' => $row, 'native_checkpoint_sequence' => ($operation->context['native_checkpoint_sequence'] ?? 0) + 1]]);
                    });
                    if ($row['step'] === 'stage_intent') { $report('stage_setup'); }
                };
                $saveProgress = app(\Arcturus\ModpackManager\Services\ApplyProgress::class)->reporter($operation);
            }
            $timingReport = $report;
            $report = function ($name, $done = 0, $total = 0) use ($timingReport, $saveProgress): void { $timingReport($name, $done, $total); $saveProgress($name, $done, $total); };
        }
        $files = new Wings($server, app(DaemonFileRepository::class), $heartbeat, $metrics);
        $tx = new Transaction($files);
        if ($version !== 'current') {
            // Optional local comparison: read the immutable baseline, never checkout/reset the workspace.
            foreach (['Files','BatchFiles',...($baseline === '8d3a107' ? [] : ['MutationBatches']),'SymlinkPath','TextChangedDuringInspection','WingsConnectionException','Wings','Transaction'] as $name) {
                $text=shell_exec('git show '.$baseline.':src/Native/'.$name.'.php'); check(is_string($text) && str_contains($text,'<?php'), 'Baseline commit unavailable; fetch its history first');
                $text=str_replace('namespace Arcturus\\ModpackManager\\Native;', 'namespace Arcturus\\ModpackManager\\BenchmarkOld;', $text);
                if($name==='Transaction' && $baseline === '8d3a107') {
                    $text=str_replace('array $moves = []): void','array $moves = [], ?\\Closure $progress = null): void',$text);
                    foreach (["        \$actual ="=>'stage_verification',"        \$modes ="=>'permissions',"        foreach (\$installs as"=>'upload',"        foreach (\$moves as \$from"=>'deploy',"        \$this->verify(\$after);"=>'final_verification'] as $marker=>$label)
                        $text=str_replace($marker,"        \$progress?->__invoke('".$label."');\n".$marker,$text);
                    $first=true; $text=preg_replace_callback('/        \\$this->verify\\(\\$before\\);/',function($m) use (&$first) { $label=$first?'live_verification':'live_recheck'; $first=false; return "        \$progress?->__invoke('".$label."');\n".$m[0]; },$text);
                }
                eval(substr($text,5));
            }
            $oldFiles=new \Arcturus\ModpackManager\BenchmarkOld\Wings($server,app(DaemonFileRepository::class), $heartbeat);
            $tx=new \Arcturus\ModpackManager\BenchmarkOld\Transaction($oldFiles);
        }
        $tx->apply(str_repeat('a',32),$stage,$before,$after,[],$journal,[],$report); $report('done');
        foreach($after as $path=>$hash) check(($hash===null ? !isset($state[$path]) : hash('sha256',$state[$path])===$hash),'Final fixture mismatch');
        if($version==='current') { check($progress['upload']===[$count,$count] && $progress['deploy']===[$count+32,$count+32], 'Upload/deploy denominators confused'); }
        $results[$version]=['requests'=>$stats,'seconds'=>$elapsed,'wire_bytes'=>$wireBytes,'sql_queries'=>$sqlCount,'metrics'=>$metrics->snapshot()];
        fwrite(STDERR, 'Completed benchmark '.$version.' '.array_sum($elapsed)." seconds\n");
    }
    $output = ['baseline'=>$baseline,'environment'=>['php'=>PHP_VERSION,'panel'=>'v1.0.0-rc.2','laravel'=>\Composer\InstalledVersions::getPrettyVersion('laravel/framework'),'guzzle'=>\Composer\InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'),'transport'=>'zero-latency HTTP fake','database'=>getenv('APPLY_BENCH_DB') ? 'encrypted Eloquent receipts, in-memory SQLite' : 'none','downtime_seconds'=>null], 'uploads'=>$count,'paths'=>$count+32,'configs'=>$payloads['configs'],'jars'=>$payloads['jars'],'upload_bytes'=>$payloads['upload_bytes'],'results'=>$results];
    if ($outputPath = getenv('APPLY_BENCH_OUTPUT')) { file_put_contents($outputPath, json_encode($output, JSON_PRETTY_PRINT)."\n"); }
    echo json_encode($output,JSON_PRETTY_PRINT)."\n";
    foreach(['partial','rename_noop','timeout','chmod_noop','upload_partial','upload_noop','upload_corrupt'] as $fault) {
        $load(); $files=new Wings($server,app(DaemonFileRepository::class));
        rejects(fn()=>(new Transaction($files))->apply(str_repeat('b',32),$stage,array_slice($before,0,3,true),array_slice($after,0,3,true),[],$journal), 'Incomplete batch accepted: '.$fault);
        check($renames<=1, 'Ambiguous mutation batch was replayed');
        if (str_starts_with($fault, 'upload_')) { check($renames === 0 && array_intersect_key($state, $payloads['old']) === $payloads['old'], 'Failed staging reached live changes'); }
    }
    // Stop only at a verified boundary when the next complete unit cannot fit.
    $fault = ''; $load(); $virtualNow = 0.0;
    $budget = new \Arcturus\ModpackManager\Native\ApplyBudget(100, 10, function () use (&$virtualNow): float { return $virtualNow; });
    $files = new Wings($server, app(DaemonFileRepository::class)); $files->budget($budget);
    $budgetJournal = function ($row) use (&$intents): void { $intents[] = $row; };
    $budgetProgress = function ($step, $done) use (&$virtualNow): void { if ($step === 'upload' && $done === 4) { $virtualNow = 95; } };
    rejects(fn () => (new Transaction($files, $budget))->apply(str_repeat('c', 32), $stage, array_slice($before, 0, 8, true), array_slice($after, 0, 8, true), [], $budgetJournal, [], $budgetProgress), 'Exhausted budget continued to another batch');
    check($renames === 0 && end($intents)['step'] === 'upload_batch_verified', 'Budget exhaustion did not retain a verified staging boundary');
    check(array_sum(array_column($stats, 'write')) === 4, 'Budget exhaustion started/replayed another upload batch');
    check(array_intersect_key($state, $payloads['old']) === $payloads['old'], 'Budget exhaustion changed live installation');
    // Expiry within a unit leaves its durable intent; no second write or batch replay.
    $load(); $virtualNow = 0.0; $postWrite = function () use (&$virtualNow): void { $virtualNow = 11; };
    $budget = new \Arcturus\ModpackManager\Native\ApplyBudget(100, 10, function () use (&$virtualNow): float { return $virtualNow; });
    $files = new Wings($server, app(DaemonFileRepository::class)); $files->budget($budget);
    rejects(fn () => (new Transaction($files, $budget))->apply(str_repeat('e', 32), $stage, array_slice($before, 0, 4, true), array_slice($after, 0, 4, true), [], $budgetJournal), 'Expired partial upload unit accepted');
    check(array_sum(array_column($stats, 'write')) === 1 && $renames === 0 && end($intents)['step'] === 'upload_batch_intent', 'Expired partial upload replayed or advanced');
    $postWrite = null;
    // An ambiguous checkpoint save is never followed by a remote mutation.
    $load(); $files = new Wings($server, app(DaemonFileRepository::class));
    $uncertainJournal = function ($row) use (&$intents): void {
        $intents[] = $row; if ($row['step'] === 'upload_batch_intent') { throw new RuntimeException('ambiguous DB write'); }
    };
    rejects(fn () => (new Transaction($files))->apply(str_repeat('d', 32), $stage, array_slice($before, 0, 4, true), array_slice($after, 0, 4, true), [], $uncertainJournal), 'Uncertain checkpoint allowed upload');
    check(array_sum(array_column($stats, 'write')) === 0 && $renames === 0, 'Uncertain checkpoint was replayed or mutated files');
    rejects(fn() => $files->renameBatch(['a' => 'b', 'b' => 'c']), 'Overlapping rename batch accepted');
    rejects(fn() => $files->renameBatch(['a' => 'tree', 'b' => 'tree/file']), 'Ancestor-dependent rename batch accepted');
    $tooMany = []; for ($i = 0; $i < 17; $i++) { $tooMany['from'.$i] = 'to'.$i; }
    rejects(fn() => $files->renameBatch($tooMany), 'Oversized rename batch accepted');
    echo "Apply batch fixture: real counts, pre-checkpoint progress, partial/no-op/error/chmod batches passed\n";
} finally {
    $cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($cleanup as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($stage);
}
$GLOBALS['arcturus_integration_passed']=true;
