<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
$source = dirname(__DIR__, 2);
use App\Models\User;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use App\Events\ActivityLogged;
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
/**
 * Real Pelican DaemonServerRepository::power() writes activity records.
 * Supply the same audit tables it needs in this disposable in-memory DB,
 * instead of mocking out the repository's native side effects.
 */
Schema::create('activity_logs', function (Blueprint $t) {
    $t->id();
    $t->string('ip');
    $t->text('properties');
    $t->unsignedBigInteger('api_key_id')->nullable();
    $t->unsignedBigInteger('actor_id')->nullable();
    $t->string('actor_type')->nullable();
    $t->string('event');
    $t->text('description')->nullable();
    $t->timestamp('timestamp')->nullable();
});
Schema::create('activity_log_subjects', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('activity_log_id');
    $t->unsignedBigInteger('subject_id');
    $t->string('subject_type');
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
use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Jobs\SendDiscordNotification;
use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use App\Http\Controllers\Api\Remote\Backups\BackupStatusController;
use App\Http\Requests\Api\Remote\ReportBackupCompleteRequest;
use App\Models\Backup;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\DeploymentApproval;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Illuminate\Http\Request;

Schema::table('servers', function (Blueprint $t) {
    $t->unsignedBigInteger('node_id')->default(1); $t->unsignedBigInteger('owner_id')->default(1);
    $t->unsignedBigInteger('egg_id')->nullable(); $t->string('status')->nullable(); $t->integer('backup_limit')->default(20);
    $t->boolean('suspended')->default(false); $t->timestamps();
});
Schema::create('subusers', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('user_id'); $t->json('permissions')->nullable(); });
Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('uuid'); $t->string('username'); $t->string('email'); $t->string('password'); $t->timestamps(); });
Schema::create('server_transfers', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->boolean('successful')->nullable(); });
Schema::create('nodes', function (Blueprint $t) {
    $t->id(); $t->string('uuid'); $t->string('daemon_base'); $t->string('scheme'); $t->string('fqdn');
    $t->boolean('maintenance_mode')->default(false); $t->integer('daemon_connect'); $t->text('daemon_token'); $t->string('daemon_token_id'); $t->timestamps();
});
Schema::create('backup_hosts', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('schema'); $t->json('configuration')->nullable(); $t->timestamps(); });
Schema::create('backup_host_node', function (Blueprint $t) { $t->unsignedBigInteger('backup_host_id'); $t->unsignedBigInteger('node_id'); });
Schema::create('backups', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('server_id'); $t->string('uuid'); $t->string('name'); $t->json('ignored_files');
    $t->unsignedBigInteger('backup_host_id'); $t->boolean('is_successful')->default(false); $t->boolean('is_locked')->default(false);
    $t->boolean('is_scheduled')->default(false); $t->string('checksum')->nullable(); $t->unsignedBigInteger('bytes')->default(0);
    $t->string('upload_id')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
});
Schema::create('jobs', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
    $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
});
Schema::create('allocations', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id')->nullable(); $t->unsignedBigInteger('node_id'); $t->string('ip'); $t->integer('port'); });
Schema::create('mounts', function (Blueprint $t) { $t->id(); });
Schema::create('mountables', function (Blueprint $t) { $t->unsignedBigInteger('mount_id'); $t->unsignedBigInteger('mountable_id'); $t->string('mountable_type'); });
Schema::create('egg_variables', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('egg_id'); $t->string('env_variable'); $t->string('default_value')->nullable(); });
Schema::create('server_variables', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('server_id'); $t->unsignedBigInteger('variable_id'); $t->string('variable_value')->nullable(); });
$egg = new \App\Models\Egg;
$egg->forceFill(['uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'force_outgoing_ip' => false, 'file_denylist' => [], 'features' => []]);
Server::retrieved(function (Server $server) use ($egg): void {
    $server->setRelation('egg', $egg)->setRelation('allocations', new \Illuminate\Database\Eloquent\Collection)->setRelation('allocation', null)->setRelation('mounts', new \Illuminate\Database\Eloquent\Collection);
});
$pluginPath = plugin_path('arcturus-modpack-manager');
if (! is_dir(dirname($pluginPath))) { mkdir(dirname($pluginPath), 0700, true); }
if (! file_exists($pluginPath)) { symlink($source, $pluginPath); }
$app->register(\Arcturus\ModpackManager\Providers\ModpackManagerServiceProvider::class);
DB::table('users')->insert(['id' => 1, 'uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'username' => 'fixture', 'email' => 'fixture@example.test', 'password' => 'unused']);
$node = new Node;
$node->forceFill(['id' => 1, 'uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'daemon_base' => '/fixture',
    'scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => str_repeat('x', 64), 'daemon_token_id' => 'fixture']);
DB::table('nodes')->insert(array_intersect_key($node->getAttributes(), array_flip(Schema::getColumnListing('nodes'))));
DB::table('backup_hosts')->insert(['id' => 1, 'name' => 'Wings fixture', 'schema' => 'wings', 'configuration' => '{}']);
DB::table('backup_host_node')->insert(['backup_host_id' => 1, 'node_id' => 1]);
$adminUser = Auth::user(); $adminUser->id = 1;
Event::fake([ActivityLogged::class, \App\Events\Server\BackupCompleted::class]);
config(['backups.throttles.period' => 0, 'backups.throttles.limit' => 100,
    'queue.default' => 'deployment-fixture', 'queue.connections.deployment-fixture' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 14520],
    'queue.failed.driver' => null, 'arcturus-modpack-manager.deployment_guard_timeout' => 1, 'arcturus-modpack-manager.deployment_start_timeout' => 2,
    'arcturus-modpack-manager.deployment_backup_timeout' => 1, 'arcturus-modpack-manager.deployment_restore_timeout' => 1,
    'arcturus-modpack-manager.discord_events' => false]);
Http::preventStrayRequests();
$root = sys_get_temp_dir().'/arcturus-deployment-'.bin2hex(random_bytes(6)); mkdir($root, 0700);
$hash = fn ($value) => hash('sha256', $value);
$runtimeLog = "[main/INFO]: ModLauncher running: args [--launchTarget, forgeserver, --fml.forgeVersion, 47.4.13, --fml.mcVersion, 1.20.1]\n[main/INFO]: ModLauncher 10.0.9 starting: java version 17.0.17 by Eclipse Adoptium\n[Server thread/INFO]: Done (1.0s)!\n";
$requests = []; $snapshots = []; $states = []; $starts = []; $daemonConfigurations = []; $scenario = 'success';
\Arcturus\ModpackManager\Models\UpdateEvent::created(function ($event) use (&$scenario): void { if ($scenario === 'metadata_failure' && $event->kind === 'deploy' && $event->status === 'success') { throw new RuntimeException('Fixture completion metadata transaction failed'); } });
$fixtures = [];
$write = function ($root, $path, $content): void { if (! is_dir(dirname($root.'/'.$path))) { mkdir(dirname($root.'/'.$path), 0770, true); } file_put_contents($root.'/'.$path, $content); };
$make = function (string $name) use (&$fixtures, &$states, &$starts, &$daemonConfigurations, $root, $write, $hash, $runtimeLog): ManagedServer {
    $uuid = (string) \Illuminate\Support\Str::uuid(); $id = bin2hex(random_bytes(16));
    $live = $root.'/'.$uuid; $stage = $root.'/stages-'.$uuid; mkdir($live, 0700); mkdir($stage, 0700);
    $files = $stage.'/'.$id.'/files'; mkdir($files, 0700, true);
    $common = ['forge-auto-install.txt' => "minecraftVersion=1.20.1\nloaderType=Forge\nloaderVersion=47.4.13\n",
        'start_server.sh' => "java -jar minecraft_server.jar\n", 'minecraft_server.jar' => 'verified server starter fixture', 'server.properties' => "level-name=world\n"];
    $baseFiles = [...$common, 'kubejs/server_scripts/recipes.js' => 'old upstream recipe', 'kubejs/server_scripts/obsolete.js' => 'old obsolete', 'config/upstream.cfg' => 'old upstream config', 'config/Paradigm/options.toml' => 'upstream default'];
    $targetFiles = [...$common, 'kubejs/server_scripts/recipes.js' => 'new upstream recipe', 'kubejs/server_scripts/added.js' => 'new script', 'config/upstream.cfg' => 'new upstream config', 'config/Paradigm/options.toml' => 'new upstream default'];
    foreach ($baseFiles as $path => $content) { $write($live, $path, $content); }
    foreach ($targetFiles as $path => $content) { $write($files, $path, $content); }
    foreach (['config/Paradigm/options.toml' => 'custom protected configuration', 'kubejs/server_scripts/local.js' => 'local extra', 'mods/Paradigm.jar' => 'custom mod',
        'world/level.dat' => 'world data', 'world/playerdata/uuid.dat' => 'player data', 'backups/previous.zip' => 'large excluded backup', 'logs/latest.log' => $runtimeLog,
        'libraries/fixture/runtime.jar' => 'installed runtime dependency', 'libraries/net/minecraftforge/forge/1.20.1-47.4.13/unix_args.txt' => '-DlegacyClassPath=libraries/fixture/runtime.jar --fml.forgeVersion 47.4.13 --fml.mcVersion 1.20.1'] as $path => $content) { $write($live, $path, $content); }
    $manifest = fn ($version, $contents) => ['schema' => 2, 'provider' => 'custom', 'project' => 'TFG-fixture', 'version' => $version, 'verification' => 'upstream-checksums',
        'files' => array_map($hash, $contents), 'runtime' => [], 'artifacts' => [['sha256' => $hash($name), 'hashes' => ['sha256' => $hash($name)]]]];
    $base = $manifest('0.13.15', $baseFiles); $target = $manifest('0.13.17', $targetFiles);
    file_put_contents($stage.'/'.$id.'/manifest.json', json_encode($target, JSON_THROW_ON_ERROR));
    $digest = hash_file('sha256', $stage.'/'.$id.'/manifest.json');
    file_put_contents($stage.'/'.$id.'/operation.json', json_encode(['state' => 'completed', 'manifest_sha256' => $digest]));
    DB::table('servers')->insert(['uuid' => $uuid, 'name' => $name, 'node_id' => 1, 'owner_id' => 1, 'startup' => 'bash start_server.sh', 'image' => 'fixture:java_17', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
    $entries = config('arcturus-modpack-manager.local_servers', []); $entries[$uuid] = ['root' => $live, 'staging' => $stage]; config(['arcturus-modpack-manager.local_servers' => $entries]);
    $server = ManagedServer::create(['label' => $name, 'pelican_server_uuid' => $uuid, 'provider' => 'custom', 'source' => 'TFG-fixture', 'installed_version' => '0.13.15',
        'selected_version' => '0.13.17', 'baseline_status' => 'verified', 'transport' => 'local', 'local_root' => $live, 'staging_parent' => $stage, 'prepared_root' => 'local:'.$id,
        'base_manifest_path' => app(ReleaseStore::class)->put($base), 'target_manifest_path' => app(ReleaseStore::class)->put($target),
        'baseline_provenance' => ['transport' => 'local'], 'target_metadata' => ['prepared' => ['transport' => 'local', 'node_operation_id' => $id, 'manifest_sha256' => $digest]],
        'runtime_report' => ['world_path_verified' => true, 'protected_roots' => ['world']], 'adopted_at' => now(), 'enabled' => true, 'backup_mode' => 'pelican', 'backup_excluded_directories' => ['backups'],
        'file_rules' => (new ProtectionPolicy)->template('tfg_arcturus')]);
    $daemonConfigurations[$uuid] = array_replace(['crash_detection_enabled' => true], app(\App\Services\Servers\ServerConfigurationStructureService::class)->handle(Server::where('uuid', $uuid)->firstOrFail()));
    $states[$uuid] = 'running'; $starts[$uuid] = 0; $fixtures[$uuid] = ['live' => $live, 'stage' => $stage, 'files' => $files];
    return $server;
};
$removeTree = function (string $directory): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) { if ($entry->isDir() && ! $entry->isLink()) { chmod($entry->getPathname(), 0770); } }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) { chmod($entry->getPathname(), 0770); rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
};
Http::fake(function ($request) use (&$requests, &$snapshots, &$states, &$starts, &$daemonConfigurations, &$scenario, &$fixtures, $root, $removeTree, $runtimeLog) {
    $url = $request->url(); preg_match('~/api/servers/([a-f0-9-]+)~', $url, $match); $uuid = $match[1] ?? '';
    check(isset($fixtures[$uuid]), 'Unexpected server HTTP request'); $fixture = $fixtures[$uuid];
    $requests[] = [$uuid, $request->method(), parse_url($url, PHP_URL_PATH), $request->data()];
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    if (str_ends_with($url, '/sync')) {
        $configuration = app(\App\Services\Servers\ServerConfigurationStructureService::class)->handle(Server::where('uuid', $uuid)->firstOrFail());
        $daemonConfigurations[$uuid] = array_replace(['crash_detection_enabled' => true], $configuration);
        if ($scenario === 'guard_ignored') { $daemonConfigurations[$uuid]['crash_detection_enabled'] = true; }
        return Http::response([], 204, $headers);
    }
    if (str_contains($url, '/files/list-directory')) {
        $rows = []; foreach (scandir($fixture['live']) as $name) { if (in_array($name, ['.', '..'])) { continue; } $file = $fixture['live'].'/'.$name; $rows[] = ['name' => $name, 'file' => is_file($file), 'directory' => is_dir($file), 'symlink' => is_link($file), 'size' => is_file($file) ? filesize($file) : 0]; }
        return Http::response($rows, 200, $headers);
    }
    if (str_contains($url, '/files/contents')) { return Http::response(file_get_contents($fixture['live'].'/server.properties'), 200, $headers); }
    if (str_ends_with($url, '/power')) {
        if ($request['action'] === 'stop') { $states[$uuid] = 'offline'; }
        else {
            check(($daemonConfigurations[$uuid]['suspended'] ?? true) === false && ($daemonConfigurations[$uuid]['crash_detection_enabled'] ?? true) === false, 'Controlled startup did not disable automatic crash recovery');
            $starts[$uuid]++;
            $failBoot = (in_array($scenario, ['failed_start', 'failed_restore', 'corrupt_restore', 'pending_restore'], true) && $starts[$uuid] === 1) || $scenario === 'failed_recovery_start';
            $states[$uuid] = $failBoot ? 'offline' : 'running';
            if ($states[$uuid] === 'running') { file_put_contents($fixture['live'].'/logs/latest.log', $runtimeLog."[Server thread/INFO]: Done (0.1s)!\n"); }
        }
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($url, '/backup')) {
        check($states[$uuid] === 'offline' && $daemonConfigurations[$uuid]['suspended'] === true && $daemonConfigurations[$uuid]['crash_detection_enabled'] === false, 'Backup lacked an acknowledged offline maintenance guard');
        $backup = Backup::where('uuid', $request['uuid'])->firstOrFail();
        check($backup->is_locked && $backup->ignored_files === ['/backups/'], 'Backup was not locked or exclusions differ');
        $tarPath = $root.'/'.$backup->uuid.'.tar'; $tar = new PharData($tarPath);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture['live'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $path = substr($entry->getPathname(), strlen($fixture['live']) + 1); if ($path === 'backups' || str_starts_with($path, 'backups/')) { continue; }
            if ($entry->isDir()) { $tar->addEmptyDir($path); } else { $tar->addFile($entry->getPathname(), $path); }
        }
        unset($tar); $snapshots[$backup->uuid] = $tarPath;
        if ($scenario === 'pending_backup') { return Http::response([], 202, $headers); }
        $callback = ReportBackupCompleteRequest::create('/fixture/backup', 'POST', ['successful' => $scenario !== 'backup_failure', 'checksum_type' => 'sha256', 'checksum' => $scenario === 'bad_backup' ? 'invalid' : hash_file('sha256', $tarPath), 'size' => filesize($tarPath)]);
        $callback->attributes->set('node', Node::findOrFail(1));
        app(BackupStatusController::class)->index($callback, $backup->uuid);
        if ($scenario === 'unlocked_backup') { $backup->refresh()->update(['is_locked' => false]); }
        if ($scenario === 'settings_drift') { DB::table('servers')->where('uuid', $uuid)->update(['image' => 'fixture:java_21', 'updated_at' => now()]); }
        if ($scenario === 'copy_failure') { chmod($fixture['live'].'/kubejs/server_scripts', 0555); }
        if ($scenario === 'worker_interrupted') {
            $managed = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
            (new DeployManagedServer($managed->id, false, $managed->approved_plan_sha256, $managed->active_operation_id))->failed(null);
        }
        if ($scenario === 'stage_drift') { file_put_contents($fixture['files'].'/kubejs/server_scripts/recipes.js', 'unexpected staged bytes'); }
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($url, '/restore')) {
        check($states[$uuid] === 'offline' && $request['truncate_directory'] === true && $daemonConfigurations[$uuid]['suspended'] === true && $daemonConfigurations[$uuid]['crash_detection_enabled'] === false, 'Restore not guarded/offline/full');
        check(! file_exists($fixture['live'].'/backups'), 'Excluded backups were not preserved before truncate');
        preg_match('~/backup/([a-f0-9-]+)/restore$~', $url, $backupMatch); $backupUuid = $backupMatch[1];
        $removeTree($fixture['live']); (new PharData($snapshots[$backupUuid]))->extractTo($fixture['live'], null, true);
        if ($scenario === 'corrupt_restore') { file_put_contents($fixture['live'].'/world/level.dat', 'corrupt restored world'); }
        if ($scenario === 'pending_restore') { return Http::response([], 202, $headers); }
        $callback = Request::create('/fixture/restore', 'POST', ['successful' => $scenario !== 'failed_restore']);
        $callback->attributes->set('node', Node::findOrFail(1)); app(BackupStatusController::class)->restore($callback, $backupUuid);
        return Http::response([], 202, $headers);
    }
    return Http::response(['state' => $states[$uuid], 'utilization' => ['uptime' => 60000], 'configuration' => $daemonConfigurations[$uuid]], 200, $headers);
});
$server = $make('successful manual update');
$plan = app(PreviewService::class)->generate($server);
check($plan['applicable'], 'Qualified fixture blocked: '.implode('; ', $plan['capability_blockers']));
check($plan['qualification']['current']['java'] === 17, 'Image hint was not replaced by actual startup evidence');
$beforeRequests = count($requests);
$test = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $server->id])->call('selectTab', 'updates');
check($test->instance()->canApplyUpdate(), 'Qualified Apply Update did not enable');
$test->call('mountAction', 'applyUpdate')->set('mountedActions.0.data.confirm', true)->call('callMountedAction');
$server->refresh(); $operation = Operation::findOrFail($server->active_operation_id);
check($operation->phase === 'queued' && DB::table('jobs')->count() === 1 && count($requests) === $beforeRequests, 'Approval performed server work in the HTTP request');
$replay = app(DeploymentApproval::class)->queue($server, $plan['plan_sha256'], $operation->id);
check($replay->id === $operation->id && DB::table('jobs')->count() === 1, 'Approval replay duplicated a deployment');
DB::table('arcturus_modpack_operations')->where('id', $operation->id)->update(['heartbeat_at' => now()->subMinutes(6)]);
$recover = app(\Arcturus\ModpackManager\Console\Commands\RecoverOperations::class);
$recover->setLaravel(app());
$recover->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput);
check(DB::table('jobs')->count() === 2, 'Scheduler did not redispatch the same queued approval');
Auth::guard()->forgetUser();
app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
$operation->refresh(); $server->refresh();
$count = count($requests); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
check(count($requests) === $count, 'Scheduler duplicate job replayed the deployment');
check($daemonConfigurations[$server->pelican_server_uuid]['suspended'] === false && $daemonConfigurations[$server->pelican_server_uuid]['crash_detection_enabled'] === true, 'Completed update did not release its per-server maintenance guard: '.$operation->phase.' '.($operation->error ?? ''));
check($operation->phase === 'completed' && $server->installed_version === '0.13.17', 'Queued update did not complete: '.($operation->error ?? $server->last_error));
$live = $fixtures[$server->pelican_server_uuid]['live'];
check(file_get_contents($live.'/kubejs/server_scripts/recipes.js') === 'new upstream recipe' && ! file_exists($live.'/kubejs/server_scripts/obsolete.js'), 'Approved update/removal not applied');
foreach (['kubejs/server_scripts/local.js' => 'local extra', 'world/level.dat' => 'world data', 'world/playerdata/uuid.dat' => 'player data',
    'mods/Paradigm.jar' => 'custom mod', 'config/Paradigm/options.toml' => 'custom protected configuration', 'backups/previous.zip' => 'large excluded backup'] as $path => $content) { check(file_get_contents($live.'/'.$path) === $content, 'Protected content changed: '.$path); }
$count = count($requests); app()->call([new DeployManagedServer($server->id, false, $plan['plan_sha256'], $operation->id), 'handle']);
check(count($requests) === $count, 'Duplicate terminal job replayed a successful deployment');
foreach (['failed_start', 'failed_restore', 'corrupt_restore', 'pending_backup', 'bad_backup', 'pending_restore', 'metadata_failure', 'copy_failure', 'stage_drift', 'settings_drift', 'backup_failure', 'unlocked_backup', 'failed_recovery_start', 'guard_ignored', 'worker_interrupted'] as $case) {
    Auth::guard()->setUser($adminUser); $scenario = $case; $server = $make($case);
    $plan = app(PreviewService::class)->generate($server); check($plan['applicable'], 'Failure fixture did not qualify');
    $operation = app(DeploymentApproval::class)->queue($server, $plan['plan_sha256'], (string) \Illuminate\Support\Str::uuid());
    // Consume the real serialized job in every scenario, including native backup/restore callbacks.
    Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
    $operation->refresh(); $server->refresh(); $uuid = $server->pelican_server_uuid; $live = $fixtures[$uuid]['live'];
    check($server->installed_version === '0.13.15' && $server->last_error, 'Failed update lost its original version or diagnostic: '.$case);
    $backupFolder = is_file($live.'/backups/previous.zip') ? $live.'/backups' : ($operation->context['preserved_stash'] ?? '').'/backups';
    check(file_get_contents($backupFolder.'/previous.zip') === 'large excluded backup', 'Backup exclusion lost during restore');
    if (in_array($case, ['failed_start', 'metadata_failure', 'copy_failure', 'stage_drift'], true)) {
        check($operation->phase === 'restored' && $states[$uuid] === 'running' && $server->active_operation_id === null, 'Verified rollback did not restore original running server: '.$operation->error);
        check(file_get_contents($live.'/world/level.dat') === 'world data' && file_get_contents($live.'/kubejs/server_scripts/recipes.js') === 'old upstream recipe', 'Verified rollback did not restore full original content');
    } else {
        check($operation->phase === 'manual_intervention' && $states[$uuid] === 'offline' && $server->active_operation_id === $operation->id, 'Failed/corrupt restore was restarted or unlocked');
        $count = count($requests); app()->call([new DeployManagedServer($server->id, false, $plan['plan_sha256'], $operation->id), 'handle']);
        check(count($requests) === $count, 'Uncertain deployment was replayed');
        if ($case === 'failed_restore') {
            $scenario = 'success';
            $backup = Backup::where('uuid', $operation->context['backup_uuid'])->firstOrFail();
            app(\Arcturus\ModpackManager\Services\PelicanBackupManager::class)->restoreAndWait($server->pelicanServer, $backup, ['backups'], timeoutSeconds: 1, preservedStash: $operation->context['preserved_stash']);
            Auth::guard()->setUser($adminUser);
            $verify = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $server->id])->call('mountAction', 'verifyRecovery')->call('callMountedAction');
            Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
            check($operation->refresh()->phase === 'restored' && $server->refresh()->active_operation_id === null && $states[$uuid] === 'running', 'Explicit operator recovery could not be verified/unlocked');
            check(file_get_contents($live.'/backups/previous.zip') === 'large excluded backup', 'Preserved folders did not return during verified operator recovery');
        }
    }
}
// Preflight rejects missing/stale runtime evidence, target drift, conflicts and unavailable backup capacity.
$scenario = 'success'; Auth::guard()->setUser($adminUser);
foreach (['unknown_java', 'wrong_java', 'missing_runtime', 'stage_tampered', 'quota', 'conflict', 'world_read_access', 'launch_settings'] as $case) {
    $server = $make('blocked '.$case); $uuid = $server->pelican_server_uuid; $fixture = $fixtures[$uuid];
    if ($case === 'unknown_java') { file_put_contents($fixture['live'].'/logs/latest.log', 'No Java startup evidence'); }
    if ($case === 'wrong_java') { file_put_contents($fixture['live'].'/logs/latest.log', str_replace('java version 17', 'java version 21', $runtimeLog)); }
    if ($case === 'missing_runtime') { unlink($fixture['live'].'/libraries/fixture/runtime.jar'); }
    if ($case === 'stage_tampered') { file_put_contents($fixture['files'].'/kubejs/server_scripts/recipes.js', 'tampered'); }
    if ($case === 'quota') { DB::table('servers')->where('uuid', $uuid)->update(['backup_limit' => 0]); }
    if ($case === 'conflict') { file_put_contents($fixture['live'].'/kubejs/server_scripts/recipes.js', 'custom recipe'); }
    if ($case === 'world_read_access') { chmod($fixture['live'].'/world/level.dat', 0000); }
    if ($case === 'launch_settings') { DB::table('servers')->where('uuid', $uuid)->update(['image' => 'fixture:java_21', 'updated_at' => now()]); }
    $plan = app(PreviewService::class)->generate($server);
    check(! $plan['applicable'], 'Unsafe preflight passed: '.$case);
    rejects(fn () => app(DeploymentApproval::class)->queue($server, $plan['plan_sha256'], (string) \Illuminate\Support\Str::uuid()), 'Blocked plan was approved: '.$case);
    check($states[$uuid] === 'running' && $server->refresh()->active_operation_id === null, 'Blocked preflight stopped/modified the server: '.$case);
    if ($case === 'world_read_access') { chmod($fixture['live'].'/world/level.dat', 0600); }
}

// Live drift after approval cancels before power changes; the old plan cannot be approved again.
$server = $make('drift after approval'); $uuid = $server->pelican_server_uuid;
$plan = app(PreviewService::class)->generate($server);
rejects(fn () => app(DeploymentApproval::class)->queue($server, str_repeat('0', 64), (string) \Illuminate\Support\Str::uuid()), 'Stale approval dialog accepted a different plan');
$operation = app(DeploymentApproval::class)->queue($server, $plan['plan_sha256'], (string) \Illuminate\Support\Str::uuid());
file_put_contents($fixtures[$uuid]['live'].'/kubejs/server_scripts/recipes.js', 'changed after approval');
Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
check($operation->refresh()->phase === 'cancelled' && $states[$uuid] === 'running' && ! $server->refresh()->approved_plan_sha256 && ! $server->last_preview_at, 'Post-approval drift was deployed or old approval retained');
check(! collect($requests)->contains(fn ($row) => $row[0] === $uuid && $row[1] === 'POST'), 'Cancelled preflight changed power or requested a backup');
rejects(fn () => app(DeploymentApproval::class)->queue($server, $plan['plan_sha256'], (string) \Illuminate\Support\Str::uuid()), 'Guest approved a deployment');
rejects(fn () => app()->call([new DeployManagedServer($server->id, true), 'handle']), 'Automatic deployment accepted');

// A genuine recipe conflict can be kept local through the native decision action and then deployed manually.
Auth::guard()->setUser($adminUser); $server = $make('keep custom recipe'); $uuid = $server->pelican_server_uuid;
file_put_contents($fixtures[$uuid]['live'].'/kubejs/server_scripts/recipes.js', 'custom recipe kept');
$plan = app(PreviewService::class)->generate($server); check($plan['blocked'] === 1, 'Custom recipe did not require a decision');
$view = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $server->id])->call('selectTab', 'changes')->call('resolve', 'kubejs/server_scripts/recipes.js', 'protect');
Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
Auth::guard()->setUser($adminUser); $view->call('selectTab', 'updates')->call('mountAction', 'applyUpdate')->set('mountedActions.0.data.confirm', true)->call('callMountedAction');
Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
check($server->refresh()->installed_version === '0.13.17' && file_get_contents($fixtures[$uuid]['live'].'/kubejs/server_scripts/recipes.js') === 'custom recipe kept', 'Conflict resolution was ignored during deployment');
check(config('arcturus-modpack-manager.automatic_deploy') === false && ! $server->deploy_enabled, 'Workflow relied on an automatic/deploy boolean toggle');

echo "Manual Filament approval, real database worker, offline locked native backups, full restore callbacks, world/custom preservation and failed/corrupt recovery passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
