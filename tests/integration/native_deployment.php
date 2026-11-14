<?php

declare(strict_types=1);
require __DIR__.'/fixtures/deployment-bootstrap.php';
require __DIR__.'/fixtures/clock.php';
use function Arcturus\ModpackManager\Native\microtime;

use App\Models\Server;
use App\Models\Node;
use App\Models\Backup;
use App\Http\Controllers\Api\Remote\Backups\BackupStatusController;
use App\Http\Requests\Api\Remote\ReportBackupCompleteRequest;
use App\Repositories\Daemon\DaemonServerRepository;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
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
// Model CPU work between sleeps without a real delay: Laravel audit time must keep advancing.
$auditClock=\Illuminate\Support\Facades\Date::getTestNow();
check($auditClock instanceof Closure, 'Fixture audit clock must follow elapsed work, not a frozen last-sleep snapshot');
$wall=\Illuminate\Support\Carbon::createFromTimestamp(1700000000);
$beforeWork=$auditClock($wall->copy()); $afterWork=$auditClock($wall->copy()->addSeconds(2));
check((float)$afterWork->format('U.u')-(float)$beforeWork->format('U.u')===2.0, 'Fixture audit timestamps diverged from Wings during work between sleeps');

$egg->docker_images = ['Java 21' => 'fixture:java_21', 'Java 25' => 'fixture:java_25'];
$foreignReceiptCipher = null;
$systems = []; $snapshots = []; $requests = []; $mode = 'success'; $stages = []; $syncFail = false;
$hash = fn ($value) => hash('sha256', $value);
$bootLog = fn ($mc, $version, $java) => "ModLauncher running: args [--fml.neoForgeVersion, $version, --fml.mcVersion, $mc]\nModLauncher 10.0.9 starting: java version $java.0.1\n[Server thread/INFO]: Done (0.1s)!\n";
$crucibleBootLog = fn () => file_get_contents(__DIR__.'/../fixtures/gtnh-crucible/startup-output.txt');
$crucibleJar = function (string $forge = '10.13.4.1614', string $build = 'original'): string {
    $file = tempnam(sys_get_temp_dir(), 'arcturus-crucible-fixture-');
    try {
        $zip = new ZipArchive;
        check($zip->open($file, ZipArchive::OVERWRITE) === true, 'Cannot create Crucible fixture');
        $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\r\nImplementation-Title: Crucible\r\nForge-Version: $forge\r\nImplementation-Version: $build\r\nMain-Class: cpw.mods.fml.relauncher.ServerLaunchWrapper\r\n\r\n");
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
    if ($crucible) { $startup = rtrim(file_get_contents(__DIR__.'/../fixtures/gtnh-crucible/startup.txt'), "\r\n"); }
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
        $systems[$uuid]['file_modified'] = array_fill_keys(['Crucible-1.7.10-server.jar', 'java9args.txt'], now()->subMinutes(2)->toIso8601String());
        $systems[$uuid]['data']['java9args.txt'] = file_get_contents(__DIR__.'/../fixtures/gtnh-crucible/java9args.txt');
        $systems[$uuid]['data']['logs/latest.log'] = $crucibleBootLog();
    }
    return $managed;
};
$wings = function ($request) use (&$systems, &$snapshots, &$requests, &$mode, &$syncFail, &$foreignReceiptCipher, $bootLog, $crucibleBootLog, $targetConsole) {
    $url = $request->url(); preg_match('~/api/servers/([a-f0-9-]+)~', $url, $m); $uuid = $m[1] ?? ''; check(isset($systems[$uuid]), 'Unexpected native fixture server');
    $sys = &$systems[$uuid]; $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    $path = parse_url($url, PHP_URL_PATH); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
    $requests[] = [$request->method(), $path, $q];
    if (str_ends_with($path, '/logs')) {
        check($request->method() === 'GET' && in_array($q['size'] ?? '', ['60','100'], true), 'Console was not queried with a bounded read');
        if (in_array($mode, ['diagnostic_unavailable', 'crucible_offline_failed_boot'], true) && $sys['starts'] > 0) { return Http::response(['error' => 'private remote failure body'], 500, $headers); }
        return Http::response(['data' => $sys['console'] ?? ['Original console before the update']], 200, $headers);
    }
    if (str_ends_with($path, '/sync')) {
        if ($syncFail) { return Http::response([], 500, $headers); }
        $cfg = app(\App\Services\Servers\ServerConfigurationStructureService::class)->handle(Server::where('uuid', $uuid)->firstOrFail());
        $sys['configuration'] = array_replace(['crash_detection_enabled' => true], $cfg);
        if (($sys['sampled_sync'] ?? '') === 'plateau') { $sys['uptime_repeat'] = 3; $sys['post_sync_samples'] = []; }
        if (($sys['sampled_sync'] ?? '') === 'restart') { $sys['boot_started'] = microtime(true); }
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/files/list-directory')) {
        $dir = trim($q['directory'] ?? '', '/'); $prefix = $dir === '' ? '' : $dir.'/'; $rows = [];
        foreach ([...array_keys($sys['data']), ...$sys['dirs']] as $file) {
            if (! str_starts_with($file, $prefix)) { continue; } $rest = substr($file, strlen($prefix)); if ($rest === '') { continue; }
            $name = explode('/', $rest)[0]; $full = $prefix.$name; $isFile = isset($sys['data'][$full]);
            $rows[$name] = ['name' => $name, 'file' => $isFile, 'directory' => ! $isFile, 'symlink' => isset($sys['links'][$full]), 'size' => $isFile ? strlen($sys['data'][$full]) : 0, 'mode_bits' => decoct($sys['modes'][$full] ?? 0644), 'modified' => $sys['file_modified'][$full] ?? $sys['modified']];
        }
        return Http::response(array_values($rows), 200, $headers);
    }
    if (str_ends_with($path, '/files/contents')) {
        $file = ltrim($q['file'], '/'); check(! str_starts_with($file, 'world/'), 'Plugin attempted to read world data');
        $contents = $sys['data'][$file];
        if ($mode === 'prebackup_failure' && $sys['state'] === 'offline' && $file === 'config/options.cfg') { $contents = 'bad'; }
        if ($file === 'logs/latest.log' && isset($sys['pending_boot_log'])) {
            $sys['data'][$file] = $sys['pending_boot_log']; unset($sys['pending_boot_log']);
            $sys['boot_log_races'] = ($sys['boot_log_races'] ?? 0) + 1;
        }
        return Http::response($contents, 200, $headers);
    }
    if (str_ends_with($path, '/files/create-directory')) { $sys['dirs'][] = ltrim($request['path'].'/'.$request['name'], '/'); return Http::response([], 204, $headers); }
    if (str_ends_with($path, '/files/write')) {
        check(in_array($sys['state'], ['offline', 'exited'], true) && $sys['configuration']['suspended'] && ! $sys['configuration']['crash_detection_enabled'], 'Upload not guarded/offline');
        $file = ltrim($q['file'], '/'); $sys['data'][$file] = $mode === 'staging_corrupt' ? str_repeat('z', strlen($request->body())) : $request->body();
        if ($mode === 'ownership_lost') {
            $mode = 'success'; $managed = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
            $newOwner = Operation::findOrFail($managed->active_operation_id);
            $newOwner->update(['context' => [...$newOwner->context, 'native_execution_id' => 'foreign-execution']]);
            $foreignReceiptCipher = $newOwner->getRawOriginal('context');
        }
        if ($mode === 'interrupted') {
            $mode = 'success'; $managed = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
            // Model a dead worker whose cache lease was lost, rather than a second callback taking over a live lease.
            \Illuminate\Support\Facades\Cache::lock('arcturus-modpack-manager-deploy-'.$managed->id)->forceRelease();
            (new DeployManagedServer($managed->id, false, $managed->approved_plan_sha256, $managed->active_operation_id))->failed(null);
        }
        return Http::response([], 204, $headers);
    }
    if (str_ends_with($path, '/files/chmod')) { foreach ($request['files'] as $file) { $sys['modes'][$file['file']] = octdec($file['mode']); } return Http::response([], 204, $headers); }
    if (str_ends_with($path, '/files/rename')) {
        $requests[array_key_last($requests)][3] = $request['files'];
        if ($mode === 'batch_noop') { $mode = 'success'; return Http::response([], 204, $headers); }
        $partialBatch = $mode === 'batch_partial' && count($request['files']) > 1;
        foreach ($request['files'] as $index => $move) {
            if ($partialBatch && $index === 1) { $mode = 'success'; return Http::response([], 204, $headers); }
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
            $sys['omit_uptime'] = false; // A target start must supply new, real boot evidence.
            $sys['boot_started'] = microtime(true); $sys['polls_since_start'] = 0;
            $sys['starts']++; $fail = in_array($mode, ['failed_boot', 'failed_restore', 'installer_failed_boot', 'disable_failed_boot', 'root_args_failed_boot', 'rollback_log_grows', 'diagnostic_unavailable', 'stale_console'], true) && $sys['starts'] === 1;
            if ($mode === 'crucible_offline_failed_boot') { $fail = true; }
            $sys['state'] = $fail ? 'offline' : 'running';
            if ($fail) {
                $sys['data']['world/region/new-failed-boot.mca'] = 'must disappear on full recovery';
                if ($mode !== 'stale_console') { $sys['console'] = [$targetConsole]; }
                if ($mode === 'crucible_offline_failed_boot') {
                    $sys['data']['logs/latest.log'] = 'java.lang.NoClassDefFoundError: java/util/jar/Pack200';
                    $sys['console'] = ['java.lang.NoClassDefFoundError: java/util/jar/Pack200'];
                }
            }
            else {
                $sys['console'] = ['Original or target boot continued normally'];
                $server = Server::where('uuid', $uuid)->firstOrFail(); $new = str_contains($server->startup, '/26.1.2.121/');
                $log = $bootLog($new ? '26.1.2' : '1.21.1', $new ? '26.1.2.121' : '21.1.1', str_contains($server->image, '25') ? 25 : 21);
                if (str_contains($server->startup, 'Crucible-1.7.10-server.jar')) {
                    $log = $crucibleBootLog();
                    if ($mode === 'crucible_wrong_boot' && $sys['starts'] === 1) { $log = str_replace('Crucible', 'stock Forge', $log); }
                }
                $log .= "\nBoot instance ".$sys['starts']."\n";
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
        check(in_array($sys['state'], ['offline', 'exited'], true) && $sys['configuration']['suspended'], 'Backup not offline/guarded');
        $consoleManaged = ManagedServer::where('pelican_server_uuid', $uuid)->firstOrFail();
        $consoleLines = \Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory::read($consoleManaged->id, null)['lines'];
        check(str_contains(end($consoleLines)['text'], 'locked Pelican backup'), 'Native backup phase not visible while Minecraft is offline');
        $backup = Backup::where('uuid', $request['uuid'])->firstOrFail();
        check($backup->is_locked && $backup->ignored_files === ['/backups/'], 'Backup identity/protection differs');
        $snapshots[$backup->uuid] = array_filter($sys['data'], fn ($p) => ! str_starts_with($p, 'backups/') && ! isset($sys['links'][$p]), ARRAY_FILTER_USE_KEY);
        $backupBytes = serialize($snapshots[$backup->uuid]);
        $callback = ReportBackupCompleteRequest::create('/fixture/backup', 'POST', ['successful' => $mode !== 'backup_failed', 'checksum_type' => 'sha256', 'checksum' => hash('sha256', $backupBytes), 'size' => strlen($backupBytes)]);
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
    $observedState = $sys['state'];
    if ($observedState === 'offline' && $sys['starts'] === 1 && ($sys['polls_since_start'] ?? 0) === 0) { $sys['polls_since_start'] = 1; $observedState = 'starting'; }
    if (isset($sys['uptime_cache_ages'])) {
        // Cached sample acquisition can predate the previous HTTP request, even if uptime grows.
        \Arcturus\ModpackManager\Tests\FixtureClock::advance(2);
        check($sys['uptime_cache_ages']!==[], 'Unexpected extra details request in cached sync fixture');
        $age=array_shift($sys['uptime_cache_ages']);
        $at=microtime(true); $uptime=($at-$sys['boot_started']-$age)*1000;
        $sys['cached_samples'][]=['at'=>$at,'uptime'=>$uptime,'age'=>$age];
    } else {
        $uptime = max(1, (microtime(true) - ($sys['boot_started'] ?? (microtime(true) - 60))) * 1000);
    }
    if (($sys['uptime_repeat'] ?? 0) > 0) { $uptime = $sys['sampled_uptime']; $sys['uptime_repeat']--; }
    $sys['sampled_uptime'] = $uptime;
    if (isset($sys['post_sync_samples'])) { $sys['post_sync_samples'][] = $uptime; }
    if ($sys['omit_uptime'] ?? false) { $uptime = null; }
    return Http::response(['state' => $observedState, 'configuration' => $sys['configuration'], 'utilization' => ['uptime' => $uptime]], 200, $headers);
};
try {
    foreach ((getenv('ARCTURUS_RECOVERY_ONLY') ? [] : [
        ['file-only', false, 'success'], ['published NeoForge migration', true, 'success'], ['partial file write', false, 'partial_write'],
        ['corrupt staging rollback', false, 'staging_corrupt'], ['partial batch 204 rollback', false, 'batch_partial'], ['no-op batch 204 rollback', false, 'batch_noop'],
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
        ['offline without log or uptime', false, 'offline_success'],
        ['GTNH offline with replaced JAR', false, 'crucible_offline_success'],
        ['GTNH offline without log', false, 'crucible_offline_no_log'],
        ['GTNH stopped after approval', false, 'crucible_stopped_after_compare'],
        ['GTNH broken target and original boot', false, 'crucible_offline_failed_boot'],
        ['root args migration', true, 'root_args_success'], ['root args migration rollback', true, 'root_args_failed_boot'],
    ]) as [$label, $migration, $scenario]) {
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
        $offlineScenario = $scenario === 'offline_success' || str_starts_with($scenario, 'crucible_offline_');
        if ($offlineScenario) {
            $systems[$uuid]['state'] = 'exited'; $systems[$uuid]['omit_uptime'] = true;
            if ($crucible) {
                $systems[$uuid]['data']['Crucible-1.7.10-server.jar'] = $crucibleJar('10.13.4.1614', 'operator-replaced');
                $systems[$uuid]['file_modified']['Crucible-1.7.10-server.jar'] = now()->toIso8601String();
                // Stale, successful log from the old JAR must neither authorize this JAR nor block Compare.
                $systems[$uuid]['data']['logs/latest.log'] = str_replace('25.0.1', '27.0.1', $crucibleBootLog());
            }
            if (in_array($scenario, ['offline_success', 'crucible_offline_no_log'], true)) { unset($systems[$uuid]['data']['logs/latest.log']); }
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
                check(! $nested['applicable'] && str_contains(implode(' ', $nested['capability_blockers']), 'JVM argument file java9args.txt'), 'Nested Java argfile was accepted');
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
        if ($offlineScenario) {
            check($preview['applicable'], 'Offline Compare blocked: '.implode('; ', $preview['capability_blockers']));
            check(($preview['qualification']['boot_evidence']['verified'] ?? true) === false &&
                $preview['qualification']['current']['loader_confidence'] === 'file_evidence' &&
                $preview['qualification']['current']['java_confidence'] === 'image_hint', 'Offline Compare fabricated boot/Java proof');
            check($systems[$uuid]['starts'] === 0 && ! isset($snapshots[$uuid]), 'Offline Compare started Minecraft or created a backup');
            if ($crucible) { check($preview['qualification']['runtime_plan']['retained_launcher_files']['Crucible-1.7.10-server.jar']['sha256'] === hash('sha256', $originalData['Crucible-1.7.10-server.jar']), 'Plan retained a stale JAR hash'); }
            rejects(fn () => \Arcturus\ModpackManager\Native\Qualification::requireAutomaticBoot($preview['qualification']), 'Offline manual qualification silently enabled automation');
            $compareCalls = array_filter($requests, fn ($r) => str_contains($r[1], $uuid) && str_ends_with($r[1], '/files/contents') && ($r[2]['file'] ?? '') === '/logs/latest.log');
            check($compareCalls === [], 'Offline Compare consumed stale latest.log');
        }
        $operation = app(DeploymentApproval::class)->queue($managed, $preview['plan_sha256'], (string) Str::uuid());
        if ($scenario === 'crucible_stopped_after_compare') { $systems[$uuid]['state'] = 'exited'; $systems[$uuid]['omit_uptime'] = true; }
        $deployRequestStart = count($requests);
        Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
        $operation->refresh(); $managed->refresh();
        if ($offlineScenario || $scenario === 'crucible_stopped_after_compare') {
            $calls = array_values(array_slice($requests, $deployRequestStart));
            $backupIndex = array_key_first(array_filter($calls, fn ($r) => str_ends_with($r[1], '/backup')));
            check($backupIndex !== null, 'Offline Deploy never requested its exact backup: '.$operation->error);
            check(! array_filter(array_slice($calls, 0, $backupIndex + 1), fn ($r) => str_ends_with($r[1], '/power')), 'Offline deploy powered Minecraft before backup');
            check(($operation->context['native_original_boot_verified'] ?? true) === false, 'Offline initial boot was recorded as verified');
        }
        if ($scenario === 'success') {
            $measurements = $operation->context['native_metrics'];
            check(isset($measurements['phases_seconds']['verifying_before_backup'], $measurements['phases_seconds']['backing_up'], $measurements['phases_seconds']['verifying_after_backup']), 'Backup verification and wait timings were combined: '.json_encode(['phase' => $operation->phase, 'error' => $operation->error, 'metrics' => $measurements]));
            check(is_numeric($measurements['offline_to_verified_boot_seconds'] ?? null) && $measurements['offline_to_verified_boot_seconds'] >= 0, 'Verified boot did not record its offline interval');
            check(($measurements['work']['upload']['bytes'] ?? 0) > 0 && ($measurements['work']['db-checkpoint']['calls'] ?? 0) > 0 && ($measurements['work']['list-directory']['calls'] ?? 0) > 0, 'Endpoint/byte/checkpoint measurements missing');
        }
        if ($operation->phase === 'restored' && ! array_key_exists('running_original_sync_pending', $operation->context)) {
            $page = app(ViewManagedServer::class); $page->record = $managed;
            $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
            $sync = array_values(array_filter($actions, fn ($action) => $action->getName() === 'syncRunningOriginal'))[0];
            check(! $sync->isVisible(), 'Ordinary rollback without a sync-pending key exposed recovery sync or broke the page');
        }
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
            if ($scenario === 'crucible_offline_failed_boot') {
                check($operation->phase === 'manual_intervention' && $managed->installed_version === '1' && $managed->active_operation_id === $operation->id, 'Broken original was reported as functional rollback');
                check(($operation->context['restore_files_verified'] ?? false) === true && ($operation->context['restore_verified'] ?? true) === false && ($operation->context['recovery_startup_verified'] ?? true) === false, 'Restore files and boot proof were conflated');
                check($systems[$uuid]['state'] === 'offline' && $systems[$uuid]['data']['config/options.cfg'] === 'old', 'Failed rollback lost the offline restored snapshot');
                check(($operation->context['native_target_boot_failure']['source'] ?? '') === 'startup_log_sample' && str_contains(json_encode($operation->context['native_target_boot_failure'] ?? []), 'Pack200'), 'Fresh target crash log was discarded when the console was unavailable');
                check(str_contains($operation->error, 'Original files and runtime settings restored; original Minecraft boot is NOT verified'), 'File restoration was not distinguished from boot verification');
                $history = \Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory::read($managed->id, null)['lines'];
                check(! str_contains(json_encode($history), 'Rollback verified'), 'Failed original boot was announced as successful');
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
            $consoleLines = \Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory::read($managed->id, null)['lines'];
            check(str_contains(end($consoleLines)['text'], 'Recovery unverified') && ! str_contains(json_encode($consoleLines), 'Rollback verified'), 'Unverified recovery was announced as successful');
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
        if (in_array($scenario, ['success', 'offline_success', 'installer_success', 'disable_success', 'root_args_success', 'target_log_grows', 'drift_success'], true)) {
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
        $consoleLines = \Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory::read($managed->id, null)['lines'];
        $consoleText = implode("\n", array_column($consoleLines, 'text'));
        check(str_contains($consoleText, 'locked Pelican backup') && str_contains($consoleText, 'Applying and verifying'), 'Native phase history missing');
        check(str_contains(end($consoleLines)['text'], $operation->phase === 'completed' ? 'Minecraft startup verified' : 'Rollback verified'), 'Native final console state disagrees with verified receipt');
        check(! str_contains($consoleText, 'Target launcher failed') && ! str_contains($consoleText, 'token='), 'Private boot diagnostics leaked into the terminal');
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

    // Recreate a retained recovery after an actually successful original boot, without inferring power ordering.
    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
    Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps(); });
    Schema::create('model_has_roles', function (Blueprint $t) { $t->unsignedBigInteger('role_id'); $t->string('model_type'); $t->unsignedBigInteger('model_id'); });
    DB::table('roles')->insert(['id'=>1,'name'=>\App\Models\Role::ROOT_ADMIN,'guard_name'=>'web']);
    DB::table('model_has_roles')->insert(['role_id'=>1,'model_type'=>(new \App\Models\User)->getMorphClass(),'model_id'=>1]);
    $mode = 'crucible_wrong_boot'; Auth::guard()->setUser($adminUser); $orphan = $make(false, false, false, true);
    app(\Arcturus\ModpackManager\Services\ServerPolicyService::class)->save($orphan, [...$orphan->file_rules, ['scope'=>'exact','pattern'=>'java9args.txt','mode'=>'protect'], ['scope'=>'exact','pattern'=>'lwjgl3ify-forgePatches.jar','mode'=>'protect']], [], $orphan->policy_revision, ['keep_existing_crucible_runtime'=>true]);
    $orphan->refresh();
    $orphanPlan = app(PreviewService::class)->generate($orphan);
    $orphanOp = app(DeploymentApproval::class)->queue($orphan, $orphanPlan['plan_sha256'], (string) Str::uuid());
    Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep:0,timeout:0));
    $orphanOp->refresh(); $orphan->refresh(); $ouuid = $orphan->pelican_server_uuid;
    check($orphanOp->phase==='restored', 'Original fixture startup did not complete');
    $originalContext = $orphanOp->context;
    $resetOrphan = function (bool $legacy = false) use ($orphanOp, $orphan, $ouuid, $originalContext, &$systems): void {
        $orphanOp->refresh(); $orphan->refresh();
        $ctx = $originalContext; $ctx['from']='2.8.1'; $ctx['to']='2.9.0';
        if ($legacy) {
            unset($ctx['native_restore_confirmation'], $ctx['native_original_files_verified'], $ctx['native_boot_attempts']);
            $ctx['native_recovery_attempt']=1; $ctx['native_checkpoint']=['step'=>'boot_start_intent'];
            $old='logs/.arcturus-before-'.$orphanOp->id.'-old.log';
            $systems[$ouuid]['data']['logs/.arcturus-before-'.$orphanOp->node_operation_id.'-r1.log']=$systems[$ouuid]['data'][$old];
        }
        $orphanOp->update(['phase'=>'manual_intervention','context'=>$ctx,'completed_at'=>now(),'error'=>'Queue worker timed out after 10800 seconds; startup verification was not attributable']);
        $orphan->update(['active_operation_id'=>$orphanOp->id]); $systems[$ouuid]['state']='running'; $systems[$ouuid]['configuration']['suspended']=true;
    };
    $forbidden = fn ($rows) => array_filter($rows, fn ($r) => preg_match('~/(?:power|restore|files/(?:write|rename|chmod|delete|create-directory))$~', $r[1]));
    $verifier = app(\Arcturus\ModpackManager\Native\RunningOriginal::class);
    foreach (['new','legacy','v1_uptime'] as $format) {
        $resetOrphan($format === 'legacy');
        if ($format === 'v1_uptime') {
            $ctx = $orphanOp->context;
            foreach ($ctx['native_boot_attempts'] as &$boot) {
                if (isset($boot['instance'])) {
                    // Old receipts inferred birth at request time from an already cached uptime.
                    $boot['instance'] = ['min'=>$boot['instance']['min']+0.5, 'max'=>$boot['instance']['max']+0.5];
                }
            }
            unset($boot); $orphanOp->update(['context'=>$ctx]);
        }
        $start=count($requests); $ctx=$orphanOp->getRawOriginal('context');
        $dry=$verifier->run($orphanOp->id);
        check($dry['verified'] && !$dry['applied'] && $orphanOp->fresh()->getRawOriginal('context')===$ctx, 'Dry run changed retained recovery evidence');
        check(!$forbidden(array_slice($requests,$start)) && !array_filter(array_slice($requests,$start),fn($r)=>str_ends_with($r[1],'/sync')), 'Dry run mutated server');
        $result=$verifier->run($orphanOp->id,true,1);
        check($result['applied'] && $orphanOp->fresh()->phase==='restored' && $orphan->fresh()->active_operation_id===null && !$systems[$ouuid]['configuration']['suspended'], 'Running original did not release modpack suspension');
        check(!$forbidden(array_slice($requests,$start)), 'Finalization issued power/restore/file writes');
    }
    // Legacy audit is a request timestamp: Docker may prove a start 3.545s later.
    // Exercise the exact audit lookup and the same Wings/StartedAt intersections without waiting.
    $resetOrphan(true); $requestStart=count($requests);
    $restoreEvent=\App\Models\ActivityLog::findOrFail($originalContext['native_restore_confirmation']['callback_id']);
    $restore=['callback_id'=>$restoreEvent->id,'timestamp'=>$restoreEvent->timestamp->getTimestamp()];
    $startAudit=\App\Models\ActivityLog::where('id','>',$restoreEvent->id)->where('event','server:power.start')
        ->whereHas('subjects',fn($q)=>$q->where('subject_type',$orphan->pelicanServer->getMorphClass())->where('subject_id',$orphan->pelicanServer->id))->sole();
    $auditBefore=$startAudit->getRawOriginal(); $at=(float)$startAudit->timestamp->getTimestamp();
    $files=new \Arcturus\ModpackManager\Native\Wings($orphan->pelicanServer,app(\App\Repositories\Daemon\DaemonFileRepository::class));
    $bootProof=new ReflectionMethod(\Arcturus\ModpackManager\Native\RunningOriginal::class,'bootProof');
    $ackBefore=config('arcturus-modpack-manager.deployment_start_ack_timeout',60);
    config(['arcturus-modpack-manager.deployment_start_ack_timeout'=>4]);
    try {
        $legacy=$bootProof->invoke($verifier,$orphanOp,$orphan->pelicanServer,$files,$orphanOp->context,$restore,true);
        check($legacy['start_window']===['min'=>max($restore['timestamp'],$at),'max'=>$at+4], 'Legacy Docker proof did not use the configured ACK budget');
        $identity=function(float $delay)use($at,$legacy):array {
            $started=$at+$delay; $observed=$at+100;
            $sample=\Arcturus\ModpackManager\Native\RunningInstance::sample(['state'=>'running','utilization'=>['uptime'=>($observed-$started)*1000]],$observed,$observed+0.001);
            $bounded=\Arcturus\ModpackManager\Native\RunningInstance::same($sample,$legacy['start_window']);
            return \Arcturus\ModpackManager\Native\RunningInstance::same($bounded,['min'=>$started-0.002,'max'=>$started+0.002]);
        };
        check($identity(3.545)['min']<=$at+3.545, 'Legacy Docker start 3.545s after the audited request was rejected');
        rejects(fn()=>$identity(4.1),'Docker StartedAt outside the ACK budget was accepted through sampled Wings uncertainty');
        $ordinary=$bootProof->invoke($verifier,$orphanOp,$orphan->pelicanServer,$files,$orphanOp->context,$restore,false);
        check($ordinary['start_window']['max']===$at+1, 'Legacy proof without Docker evidence was widened');
        $modern=$originalContext; $modernId=$orphanOp->id.'-old'; $modern['native_boot_attempts'][$modernId]['ack_seconds']=2;
        $modernProof=$bootProof->invoke($verifier,$orphanOp,$orphan->pelicanServer,$files,$modern,$restore,true);
        check(!isset($modernProof['start_window']) && $modernProof['ack_seconds']===2 && $modernProof['requested_at']===$modern['native_boot_attempts'][$modernId]['requested_at'], 'Docker import rewrote the recorded modern boot window');
        check($startAudit->fresh()->getRawOriginal()===$auditBefore, 'Legacy verification changed the stored power audit');
        check(!array_filter(array_slice($requests,$requestStart),fn($r)=>$r[0]!=='GET'), 'Legacy window verification mutated Wings');
    } finally { config(['arcturus-modpack-manager.deployment_start_ack_timeout'=>$ackBefore]); }
    // GTNH latest.log can retain Done/game output without the startup-only FML/Java stdout.
    $resetOrphan(); $savedLog=$systems[$ouuid]['data']['logs/latest.log'];
    $systems[$ouuid]['data']['logs/latest.log']="[Server thread/INFO]: Done (1.0s)!\nPlayer connected\n";
    rejects(fn()=>$verifier->run($orphanOp->id), 'Headerless game log accepted without attributable startup proof');
    $evidenceDir=sys_get_temp_dir().'/arcturus-native-docker-'.bin2hex(random_bytes(8)); mkdir($evidenceDir,0700);
    $makeEvidence = function () use ($evidenceDir,$orphanOp,$ouuid,&$systems,$crucibleBootLog): array {
        $format=fn($t)=>gmdate('Y-m-d\TH:i:s',(int)$t).sprintf('.%06dZ',(int)(($t-floor($t))*1000000));
        $started=$systems[$ouuid]['boot_started'];
        $container=['Id'=>str_repeat('a',64),'Name'=>'/'.$ouuid,'State'=>['Running'=>true,'StartedAt'=>$format($started),'Pid'=>1234],'RestartCount'=>0];
        $log=''; foreach(explode("\n",rtrim($crucibleBootLog(),"\n")) as $line) { $log.=$format($started+0.01).' '.$line."\n"; }
        $metadata=['schema'=>1,'operation_id'=>$orphanOp->id,'server_uuid'=>$ouuid,'inspect_before'=>$container,'inspect_after'=>$container,'collected_at'=>$format(microtime(true)),'log_sha256'=>hash('sha256',$log)];
        file_put_contents($evidenceDir.'/console.log',$log); file_put_contents($evidenceDir.'/metadata.json',json_encode($metadata));
        chmod($evidenceDir.'/console.log',0600); chmod($evidenceDir.'/metadata.json',0600);
        return $metadata;
    };
    try {
        $metadata=$makeEvidence(); $start=count($requests); $ctx=$orphanOp->getRawOriginal('context');
        rejects(fn()=>$verifier->run($orphanOp->id,true,1,false,$evidenceDir), 'Operator apply skipped the audited dry run');
        rejects(fn()=>$verifier->run($orphanOp->id,false,null,false,$evidenceDir), 'Docker import accepted no root actor');
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\VerifyRunningOriginal);
        $args=['operation'=>$orphanOp->id,'--docker-evidence'=>$evidenceDir,'--actor'=>1];
        check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',$args)===0,'CLI operator evidence dry run failed');
        check($orphanOp->fresh()->getRawOriginal('context')===$ctx && $orphan->fresh()->active_operation_id===$orphanOp->id, 'Operator dry run changed recovery ownership/context');
        $audit=$orphan->events()->where('kind','running_original_evidence')->latest('id')->first();
        check($audit && $audit->details['actor_id']===1 && $audit->details['evidence']['container_id']===$metadata['inspect_before']['Id'], 'Operator dry run was not audited');
        check(!array_filter(array_slice($requests,$start),fn($r)=>$r[0]!=='GET'), 'Operator dry run mutated Wings');
        check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',[...$args,'--apply'=>true])===0,'Audited Docker --apply failed');
        check($orphanOp->fresh()->phase==='restored' && $orphan->fresh()->active_operation_id===null && !$systems[$ouuid]['configuration']['suspended'] && !$forbidden(array_slice($requests,$start)), 'Docker finalization restarted/restored/wrote or failed to release suspension');
        check($orphanOp->fresh()->context['running_original_verification']['startup_evidence']['dry_run_event_id']===$audit->id,'Apply lost its successful dry-run audit binding');
        // The source must survive a failed sync and retry without re-import, restart or latest.log headers.
        $resetOrphan(); $makeEvidence(); $verifier->run($orphanOp->id,false,1,false,$evidenceDir); $syncFail=true; $start=count($requests);
        rejects(fn()=>$verifier->run($orphanOp->id,true,1,false,$evidenceDir),'Failed Docker-proof sync unexpectedly succeeded');
        $syncFail=false;
        // Entire CLI retry, using a retained Docker start window of +/-2ms and variable cached ages.
        $pending=$orphanOp->fresh()->context;
        $dockerStart=\Arcturus\ModpackManager\Native\OperatorBootEvidence::timestamp($pending['running_original_verification']['startup_evidence']['started_at']);
        $pending['running_original_verification']['instance']['min']=$dockerStart-0.002;
        $pending['running_original_verification']['instance']['max']=$dockerStart+0.002;
        $orphanOp->update(['context'=>$pending]);
        \Arcturus\ModpackManager\Tests\FixtureClock::advance(12);
        $systems[$ouuid]['uptime_cache_ages']=[6.0,6.2,5.8,6.1]; $systems[$ouuid]['cached_samples']=[];
        $start=count($requests);
        $syncArgs=['operation'=>$orphanOp->id,'--sync-only'=>true,'--apply'=>true,'--actor'=>1];
        check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',$syncArgs)===0, 'CLI sync-only apply rejected variable cached Wings samples');
        $syncRows=array_filter(array_slice($requests,$start),fn($r)=>$r[0]==='POST' && str_ends_with($r[1],'/sync'));
        check(count($syncRows)===1 && !$orphanOp->fresh()->context['running_original_sync_pending'] && !$systems[$ouuid]['configuration']['suspended'], 'Cached uptime retry skipped sync or failed to clear pending/suspension');
        check(!$forbidden(array_slice($requests,$start)), 'Cached uptime retry powered/restored/wrote the server');
        $verifiedInstance=$orphanOp->fresh()->context['running_original_sync_verified']['instance'];
        check($verifiedInstance['min']===$dockerStart-0.002 && $verifiedInstance['max']===$dockerStart+0.002, 'Sync widened or excluded the retained Docker start window');
        $samples=$systems[$ouuid]['cached_samples'];
        check(count($samples)===4 && $systems[$ouuid]['uptime_cache_ages']===[], 'Full cached sequence was not exercised across sync');
        foreach ($samples as $i=>$sample) {
            $apparentStart=$sample['at']-$sample['uptime']/1000;
            check(abs($apparentStart-$dockerStart-6)<0.3, 'Fixture did not model cache-age windows around -4 / +6 seconds');
            if($i>0)check($sample['uptime']>$samples[$i-1]['uptime'], 'Cached fixture uptime was not increasing');
        }
        unset($systems[$ouuid]['uptime_cache_ages'],$systems[$ouuid]['cached_samples']);
        // The same retained pending proof must refuse a genuinely restarted instance BEFORE sync.
        $orphanOp->refresh(); $orphanOp->update(['context'=>$pending]); $systems[$ouuid]['configuration']['suspended']=true;
        $savedBoot=$systems[$ouuid]['boot_started']; $systems[$ouuid]['boot_started']=microtime(true); $start=count($requests);
        try {
            check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',$syncArgs)!==0, 'CLI sync-only apply accepted a restarted instance');
            check(str_contains(\Illuminate\Support\Facades\Artisan::output(),'uptime decreased'), 'Sync-only failure did not detect the actual restarted instance');
            check(!array_filter(array_slice($requests,$start),fn($r)=>str_ends_with($r[1],'/sync')) && $orphanOp->fresh()->context['running_original_sync_pending'], 'Restart rejection sent sync or lost pending state');
            check(!$forbidden(array_slice($requests,$start)), 'Restart rejection issued power/restore/file writes');
        } finally { $systems[$ouuid]['boot_started']=$savedBoot; }
        echo "CLI Docker sync-only: +/-2ms receipt, cached -4/+6s windows, actual sync and restart-before-sync rejection passed\n";
        // Same state transition, but using facts captured automatically by Boot during this exact attempt.
        $resetOrphan(); $ctx=$orphanOp->context; $bootId=$orphanOp->id.'-old'; $facts=new \Arcturus\ModpackManager\Native\StartupEvidence;
        foreach(explode("\n",$crucibleBootLog()) as $line) $facts->line($line);
        $boot=$ctx['native_boot_attempts'][$bootId];
        $ctx['native_boot_attempts'][$bootId]['console']=[...$facts->proof(),'source'=>'wings_console','first_observed_at'=>$boot['accepted_at']+0.01,'last_observed_at'=>$boot['accepted_at']+0.02,'instance'=>$boot['instance']];
        $orphanOp->update(['context'=>$ctx]); $start=count($requests);
        check($verifier->run($orphanOp->id)['verified'] && $verifier->run($orphanOp->id,true,1)['applied'] && !$forbidden(array_slice($requests,$start)), 'Retained Wings boot evidence did not survive missing latest.log header');
        echo "GTNH headerless latest.log: audited operator dry run/apply, retained Wings proof and safe sync retry passed\n";
    } finally {
        $syncFail=false; unset($systems[$ouuid]['uptime_cache_ages'],$systems[$ouuid]['cached_samples']); $systems[$ouuid]['data']['logs/latest.log']=$savedLog;
        unlink($evidenceDir.'/metadata.json'); unlink($evidenceDir.'/console.log'); rmdir($evidenceDir);
    }
    // Several HTTP requests return the same resource sample, including immediately after sync.
    $resetOrphan(); $start=count($requests);
    $systems[$ouuid]['sampled_uptime'] = (microtime(true)-$systems[$ouuid]['boot_started'])*1000;
    $systems[$ouuid]['uptime_repeat'] = 4; $systems[$ouuid]['sampled_sync'] = 'plateau';
    $sampled=$verifier->run($orphanOp->id,true,1);
    $samples=$systems[$ouuid]['post_sync_samples'];
    check($sampled['applied'] && count($samples)>=4 && $samples[0]===$samples[1] && $samples[1]===$samples[2] && $samples[3]>$samples[2], 'Post-sync identity did not wait through repeated resource samples');
    check(!$forbidden(array_slice($requests,$start)) && !$orphanOp->fresh()->context['running_original_sync_pending'], 'Sampled finalization mutated or failed to release the original');
    unset($systems[$ouuid]['sampled_sync'],$systems[$ouuid]['post_sync_samples']);
    $resetOrphan(); $savedBoot=$systems[$ouuid]['boot_started']; $start=count($requests);
    $systems[$ouuid]['sampled_sync']='restart';
    rejects(fn()=>$verifier->run($orphanOp->id,true,1), 'A genuine restart during sync accepted');
    check($orphanOp->fresh()->phase==='restored' && $orphanOp->fresh()->context['running_original_sync_pending'] && !$forbidden(array_slice($requests,$start)), 'Sync identity failure lost the pending receipt or powered/restored the server');
    rejects(fn()=>$verifier->run($orphanOp->id,true,1,true), 'Sync retry accepted a restarted instance with the old Done log');
    check(!$forbidden(array_slice($requests,$start)), 'Rejected sync retry performed power/restore/file writes');
    $systems[$ouuid]['boot_started']=$savedBoot; unset($systems[$ouuid]['sampled_sync']);
    echo "Sampled running-original inspection and post-sync plateau/restart fixtures passed\n";
    $resetOrphan(); $start=count($requests);
    $foreign=\Illuminate\Support\Facades\Cache::lock('arcturus-modpack-manager-deploy-'.$orphan->id,14400); check($foreign->get(),'Cannot acquire foreign test lease');
    rejects(fn()=>$verifier->run($orphanOp->id,true,1),'Foreign lease accepted');
    $orphanOp->update(['phase'=>'checking_recovery','completed_at'=>null]);
    app(\Arcturus\ModpackManager\Native\RecoveryVerification::class)->run($orphanOp);
    (new \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery($orphanOp->id))->failed(null);
    $foreign->release(); check(count($requests)===$start && $orphanOp->fresh()->phase==='checking_recovery','Failed lease acquisition stopped or changed another worker server');
    $resetOrphan();
    $staleJob = new \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery($orphanOp->id);
    $orphanOp->update(['phase'=>'recovery_queued','completed_at'=>null,'context'=>[...$orphanOp->context,'native_execution_id'=>bin2hex(random_bytes(16))]]);
    $newAttemptContext = $orphanOp->getRawOriginal('context'); $start=count($requests);
    $staleJob->handle(app(\Arcturus\ModpackManager\Services\PelicanPowerController::class)); $staleJob->failed(null);
    check(count($requests)===$start && $orphanOp->fresh()->phase==='recovery_queued' && $orphanOp->fresh()->getRawOriginal('context')===$newAttemptContext,'Stale job or failure callback took over a newer recovery');
    foreach (['callback','runtime','backup','instance','archive'] as $bad) {
        $resetOrphan(); $savedContext=$orphanOp->context; $savedLog=$systems[$ouuid]['data']['logs/latest.log']; $savedBoot=$systems[$ouuid]['boot_started']; $savedArchive=$systems[$ouuid]['data']['logs/.arcturus-before-'.$orphanOp->id.'-old.log'];
        if($bad==='callback')$orphanOp->update(['context'=>[...$savedContext,'native_restore_confirmation'=>[...$savedContext['native_restore_confirmation'],'callback_id'=>999999]]]);
        if($bad==='backup')$orphanOp->update(['context'=>[...$savedContext,'backup_uuid'=>(string)Str::uuid()]]);
        if($bad==='instance')$systems[$ouuid]['boot_started']=microtime(true);
        if($bad==='archive')unset($systems[$ouuid]['data']['logs/.arcturus-before-'.$orphanOp->id.'-old.log']);
        if($bad==='runtime')$systems[$ouuid]['data']['logs/latest.log']=str_replace('Crucible','stock Forge',$savedLog);
        $start=count($requests); rejects(fn()=>$verifier->run($orphanOp->id,true,1),'Bad proof accepted: '.$bad);
        check(!$forbidden(array_slice($requests,$start)) && $orphanOp->fresh()->phase==='manual_intervention','Rejected evidence changed server state');
        $systems[$ouuid]['data']['logs/latest.log']=$savedLog; $systems[$ouuid]['boot_started']=$savedBoot; $systems[$ouuid]['data']['logs/.arcturus-before-'.$orphanOp->id.'-old.log']=$savedArchive;
    }
    $resetOrphan(); $start=count($requests);
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\VerifyRunningOriginal);
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',['operation'=>$orphanOp->id])===0,'CLI default inspection failed');
    check($orphanOp->fresh()->phase==='manual_intervention' && !$forbidden(array_slice($requests,$start)),'CLI default inspection mutated recovery');
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:verify-running-original',['operation'=>$orphanOp->id,'--apply'=>true])!==0,'CLI apply accepted no root actor');
    rejects(fn()=>$verifier->run($orphanOp->id,true,1,true),'Sync-only closed an unverified recovery');
    $orphan->pelicanServer->update(['status'=>\App\Enums\ServerState::Suspended]);
    rejects(fn()=>$verifier->run($orphanOp->id,true,1),'Administrator suspension was overridden');
    check($orphan->pelicanServer->fresh()->isSuspended(),'Administrator suspension changed');
    $orphan->pelicanServer->update(['status'=>null]);
    $resetOrphan(); $syncFail=true; $start=count($requests);
    rejects(fn()=>$verifier->run($orphanOp->id,true,1),'Sync failure unexpectedly succeeded');
    check($orphanOp->fresh()->phase==='restored' && $orphanOp->fresh()->context['running_original_sync_pending'] && $orphan->fresh()->active_operation_id===null,'Sync failure lost the verified recovery receipt');
    $syncFail=false; $retry=$verifier->run($orphanOp->id,true,1,true);
    check($retry['applied'] && !$orphanOp->fresh()->context['running_original_sync_pending'] && !$forbidden(array_slice($requests,$start)),'Sync retry restarted or restored the server');
    echo "Running original finalization: new/legacy proof, dry run, foreign lease, failures and sync-only retry passed\n";

    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
    $mode = 'success'; Auth::guard()->setUser($adminUser); $precheck = $make(false);
    $precheckPlan = app(PreviewService::class)->generate($precheck);
    $precheckOp = app(DeploymentApproval::class)->queue($precheck, $precheckPlan['plan_sha256'], (string) Str::uuid());
    $mode = 'prebackup_failure'; $requestStart = count($requests);
    Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
    check($precheckOp->fresh()->phase === 'manual_intervention' && $systems[$precheck->pelican_server_uuid]['state'] === 'offline', 'Pre-backup mismatch did not retain the stopped checkpoint');
    check(! array_filter(array_slice($requests, $requestStart), fn ($r) => str_ends_with($r[1], '/backup') || str_ends_with($r[1], '/files/write')), 'Failed pre-backup verification reached backup or file writes');
    $precheckHistory = \Arcturus\ModpackManager\ConsoleIntegration\ConsoleHistory::read($precheck->id, null)['lines'];
    check(str_contains(json_encode($precheckHistory), 'Verifying files before backup'), 'New verification phase missing from console history');
    echo "Pre-backup verification failure retained guard without backup or writes\n";

    // Exact failed backup blocks staging and every game-file mutation.
    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
    $mode = 'success'; Auth::guard()->setUser($adminUser); $badBackup = $make(false); $badUuid = $badBackup->pelican_server_uuid;
    $badOriginal = $systems[$badUuid]['data']; $badPlan = app(PreviewService::class)->generate($badBackup);
    $badOp = app(DeploymentApproval::class)->queue($badBackup, $badPlan['plan_sha256'], (string) Str::uuid());
    $mode = 'backup_failed'; $requestStart = count($requests);
    Auth::guard()->forgetUser(); app('queue.worker')->runNextJob('deployment-fixture', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0, timeout: 0));
    check($badOp->fresh()->phase === 'manual_intervention' && $systems[$badUuid]['data'] === $badOriginal, 'Failed backup changed installation');
    check(! array_filter(array_slice($requests, $requestStart), fn ($r) => str_ends_with($r[1], '/files/write') || str_ends_with($r[1], '/files/rename')), 'Failed backup reached staging/live mutation');
    $budgetFault = null;
    Operation::saved(function (Operation $row) use (&$budgetFault): void {
        if ($budgetFault !== null && ($row->context['native_checkpoint']['step'] ?? null) === $budgetFault) {
            $budgetFault = null; \Arcturus\ModpackManager\Tests\FixtureClock::advance(3721);
        }
    });
    foreach (['before_apply', 'upload_batch_verified', 'retire_batch_intent'] as $boundary) {
        Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
        $mode = 'success'; Auth::guard()->setUser($adminUser); $timed = $make(false); $timedUuid = $timed->pelican_server_uuid;
        $timedPlan = app(PreviewService::class)->generate($timed);
        $timedOp = app(DeploymentApproval::class)->queue($timed, $timedPlan['plan_sha256'], (string) Str::uuid());
        // Invoke this exact approved job directly; retire its queued copy afterwards.
        DB::table('jobs')->delete();
        $budgetFault = $boundary === 'before_apply' ? null : $boundary;
        $timedJob = new DeployManagedServer($timed->id, false, $timedPlan['plan_sha256'], $timedOp->id);
        if ($boundary === 'before_apply') { $timedJob->timeout = 100; }
        $requestStart = count($requests); Auth::guard()->forgetUser();
        app()->call([$timedJob, 'handle']);
        $timedOp->refresh();
        check($timedOp->phase === 'completed' && $systems[$timedUuid]['data']['config/options.cfg'] === 'new' && isset($systems[$timedUuid]['data']['config/added.cfg']), 'Healthy verified work was stopped by elapsed reserve time: '.$timedOp->error);
        check(($timedOp->context['native_apply_budget']['mode'] ?? null) === 'per-request' && ! isset($timedOp->context['native_apply_budget']['apply_deadline']), 'Cumulative reserve deadline leaked into native deployment');
        $gameRenames = array_filter(array_slice($requests, $requestStart), fn ($r) => str_ends_with($r[1], '/files/rename') && array_filter($r[3], fn ($move) => str_starts_with($move['from'], 'config/') || str_starts_with($move['to'], 'config/')));
        check($gameRenames !== [], 'Verified deployment never reached its approved renames');
        check(! array_filter(array_slice($requests, $requestStart), fn ($r) => str_contains($r[1], '/restore')), 'Elapsed reserves triggered restore');
    }
    Http::swap(new \Illuminate\Http\Client\Factory); Http::fake($wings);
    $mode = 'success'; Auth::guard()->setUser($adminUser); $fenced = $make(false); $fencedUuid = $fenced->pelican_server_uuid;
    $fencedPlan = app(PreviewService::class)->generate($fenced);
    $fencedOp = app(DeploymentApproval::class)->queue($fenced, $fencedPlan['plan_sha256'], (string) Str::uuid());
    DB::table('jobs')->delete(); Auth::guard()->forgetUser(); $mode = 'ownership_lost'; $requestStart = count($requests);
    rejects(fn () => app()->call([new DeployManagedServer($fenced->id, false, $fencedPlan['plan_sha256'], $fencedOp->id), 'handle']), 'Worker continued after ownership loss');
    $fencedOp->refresh(); $fencedCalls = array_slice($requests, $requestStart);
    check($fencedOp->phase === 'applying' && $fencedOp->getRawOriginal('context') === $foreignReceiptCipher, 'Old worker overwrote the newer execution receipt');
    check(count(array_filter($fencedCalls, fn ($r) => str_ends_with($r[1], '/files/write'))) === 1 && ! array_filter($fencedCalls, fn ($r) => str_contains($r[1], '/restore') || str_ends_with($r[1], '/files/rename')), 'Old worker wrote another member or took over recovery');
    check($systems[$fencedUuid]['state'] === 'offline' && $systems[$fencedUuid]['data']['config/options.cfg'] === 'old', 'Ownership loss released guard or changed live game files');
    echo "Backup failure blocked all writes; verified checkpoints continued without cumulative boot/recovery reserve rollback\n";

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

    $leased = $make(false);
    $leasedOp = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($leased, 'deploy', ['protocol' => \Arcturus\ModpackManager\Native\Qualification::PROTOCOL]);
    $leasedOp->update(['phase' => 'applying']);
    $foreignLease = \Illuminate\Support\Facades\Cache::lock('arcturus-modpack-manager-deploy-'.$leased->id, 14400); check($foreignLease->get(), 'Cannot acquire fixture lease');
    $requestStart = count($requests);
    (new DeployManagedServer($leased->id, false, 'fixture', $leasedOp->id))->failed(null);
    check(count($requests) === $requestStart && $leasedOp->fresh()->phase === 'applying', 'Old failure callback took over a held lease');
    $foreignLease->release();

    // Unknown context.Background archive jobs cannot be cleared by ordinary recovery or a bare CLI flag.
    $uncertain = $make(false); $uncertainUuid = $uncertain->pelican_server_uuid;
    $pending = app(\Arcturus\ModpackManager\Services\OperationTracker::class)->create($uncertain, 'deploy', [
        'protocol' => \Arcturus\ModpackManager\Native\Qualification::PROTOCOL, 'native_execution_id' => 'fixture-execution',
        'native_archive_pending' => true, 'native_checkpoint' => ['step' => 'bundle_intent', 'archive_pending' => true]]);
    app(\Arcturus\ModpackManager\Services\OperationTracker::class)->transition($pending, 'manual_intervention');
    $systems[$uncertainUuid]['state'] = 'offline'; $systems[$uncertainUuid]['configuration']['suspended'] = true;
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\ConfirmArchiveQuiesced);
    $args = ['operation' => $pending->id]; $requestStart = count($requests); $cipher = $pending->fresh()->getRawOriginal('context');
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:confirm-archive-quiesced', $args) === 0, 'Archive read-only inspection failed');
    $proof = hash('sha256', $pending->id."\n".$cipher);
    check($pending->fresh()->getRawOriginal('context') === $cipher, 'Archive dry run modified evidence');
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:confirm-archive-quiesced', [...$args, '--apply' => true, '--confirm' => $proof]) !== 0, 'Archive uncertainty cleared without restart attestation');
    check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:confirm-archive-quiesced', [...$args, '--apply' => true, '--daemon-restarted' => true, '--confirm' => $proof]) === 0, 'Exact archive clearance failed');
    check($pending->fresh()->context['native_archive_pending'] === false && $pending->phase === 'manual_intervention', 'Archive clearance released operation maintenance');
    check(! array_filter(array_slice($requests, $requestStart), fn ($r) => $r[0] !== 'GET'), 'Archive clearance mutated Wings');
    echo "Unknown archive clearance: read-only default, restart attestation, exact receipt proof and retained maintenance passed\n";

    // Native Schedules bridge: real task handler, no-update restart, and prepared update.
    \App\Models\User::retrieved(function ($user): void {
        $user->setRelation('roles', new \Illuminate\Database\Eloquent\Collection([(new \App\Models\Role)->forceFill(['name' => \App\Models\Role::ROOT_ADMIN])]));
    });
    $offlineAuto = $make(false); $offlineAutoUuid = $offlineAuto->pelican_server_uuid;
    $offlineAuto->update(['policy' => 'auto', 'deploy_enabled' => true, 'automation_actor_id' => 1]);
    $systems[$offlineAutoUuid]['state'] = 'exited'; $systems[$offlineAutoUuid]['omit_uptime'] = true;
    unset($systems[$offlineAutoUuid]['data']['logs/latest.log']);
    $offlineAutoPlan = app(PreviewService::class)->generate($offlineAuto);
    check($offlineAutoPlan['applicable'], 'Offline automation fixture did not produce a valid manual plan');
    $requestStart = count($requests);
    try {
        app(DeploymentApproval::class)->queueAutomatic($offlineAuto);
        throw new LogicException('Offline manual plan bypassed the automatic boot requirement');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'Automatic native deployment requires'), 'Offline automation failed for an unrelated reason: '.$error->getMessage());
    }
    check($offlineAuto->fresh()->active_operation_id === null && count($requests) === $requestStart && $systems[$offlineAutoUuid]['starts'] === 0, 'Rejected offline automation dispatched work or powered the server');

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
