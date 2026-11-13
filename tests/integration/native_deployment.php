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
$crucibleBootLog = fn () => "Forge Mod Loader version 7.99.40.1614 for Minecraft 1.7.10 loading\nJava is OpenJDK, version 25.0.1\nMinecraftForge v10.13.4.1614 Initialized\nCrucible DummyMod injected successfully!\n[Server thread/INFO]: Done (0.1s)!\n";
$crucibleJar = function (string $forge = '10.13.4.1614'): string {
    $file = tempnam(sys_get_temp_dir(), 'arcturus-crucible-fixture-');
    try {
        $zip = new ZipArchive;
        check($zip->open($file, ZipArchive::OVERWRITE) === true, 'Cannot create Crucible fixture');
        $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\nImplementation-Title: Crucible\r\nForge-Version: $forge\r\nMain-Class: cpw.mods.fml.relauncher.ServerLaunchWrapper\r\n\r\n");
        $zip->close();
        return file_get_contents($file);
    } finally { unlink($file); }
};
$targetConsole = "\033[31mTarget launcher failed <error>before creating latest.log</error>\033[0m\r";
$almanac = fn ($v) => 'mods/almanac-forge-1.20.1-'.$v.'.jar';
$letMeDespawn = fn ($v) => 'mods/letmedespawn-forge-1.20.1-'.$v.'.jar';
$fixtureModJar = function (string $mod, string $version): string {
    $path = tempnam(sys_get_temp_dir(), 'arcturus-fixture-');
    try {
        $zip = new ZipArchive;
        check($zip->open($path, ZipArchive::OVERWRITE) === true, 'Cannot make JAR fixture');
        $zip->addFromString('META-INF/mods.toml', "modLoader = \"javafml\"\n[[mods]]\nmodId = \"$mod\"\nversion = \"$version\"\n");
        $zip->close();
        return file_get_contents($path);
    } finally { unlink($path); }
};
$make = function (bool $migration, bool $installer = false, bool $drift = false, bool $crucible = false) use (&$systems, &$stages, $bootLog, $crucibleBootLog, $crucibleJar, $hash, $fixtureModJar, $almanac, $letMeDespawn): ManagedServer {
    $uuid = (string) Str::uuid(); $id = bin2hex(random_bytes(16));
    $args = fn ($mc, $v) => "-p libraries/fixture/runtime.jar --fml.neoForgeVersion $v --fml.mcVersion $mc";
    $oldArgs = 'libraries/net/neoforged/neoforge/21.1.1/unix_args.txt';
    $newArgs = 'libraries/net/neoforged/neoforge/26.1.2.121/unix_args.txt';
    $base = ['config/options.cfg' => 'old', 'kubejs/server_scripts/upstream.js' => 'old script', $oldArgs => $args('1.21.1', '21.1.1'), 'libraries/fixture/runtime.jar' => 'verified runtime dependency'];
    $target = [...$base, 'config/options.cfg' => 'new', 'kubejs/server_scripts/upstream.js' => 'new script', 'config/added.cfg' => 'new added file'];
    if ($drift) {
        $base[$almanac('1.26.10.3')] = $fixtureModJar('almanac', '1.26.10.3');
        $target[$almanac('1.26.11.1')] = $fixtureModJar('almanac', '1.26.11.1');
        $base[$letMeDespawn('1.26.10.1')] = $fixtureModJar('letmedespawn', '1.26.10.1');
        $target[$letMeDespawn('1.26.11.1')] = $fixtureModJar('letmedespawn', '1.26.11.1');
    }
    if ($migration) { unset($target[$oldArgs]); if (! $installer) { $target[$newArgs] = $args('26.1.2', '26.1.2.121'); $target['libraries/net/neoforged/neoforge/26.1.2.121/neoforge-26.1.2.121-server.jar'] = 'published patched server fixture'; } }
    $runtime = ['minecraft' => $migration ? '26.1.2' : '1.21.1', 'loader' => 'neoforge', 'loader_version' => $migration ? '26.1.2.121' : '21.1.1'];
    $baselineRuntime = ['minecraft' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.1'];
    if ($crucible) {
        $base = ['config/options.cfg' => 'old', 'java9args.txt' => '-Dfixture=upstream', 'lwjgl3ify-forgePatches.jar' => 'old upstream launcher',
            'forge-1.7.10-10.13.4.1614-1.7.10-universal.jar' => 'shared upstream forge'];
        $target = [...$base, 'config/options.cfg' => 'new', 'config/added.cfg' => 'new added file',
            'java9args.txt' => '-Dfixture=target', 'lwjgl3ify-forgePatches.jar' => 'new upstream launcher'];
        $baselineRuntime = ['minecraft' => '1.7.10', 'java' => 17, 'java_max' => 25];
        $runtime = [...$baselineRuntime, 'java_max' => 26];
    }
    $manifest = fn ($version, $files, $runtime) => ['schema' => 2, 'provider' => $crucible ? 'gtnh' : 'custom', 'project' => $crucible ? 'gtnh' : 'native-fixture', 'version' => $version, 'verification' => 'upstream-checksums',
        'files' => array_map($hash, $files), 'runtime' => $runtime, 'artifacts' => [['sha256' => $hash($version), 'hashes' => ['sha256' => $hash($version)]]]];
    $baseManifest = $manifest('1', $base, $baselineRuntime);
    $targetManifest = $manifest('2', $target, $runtime);
    if ($drift) {
        foreach ([&$baseManifest, &$targetManifest] as &$item) {
            $old = $item['version'] === '1';
            foreach ([['almanac', $almanac, '1.26.10.3'], ['letmedespawn', $letMeDespawn, '1.26.10.1']] as [$mod, $path, $original]) {
                $v = $old ? $original : '1.26.11.1'; $p = $path($v);
                $item['identities'][$p] = $mod;
                $item['metadata'][$p] = ['identity' => $mod, 'ids' => [$mod], 'version' => $v,
                    'source' => 'META-INF/mods.toml', 'loader' => 'forge', 'sha256' => $item['files'][$p]];
                if ($old && $mod === 'almanac') { $item['metadata'][$p] = ['loader' => 'forge', 'version' => $v]; }
            }
        }
        unset($item);
    }
    $startup = $migration ? 'java -Xms128M @'.$oldArgs.' nogui' : 'java -Xms128M @unix_args.txt nogui';
    if ($crucible) { $startup = 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar @java9args.txt -jar Crucible-1.7.10-server.jar nogui'; }
    $image = $crucible ? 'fixture:java_25' : 'fixture:java_21';
    DB::table('servers')->insert(['uuid' => $uuid, 'name' => 'Native fixture', 'node_id' => 1, 'owner_id' => 1, 'startup' => $startup, 'image' => $image, 'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
    $managed = ManagedServer::create(['label' => 'Native fixture', 'pelican_server_uuid' => $uuid, 'provider' => $baseManifest['provider'], 'source' => $baseManifest['project'], 'transport' => 'native',
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
        'configuration' => ['invocation' => $startup, 'container' => ['image' => $image], 'suspended' => false, 'crash_detection_enabled' => true]];
    if ($drift) {
        unset($systems[$uuid]['data'][$almanac('1.26.10.3')]);
        $systems[$uuid]['data'][$almanac('1.26.10.2')] = $fixtureModJar('almanac', '1.26.10.2');
    }
    if ($crucible) {
        $systems[$uuid]['data']['Crucible-1.7.10-server.jar'] = $crucibleJar();
        $systems[$uuid]['data']['java9args.txt'] = "-Dcrucible.weAreJava9=true\n--add-opens\njava.base/java.io=ALL-UNNAMED\n";
        $systems[$uuid]['data']['logs/latest.log'] = $crucibleBootLog();
    }
    return $managed;
};
$wings = function ($request) use (&$systems, &$snapshots, &$requests, &$mode, $bootLog, $crucibleBootLog, $targetConsole) {
    $url = $request->url(); preg_match('~/api/servers/([a-f0-9-]+)~', $url, $m); $uuid = $m[1] ?? ''; check(isset($systems[$uuid]), 'Unexpected native fixture server');
    $sys = &$systems[$uuid]; $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    $requests[] = [$request->method(), $path, $q];
    if (str_ends_with($path, '/logs')) {
        check($request->method() === 'GET' && ($q['size'] ?? '') === '60', 'Console was not queried with a bounded read');
        if ($mode === 'diagnostic_unavailable' && $sys['starts'] > 0) { return Http::response(['error' => 'private remote failure body'], 500, $headers); }
        return Http::response(['data' => $sys['console'] ?? ['Original console before the update']], 200, $headers);
    }
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
    if (str_ends_with($path, '/files/contents')) {
        $file = ltrim($q['file'], '/'); check(! str_starts_with($file, 'world/'), 'Plugin attempted to read world data');
        $contents = $sys['data'][$file];
        if ($file === 'logs/latest.log' && isset($sys['pending_boot_log'])) {
            $sys['data'][$file] = $sys['pending_boot_log']; unset($sys['pending_boot_log']);
            $sys['boot_log_races'] = ($sys['boot_log_races'] ?? 0) + 1;
        }
        return Http::response($contents, 200, $headers);
    }
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
        if ($request['action'] === 'stop') {
            $sys['state'] = 'offline';
            if ($mode === 'crucible_drift') { $sys['modes']['java9args.txt'] = 0600; }
        }
        else {
            $sys['starts']++; $fail = in_array($mode, ['failed_boot', 'failed_restore', 'installer_failed_boot', 'disable_failed_boot', 'root_args_failed_boot', 'rollback_log_grows', 'diagnostic_unavailable', 'stale_console'], true) && $sys['starts'] === 1;
            $sys['state'] = $fail ? 'offline' : 'running';
            if ($fail) {
                $sys['data']['world/region/new-failed-boot.mca'] = 'must disappear on full recovery';
                if ($mode !== 'stale_console') { $sys['console'] = [$targetConsole]; }
            }
            else {
                $sys['console'] = ['Original or target boot continued normally'];
                $server = Server::where('uuid', $uuid)->firstOrFail(); $new = str_contains($server->startup, '/26.1.2.121/');
                $log = $bootLog($new ? '26.1.2' : '1.21.1', $new ? '26.1.2.121' : '21.1.1', str_contains($server->image, '25') ? 25 : 21);
                if (str_contains($server->startup, 'Crucible-1.7.10-server.jar')) {
                    $log = $crucibleBootLog();
                    if ($mode === 'crucible_wrong_boot' && $sys['starts'] === 1) { $log = str_replace('Crucible', 'stock Forge', $log); }
                }
                if (in_array($mode, ['target_log_grows', 'rollback_log_grows', 'recovery_log_grows'], true)) {
                    $sys['pending_boot_log'] = $log;
                    $log = substr($log, 0, strpos($log, '[Server thread/INFO]'));
                }
                $sys['data']['logs/latest.log'] = $log;
            }
        }
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($path, '/backup')) {
        check($sys['state'] === 'offline' && $sys['configuration']['suspended'], 'Backup not offline/guarded');
        $backup = Backup::where('uuid', $request['uuid'])->firstOrFail();
        check($backup->is_locked && $backup->ignored_files === ['/backups/'], 'Backup identity/protection differs');
        $snapshots[$backup->uuid] = array_filter($sys['data'], fn ($p) => ! str_starts_with($p, 'backups/') && ! isset($sys['links'][$p]), ARRAY_FILTER_USE_KEY);
        $backupBytes = serialize($snapshots[$backup->uuid]);
        $callback = ReportBackupCompleteRequest::create('/fixture/backup', 'POST', ['successful' => true, 'checksum_type' => 'sha256', 'checksum' => hash('sha256', $backupBytes), 'size' => strlen($backupBytes)]);
        $callback->attributes->set('node', Node::findOrFail(1)); app(BackupStatusController::class)->index($callback, $backup->uuid);
        // Other Panel starts must be blocked even if they bypass the UI button.
        rejects(fn () => app(DaemonServerRepository::class)->setServer(Server::where('uuid', $uuid)->firstOrFail())->power('restart'), 'Concurrent Panel restart bypassed the operation');
        return Http::response([], 202, $headers);
    }
    if (str_ends_with($path, '/restore')) {
        if (in_array($mode, ['failed_boot', 'diagnostic_unavailable', 'stale_console'], true)) {
            $managed = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
            $evidence = Operation::findOrFail($managed->active_operation_id)->context['native_target_boot_failure'] ?? [];
            $expected = $mode === 'failed_boot' ? ['source' => 'wings_console', 'text' => 'Target launcher failed <error>before creating latest.log</error>'] : ['source' => 'unavailable', 'text' => ''];
            check($evidence === $expected, 'Target failure evidence was missing, stale or unsafe at restore time');
        }
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
};
try {
    foreach ([
        ['file-only', false, 'success'], ['published NeoForge migration', true, 'success'], ['partial file write', false, 'partial_write'],
        ['runtime/image rollback', true, 'failed_boot'], ['target boot with growing log', true, 'target_log_grows'], ['rollback boot with growing log', true, 'rollback_log_grows'],
        ['rollback with console API failure', false, 'diagnostic_unavailable'], ['rollback with stale console', false, 'stale_console'],
        ['failed callback recovery', false, 'failed_restore'], ['interrupted worker recovery', true, 'interrupted'], ['installer-produced runtime', true, 'installer_success'],
        ['installer runtime rollback', true, 'installer_failed_boot'], ['disabled mod', false, 'disable_success'], ['disabled mod rollback', false, 'disable_failed_boot'],
        ['disabled destination collision', false, 'disable_collision'], ['missing implicit runtime dependency', true, 'missing_core'],
        ['TFG alternate Almanac JAR', false, 'drift_success'], ['duplicate Almanac IDs', false, 'drift_duplicate'],
        ['Almanac explicitly disabled', false, 'drift_disabled'], ['unverified Almanac candidate', false, 'drift_unverified'],
        ['Almanac filename lookalike', false, 'drift_lookalike'],
        ['GTNH retains Crucible', false, 'crucible_success'], ['GTNH rejects wrong boot and rolls back', false, 'crucible_wrong_boot'],
        ['GTNH blocks launcher permission drift', false, 'crucible_drift'],
        ['root args migration', true, 'root_args_success'], ['root args migration rollback', true, 'root_args_failed_boot'],
    ] as [$label, $migration, $scenario]) {
        // Keep only one scenario's HTTP recordings and stream responses in memory.
        Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
        $mode = $scenario; $crucible = str_starts_with($scenario, 'crucible_'); Auth::guard()->setUser($adminUser); $managed = $make($migration, str_starts_with($scenario, 'installer_'), str_starts_with($scenario, 'drift_'), $crucible); $uuid = $managed->pelican_server_uuid; $originalStartup = $managed->pelicanServer->startup;
        if ($scenario === 'drift_duplicate') { $systems[$uuid]['data'][$almanac('1.26.10.1')] = $fixtureModJar('almanac', '1.26.10.1'); }
        if ($scenario === 'drift_disabled') {
            $systems[$uuid]['data'][$almanac('1.26.10.2').'.disabled'] = $systems[$uuid]['data'][$almanac('1.26.10.2')];
            unset($systems[$uuid]['data'][$almanac('1.26.10.2')]);
        }
        if ($scenario === 'drift_unverified') { $systems[$uuid]['data'][$almanac('1.26.10.2')] = 'not a JAR'; }
        if ($scenario === 'drift_lookalike') { $systems[$uuid]['data'][$almanac('1.26.10.2')] = $fixtureModJar('unrelated', '1.26.10.2'); }
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

        if ($scenario === 'success' && ! $migration) {
            $from = count($requests);
            $inspection = (new Preparation($managed))->request('inventory', ['paths' => ['unix_args.txt', 'config/options.cfg']]);
            check($inspection['files'] === ['config/options.cfg' => $hash('old')] && $inspection['skipped_symlinks'] === ['unix_args.txt'] && in_array('unix_args.txt', $inspection['excluded_roots'], true), 'Native candidate link was not protected during comparison');
            check(! array_filter(array_slice($requests, $from), fn ($r) => str_ends_with($r[1], '/files/contents') && ($r[2]['file'] ?? '') === '/unix_args.txt'), 'Native inventory dereferenced a preserved launcher link');
        }

        if (in_array($scenario, ['success', 'failed_boot', 'partial_write'], true)) {
            // Real upstream TLS bytes and actual Native preparation precede this deployment.
            $tlsRoot = sys_get_temp_dir().'/arcturus-pipeline-'.bin2hex(random_bytes(8)); mkdir($tlsRoot, 0700);
            $prepared = $managed->target_metadata['prepared'];
            $fixtureStage = (new Preparation($managed))->stage($prepared['node_operation_id'], $prepared['manifest_sha256']);
            $targetRecipe = app(ReleaseStore::class)->load($managed->target_manifest_path);
            $zip = new ZipArchive; $zip->open($tlsRoot.'/input.zip', ZipArchive::CREATE);
            foreach ($targetRecipe['files'] as $path => $hashValue) { $zip->addFile($fixtureStage.'/'.$path, $path); } $zip->close();
            $tls = new \Symfony\Component\Process\Process(['/usr/bin/python3', '-B', __DIR__.'/fixtures/native_tls/server.py', $tlsRoot]); $tls->start();
            $envSaved = [];
            try {
                $deadline = microtime(true) + 10;
                while (! is_file($tlsRoot.'/routing.json') && microtime(true) < $deadline && $tls->isRunning()) { usleep(10000); }
                check(is_file($tlsRoot.'/routing.json'), 'Pipeline TLS fixture failed');
                foreach (['SSL_CERT_FILE' => $tlsRoot.'/cert.pem', 'PYTHONPATH' => __DIR__.'/fixtures/native_tls', 'ARCTURUS_TEST_TLS_ROUTING' => $tlsRoot.'/routing.json'] as $key => $value) {
                    $envSaved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null]; putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
                }
                $source = 'https://api.github.com/fixture/native-recipe';
                $baseRecipe = app(ReleaseStore::class)->load($managed->base_manifest_path); $baseRecipe['project'] = $source;
                $managed->update(['source' => $source, 'base_manifest_path' => app(ReleaseStore::class)->put($baseRecipe)]);
                $app->instance(\Arcturus\ModpackManager\Modpacks\JsonClient::class, new class($targetRecipe['runtime'], hash_file('sha256', $tlsRoot.'/input.zip')) extends \Arcturus\ModpackManager\Modpacks\JsonClient {
                    public function __construct(private array $runtime, private string $hash) {}
                    public function get(string $url, array $headers = []): array { return ['schema' => 2, 'version' => '2', 'format' => 'zip', 'runtime' => $this->runtime,
                        'downloads' => [['url' => 'https://github.com/redirect', 'archive' => true, 'hashes' => ['sha256' => $this->hash]]]]; }
                });
                $preparing = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($managed, 'prepare', ['provider' => 'custom', 'source' => $source, 'version' => '2']);
                \Arcturus\ModpackManager\Jobs\PrepareUpdate::dispatch($managed->id, false, $preparing->id);
                app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
                check($preparing->fresh()->phase === 'completed', 'Actual native pipeline failed before deployment: '.$preparing->fresh()->error);
                $managed->refresh();
                $requestsBeforePolicy = file_get_contents($tlsRoot.'/requests.json');
                $managed->update(['file_rules' => [...$managed->file_rules, ['scope' => 'exact', 'pattern' => 'config/local-only.cfg', 'mode' => 'protect', 'enabled' => true]], 'policy_revision' => $managed->policy_revision + 1]);
                app(PreviewService::class)->generate($managed);
                check(file_get_contents($tlsRoot.'/requests.json') === $requestsBeforePolicy, 'Policy-only comparison re-downloaded the verified server pack');
                check($managed->target_metadata['prepared']['node_operation_id'] !== $prepared['node_operation_id'], 'Deployment reused a fabricated target stage');
            } finally {
                foreach ($envSaved as $key => [$value, $env, $serverEnv]) { putenv($value === false ? $key : $key.'='.$value); unset($_ENV[$key], $_SERVER[$key]); if ($env !== null) { $_ENV[$key] = $env; } if ($serverEnv !== null) { $_SERVER[$key] = $serverEnv; } }
                $tls->stop(0); foreach (glob($tlsRoot.'/*') as $file) { unlink($file); } rmdir($tlsRoot);
            }
        }

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
        if ($crucible) {
            $optOut = app(PreviewService::class)->generate($managed);
            check(! $optOut['applicable'] && str_contains(implode(' ', $optOut['capability_blockers']), 'Keep existing Crucible runtime'), 'Crucible qualified without opt-in');
            $settings = app(\Arcturus\ModpackManager\Services\ServerPolicyService::class);
            $revision = $managed->policy_revision;
            $settings->save($managed, $managed->file_rules, [], $revision, ['keep_existing_crucible_runtime' => true]);
            check($managed->refresh()->policy_revision === $revision + 1, 'Crucible opt-in did not invalidate approval');
            $overwrites = app(PreviewService::class)->generate($managed);
            check(! $overwrites['applicable'] && str_contains(implode(' ', $overwrites['capability_blockers']), 'planned change to lwjgl3ify-forgePatches.jar'), 'A Crucible update could overwrite a local runtime file');
            $settings->save($managed, [...$managed->file_rules,
                ['scope' => 'exact', 'pattern' => 'java9args.txt', 'mode' => 'protect'],
                ['scope' => 'exact', 'pattern' => 'lwjgl3ify-forgePatches.jar', 'mode' => 'protect'],
            ], [], $managed->policy_revision);
            $managed->refresh();
            if ($scenario === 'crucible_success') {
                $systems[$uuid]['data']['logs/latest.log'] = str_replace('25.0.1', '27.0.1', $crucibleBootLog());
                $wrongJava = app(PreviewService::class)->generate($managed);
                check(! $wrongJava['applicable'] && str_contains(implode(' ', $wrongJava['capability_blockers']), 'Java range'), 'Out-of-range Java was accepted');
                $systems[$uuid]['data']['logs/latest.log'] = $originalData['logs/latest.log'];
                $systems[$uuid]['data']['Crucible-1.7.10-server.jar'] = $crucibleJar('10.13.4.1500');
                $incorrectJar = app(PreviewService::class)->generate($managed);
                check(! $incorrectJar['applicable'] && str_contains(implode(' ', $incorrectJar['capability_blockers']), 'active Crucible JAR does not prove'), 'An incompatible Crucible JAR was accepted');
                $systems[$uuid]['data']['Crucible-1.7.10-server.jar'] = $originalData['Crucible-1.7.10-server.jar'];
                $systems[$uuid]['data']['java9args.txt'] = '@nested.txt';
                $nested = app(PreviewService::class)->generate($managed);
                check(! $nested['applicable'] && str_contains(implode(' ', $nested['capability_blockers']), 'direct java command'), 'Nested Java argfile was accepted');
                $systems[$uuid]['data']['java9args.txt'] = $originalData['java9args.txt'];
            }
        }
        if ($scenario === 'missing_core') {
            $incomplete = app(ReleaseStore::class)->load($managed->target_manifest_path);
            unset($incomplete['files']['libraries/net/neoforged/neoforge/26.1.2.121/neoforge-26.1.2.121-server.jar']);
            $managed->update(['target_manifest_path' => app(ReleaseStore::class)->put($incomplete)]);
        }
        $from = count($requests);
        $preview = app(PreviewService::class)->generate($managed);
        if (str_starts_with($scenario, 'drift_')) {
            $readPaths = array_column(array_column(array_filter(array_slice($requests, $from), fn ($r) => str_ends_with($r[1], '/files/contents')), 2), 'file');
            check(! in_array('/mods/Paradigm.jar', $readPaths, true), 'Mod drift read an unrelated JAR');
            $row = array_column($preview['changes'], null, 'path')[$almanac('1.26.10.3')];
            if (in_array($scenario, ['drift_disabled', 'drift_lookalike'], true)) {
                check($row['action'] === 'keep_mod_disabled' && $row['mod_upgrade']['members'] === [], 'A disabled or unrelated JAR was selected for replacement');
            }
            elseif ($scenario !== 'drift_success') { check($row['action'] === 'mod_identity_review' && ! $preview['applicable'], 'Unverified/duplicate JAR did not block approval'); }
            else {
                check($row['action'] === 'mod_upgrade_conflict' && $row['mod_upgrade']['installed']['path'] === $almanac('1.26.10.2') &&
                    $row['mod_upgrade']['installed']['version'] === '1.26.10.2' && ! $preview['applicable'], 'Active Almanac drift was not presented as a local version conflict');
                app(\Arcturus\ModpackManager\Services\ServerPolicyService::class)->assign($managed, [$almanac('1.26.10.3')], 'replace', $managed->policy_revision);
                $preview = app(PreviewService::class)->generate($managed->refresh());
                $scope = \Arcturus\ModpackManager\Native\Transaction::scope($preview['changes']);
                check(array_key_exists($almanac('1.26.10.2'), $scope['after']) && $scope['after'][$almanac('1.26.10.2')] === null && isset($scope['after'][$almanac('1.26.11.1')]), 'Approved plan did not replace the actual old JAR');
            }
            if ($scenario !== 'drift_success') { echo $label." native qualification passed\n"; continue; }
        }
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
        if ($crucible) {
            $runtime = $preview['qualification']['runtime_plan'];
            check($runtime['strategy'] === \Arcturus\ModpackManager\Native\CrucibleRuntime::STRATEGY &&
                $runtime['upstream_runtime']['loader'] === 'forge' && $runtime['target']['loader'] === 'crucible' &&
                $runtime['target']['java'] === 25 && count($runtime['retained_launcher_files']) === 2, 'Crucible runtime plan lost the separate upstream or retained runtime');
        }
        check($managed->local_root === null && $managed->staging_parent === null, 'Native deployment inherited host volume access');
        $operation = app(DeploymentApproval::class)->queue($managed, $preview['plan_sha256'], (string) Str::uuid());
        Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
        $operation->refresh(); $managed->refresh();
        if ($crucible) {
            foreach (['Crucible-1.7.10-server.jar', 'java9args.txt', 'lwjgl3ify-forgePatches.jar'] as $path) {
                check($systems[$uuid]['data'][$path] === $originalData[$path], 'Crucible runtime file changed: '.$path);
            }
            check($managed->pelicanServer->startup === $originalStartup && $managed->pelicanServer->image === 'fixture:java_25', 'Crucible startup/image changed');
            if ($scenario === 'crucible_drift') {
                check($operation->phase === 'manual_intervention' && $managed->last_backup_uuid === null && $systems[$uuid]['starts'] === 0
                    && str_contains($operation->error ?? '', 'Retained Crucible launcher file drifted'), 'Retained runtime drift did not stop the deployment before backup/writes');
                check($systems[$uuid]['data']['config/options.cfg'] === 'old', 'A config was installed despite launcher drift');
                echo $label." native transaction passed\n"; continue;
            }
            if ($scenario === 'crucible_wrong_boot') {
                check($operation->phase === 'restored' && $managed->installed_version === '1' && $systems[$uuid]['starts'] === 2
                    && str_contains($operation->error ?? '', 'did not confirm target loader'), 'Unexpected Forge boot was committed or could not roll back');
                check($systems[$uuid]['data']['config/options.cfg'] === 'old', 'Crucible rollback failed to restore original config');
            } else {
                check($operation->phase === 'completed' && $managed->installed_version === '2' && $managed->runtime_report['loader'] === 'crucible'
                    && $systems[$uuid]['data']['config/options.cfg'] === 'new', 'Crucible update did not commit the new pack');
            }
            check($systems[$uuid]['data']['world/level.dat'] === $originalData['world/level.dat'], 'Crucible deployment touched world data');
            echo $label." native transaction passed\n"; continue;
        }
        if ($scenario === 'failed_boot') {
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\BootDiagnostics);
            $requestCount = count($requests); $contextBefore = $operation->getRawOriginal('context');
            check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:boot-diagnostics', ['operation' => $operation->id]) === 0, 'Private diagnostics command failed');
            $output = \Illuminate\Support\Facades\Artisan::output();
            check(str_contains($output, 'Target launcher failed <error>before creating latest.log</error>') && ! str_contains($output, 'Original console'), 'The operator did not receive the exact failed target output');
            check(count($requests) === $requestCount && $operation->fresh()->getRawOriginal('context') === $contextBefore, 'Diagnostics command contacted Wings or modified the receipt');
        }
        if (in_array($scenario, ['failed_restore', 'interrupted'], true)) {
            check($operation->phase === 'manual_intervention' && $systems[$uuid]['state'] === 'offline' && $managed->active_operation_id === $operation->id,
                'Interrupted/unverified operation was released: '.$operation->error);
            $mode = $scenario === 'failed_restore' ? 'recovery_log_grows' : 'success'; Auth::guard()->setUser($adminUser);
            app(DeploymentApproval::class)->verifyRecovery($managed);
            Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
            $operation->refresh(); $managed->refresh();
        }
        if (in_array($scenario, ['target_log_grows', 'rollback_log_grows', 'failed_restore'], true)) {
            check(($systems[$uuid]['boot_log_races'] ?? 0) === 1, 'Growing startup log fixture did not exercise the read race');
        }
        if ($scenario === 'rollback_log_grows') {
            check($systems[$uuid]['starts'] === 2 && str_contains($operation->error ?? '', 'Minecraft exited during native startup verification'), 'Real target exit was not rolled back');
        }
        if (in_array($scenario, ['success', 'installer_success', 'disable_success', 'root_args_success', 'target_log_grows', 'drift_success'], true)) {
            check($operation->phase === 'completed' && $managed->installed_version === '2', $label.' did not complete: '.$operation->error);
            if ($scenario === 'drift_success') {
                check(! isset($systems[$uuid]['data'][$almanac('1.26.10.2')]) && ! isset($systems[$uuid]['data'][$letMeDespawn('1.26.10.1')]) &&
                    isset($systems[$uuid]['data'][$almanac('1.26.11.1')], $systems[$uuid]['data'][$letMeDespawn('1.26.11.1')]), 'The approved Almanac and Let Me Despawn pair was not installed');
            }
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
    // An old receipt truncated by MySQL TEXT can only be retired after an
    // independent read-only verification. No backup, apply, restore or start.
    $prewrite = $make(false);
    $prewriteUuid = $prewrite->pelican_server_uuid;
    app(PreviewService::class)->generate($prewrite);
    $prewritePreview = $prewrite->refresh()->preview_summary;
    $prewriteExpected = \Arcturus\ModpackManager\Native\Transaction::scope($prewritePreview['changes'])['before'];
    check(count($prewriteExpected) > 0, 'No candidate scope for prewrite safety fixture');
    $damaged = Operation::create([
        'id' => (string) Str::uuid(), 'managed_server_id' => $prewrite->id,
        'kind' => 'deploy', 'phase' => 'stopping', 'node_operation_id' => bin2hex(random_bytes(16)),
        'context' => ['truncated_evidence' => str_repeat('encrypted-original-', 6000)],
        'heartbeat_at' => now()->subHour(), 'created_at' => now()->subHour(),
    ]);
    $cipher = substr($damaged->getAttributes()['context'], 0, 65535);
    DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->update(['context' => $cipher]);
    $prewrite->update(['active_operation_id' => $damaged->id, 'approved_plan_sha256' => $prewritePreview['plan_sha256']]);
    $systems[$prewriteUuid]['state'] = 'offline';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(
        new \Arcturus\ModpackManager\Console\Commands\RetireCorruptPrewrite);
    $args = ['operation' => $damaged->id];
    // A conflicting, still-present approval is never treated like one that
    // the production deployment failure handler deliberately cleared.
    $prewrite->update(['approved_plan_sha256' => str_repeat('0', 64)]);
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:retire-corrupt-prewrite', $args) !== 0 &&
        $prewrite->fresh()->active_operation_id === $damaged->id, 'Conflicting approval was accepted for retirement');
    // In production, Native Deployment::catch() removes this pointer before
    // persisting failure; retain the independent exact preview for verification.
    $prewrite->update(['approved_plan_sha256' => null]);
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:retire-corrupt-prewrite', $args) === 0,
        'Read-only truncated receipt verification did not pass with cleared approval');
    $review = \Illuminate\Support\Facades\Artisan::output();
    check(preg_match('/Proof: ([a-f0-9]{64})/', $review, $proofMatch) === 1 &&
        str_contains($review, 'Original candidate paths verified: '.count($prewriteExpected)),
        'Read-only proof/count not reported');
    check($prewrite->fresh()->active_operation_id === $damaged->id &&
        DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->value('context') === $cipher,
        'Read-only prewrite review changed the encrypted receipt or server lock');
    $bad = $args + ['--apply' => true, '--confirm' => str_repeat('0', 64), '--expect-count' => count($prewriteExpected)];
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:retire-corrupt-prewrite', $bad) !== 0 &&
        $prewrite->fresh()->active_operation_id === $damaged->id, 'Invalid attestation released damaged receipt');
    $good = $args + ['--apply' => true, '--confirm' => $proofMatch[1], '--expect-count' => count($prewriteExpected)];
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:retire-corrupt-prewrite', $good) === 0,
        'Verified prewrite retirement did not complete: '.\Illuminate\Support\Facades\Artisan::output());
    $retired = Operation::findOrFail($damaged->id);
    check($retired->phase === 'cancelled' && $retired->completed_at !== null &&
        $prewrite->fresh()->active_operation_id === null &&
        DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->value('context') === $cipher &&
        $systems[$prewriteUuid]['state'] === 'offline' &&
        $systems[$prewriteUuid]['data']['config/options.cfg'] === 'old',
        'Prewrite retirement removed evidence, changed files, started Minecraft, or retained the lock');
    echo "Guarded pre-backup retirement preserved damaged ciphertext, original files and offline state\n";

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
    foreach ([false, true] as $alreadyReported) {
        DB::table('jobs')->delete();
        $failed = $make(false); $failed->update(['policy' => 'auto', 'deploy_enabled' => true, 'automation_actor_id' => 1]);
        $sid = DB::table('schedules')->insertGetId(['server_id' => $failed->pelicanServer->id, 'name' => 'Failed native update']);
        $tid = DB::table('tasks')->insertGetId(['schedule_id' => $sid, 'sequence_id' => 1, 'action' => \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID,
            'payload' => 'update', 'is_queued' => true, 'continue_on_failure' => true]);
        $following = DB::table('tasks')->insertGetId(['schedule_id' => $sid, 'sequence_id' => 2, 'action' => 'power', 'payload' => 'start']);
        $rid = (string) Str::uuid();
        $failedOp = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($failed, $alreadyReported ? 'deploy' : 'prepare', ['version' => '2'], $rid);
        app(\Arcturus\ModpackManager\Services\OperationTracker::class)->fail($failedOp, 'Isolated scheduled failure');
        if ($alreadyReported) {
            $reportedEvent = $failed->events()->create(['kind' => 'deploy', 'status' => 'failed', 'details' => ['operation_id' => $failedOp->id, 'discord_notification_pending' => true]]);
            app(\Arcturus\ModpackManager\Services\DiscordNotifier::class)->forEvent($reportedEvent);
        }
        $failureRun = \Arcturus\ModpackManager\Models\NativeScheduleRun::create(['id' => $rid, 'dispatch_key' => 'fixture:failed:'.$rid, 'task_id' => $tid,
            'managed_server_id' => $failed->id, 'task_snapshot' => ['schedule_id' => $sid, 'sequence_id' => 1, 'payload' => 'update', 'server_uuid' => $failed->pelican_server_uuid],
            'manual_run' => true, 'policy_revision' => $failed->policy_revision, 'actor_id' => 1, 'state' => 'waiting', 'operation_id' => $failedOp->id]);
        app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($rid);
        check($failureRun->refresh()->state === 'failed' && ! \App\Models\Task::find($following)->is_queued && ! \App\Models\Schedule::find($sid)->is_processing, 'Failed update advanced its task chain');
        $failureEvent = $failed->events()->where('kind', 'schedule')->where('status', 'failed')->firstOrFail();
        check(($failureEvent->details['discord_notification_pending'] ?? false) === ! $alreadyReported, 'Schedule alert duplicated a deployment failure or lost a preparation failure');
        $count = \Arcturus\ModpackManager\Models\DiscordDelivery::where('source_event_id', $failureEvent->id)->count();
        check($count === ($alreadyReported ? 0 : 1), 'Scheduled failure was not handed off to the notification outbox');
        app(\Arcturus\ModpackManager\Schedules\NativeSchedules::class)->tick($rid);
        check($failed->events()->where('kind', 'schedule')->count() === 1, 'Duplicate failed schedule produced another event');
    }
    echo "Native Wings schedules: no-update restart without backup, prepared automatic deployment, sequencing and duplicate receipt passed\n";
} finally {
    foreach ($stages as $stage) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($stage);
    }
}
echo "Native deployment receipts, backups/callbacks, boot verification and recovery passed using real rc.2 services and HTTP fixtures; no live Wings/Docker/Minecraft exercised\n";
$GLOBALS['arcturus_integration_passed'] = true;
