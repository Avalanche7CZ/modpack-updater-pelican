<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';

use App\Models\Server;
use App\Models\Node;
use App\Models\Backup;
use App\Http\Controllers\Api\Remote\Backups\BackupStatusController;
use App\Http\Requests\Api\Remote\ReportBackupCompleteRequest;
use App\Repositories\Daemon\DaemonServerRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Native\Preparation;
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Services\DeploymentApproval;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

Schema::table('servers', fn (Blueprint $t) => $t->boolean('skip_scripts')->default(false));
$egg->docker_images = ['Java 21' => 'fixture:java_21', 'Java 25' => 'fixture:java_25'];
$systems = []; $snapshots = []; $requests = []; $mode = 'success'; $stages = [];
$hash = fn ($value) => hash('sha256', $value);
$bootLog = fn ($mc, $version, $java) => "ModLauncher running: args [--fml.neoForgeVersion, $version, --fml.mcVersion, $mc]\nModLauncher 10.0.9 starting: java version $java.0.1\n[Server thread/INFO]: Done (0.1s)!\n";
$make = function (bool $migration, bool $installer = false) use (&$systems, &$stages, $bootLog, $hash): ManagedServer {
    $uuid = (string) Str::uuid(); $id = bin2hex(random_bytes(16));
    $args = fn ($mc, $v) => "-p libraries/fixture/runtime.jar --fml.neoForgeVersion $v --fml.mcVersion $mc";
    $oldArgs = 'libraries/net/neoforged/neoforge/21.1.1/unix_args.txt';
    $newArgs = 'libraries/net/neoforged/neoforge/26.1.2.121/unix_args.txt';
    $base = ['config/options.cfg' => 'old', 'kubejs/server_scripts/upstream.js' => 'old script', $oldArgs => $args('1.21.1', '21.1.1'), 'libraries/fixture/runtime.jar' => 'verified runtime dependency'];
    $target = [...$base, 'config/options.cfg' => 'new', 'kubejs/server_scripts/upstream.js' => 'new script', 'config/added.cfg' => 'new added file'];
    if ($migration) { unset($target[$oldArgs]); if (! $installer) { $target[$newArgs] = $args('26.1.2', '26.1.2.121'); $target['libraries/net/neoforged/neoforge/26.1.2.121/neoforge-26.1.2.121-server.jar'] = 'published patched server fixture'; } }
    $runtime = ['minecraft' => $migration ? '26.1.2' : '1.21.1', 'loader' => 'neoforge', 'loader_version' => $migration ? '26.1.2.121' : '21.1.1'];
    $manifest = fn ($version, $files, $runtime) => ['schema' => 2, 'provider' => 'custom', 'project' => 'native-fixture', 'version' => $version, 'verification' => 'upstream-checksums',
        'files' => array_map($hash, $files), 'runtime' => $runtime, 'artifacts' => [['sha256' => $hash($version), 'hashes' => ['sha256' => $hash($version)]]]];
    $baseManifest = $manifest('1', $base, ['minecraft' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.1']);
    $targetManifest = $manifest('2', $target, $runtime);
    $startup = $migration ? 'java -Xms128M @'.$oldArgs.' nogui' : 'java -Xms128M @unix_args.txt nogui';
    DB::table('servers')->insert(['uuid' => $uuid, 'name' => 'Native fixture', 'node_id' => 1, 'owner_id' => 1, 'startup' => $startup, 'image' => 'fixture:java_21', 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
    $managed = ManagedServer::create(['label' => 'Native fixture', 'pelican_server_uuid' => $uuid, 'provider' => 'custom', 'source' => 'native-fixture', 'transport' => 'native',
        'installed_version' => '1', 'selected_version' => '2', 'baseline_status' => 'verified', 'base_manifest_path' => app(ReleaseStore::class)->put($baseManifest),
        'target_manifest_path' => app(ReleaseStore::class)->put($targetManifest), 'baseline_provenance' => ['transport' => 'local'],
        'runtime_report' => ['world_path_verified' => true, 'protected_roots' => ['world']], 'adopted_at' => now(), 'enabled' => true, 'backup_mode' => 'pelican',
        'backup_excluded_directories' => ['backups'], 'file_rules' => [...(new ProtectionPolicy)->template('tfg_arcturus'), ...($migration ? [['scope' => 'exact', 'pattern' => $oldArgs, 'mode' => 'remove', 'priority' => 100, 'enabled' => true]] : [])]]);
    $prep = new Preparation($managed); $entry = $prep->configuration()[$uuid]; $opRoot = $entry['staging'].'/'.$id; mkdir($opRoot, 0700); mkdir($opRoot.'/files', 0700);
    foreach ($target as $path => $contents) { if (! is_dir(dirname($opRoot.'/files/'.$path))) { mkdir(dirname($opRoot.'/files/'.$path), 0700, true); } file_put_contents($opRoot.'/files/'.$path, $contents); }
    file_put_contents($opRoot.'/manifest.json', json_encode($targetManifest, JSON_THROW_ON_ERROR));
    $digest = hash_file('sha256', $opRoot.'/manifest.json'); file_put_contents($opRoot.'/operation.json', json_encode(['state' => 'completed', 'manifest_sha256' => $digest]));
    $managed->update(['target_metadata' => ['prepared' => ['transport' => 'native', 'node_operation_id' => $id, 'manifest_sha256' => $digest]], 'prepared_root' => 'native:'.$id]);
    $stages[] = dirname($entry['staging']);
    $systems[$uuid] = ['modified' => now()->toIso8601String(), 'state' => 'running', 'starts' => 0, 'modes' => [], 'dirs' => [], 'links' => $migration ? [] : ['unix_args.txt' => true], 'data' => [...$base, ...($migration ? [] : ['unix_args.txt' => $oldArgs]), 'server.properties' => "level-name=world\n",
        'mods/Paradigm.jar' => 'custom paradigm', 'mods/ArcturusTokens.jar' => 'custom tokens', 'mods/disabled.jar.disabled' => 'disabled mod',
        'kubejs/server_scripts/local.js' => 'custom script', 'world/level.dat' => 'world data', 'world/region/r.0.0.mca' => 'old region',
        'backups/previous.zip' => 'excluded archive', 'logs/latest.log' => $bootLog('1.21.1', '21.1.1', 21)],
        'configuration' => ['invocation' => $startup, 'container' => ['image' => 'fixture:java_21'], 'suspended' => false, 'crash_detection_enabled' => true]];
    return $managed;
};
Http::fake(function ($request) use (&$systems, &$snapshots, &$requests, &$mode, $bootLog) {
    $url = $request->url(); preg_match('~/api/servers/([a-f0-9-]+)~', $url, $m); $uuid = $m[1] ?? ''; check(isset($systems[$uuid]), 'Unexpected native fixture server');
    $sys = &$systems[$uuid]; $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    $requests[] = [$request->method(), $path, $q];
    if (str_ends_with($path, '/sync')) {
        $cfg = app(\App\Services\Servers\ServerConfigurationStructureService::class)->handle(Server::where('uuid', $uuid)->firstOrFail());
        $sys['configuration'] = array_replace(['crash_detection_enabled' => true], $cfg);
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/files/list-directory')) {
        $dir = trim($q['directory'] ?? '', '/'); $prefix = $dir === '' ? '' : $dir.'/'; $rows = [];
        foreach ([...array_keys($sys['data']), ...$sys['dirs']] as $file) {
            if (! str_starts_with($file, $prefix)) { continue; } $rest = substr($file, strlen($prefix)); if ($rest === '') { continue; }
            $name = explode('/', $rest)[0]; $full = $prefix.$name; $isFile = isset($sys['data'][$full]);
            $rows[$name] = ['name' => $name, 'file' => $isFile, 'directory' => ! $isFile, 'symlink' => isset($sys['links'][$full]), 'size' => $isFile ? strlen($sys['data'][$full]) : 0, 'mode_bits' => decoct($sys['modes'][$full] ?? 0644), 'modified' => $sys['modified']];
        }
        return Http::response(array_values($rows), 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) { $file = ltrim($q['file'], '/'); check(! str_starts_with($file, 'world/'), 'Plugin attempted to read world data'); return Http::response($sys['data'][$file], 200, $headers); }
    if (str_ends_with($path, '/files/create-directory')) { $sys['dirs'][] = ltrim($request['path'].'/'.$request['name'], '/'); return Http::response([], 204, $headers); }
    if (str_ends_with($path, '/files/write')) {
        check($sys['state'] === 'offline' && $sys['configuration']['suspended'] && ! $sys['configuration']['crash_detection_enabled'], 'Upload not guarded/offline');
        $file = ltrim($q['file'], '/'); $sys['data'][$file] = $request->body();
        if ($mode === 'interrupted') {
            $mode = 'success'; $managed = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
            (new DeployManagedServer($managed->id, false, $managed->approved_plan_sha256, $managed->active_operation_id))->failed(null);
        }
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/files/chmod')) { foreach ($request['files'] as $file) { $sys['modes'][$file['file']] = octdec($file['mode']); } return Http::response([], 204, $headers); }
    if (str_ends_with($path, '/files/rename')) {
        foreach ($request['files'] as $move) {
            if ($mode === 'partial_write' && $move['to'] === 'config/options.cfg') { $mode = 'success'; return Http::response(['error' => 'fixture write failure'], 500, $headers); }
            $sys['modes'][$move['to']] = $sys['modes'][$move['from']] ?? 0644; unset($sys['modes'][$move['from']]);
            $sys['data'][$move['to']] = $sys['data'][$move['from']]; unset($sys['data'][$move['from']]);
            if (isset($sys['links'][$move['from']])) { $sys['links'][$move['to']] = true; unset($sys['links'][$move['from']]); }
        }
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/files/delete')) {
        foreach ($request['files'] as $file) {
            check($file !== 'backups', 'Excluded backup directory was deleted');
            foreach (array_keys($sys['data']) as $p) { if ($p === $file || str_starts_with($p, $file.'/')) { unset($sys['data'][$p], $sys['links'][$p]); } }
            $sys['dirs'] = array_values(array_filter($sys['dirs'], fn ($p) => $p !== $file && ! str_starts_with($p, $file.'/')));
        }
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/power')) {
        if ($request['action'] === 'stop') { $sys['state'] = 'offline'; }
        else {
            $sys['starts']++; $fail = in_array($mode, ['failed_boot', 'failed_restore', 'installer_failed_boot', 'disable_failed_boot', 'root_args_failed_boot'], true) && $sys['starts'] === 1;
            $sys['state'] = $fail ? 'offline' : 'running';
            if ($fail) { $sys['data']['world/region/new-failed-boot.mca'] = 'must disappear on full recovery'; }
            else {
                $server = Server::where('uuid', $uuid)->firstOrFail(); $new = str_contains($server->startup, '/26.1.2.121/');
                $sys['data']['logs/latest.log'] = $bootLog($new ? '26.1.2' : '1.21.1', $new ? '26.1.2.121' : '21.1.1', str_contains($server->image, '25') ? 25 : 21);
            }
        }
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($path, '/backup')) {
        check($sys['state'] === 'offline' && $sys['configuration']['suspended'], 'Backup not offline/guarded');
        $backup = Backup::where('uuid', $request['uuid'])->firstOrFail();
        check($backup->is_locked && $backup->ignored_files === ['/backups/'], 'Backup identity/protection differs');
        $snapshots[$backup->uuid] = array_filter($sys['data'], fn ($p) => ! str_starts_with($p, 'backups/') && ! isset($sys['links'][$p]), ARRAY_FILTER_USE_KEY);
        $callback = ReportBackupCompleteRequest::create('/fixture/backup', 'POST', ['successful' => true, 'checksum_type' => 'sha256', 'checksum' => hash('sha256', json_encode($snapshots[$backup->uuid])), 'size' => strlen(json_encode($snapshots[$backup->uuid]))]);
        $callback->attributes->set('node', Node::findOrFail(1)); app(BackupStatusController::class)->index($callback, $backup->uuid);
        // Other Panel starts must be blocked even if they bypass the UI button.
        rejects(fn () => app(DaemonServerRepository::class)->setServer(Server::where('uuid', $uuid)->firstOrFail())->power('restart'), 'Concurrent Panel restart bypassed the operation');
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($path, '/restore')) {
        check($request['truncate_directory'] === false && $sys['state'] === 'offline', 'Native restore truncated exclusions or ran online');
        check(str_contains($sys['configuration']['invocation'], 'recovery verification pending'), 'Restore lacked an inert recovery startup barrier');
        check(! array_filter(array_keys($sys['data']), fn ($p) => $p !== 'backups/previous.zip' && ! str_starts_with($p, '.arcturus-recovery-')), 'Root was not completely cleared before nontruncating restore');
        preg_match('~/backup/([a-f0-9-]+)/restore$~', $path, $b); $backupUuid = $b[1];
        $sys['data'] = [...$sys['data'], ...$snapshots[$backupUuid]];
        $sys['configuration']['suspended'] = false; // Actual Wings restore behavior.
        $callback = Request::create('/fixture/restore', 'POST', ['successful' => $mode !== 'failed_restore']);
        $callback->attributes->set('node', Node::findOrFail(1)); app(BackupStatusController::class)->restore($callback, $backupUuid);
        return Http::response([], 202, $headers);
    }
    return Http::response(['state' => $sys['state'], 'configuration' => $sys['configuration'], 'utilization' => ['uptime' => 60000]], 200, $headers);
});
try {
    foreach ([['file-only', false, 'success'], ['published NeoForge migration', true, 'success'], ['partial file write', false, 'partial_write'], ['runtime/image rollback', true, 'failed_boot'], ['failed callback recovery', false, 'failed_restore'], ['interrupted worker recovery', true, 'interrupted'], ['installer-produced runtime', true, 'installer_success'], ['installer runtime rollback', true, 'installer_failed_boot'], ['disabled mod', false, 'disable_success'], ['disabled mod rollback', false, 'disable_failed_boot'], ['disabled destination collision', false, 'disable_collision'], ['missing implicit runtime dependency', true, 'missing_core'], ['root args migration', true, 'root_args_success'], ['root args migration rollback', true, 'root_args_failed_boot']] as [$label, $migration, $scenario]) {
        $mode = $scenario; Auth::guard()->setUser($adminUser); $managed = $make($migration, str_starts_with($scenario, 'installer_')); $uuid = $managed->pelican_server_uuid; $originalStartup = $managed->pelicanServer->startup;
        if (str_starts_with($scenario, 'disable_')) {
            $managed->update(['file_rules' => [...$managed->file_rules, ['scope' => 'exact', 'pattern' => 'mods/local-disable.jar', 'mode' => 'disable', 'priority' => 100, 'enabled' => true]]]);
            $systems[$uuid]['data']['mods/local-disable.jar'] = 'local mod bytes';
        }
        if ($scenario === 'disable_collision') { $systems[$uuid]['data']['mods/local-disable.jar.disabled'] = 'existing disabled mod'; }
        if (str_starts_with($scenario, 'root_args_')) {
            DB::table('servers')->where('uuid', $uuid)->update(['startup' => 'java -Xms128M @unix_args.txt nogui', 'updated_at' => now()->subMinutes(2)]);
            $originalStartup = $managed->unsetRelation('pelicanServer')->pelicanServer->startup;
            $systems[$uuid]['configuration']['invocation'] = $originalStartup;
            $systems[$uuid]['data']['unix_args.txt'] = 'libraries/net/neoforged/neoforge/21.1.1/unix_args.txt';
            $systems[$uuid]['links']['unix_args.txt'] = true;
        }
        $originalData = $systems[$uuid]['data'];
        check((new Preparation($managed))->request('capabilities')['backend'] === 'native', 'Native preparation still requires host provisioning');

        if (str_starts_with($scenario, 'installer_')) {
            $input = tempnam(sys_get_temp_dir(), 'arcturus-installer-input-');
            $argsText = '-p libraries/fixture/runtime.jar --fml.neoForgeVersion 26.1.2.121 --fml.mcVersion 26.1.2';
            $jar = new ZipArchive; $jar->open($input, ZipArchive::OVERWRITE);
            $jar->addFromString('install_profile.json', json_encode(['spec' => 1, 'minecraft' => '26.1.2', 'data' => ['PATCHED' => ['server' => '[net.neoforged:neoforge:26.1.2.121:server]']]]));
            $jar->addFromString('version.json', json_encode(['inheritsFrom' => '26.1.2', 'arguments' => ['game' => ['--fml.neoForgeVersion', '26.1.2.121']]]));
            $jar->addFromString('data/unix_args.txt', $argsText); $jar->close();
            $receipt = ['path' => $input, 'sha256' => hash_file('sha256', $input), 'hashes' => ['sha256' => hash_file('sha256', $input)]];
            rejects(fn () => (new \Arcturus\ModpackManager\Runtime\InstallerProfile)->inspect([...$receipt, 'sha256' => str_repeat('0', 64)], ['minecraft' => '26.1.2', 'loader' => 'neoforge', 'loader_version' => '26.1.2.121']), 'Changed installer was trusted');
            $app->instance(\Arcturus\ModpackManager\Runtime\InstallerArtifact::class, new class($receipt) extends \Arcturus\ModpackManager\Runtime\InstallerArtifact {
                public function __construct(private array $receipt) {}
                public function obtain(array $target, string $cache): array {
                    if (! is_dir($cache)) { mkdir($cache, 0700); }
                    return [...$this->receipt, 'url' => $this->url($target)];
                }
            });
            $runner = new class extends \Arcturus\ModpackManager\Runtime\InstallerSandbox {
                public int $runs = 0;
                public function run(array $artifact, string $work, int $minimum, ?\Closure $heartbeat = null): int {
                    $this->runs++; $zip = new ZipArchive; $zip->open($artifact['path']); $args = $zip->getFromName('data/unix_args.txt'); $zip->close();
                    $root = $work.'/libraries/net/neoforged/neoforge/26.1.2.121'; mkdir($root, 0700, true);
                    file_put_contents($root.'/unix_args.txt', $args);
                    mkdir($work.'/libraries/fixture', 0700); file_put_contents($work.'/libraries/fixture/runtime.jar', 'verified runtime dependency');
                    $jar = new ZipArchive; $jar->open($root.'/neoforge-26.1.2.121-server.jar', ZipArchive::CREATE); $jar->addFromString('fixture.class', 'generated server fixture'); $jar->close();
                    $heartbeat?->__invoke(); return 25;
                }
            };
            $app->instance(\Arcturus\ModpackManager\Runtime\InstallerSandbox::class, $runner);
            $builder = app(\Arcturus\ModpackManager\Runtime\RuntimeBuild::class);
            $sourceManifest = app(ReleaseStore::class)->load($managed->target_manifest_path); $prepared = $managed->target_metadata['prepared'];
            $built = $builder->prepare($managed, $sourceManifest, $prepared['node_operation_id'], $prepared['manifest_sha256']);
            $again = $builder->prepare($managed, $sourceManifest, $prepared['node_operation_id'], $prepared['manifest_sha256']);
            check($runner->runs === 1 && $again['node_id'] === $built['node_id'], 'Verified runtime cache was rebuilt');
            check(isset($built['manifest']['files']['libraries/net/neoforged/neoforge/26.1.2.121/neoforge-26.1.2.121-server.jar']), 'Generated core is absent from the exact target manifest');
            $managed->update(['target_manifest_path' => app(ReleaseStore::class)->put($built['manifest']), 'prepared_root' => 'native:'.$built['node_id'],
                'target_metadata' => ['prepared' => [...$prepared, 'node_operation_id' => $built['node_id'], 'manifest_sha256' => $built['manifest_sha256']]]]);
            unlink($input);
        }
        if ($scenario === 'missing_core') {
            $incomplete = app(ReleaseStore::class)->load($managed->target_manifest_path);
            unset($incomplete['files']['libraries/net/neoforged/neoforge/26.1.2.121/neoforge-26.1.2.121-server.jar']);
            $managed->update(['target_manifest_path' => app(ReleaseStore::class)->put($incomplete)]);
        }
        $preview = app(PreviewService::class)->generate($managed);
        if ($scenario === 'missing_core') {
            check(! $preview['applicable'] && str_contains(implode('; ', $preview['capability_blockers']), 'neoforge-26.1.2.121-server.jar'), 'Incomplete runtime with an implicit missing core was approved');
            check($systems[$uuid]['data'] === $originalData && ! isset($snapshots[$uuid]), 'Incomplete runtime changed live files or created a backup');
            echo $label." native qualification passed\n"; continue;
        }
        if ($scenario === 'disable_collision') {
            check(! $preview['applicable'] && str_contains(implode('; ', $preview['capability_blockers']), 'Disabled mod destination already exists'), 'Occupied disabled destination did not block qualification');
            check($systems[$uuid]['data'] === $originalData && ! isset($snapshots[$uuid]), 'Collision changed files or created an update backup');
            echo $label." native qualification passed\n"; continue;
        }
        check($preview['applicable'], $label.' blocked: '.implode('; ', $preview['capability_blockers']).' '.json_encode(array_values(array_filter($preview['changes'], fn ($c) => $c['blocked']))));
        check($managed->local_root === null && $managed->staging_parent === null, 'Native deployment inherited host volume access');
        $operation = app(DeploymentApproval::class)->queue($managed, $preview['plan_sha256'], (string) Str::uuid());
        Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
        $operation->refresh(); $managed->refresh();
        if (in_array($scenario, ['failed_restore', 'interrupted'], true)) {
            check($operation->phase === 'manual_intervention' && $systems[$uuid]['state'] === 'offline' && $managed->active_operation_id === $operation->id,
                'Interrupted/unverified operation was released: '.$operation->error);
            $mode = 'success'; Auth::guard()->setUser($adminUser);
            app(DeploymentApproval::class)->verifyRecovery($managed);
            Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
            $operation->refresh(); $managed->refresh();
        }
        if (in_array($scenario, ['success', 'installer_success', 'disable_success', 'root_args_success'], true)) {
            check($operation->phase === 'completed' && $managed->installed_version === '2', $label.' did not complete: '.$operation->error);
            if ($migration) { check(str_contains($managed->pelicanServer->startup, '/26.1.2.121/') && $managed->pelicanServer->image === 'fixture:java_25', 'Runtime startup/image migration was not committed'); }
        } else {
            check($operation->phase === 'restored' && $managed->installed_version === '1' && $managed->active_operation_id === null, $label.' recovery failed: '.$operation->error);
            check($managed->pelicanServer->startup === $originalStartup && $managed->pelicanServer->image === 'fixture:java_21', 'Original runtime settings were not restored');
            check($systems[$uuid]['data']['config/options.cfg'] === 'old' && ! isset($systems[$uuid]['data']['config/added.cfg']) && ! isset($systems[$uuid]['data']['world/region/new-failed-boot.mca']), 'Full recovery left new config/world files');
        }
        if (str_starts_with($scenario, 'disable_')) {
            $active = $scenario === 'disable_success' ? 'mods/local-disable.jar.disabled' : 'mods/local-disable.jar';
            $absent = $scenario === 'disable_success' ? 'mods/local-disable.jar' : 'mods/local-disable.jar.disabled';
            check(($systems[$uuid]['data'][$active] ?? null) === 'local mod bytes' && ! isset($systems[$uuid]['data'][$absent]), 'Disabled-mod success/rollback did not preserve exact bytes and absence');
        }
        foreach (['mods/Paradigm.jar', 'mods/ArcturusTokens.jar', 'mods/disabled.jar.disabled', 'kubejs/server_scripts/local.js', 'world/level.dat', 'backups/previous.zip'] as $path) { check($systems[$uuid]['data'][$path] === $originalData[$path], 'Custom/excluded content changed: '.$path); }
        if (! $migration || str_starts_with($scenario, 'root_args_')) { check(isset($systems[$uuid]['links']['unix_args.txt']) && $systems[$uuid]['data']['unix_args.txt'] === $originalData['unix_args.txt'], 'Existing root launcher symlink was not preserved'); }
        $count = count($requests); app()->call([new DeployManagedServer($managed->id, false, $preview['plan_sha256'], $operation->id), 'handle']);
        check(count($requests) === $count, 'Duplicate delivery replayed a native transaction');
        echo $label." native transaction passed\n";
    }

    $mode = 'success'; Auth::guard()->forgetUser();
    // Native Schedules bridge: real task handler, no-update restart, and prepared update.
    \App\Models\User::retrieved(function ($user): void {
        $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([(new \App\Models\Role)->forceFill(['name' => \App\Models\Role::ROOT_ADMIN])]));
    });
Schema::create('schedules', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('server_id'); $t->string('name'); $t->boolean('is_active')->default(true);
    $t->boolean('is_processing')->default(true); $t->boolean('only_when_online')->default(true);
    $t->string('cron_minute')->default('0'); $t->string('cron_hour')->default('7'); $t->string('cron_day_of_month')->default('*');
    $t->string('cron_day_of_week')->default('*'); $t->string('cron_month')->default('*');
    $t->timestamp('last_run_at')->nullable(); $t->timestamp('next_run_at')->nullable(); $t->timestamps();
});
Schema::create('tasks', function (Blueprint $t) {
    $t->id(); $t->unsignedBigInteger('schedule_id'); $t->unsignedInteger('sequence_id'); $t->string('action');
    $t->text('payload'); $t->unsignedInteger('time_offset')->default(0); $t->boolean('is_queued')->default(false);
    $t->boolean('continue_on_failure')->default(false); $t->timestamps();
});

    $restart = $make(false); $restartUuid = $restart->pelican_server_uuid;
    $restart->update(['source' => 'https://recipes.fixture.test/current', 'selected_version' => null, 'available_version' => '1',
        'policy' => 'auto', 'deploy_enabled' => true, 'automation_actor_id' => 1]);
    $app->instance(\Arcturus\ModpackManager\Modpacks\JsonClient::class, new class extends \Arcturus\ModpackManager\Modpacks\JsonClient {
        public function get(string $url, array $headers = []): array {
            return ['schema' => 2, 'version' => '1', 'downloads' => [['artifact_id' => 'fixture', 'path' => 'config/options.cfg', 'hashes' => ['sha256' => str_repeat('a', 64)]]]];
        }
    });
    $scheduleId = DB::table('schedules')->insertGetId(['server_id' => $restart->pelicanServer->id, 'name' => 'Native Wings restart']);
    $taskId = DB::table('tasks')->insertGetId(['schedule_id' => $scheduleId, 'sequence_id' => 1,
        'action' => \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID, 'payload' => 'update_restart', 'is_queued' => true]);
    $nextId = DB::table('tasks')->insertGetId(['schedule_id' => $scheduleId, 'sequence_id' => 2, 'action' => 'command', 'payload' => 'say completed']);
    $from = count($requests);
    \Illuminate\Support\Facades\Bus::dispatchNow(new \App\Jobs\Schedule\RunTaskJob(\App\Models\Task::findOrFail($taskId), true));
    $run = \Arcturus\ModpackManager\Models\NativeScheduleRun::latest('created_at')->firstOrFail();
    app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($run->id);
    check($systems[$restartUuid]['starts'] === 1 && ! $restart->refresh()->active_operation_id, 'Native no-update schedule restart failed: '.json_encode($run->fresh()->toArray()).' '.$restart->fresh()->last_error);
    check(! array_filter(array_slice($requests, $from), fn ($r) => str_ends_with($r[1], '/backup')), 'No-update restart created an unnecessary backup');
    check(! \App\Models\Task::find($nextId)->is_queued, 'Following task advanced before observed restart completion');
    app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($run->id);
    check($run->refresh()->state === 'completed' && \App\Models\Task::find($nextId)->is_queued, 'Native restart did not release following task');
    DB::table('jobs')->delete(); // Fixture-only terminal observers and the now-queued following command.
    $update = $make(false); $update->update(['policy' => 'auto', 'deploy_enabled' => true, 'automation_actor_id' => 1]);
    app(PreviewService::class)->generate($update);
    $scheduleId = DB::table('schedules')->insertGetId(['server_id' => $update->pelicanServer->id, 'name' => 'Native Wings update']);
    $taskId = DB::table('tasks')->insertGetId(['schedule_id' => $scheduleId, 'sequence_id' => 1,
        'action' => \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID, 'payload' => 'update', 'is_queued' => true]);
    $nextId = DB::table('tasks')->insertGetId(['schedule_id' => $scheduleId, 'sequence_id' => 2, 'action' => 'command', 'payload' => 'say updated']);
    $runId = (string) Str::uuid();
    $prepared = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($update, 'prepare', ['automatic' => true,
        'native_schedule_run_id' => $runId, 'policy_revision' => $update->policy_revision, 'actor_id' => 1], $runId);
    app(\Arcturus\ModpackManager\Services\OperationTracker::class)->transition($prepared, 'completed');
    $run = \Arcturus\ModpackManager\Models\NativeScheduleRun::create(['id' => $runId, 'dispatch_key' => 'fixture:native-wings-update', 'task_id' => $taskId,
        'managed_server_id' => $update->id, 'task_snapshot' => ['schedule_id' => $scheduleId, 'sequence_id' => 1, 'payload' => 'update', 'server_uuid' => $update->pelican_server_uuid],
        'manual_run' => true, 'policy_revision' => $update->policy_revision, 'actor_id' => 1, 'state' => 'waiting', 'operation_id' => $prepared->id]);
    app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($runId);
    check(! \App\Models\Task::find($nextId)->is_queued && $run->refresh()->operation_id !== $prepared->id, 'Schedule skipped native deployment');
    app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
    check($update->refresh()->installed_version === '2', 'Scheduled native update did not install the target');
    app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($runId);
    check($run->refresh()->state === 'completed' && \App\Models\Task::find($nextId)->is_queued, 'Scheduled native update did not release following task');
    $count = count($requests); app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($runId);
    check(count($requests) === $count, 'Completed schedule replayed native deployment');
    echo "Native Wings schedules: no-update restart without backup, prepared automatic deployment, sequencing and duplicate receipt passed\n";
} finally {
    foreach ($stages as $stage) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($stage);
    }
}
echo "Native deployment receipts, backups/callbacks, boot verification and recovery passed using real rc.2 services and HTTP fixtures; no live Wings/Docker/Minecraft exercised\n";
$GLOBALS['arcturus_integration_passed'] = true;
