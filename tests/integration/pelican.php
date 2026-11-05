<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen(dirname(__DIR__, 2).'/src/'));
    $class = 'Arcturus\\ModpackManager\\'.str_replace('/', '\\', substr($relative, 0, -4));
    check(class_exists($class) || interface_exists($class) || trait_exists($class), 'Failed loading '.$class);
}
echo "All plugin classes load against Pelican rc.1 and its locked Filament version\n";
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Remote\NodeWorker;
use Arcturus\ModpackManager\Remote\WingsFiles;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PluginSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

Schema::create('webhook_configurations', function (Blueprint $t): void {
    $t->id();
    $t->string('scope');
    $t->json('events');
    $t->softDeletes();
});
// Existing cosmii02 data is deliberately outside Arcturus migrations.
Schema::create('modpack_installs', fn (Blueprint $t) => $t->string('sentinel'));
DB::table('modpack_installs')->insert(['sentinel' => 'reference-plugin-data']);
$migrations = [];
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) {
    $migration = require $path;
    $migration->up();
    $migrations[] = $migration;
}
check(DB::table('modpack_installs')->value('sentinel') === 'reference-plugin-data', 'Another plugin table was modified');
$managed = ManagedServer::create(['label' => 'Customized GTNH', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'provider' => 'github', 'source' => 'GTNewHorizons/GT-New-Horizons-Modpack', 'installed_version' => '2.8.0',
    'backup_mode' => 'pelican', 'backup_excluded_directories' => ['backup', 'backups']]);
check($managed->refresh()->baseline_status === 'unverified' && $managed->transport === 'wings', 'Upgrade defaults falsely verify existing servers');
$tracker = new OperationTracker;
$op = $tracker->create($managed, 'baseline', ['version' => '2.8.0', 'recipe' => ['secret-example' => 'encrypted-context']]);
rejects(fn () => $tracker->create($managed, 'prepare'), 'Concurrent operation accepted');
check(! str_contains(DB::table('arcturus_modpack_operations')->value('context'), 'encrypted-context'), 'Operation context was stored without encryption');
$tracker->transition($op, 'downloading');
check(Operation::find($op->id)->phase === 'downloading', 'Phase was not persisted across model reload');
$tracker->transition($op, 'completed');
check($managed->refresh()->active_operation_id === null, 'Terminal operation left the server locked');
rejects(fn () => $tracker->transition($op, 'applying'), 'Completed operation was replayed');
$recovery = $tracker->create($managed, 'deploy');
$tracker->transition($recovery, 'manual_intervention');
check($managed->refresh()->active_operation_id === $recovery->id, 'Uncertain destructive recovery released the operation lock');
rejects(fn () => $tracker->create($managed, 'prepare'), 'New work began over an uncertain deployment');
echo "Installation/upgrade migrations, coexistence, durable progress and duplicate-job guards passed\n";

// Native model/repository objects, fake HTTP boundary. No node is contacted.
$node = new Node;
$node->forceFill(['fqdn' => 'wings.fixture.test', 'scheme' => 'https', 'daemon_connect' => 8080,
    'daemon_token_id' => 'fixture', 'daemon_token' => 'fixture-token']);
$server = new Server;
$server->forceFill(['uuid' => $managed->pelican_server_uuid]);
$server->setRelation('node', $node);
$headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
config(['arcturus-modpack-manager.nodes.'.$server->uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('a', 64)]]);
Http::preventStrayRequests();
Http::fake([
    'https://wings.fixture.test:8080/api/servers/*/files/list-directory*' => Http::response([
        ['name' => 'server.properties', 'file' => true, 'directory' => false, 'symlink' => false, 'size' => 9, 'mode' => '-rw-r--r--', 'mode_bits' => '644'],
    ], 200, $headers),
    'https://wings.fixture.test:8080/api/servers/*/files/contents*' => Http::response('level=x\n', 200, $headers),
    'https://worker.fixture.test/v1/servers/*/hashes' => Http::response(['files' => ['mods/a.jar' => hash('sha256', 'a')], 'missing' => ['mods/missing.jar']]),
]);
$files = new WingsFiles($server, app(DaemonFileRepository::class), new NodeWorker($server->uuid));
check($files->stat('server.properties')['mode_bits'] === '644', 'Wings permission metadata was lost');
check($files->read('server.properties') === 'level=x\n', 'Bounded Wings file read failed');
check(count($files->hashes(['mods/a.jar', 'mods/missing.jar'])) === 1, 'Remote hashes were not parsed');
rejects(fn () => $files->hashes(['mods/a.jar', 'mods/unreported.jar']), 'Partial hashing response accepted');
rejects(fn () => $files->read('../secret'), 'Unsafe remote path accepted');
rejects(fn () => $files->read('server.properties', 1), 'Oversized configuration accepted');
echo "Real Pelican daemon repository contract with mocked Wings responses passed\n";

// Verify an owner/subuser cannot call admin actions or receive saved secrets.
Auth::guard()->forgetUser();
rejects(fn () => app(PluginSettings::class)->formData(), 'Unauthorized settings read accepted');
rejects(fn () => app(PluginSettings::class)->save([]), 'Unauthorized settings mutation accepted');
// Replace auth with a tiny guard for testing the plugin's explicit root requirement.
Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool
    {
        return true;
    }
});
$settings = app(PluginSettings::class);
$settings->save(['default_policy' => 'approve', 'check_interval_hours' => 6, 'discord_events' => false,
    'default_backup_exclusions' => ['backup', 'backups'], 'max_archive_bytes' => 1048576, 'download_concurrency' => 1,
    'curseforge_api_key' => 'private-fixture-key']);
check(! str_contains(DB::table('arcturus_modpack_settings')->where('key', 'curseforge_api_key')->value('value'), 'private-fixture-key'), 'Credential not encrypted');
check($settings->formData()['curseforge_api_key'] === '', 'Saved API credential leaked into Livewire hydration');
check($settings->all()['curseforge_api_key'] === 'private-fixture-key', 'Encrypted settings did not round-trip');
echo "Encrypted plugin settings and unauthorized access guards passed\n";
foreach (array_reverse($migrations) as $migration) {
    $migration->down();
}
check(DB::table('modpack_installs')->value('sentinel') === 'reference-plugin-data', 'Migration rollback touched reference plugin data');
echo "Migration rollback leaves cosmii02 data intact\n";

$GLOBALS['arcturus_integration_passed'] = true;
