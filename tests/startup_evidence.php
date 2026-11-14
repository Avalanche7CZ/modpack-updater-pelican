<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Native\OperatorBootEvidence;
use Arcturus\ModpackManager\Native\RunningInstance;
use Arcturus\ModpackManager\Native\StartupEvidence;

$target = ['minecraft'=>'1.7.10','loader'=>'crucible','loader_version'=>null,'java'=>25];
$lines = file(__DIR__.'/fixtures/gtnh-crucible/startup-output.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

// Generic Minecraft banners are weak evidence in either ordering, including after proof() is read.
$vanillaTarget=['minecraft'=>'1.7.10','loader'=>'vanilla','loader_version'=>'1.7.10','java'=>25];
$forgeTarget=['minecraft'=>'1.7.10','loader'=>'forge','loader_version'=>'10.13.4.1614','java'=>25];
$generic='[Server thread/INFO]: Starting minecraft server version 1.7.10';
$forge=['Forge Mod Loader version 7.99.40.1614 for Minecraft 1.7.10 loading','MinecraftForge v10.13.4.1614 Initialized'];
$vanilla=new StartupEvidence;
$vanilla->line($generic);
check($vanilla->proof()['runtime']['loader']===null, 'Incomplete startup was prematurely classified as Vanilla');
foreach (['Java is OpenJDK, version 25.0.1','Done (1.0s)!'] as $line) $vanilla->line($line);
check(StartupEvidence::matches($vanilla->proof(),$vanillaTarget), 'Actual vanilla fallback was lost');
foreach ($forge as $line) $vanilla->line($line);
$vanilla->line('Done (1.0s)!');
check(StartupEvidence::matches($vanilla->proof(),$forgeTarget), 'Reading vanilla fallback poisoned later strong loader evidence');
foreach ([[$generic,...$forge],[...$forge,$generic]] as $order) {
    $evidence=new StartupEvidence;
    foreach ([...$order,'Java is OpenJDK, version 25.0.1','Done (1.0s)!'] as $line) $evidence->line($line);
    check(StartupEvidence::matches($evidence->proof(),$forgeTarget), 'Generic banner conflicted with Forge depending on order');
}
$crucibleMarker='Crucible DummyMod injected successfully!';
foreach ([[$crucibleMarker,$generic],[$generic,$crucibleMarker]] as $order) {
    $evidence=new StartupEvidence;
    foreach ([...$order,'Java is OpenJDK, version 25.0.1','Done (1.0s)!'] as $line) $evidence->line($line);
    check(StartupEvidence::matches($evidence->proof(),$target), 'Vanilla fallback overrode the real Crucible marker');
}
foreach ([[$crucibleMarker,'Loading Minecraft 1.7.10 with Fabric Loader 0.16.9'],['Loading Minecraft 1.7.10 with Fabric Loader 0.16.9',$crucibleMarker]] as $order) {
    $evidence=new StartupEvidence;
    foreach ([...$order,$generic,'Java is OpenJDK, version 25.0.1','Done (1.0s)!'] as $line) $evidence->line($line);
    check(!$evidence->proof()['valid'], 'Crucible marker masked an incompatible strong loader');
}
// A generic banner cannot repair a genuine loader/version conflict.
foreach (['Loading Minecraft 1.7.10 with Fabric Loader 0.16.9','ModLauncher args [--fml.neoForgeVersion, 21.1.1, --fml.mcVersion, 1.7.10]','MinecraftForge v10.13.4.9999 Initialized'] as $conflict) {
    $evidence=new StartupEvidence;
    foreach ([...$forge,'Java is OpenJDK, version 25.0.1',$conflict,$generic,'Done (1.0s)!'] as $line) $evidence->line($line);
    check(!$evidence->proof()['valid'], 'Genuinely conflicting loader/version was accepted');
    check(!StartupEvidence::matches($evidence->proof(),$forgeTarget) && !StartupEvidence::matches($evidence->proof(),$vanillaTarget), 'Generic banner masked a strong loader conflict');
}
$changedMinecraft=new StartupEvidence;
foreach ([...$forge,'Starting minecraft server version 1.12.2','Java is OpenJDK, version 25.0.1','Done (1.0s)!'] as $line) $changedMinecraft->line($line);
check(!$changedMinecraft->proof()['valid'], 'Contradictory Minecraft version was ignored with the generic banner');

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
    // Docker import retains the FIRST valid boot, even when later runtime output resets the live parser.
    $lateCrucible=$time($started+2)." [Server thread/INFO]: Later Crucible runtime message\n";
    $fullLog=$raw.$lateCrucible; $fullMeta=[...$meta,'log_sha256'=>hash('sha256',$fullLog)];
    $write($fullMeta,$fullLog); $retained=$read();
    check(StartupEvidence::matches($retained,$target) && $retained['sha256']===$imported['sha256'] && $retained['lines']===$imported['lines'], 'Post-Done Crucible output erased or replaced the first verified startup');
    check($retained['log_sha256']===hash('sha256',$fullLog), 'Docker import hashed only the verified startup prefix');
    $secondDone=$fullLog.$time($started+3)." Done (2.0s)!\n";
    $write([...$meta,'log_sha256'=>hash('sha256',$secondDone)],$secondDone);
    check($read()['sha256']===$imported['sha256'], 'A later valid Done replaced the first matching snapshot');
    $liveParser=new StartupEvidence;
    foreach ($lines as $line) $liveParser->line($line);
    $liveParser->line('Later Crucible runtime message');
    check(!$liveParser->proof()['done'], 'Docker snapshot handling changed the live Wings parser');
    $rejectMessage=function(string $expected)use($read):void {
        try { $read(); }
        catch (RuntimeException $error) { check(str_contains($error->getMessage(),$expected), 'Docker import did not distinguish hash mismatch from missing valid startup evidence'); return; }
        throw new RuntimeException('Invalid Docker evidence unexpectedly accepted');
    };
    foreach (['untimed','truncated','old_timestamp','future_timestamp','oversized','changed_tail'] as $badTail) {
        $log=match($badTail) {
            'untimed'=>$fullLog."Untimed footer\n",
            'truncated'=>rtrim($fullLog,"\n"),
            'old_timestamp'=>$fullLog.$time($started-1)." Old footer\n",
            'future_timestamp'=>$fullLog.$time(microtime(true)+60)." Future footer\n",
            'oversized'=>$fullLog.$time($started+3).' '.str_repeat('x',262145)."\n",
            'changed_tail'=>str_replace('Later Crucible runtime message','Changed Crucible runtime message',$fullLog),
        };
        $metadata=$fullMeta;
        if ($badTail!=='changed_tail') $metadata['log_sha256']=hash('sha256',$log);
        $write($metadata,$log);
        if ($badTail==='changed_tail') $rejectMessage('SHA-256');
        else rejects($read,'Verified boot prefix bypassed invalid Docker log tail: '.$badTail);
    }
    foreach (['wrong_runtime','no_done','done_before_runtime'] as $missingBoot) {
        $withoutDone=implode('',array_map(fn($line)=>$time($started+1).' '.$line."\n",array_slice($lines,0,-1)));
        $log=match($missingBoot) {
            'wrong_runtime'=>str_replace('version 25','version 21',$raw).$lateCrucible,
            'no_done'=>$withoutDone.$lateCrucible,
            'done_before_runtime'=>$time($started)." Done (1.0s)!\n".$withoutDone.$lateCrucible,
        };
        $write([...$meta,'log_sha256'=>hash('sha256',$log)],$log);
        $rejectMessage('valid original-runtime startup evidence');
    }
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
