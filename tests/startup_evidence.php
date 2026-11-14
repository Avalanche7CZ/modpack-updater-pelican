<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\OperatorBootEvidence;
use Arcturus\ModpackManager\Native\RunningInstance;
use Arcturus\ModpackManager\Native\StartupEvidence;

$target = ['minecraft'=>'1.7.10','loader'=>'crucible','loader_version'=>null,'java'=>25];
$lines = ['Forge Mod Loader version 7.99.40.1614 for Minecraft 1.7.10 loading', 'Java is OpenJDK, version 25.0.1',
    'MinecraftForge v10.13.4.1614 Initialized', 'Crucible DummyMod injected successfully!', '[Server thread/INFO]: Done (1.0s)!'];
$proof = new StartupEvidence;
foreach ($lines as $line) { $proof->line($line); }
check(StartupEvidence::matches($proof->proof(), $target), 'Split GTNH/Crucible/Java 25 console facts were not retained');
$banner = new StartupEvidence;
foreach ($lines as $line) { $banner->line(str_replace('Java is OpenJDK, version 25.0.1', 'openjdk version "25.0.1" 2026-10-20', $line)); }
check(StartupEvidence::matches($banner->proof(),$target), 'Docker JVM version banner was not parsed');
$proof->line('password=PRIVATE token=SECRET signed URL https://private.invalid?signature=SECRET');
check(!str_contains(json_encode($proof->proof()),'SECRET'), 'Raw console credentials entered the receipt');
check(StartupEvidence::matches($proof->proof(),$target), 'Later game output erased Done');
$proof->line('Java is OpenJDK, version 21.0.1'); $proof->line('Done (1.0s)!');
check(!StartupEvidence::matches($proof->proof(),$target), 'Conflicting runtime accepted');
$early = new StartupEvidence; $early->line('Done (1.0s)!');
foreach (array_slice($lines,0,-1) as $line) { $early->line($line); }
check(!StartupEvidence::matches($early->proof(),$target), 'Done from before the startup header accepted');

$uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $operation='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$directory=sys_get_temp_dir().'/arcturus-operator-evidence-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$started=microtime(true)-3600; $time=fn(float $t) => gmdate('Y-m-d\TH:i:s',(int)$t).sprintf('.%06dZ',(int)(($t-floor($t))*1000000));
$started = OperatorBootEvidence::timestamp($time($started));
$container=['Id'=>str_repeat('a',64),'Name'=>'/'.$uuid,'State'=>['Running'=>true,'Pid'=>1234,'StartedAt'=>$time($started)],'RestartCount'=>1];
$raw=implode('',array_map(fn($line)=>$time($started+1).' '.$line."\n",$lines));
$meta=['schema'=>1,'operation_id'=>$operation,'server_uuid'=>$uuid,'inspect_before'=>$container,'inspect_after'=>$container,'collected_at'=>$time(microtime(true)),'log_sha256'=>hash('sha256',$raw)];
$write=function(array $metadata, string $log)use($directory):void {file_put_contents($directory.'/metadata.json',json_encode($metadata));file_put_contents($directory.'/console.log',$log);chmod($directory.'/metadata.json',0600);chmod($directory.'/console.log',0600);};
$sample=RunningInstance::sample(['state'=>'running','utilization'=>['uptime'=>(microtime(true)-$started)*1000]],microtime(true),microtime(true));
$read=fn()=>OperatorBootEvidence::read($directory,$operation,$uuid,$sample,$target);
try {
    $write($meta,$raw); $imported=$read();
    check($imported['container_id']===$container['Id'] && StartupEvidence::matches($imported,$target), 'Operator evidence did not bind container/runtime');
    foreach(['server','operation','container','start','pid','restart','running','runtime','hash','stale','future','old_line','truncated','untimed'] as $bad) {
        $changed=$meta; $log=$raw;
        if($bad==='server')$changed['server_uuid']='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        if($bad==='operation')$changed['operation_id']='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        if($bad==='container')$changed['inspect_after']['Id']=str_repeat('b',64);
        if($bad==='start'){$changed['inspect_before']['State']['StartedAt']=$time($started+60);$changed['inspect_after']=$changed['inspect_before'];}
        if($bad==='pid')$changed['inspect_after']['State']['Pid']++;
        if($bad==='restart')$changed['inspect_after']['RestartCount']++;
        if($bad==='running'){$changed['inspect_before']['State']['Running']=false;$changed['inspect_after']=$changed['inspect_before'];}
        if($bad==='runtime')$log=str_replace('version 25','version 21',$log);
        if($bad==='hash')$changed['log_sha256']=str_repeat('0',64);
        if($bad==='stale')$changed['collected_at']=$time(microtime(true)-1000);
        if($bad==='future')$changed['collected_at']=$time(microtime(true)+60);
        if($bad==='old_line')$log=$time($started-1)." Done (1.0s)!\n".$log;
        if($bad==='truncated')$log=rtrim($log,"\n");
        if($bad==='untimed')$log="Done (1.0s)!\n".$log;
        if($bad!=='hash')$changed['log_sha256']=hash('sha256',$log);
        $write($changed,$log); rejects($read,'Unsafe Docker evidence accepted: '.$bad);
    }
    $write($meta,$raw); chmod($directory.'/console.log',0644); rejects($read,'Public evidence accepted'); chmod($directory.'/console.log',0600);
    rename($directory.'/console.log',$directory.'/saved.log'); symlink($directory.'/saved.log',$directory.'/console.log'); rejects($read,'Symlink evidence accepted');
} finally {
    foreach(['metadata.json','console.log','saved.log'] as $file) { if(file_exists($directory.'/'.$file)||is_link($directory.'/'.$file))unlink($directory.'/'.$file); }
    rmdir($directory);
}
echo "Retained startup facts and operator Docker evidence fixtures passed\n";
