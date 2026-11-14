<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';
require __DIR__.'/fixtures/clock.php';
use function Arcturus\ModpackManager\Native\microtime;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Native\Boot;
use Arcturus\ModpackManager\Native\Wings;
use Illuminate\Support\Facades\Http;

$server = (new Server)->forceFill(['id'=>222, 'uuid'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'node_id'=>1]); $server->setRelation('node',$node);
\Illuminate\Support\Facades\Auth::guard()->forgetUser();
$log = "ModLauncher args [--fml.neoForgeVersion, 21.1.1, --fml.mcVersion, 1.21.1]\nModLauncher starting: java version 21.0.1\n[Server thread/INFO]: Done (1.0s)!\n";
$target=['minecraft'=>'1.21.1','loader'=>'neoforge','loader_version'=>'21.1.1','java'=>21];
config(['arcturus-modpack-manager.deployment_start_timeout'=>6,'arcturus-modpack-manager.deployment_start_ack_timeout'=>2]);
foreach (['delayed','exit','never','stale','other_instance','large_log','zero_uptime','cached_uptime','never_done','sampled_uptime','sampled_restart','frozen_uptime','console_only','console_stale','console_rolled'] as $scenario) {
    $data=['logs/latest.log'=>$log]; $started=null; $polls=0; $powers=0; $events=[]; $heldUptime=null;
    Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
    Http::fake(function($request) use (&$data,&$started,&$polls,&$powers,&$heldUptime,$scenario,$log) {
        $headers=['User-Agent'=>'Pelican Wings/v1.0.0 (id:fixture)']; $path=parse_url($request->url(),PHP_URL_PATH); parse_str(parse_url($request->url(),PHP_URL_QUERY)??'',$q);
        if(str_ends_with($path,'/logs')) {
            $lines=[];
            if($scenario==='console_stale' || ($started!==null && in_array($scenario,['console_only','console_rolled'],true))) {
                $lines=explode("\n",rtrim($log,"\n"));
                if($scenario==='console_rolled') {
                    $lines=$polls===0?array_slice($lines,0,2):array_map(fn($i)=>'Normal game output '.$i,range(0,59));
                    if($polls>=2) {array_shift($lines); $lines[]='Done (1.0s)!';}
                }
                if($scenario==='console_stale' && $started!==null) $lines[]='Player connected';
            }
            return Http::response(['data'=>$lines],200,$headers);
        }
        if(str_ends_with($path,'/files/list-directory')) {
            $rows=[]; $dir=trim($q['directory'],'/');
            if($dir==='') $rows[]=['name'=>'logs','file'=>false,'directory'=>true,'symlink'=>false,'size'=>0];
            else foreach($data as $name=>$value) $rows[]=['name'=>basename($name),'file'=>true,'directory'=>false,'symlink'=>false,'size'=>strlen($value)];
            return Http::response($rows,200,$headers);
        }
        if(str_ends_with($path,'/files/contents')) return Http::response($data[ltrim($q['file'],'/')],200,$headers);
        if(str_ends_with($path,'/files/rename')) { foreach($request['files'] as $m){$data[$m['to']]=$data[$m['from']];unset($data[$m['from']]);}return Http::response('',204,$headers); }
        if(str_ends_with($path,'/power')) {
            check($request['action']==='start','Boot sent a non-start power action'); $powers++; $started=microtime(true);
            if($scenario!=='never') $data['logs/latest.log']=$log.($scenario==='stale'?'':"New attempt\n");
            if($scenario==='large_log') $data['logs/latest.log']=str_replace('[Server thread/INFO]: Done (1.0s)!', '', $log).str_repeat("startup detail\n",150000)."[Server thread/INFO]: Done (2.0s)!\n".str_repeat("runtime detail\n",300000);
            if(str_starts_with($scenario,'console_')) $data['logs/latest.log']="[Server thread/INFO]: Done (1.0s)!\nGame output without boot header\n";
            if($scenario==='never_done') $data['logs/latest.log']=str_replace('[Server thread/INFO]: Done (1.0s)!', '', $log)."Fresh but incomplete boot\n";
            return Http::response('',202,$headers);
        }
        check(!str_contains($path,'/sync')&&!str_contains($path,'/restore'),'Unexpected mutation');
        $state='offline'; $uptime=0;
        if($started!==null){$polls++;$state=match($scenario){'delayed'=>$polls===1?'offline':($polls===2?'starting':'running'),'exit'=>$polls===1?'starting':'offline','never'=>'offline',default=>'running'};
            $uptime=(microtime(true)-$started)*1000;
            if(in_array($scenario,['sampled_uptime','sampled_restart','frozen_uptime'],true)) {
                $heldUptime ??= $uptime;
                if($polls<=4 || $scenario==='frozen_uptime') $uptime=$heldUptime;
                elseif($scenario==='sampled_restart') $uptime=100;
            }
            if($scenario==='other_instance'&&$polls>1)$uptime=100;
            if($scenario==='zero_uptime'&&$polls===1)$uptime=0;
            if($scenario==='cached_uptime'&&$polls===1)$uptime=600000;
        }
        return Http::response(['state'=>$state,'utilization'=>['uptime'=>$uptime]],200,$headers);
    });
    $run=function()use($server,$target,&$events){ return (new Boot)->start($server,new Wings($server,app(DaemonFileRepository::class)),str_repeat('a',32),$target,function($row)use(&$events){$events[]=$row;}); };
    if(in_array($scenario,['delayed','large_log','zero_uptime','cached_uptime','sampled_uptime','console_only','console_rolled'],true)) { config(['arcturus-modpack-manager.deployment_start_ack_timeout'=>4]);$result=$run();check($result['java']===21,'Delayed start failed'); }
    else {config(['arcturus-modpack-manager.deployment_start_ack_timeout'=>2]);rejects($run,'Unsafe boot accepted: '.$scenario);}
    if($scenario==='sampled_uptime') check($polls>=5, 'Boot accepted a repeated uptime without a new resource sample');
    if(in_array($scenario,['console_only','console_rolled'],true)) {
        $boot=end($events)['boot_attempt'];
        check(($boot['evidence_source']??'')==='wings_console' && ($boot['console']['done']??false), 'Wings boot facts were not retained');
        check(count(array_filter($events,fn($e)=>$e['step']==='boot_console_evidence'))>=($scenario==='console_rolled'?2:1), 'Boot facts were saved only at final verification');
    }
    check($powers===1,'Start was blindly replayed');
    echo $scenario." boot fixture passed\n";
}
$GLOBALS['arcturus_integration_passed']=true;
