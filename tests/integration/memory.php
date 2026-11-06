<?php

declare(strict_types=1);
ini_set('memory_limit', '128M');
$app = require __DIR__.'/bootstrap.php';
$source = dirname(__DIR__, 2);
use App\Models\User;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Schema::create('webhook_configurations', function (Blueprint $t) {
    $t->id();
    $t->string('scope');
    $t->json('events');
    $t->softDeletes();
});
Schema::create('servers', function (Blueprint $t) {
    $t->id();
    $t->string('uuid');
    $t->string('name');
    $t->string('startup')->default('bash run.sh');
    $t->string('image')->default('java_21');
});
foreach (glob($source.'/database/migrations/*.php') as $path) {
    (require $path)->up();
}
Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool
    {
        return true;
    }

    public function checkPermissionTo($permission, $guardName = null): bool
    {
        return true;
    }
});
$admin = Filament::getPanel('admin');
(new ModpackManagerPlugin)->register($admin);
Filament::setCurrentPanel($admin);
foreach (['index', 'create', 'view', 'edit'] as $page) {
    Route::get('/fixture/'.$page.'/{record?}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.'.$page);
}
app('view')->addNamespace('arcturus-modpack-manager', $source.'/resources/views');
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\LocalDeployment;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Arcturus\ModpackManager\Services\ServerPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function policySnapshot(string $name, string $html): void
{
    $directory = getenv('ARCTURUS_UI_SNAPSHOTS');
    if (! $directory) {
        return;
    }
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $manifest = json_decode(file_get_contents(getenv('PELICAN_SOURCE').'/public/build/manifest.json'), true);
    $css = $manifest['resources/css/app.css']['file'];
    file_put_contents($directory.'/'.$name.'.html', '<!doctype html><html lang="en" class="fi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/'.$css.'"></head><body class="bg-gray-50 text-gray-950 dark:bg-gray-950 dark:text-white" style="padding:16px">'.Blade::render('@filamentStyles').$html.'</body></html>');
}


$dir = sys_get_temp_dir().'/arcturus-memory-'.bin2hex(random_bytes(5)); mkdir($dir, 0700);
function memoryPoint($step) { fwrite(STDOUT, $step.' usage='.round(memory_get_usage(true)/1048576,1).' MiB peak='.round(memory_get_peak_usage(true)/1048576,1).' MiB'.PHP_EOL); }
$n=22000;
$inventory=fopen($dir.'/inventory.json','w'); $changes=fopen($dir.'/changes.json','w'); $manifest=fopen($dir.'/manifest.json','w');
fwrite($inventory,'{"files":{'); fwrite($changes,'{"policy_revision":0,"verified_baseline":true,"version":"1","changes":[');
fwrite($manifest,'{"schema":2,"provider":"custom","project":"fixture","version":"1","verification":"upstream-checksums","runtime":[],"files":{');
for($i=0;$i<$n;$i++) {
 $path=sprintf('config/mod-%03d/recipes/file-%05d.json',$i%100,$i); $hash=hash('sha256',$path);
 if($i) { fwrite($inventory,','); fwrite($changes,','); fwrite($manifest,','); }
 $pair=json_encode($path,JSON_UNESCAPED_SLASHES).':'.json_encode($hash); fwrite($inventory,$pair);fwrite($manifest,$pair);
 $row=['path'=>$path,'action'=>'preserve_local_change','classification'=>'locally_modified','file_state'=>'locally_modified','rule'=>'protect','rule_origin'=>'Three-way comparison','effective_rule'=>null,'matching_rules'=>[],'destructive'=>false,'approval_required'=>false,'metadata'=>['name'=>'Example configuration '.str_repeat('x',100),'loader'=>'forge','version'=>'1.0'],'identity'=>null,'base_sha256'=>$hash,'local_sha256'=>$hash,'target_sha256'=>$hash,'blocked'=>false];
 fwrite($changes,json_encode($row,JSON_UNESCAPED_SLASHES));
}
fwrite($inventory,'},"identities":{},"metadata":{},"excluded_roots":["world"],"scope":"managed-pack-files","notice":"fixture"}');fwrite($changes,']}');
fwrite($manifest,'},"directory_discovery":[{"path":"config","classification":"standard"}],"artifacts":[{"sha256":"'.str_repeat('a',64).'","hashes":{"sha256":"'.str_repeat('a',64).'"}}]}');
fclose($inventory);fclose($changes);fclose($manifest);
$store=storage_path('app/arcturus-modpack-manager/manifests'); if(!is_dir($store))mkdir($store,0700,true);
$path=$store.'/'.hash_file('sha256',$dir.'/manifest.json').'.json'; $out=fopen($path,'w');fwrite($out,'{"manifest":');$in=fopen($dir.'/manifest.json','r');stream_copy_to_stream($in,$out);fclose($in);fwrite($out,',"signature":"'.hash_hmac_file('sha256',$dir.'/manifest.json',config('app.key')).'"}');fclose($out);
$id=DB::table('arcturus_modpack_servers')->insertGetId(['label'=>'Large inventory fixture','pelican_server_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','provider'=>'custom','source'=>'fixture','installed_version'=>'1','baseline_status'=>'verified','base_manifest_path'=>$path,'transport'=>'wings','file_rules'=>'[]','disabled_mods'=>'[]']);
foreach(['file_inventory'=>'inventory','adoption_report'=>'changes','preview_summary'=>'changes'] as $column=>$file) DB::table('arcturus_modpack_servers')->where('id',$id)->update([$column=>file_get_contents($dir.'/'.$file.'.json')]);
memoryPoint('fixture stored');
config(['queue.default'=>'database','queue.connections.database'=>['driver'=>'database','retry_after'=>14520]]);
Illuminate\Support\Facades\Bus::fake([Arcturus\ModpackManager\Jobs\CompareInstallation::class]);
$server=ManagedServer::lightweight()->findOrFail($id);memoryPoint('model loaded');

DB::table('servers')->insert(['uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','name'=>'Large inventory fixture']);
$app->bind(Arcturus\ModpackManager\Services\PreviewService::class, fn () => throw new RuntimeException('Synchronous preview in HTTP'));
$app->bind(Arcturus\ModpackManager\Services\AdoptionService::class, fn () => throw new RuntimeException('Synchronous inventory in HTTP'));
$columnReads=0;
DB::listen(function ($query) use (&$columnReads) {
    if (str_contains($query->sql, 'arcturus_modpack_servers') && preg_match('/^select \* /i', $query->sql)) {
        throw new RuntimeException('HTTP hydrated a full evidence record');
    }
    $columnReads++;
});
$view=Livewire\Livewire::test(ViewManagedServer::class,['record'=>$id]);
check(str_contains($view->html(),'Large inventory fixture'),'Detail page failed');
memoryPoint('overview rendered');
$view->call('selectTab','mods')->set('modsOnly',false);
$files=$view->instance()->fileRows();
check($files['total']===$n && count($files['rows'])===50,'File pagination materialized or omitted rows');
$view->set('changePage',440);
check(count($view->instance()->fileRows()['rows'])===50,'Last file page incorrect');
$view->call('selectTab','changes');
check($view->instance()->changeRows()['total']===$n && count($view->instance()->changeRows()['rows'])===50,'Change pagination incorrect');
memoryPoint('file and change pages rendered');
$view->call('selectTab','overview')->call('mountAction','directoryDecisions');
$request=$view->get('mountedActions')[0]['data']['request_id'];
$beforeRevision=$server->policy_revision;
$throwResponse=false;
app('view')->composer('arcturus-modpack-manager::managed-server',function () use (&$throwResponse) {
    if ($throwResponse) { throw new RuntimeException('Simulated failed HTTP response after commit'); }
});
$throwResponse=true;
try { $view->call('callMountedAction'); throw new RuntimeException('Response failure was not simulated'); }
catch (Throwable $error) { check(str_contains($error->getMessage(),'Simulated failed HTTP response'),'Unexpected submission error: '.$error->getMessage()); }
$throwResponse=false;
$server=ManagedServer::lightweight()->findOrFail($id);
check($server->policy_revision===$beforeRevision+1,'Directory save was lost or duplicated');
$operation=$server->operations()->findOrFail($request);
check($operation->phase==='queued' && $server->active_operation_id===$request,'Failed response lost the durable comparison intent');
$decisions=[['path'=>'config','mode'=>'manage']];
$replayed=app(ServerPolicyService::class)->saveDirectories($server,$decisions,$beforeRevision,$request);
check($replayed->id===$operation->id && $server->operations()->count()===1,'HTTP replay duplicated comparison');
check(Illuminate\Support\Facades\Bus::dispatched(Arcturus\ModpackManager\Jobs\CompareInstallation::class)->count()===1,'HTTP replay dispatched another operation');
check(ManagedServer::lightweight()->findOrFail($id)->policy_revision===$beforeRevision+1,'HTTP replay changed the revision');
memoryPoint('directory submission and replay completed');
function evidenceHash($id,$column) {
    $hash=hash_init('sha256');$offset=1;
    for (;;) {
        $part=DB::table('arcturus_modpack_servers')->where('id',$id)->selectRaw('substr(CAST('.$column.' AS BLOB), ?, 65536) AS fragment',[$offset])->value('fragment');
        if ($part===null || $part==='') {break;}
        hash_update($hash,$part);$offset+=strlen($part);
    }
    return hash_final($hash);
}
foreach(['file_inventory'=>'inventory','adoption_report'=>'changes','preview_summary'=>'changes'] as $column=>$file) {
    check(evidenceHash($id,$column)===hash_file('sha256',$dir.'/'.$file.'.json'),'Policy save erased comparison evidence: '.$column);
}
app(Arcturus\ModpackManager\Services\OperationTracker::class)->transition($operation,'completed');
DB::table('arcturus_modpack_servers')->where('id',$id)->update(['target_manifest_path'=>$path,'selected_version'=>'1']);
// A dispatcher outage after commit leaves one durable queued receipt for scheduler recovery.
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class,new class($app) extends Illuminate\Bus\Dispatcher {
    public function dispatch($command) { throw new RuntimeException('queue unavailable'); }
});
$server=ManagedServer::lightweight()->findOrFail($id);
$secondId=(string) Illuminate\Support\Str::uuid();
$second=app(ServerPolicyService::class)->saveDirectories($server,[['path'=>'config','mode'=>'keep']],$server->policy_revision,$secondId);
$second->refresh();
check($second->phase==='queued' && $second->context['mode']==='preview' && str_contains($second->error,'awaiting queue dispatch'),'Dispatcher outage erased a committed policy or comparison receipt');
$server=ManagedServer::lightweight()->findOrFail($id);
$again=app(ServerPolicyService::class)->saveDirectories($server,[['path'=>'config','mode'=>'keep']],$server->policy_revision-1,$secondId);
check($again->id===$secondId && $server->operations()->count()===2,'Dispatcher recovery created duplicate operations');
app(Arcturus\ModpackManager\Services\OperationTracker::class)->transition($second,'completed');
$server=ManagedServer::lightweight()->findOrFail($id);
config(['queue.default'=>'sync']);$thirdId=(string) Illuminate\Support\Str::uuid();
rejects(fn()=>app(ServerPolicyService::class)->saveDirectories($server,[['path'=>'config','mode'=>'exclude']],$server->policy_revision,$thirdId),'Synchronous queue accepted');
$fresh=ManagedServer::lightweight()->findOrFail($id);
check($fresh->policy_revision===$server->policy_revision && ! $fresh->operations()->whereKey($thirdId)->exists(),'Failed transaction partially saved directory decisions');
check($fresh->base_manifest_path===$path && ! $fresh->deploy_enabled,'Directory review lost the verified baseline or enabled deployment');
$small=['schema'=>2,'provider'=>'custom','project'=>'stream-auth-'.bin2hex(random_bytes(5)),'version'=>'1','verification'=>'upstream-checksums',
    'files'=>['config/žluť.json'=>str_repeat('a',64)],'runtime'=>['escaped'=>str_repeat('a\\/b',20000)],
    'artifacts'=>[['sha256'=>str_repeat('a',64),'hashes'=>['sha256'=>str_repeat('a',64)]]]];
$smallPath=app(ReleaseStore::class)->put($small);
check(app(ReleaseStore::class)->directories($smallPath)===[['path'=>'config','classification'=>'standard']], 'Streaming directory metadata did not authenticate the original envelope');
$bytes=file_get_contents($smallPath);file_put_contents($smallPath,str_replace('config','configs',$bytes));unset($bytes);
rejects(fn()=>app(ReleaseStore::class)->directories($smallPath),'Tampered upstream directory evidence was accepted');
unlink($smallPath);
check(memory_get_peak_usage(true)<128*1048576,'128 MiB budget exceeded');
memoryPoint('22,000-file workflow passed under 128 MiB');
fwrite(STDOUT,'Bounded database projections, pagination, transactional submission, HTTP replay and queue outage checks passed ('.$columnReads.' bounded queries).'.PHP_EOL);
foreach (glob($dir.'/*') as $fixture) { unlink($fixture); }
rmdir($dir);
$GLOBALS['arcturus_integration_passed']=true;
