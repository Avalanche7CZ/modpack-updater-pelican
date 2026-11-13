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
$state = []; $dirs = []; $modes = []; $stats = []; $step = 'initial'; $fault = ''; $renames = 0; $intents = [];
$fake = function ($request) use (&$state, &$dirs, &$modes, &$stats, &$step, &$fault, &$renames, &$intents, $headers) {
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
    if ($route === 'contents') return Http::response($state[ltrim($q['file'], '/')], 200, $headers);
    if ($route === 'write') { $state[ltrim($q['file'], '/')] = $request->body(); return Http::response('', 204, $headers); }
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
$reset = function () use (&$state, &$dirs, &$modes, &$stats, &$step, &$renames, &$intents, $fake) {
    $state = []; $dirs = ['config' => true, 'config/shared' => true]; $modes = []; $stats = []; $step = 'initial'; $renames = 0; $intents = [];
    Http::swap(new class extends \Illuminate\Http\Client\Factory { public function recordRequestResponsePair($request, $response) { return $this; } });
    Http::preventStrayRequests(); Http::fake($fake);
};
$stage = sys_get_temp_dir().'/apply-benchmark-'.bin2hex(random_bytes(6)); mkdir($stage); mkdir($stage.'/config'); mkdir($stage.'/config/shared');
$count = (int) (getenv('APPLY_BENCH_COUNT') ?: 128);
$before = []; $after = [];
try {
    for ($i=0; $i<$count; $i++) {
        $path = 'config/shared/f'.$i.'.cfg'; file_put_contents($stage.'/'.$path, 'new-'.$i);
        $before[$path] = hash('sha256', 'old-'.$i); $after[$path] = hash('sha256', 'new-'.$i);
    }
    for ($i=0; $i<32; $i++) { $path='config/shared/remove'.$i.'.cfg'; $before[$path]=hash('sha256','remove'); $after[$path]=null; }
    $load = function () use ($reset, &$state, &$modes, $count) {
        $reset(); for($i=0;$i<$count;$i++) { $state['config/shared/f'.$i.'.cfg']='old-'.$i; $modes['config/shared/f'.$i.'.cfg']=0755; }
        for($i=0;$i<32;$i++) $state['config/shared/remove'.$i.'.cfg']='remove';
    };
    $journal = function ($row) use (&$intents, &$report) { $intents[] = $row; if ($row['step'] === 'stage_intent') { $report('stage_setup'); } };
    $results = [];
    foreach (getenv('APPLY_COMPARE_BASELINE') ? ['8d3a107','current'] : ['current'] as $version) {
        $load(); $previous='initial'; $clock=hrtime(true); $elapsed=[]; $progress=[];
        $report = function($name,$done=0,$total=0) use (&$step,&$previous,&$clock,&$elapsed,&$progress,&$intents) {
            $now=hrtime(true); $elapsed[$previous]=($elapsed[$previous]??0)+($now-$clock)/1e9; $clock=$now; $step=$previous=$name;
            $progress[$name]=[$done,$total];
            if ($name==='stage_verification') check($intents===[], 'Initial progress arrived after recovery checkpoint');
        };
        $files = new Wings($server, app(DaemonFileRepository::class));
        $tx = new Transaction($files);
        if ($version==='8d3a107') {
            // Optional local comparison: read the immutable baseline, never checkout/reset the workspace.
            foreach (['Files','BatchFiles','SymlinkPath','TextChangedDuringInspection','WingsConnectionException','Wings','Transaction'] as $name) {
                $text=shell_exec('git show 8d3a107:src/Native/'.$name.'.php'); check(is_string($text) && str_contains($text,'<?php'), 'Baseline commit unavailable; fetch its history first');
                $text=str_replace('namespace Arcturus\\ModpackManager\\Native;', 'namespace Arcturus\\ModpackManager\\BenchmarkOld;', $text);
                if($name==='Transaction') {
                    $text=str_replace('array $moves = []): void','array $moves = [], ?\\Closure $progress = null): void',$text);
                    foreach (["        \$actual ="=>'stage_verification',"        \$modes ="=>'permissions',"        foreach (\$installs as"=>'upload',"        foreach (\$moves as \$from"=>'deploy',"        \$this->verify(\$after);"=>'final_verification'] as $marker=>$label)
                        $text=str_replace($marker,"        \$progress?->__invoke('".$label."');\n".$marker,$text);
                    $first=true; $text=preg_replace_callback('/        \\$this->verify\\(\\$before\\);/',function($m) use (&$first) { $label=$first?'live_verification':'live_recheck'; $first=false; return "        \$progress?->__invoke('".$label."');\n".$m[0]; },$text);
                }
                eval(substr($text,5));
            }
            $oldFiles=new \Arcturus\ModpackManager\BenchmarkOld\Wings($server,app(DaemonFileRepository::class));
            $tx=new \Arcturus\ModpackManager\BenchmarkOld\Transaction($oldFiles);
        }
        $tx->apply(str_repeat('a',32),$stage,$before,$after,[],$journal,[],$report); $report('done');
        foreach($after as $path=>$hash) check(($hash===null ? !isset($state[$path]) : hash('sha256',$state[$path])===$hash),'Final fixture mismatch');
        if($version==='current') { check($progress['upload']===[$count,$count] && $progress['deploy']===[$count+32,$count+32], 'Upload/deploy denominators confused'); }
        $results[$version]=['requests'=>$stats,'seconds'=>$elapsed];
        fwrite(STDERR, 'Completed benchmark '.$version.' '.array_sum($elapsed)." seconds\n");
    }
    echo json_encode(['uploads'=>$count,'paths'=>$count+32,'results'=>$results],JSON_PRETTY_PRINT)."\n";
    foreach(['partial','rename_noop','timeout','chmod_noop'] as $fault) {
        $load(); $files=new Wings($server,app(DaemonFileRepository::class));
        rejects(fn()=>(new Transaction($files))->apply(str_repeat('b',32),$stage,array_slice($before,0,3,true),array_slice($after,0,3,true),[],$journal), 'Incomplete batch accepted: '.$fault);
        check($renames<=1, 'Ambiguous mutation batch was replayed');
    }
    rejects(fn() => $files->renameBatch(['a' => 'b', 'b' => 'c']), 'Overlapping rename batch accepted');
    rejects(fn() => $files->renameBatch(['a' => 'tree', 'b' => 'tree/file']), 'Ancestor-dependent rename batch accepted');
    $tooMany = []; for ($i = 0; $i < 17; $i++) { $tooMany['from'.$i] = 'to'.$i; }
    rejects(fn() => $files->renameBatch($tooMany), 'Oversized rename batch accepted');
    echo "Apply batch fixture: real counts, pre-checkpoint progress, partial/no-op/error/chmod batches passed\n";
} finally {
    foreach(glob($stage.'/config/shared/*') as $path) unlink($path); rmdir($stage.'/config/shared'); rmdir($stage.'/config'); rmdir($stage);
}
$GLOBALS['arcturus_integration_passed']=true;
