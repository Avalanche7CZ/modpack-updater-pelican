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
echo "All plugin classes load against Pelican rc.2 and its locked Filament version\n";
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
    if (str_ends_with($path, '2026_11_06_000000_add_server_policy_inventory.php')) {
        DB::table('arcturus_modpack_servers')->insert(['label' => 'Upgrade sentinel', 'pelican_server_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'provider' => 'ftb', 'source' => '129', 'installed_version' => '1',
            'file_rules' => json_encode([['pattern' => 'config/**', 'mode' => 'protect']]), 'disabled_mods' => json_encode(['legacy_disabled']), 'approved_plan_sha256' => str_repeat('a', 64)]);
    }
    $migration = require $path;
    $migration->up();
    $migrations[] = $migration;
}
check(DB::table('arcturus_modpack_servers')->where('label', 'Upgrade sentinel')->value('file_rules') === json_encode([['pattern' => 'config/**', 'mode' => 'protect']]), 'Upgrade overwrote saved rules');
check(DB::table('arcturus_modpack_servers')->where('label', 'Upgrade sentinel')->value('disabled_mods') === json_encode(['legacy_disabled']), 'Upgrade overwrote disabled mods');
check(DB::table('arcturus_modpack_servers')->where('label', 'Upgrade sentinel')->value('approved_plan_sha256') === null, 'Upgrade retained obsolete plan approval');
DB::table('arcturus_modpack_servers')->where('label', 'Upgrade sentinel')->delete();
check(DB::table('modpack_installs')->value('sentinel') === 'reference-plugin-data', 'Another plugin table was modified');
$managed = ManagedServer::create(['label' => 'Customized GTNH', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'provider' => 'github', 'source' => 'GTNewHorizons/GT-New-Horizons-Modpack', 'installed_version' => '2.8.0',
    'backup_mode' => 'pelican', 'backup_excluded_directories' => ['backup', 'backups']]);
check($managed->refresh()->baseline_status === 'unverified' && $managed->transport === 'wings', 'Upgrade defaults falsely verify existing servers');
$tracker = new OperationTracker;
$op = $tracker->create($managed, 'baseline', ['version' => '2.8.0', 'recipe' => ['secret-example' => 'encrypted-context']]);
rejects(fn () => $tracker->create($managed, 'prepare'), 'Concurrent operation accepted');
check(! str_contains(DB::table('arcturus_modpack_operations')->value('context'), 'encrypted-context'), 'Operation context was stored without encryption');
// Runtime/file receipts routinely exceed TEXT after encryption. Reload the exact bytes.
$largeContext = [...$op->context, 'fixture_receipt' => str_repeat('verified-recovery-evidence-', 6000)];
$op->update(['context' => $largeContext]);
check(strlen(DB::table('arcturus_modpack_operations')->where('id', $op->id)->value('context')) > 65535,
    'Large receipt fixture did not exceed the production truncation threshold');
check($op->fresh()->context === $largeContext, 'Large encrypted operation receipt did not survive database reload');
// Simulate a non-strict MySQL node that has not applied the widening migration.
// The model must reject the write before it ever asks for a PDO connection.
$originalResolver = Operation::getConnectionResolver();
$oldMysql = new class(null, 'fixture', '', ['driver' => 'mysql']) extends \Illuminate\Database\MySqlConnection {
    public function getSchemaBuilder() {
        return new class($this) extends \Illuminate\Database\Schema\MySqlBuilder {
            public function getColumnType($table, $column, $fullDefinition = false) { return 'text'; }
        };
    }
};
$resolver = new \Illuminate\Database\ConnectionResolver(['fixture-mysql' => $oldMysql]);
$resolver->setDefaultConnection('fixture-mysql');
try {
    Operation::setConnectionResolver($resolver);
    $oversized = new Operation;
    $oversized->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'context' => $largeContext]);
    try {
        $oversized->save();
        throw new RuntimeException('Oversized receipt was accepted by old MySQL TEXT');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'old database column limit'), 'Receipt guard failed after reaching SQL instead of before it');
    }
} finally { Operation::setConnectionResolver($originalResolver); }
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

// Pelican's supported install/upgrade seeder hook requests a graceful worker exit.
require_once dirname(__DIR__, 2).'/database/Seeders/ArcturusModpackManagerSeeder.php';
$pluginHook = (new \App\Models\Plugin)->forceFill(['name' => 'Arcturus Modpack Manager']);
check(ltrim($pluginHook->getSeeder() ?? '', chr(92)) === \Database\Seeders\ArcturusModpackManagerSeeder::class, 'Pelican did not discover the upgrade hook');
\Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', time() - 10);
$oldRestart = \Illuminate\Support\Facades\Cache::get('illuminate:queue:restart');
app(\App\Services\Helpers\PluginService::class)->runPluginSeeder($pluginHook);
check(\Illuminate\Support\Facades\Cache::get('illuminate:queue:restart') > $oldRestart, 'Upgrade did not request a graceful queue reload');
$shouldRestart = new ReflectionMethod(app('queue.worker'), 'queueShouldRestart');
check($shouldRestart->invoke(app('queue.worker'), $oldRestart), 'Core queue worker did not observe the reload request');
check($recovery->fresh()->phase === 'manual_intervention' && $managed->fresh()->active_operation_id === $recovery->id, 'Graceful reload changed an active recovery receipt');

// File-cache permission errors must not turn a successful install into NotInstalled.
$artisanKernel = \Illuminate\Support\Facades\Artisan::getFacadeRoot();
try {
    \Illuminate\Support\Facades\Artisan::swap(new class {
        public function call($command): int { throw new RuntimeException('file_put_contents(/private/cache): Permission denied'); }
    });
    app(\App\Services\Helpers\PluginService::class)->runPluginSeeder($pluginHook);
    \Illuminate\Support\Facades\Artisan::swap(new class {
        public function call($command): int { return 1; }
    });
    app(\App\Services\Helpers\PluginService::class)->runPluginSeeder($pluginHook);
    check($recovery->fresh()->phase === 'manual_intervention', 'Failed optional queue reload altered recovery');
} finally { \Illuminate\Support\Facades\Artisan::swap($artisanKernel); }

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
