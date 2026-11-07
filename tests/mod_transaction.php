<?php
/** Isolated offline filesystem proof; never qualifies or bypasses the production deployment gate. */
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\ModUpgradeTransaction;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\UpdatePlanner;

$root = sys_get_temp_dir().'/arcturus-mod-transaction-'.bin2hex(random_bytes(8));
foreach (['live/mods', 'stage/mods', 'backups', 'live/world/playerdata', 'live/config/Paradigm'] as $dir) { mkdir($root.'/'.$dir, 0700, true); }
$live=$root.'/live'; $stage=$root.'/stage';
$old='mods/Fixture-1.jar'; $new='mods/Fixture-2.jar'; $custom='mods/Paradigm.jar';
$jar = function (string $root, string $path, string $id, string $version): array {
    $file=new ZipArchive; check($file->open($root.'/'.$path, ZipArchive::CREATE) === true, 'Cannot create JAR fixture');
    $file->addFromString('META-INF/mods.toml', "modLoader=\"javafml\"\nloaderVersion=\"[47,)\"\nlicense=\"MIT\"\n[[mods]]\nmodId=\"$id\"\nversion=\"$version\"\n");
    $file->close();
    return ['identity'=>$id,'ids'=>[$id],'loader'=>'forge','source'=>'META-INF/mods.toml','version'=>$version,'sha256'=>hash_file('sha256',$root.'/'.$path)];
};
$baseMeta=[$old=>$jar($live,$old,'fixturemod','1')]; $targetMeta=[$new=>$jar($stage,$new,'fixturemod','2')];
$localMeta=[...$baseMeta,$custom=>$jar($live,$custom,'paradigm','custom')];
file_put_contents($live.'/world/playerdata/player.dat','persistent player data'); file_put_contents($live.'/config/Paradigm/server.toml','custom configuration');
$base=[$old=>$baseMeta[$old]['sha256']]; $target=[$new=>$targetMeta[$new]['sha256']];
$local=[$old=>$base[$old],$custom=>$localMeta[$custom]['sha256']];
$identities=['base'=>[$old=>'fixturemod'],'local'=>[$old=>'fixturemod',$custom=>'paradigm'],'target'=>[$new=>'fixturemod']];
$evidence=['base'=>$baseMeta,'local'=>$localMeta,'target'=>$targetMeta]; $rules=(new ProtectionPolicy)->template('tfg_arcturus');
$plan=(new UpdatePlanner)->plan($base,$local,$target,$rules,$identities,[],[],[],$evidence);
$operation=array_values(array_filter($plan['changes'],fn($r)=>$r['action']==='upgrade_mod'))[0];
$tx=new ModUpgradeTransaction;
try {
    copy($live.'/'.$old,$live.'/mods/Unexpected.jar');
    rejects(fn()=>$tx->requireSnapshot($live,$local),'Unapproved active JAR set accepted'); unlink($live.'/mods/Unexpected.jar');
    try {
        $tx->apply($live,$stage,$operation,function($state)use($live,$old,$new):void {
            check(! (is_file($live.'/'.$old) && is_file($live.'/'.$new)), 'Old and new active JARs coexisted during transaction');
            if($state==='mod_upgrade_installed') { throw new RuntimeException('Simulated durable journal failure'); }
        });
        throw new RuntimeException('Journal failure did not abort upgrade');
    } catch(RuntimeException $error) { check(str_contains($error->getMessage(),'original JAR retained'),'Original JAR was not safely restored: '.$error->getMessage()); }
    check(hash_file('sha256',$live.'/'.$old)===$base[$old] && ! is_file($live.'/'.$new),'Partial upgrade did not return exactly the old JAR');
    $context=['identities'=>$identities,'mod_evidence'=>$evidence,'local_mod_files'=>$local];
    $result=(new LocalDeployment)->apply($live,$stage,$root.'/backups',$base,$target,$rules,true,null,$context,$plan['changes']);
    check(! is_file($live.'/'.$old) && hash_file('sha256',$live.'/'.$new)===$target[$new],'Logical upgrade did not replace the old JAR');
    check(hash_file('sha256',$live.'/'.$custom)===$local[$custom] && file_get_contents($live.'/world/playerdata/player.dat')==='persistent player data' && file_get_contents($live.'/config/Paradigm/server.toml')==='custom configuration','Upgrade changed protected data');
    (new LocalDeployment)->restore($live,$result['backup'],true);
    check(is_file($live.'/'.$old) && ! is_file($live.'/'.$new) && hash_file('sha256',$live.'/'.$old)===$base[$old],'Full rollback did not restore the original mod set');
    echo "Offline logical JAR transaction, no duplicate active versions, conditional snapshot, journal-failure recovery and full backup restore passed\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}
    rmdir($root);
}
