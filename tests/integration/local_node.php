<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\User;
use Arcturus\ModpackManager\Console\Commands\DescribeLocalNode;
use Arcturus\ModpackManager\Filament\Admin\Pages\LocalFilesystem;
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\CreateManagedServer;
use Arcturus\ModpackManager\Local\LocalNode;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\ModpackManagerPlugin;
use Arcturus\ModpackManager\Remote\NodeWorker;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Services\PluginSettings;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Schema::create('webhook_configurations', function (Blueprint $t): void {
    $t->id(); $t->string('scope'); $t->json('events'); $t->softDeletes();
});
Schema::create('nodes', function (Blueprint $t): void {
    $t->id(); $t->string('uuid'); $t->string('name'); $t->string('daemon_base');
});
Schema::create('servers', function (Blueprint $t): void {
    $t->id(); $t->string('uuid'); $t->string('name'); $t->integer('node_id');
    $t->string('startup')->default('bash startserver.sh'); $t->string('image')->default('java_21');
});
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) {
    (require $path)->up();
}
$dir = sys_get_temp_dir().'/arcturus-node-'.bin2hex(random_bytes(8));
mkdir($dir, 0700); mkdir($dir.'/volumes', 0700); mkdir($dir.'/stages', 0700);
$uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$bad = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$future = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
mkdir($dir.'/volumes/'.$uuid, 0700);
mkdir($dir.'/volumes/'.$other, 0700); // A matching directory cannot make a remote-node server eligible.
symlink($dir.'/volumes/'.$uuid, $dir.'/volumes/'.$bad);
DB::table('nodes')->insert([
    ['id' => 1, 'uuid' => $uuid, 'name' => 'Local node', 'daemon_base' => $dir.'/volumes'],
    ['id' => 2, 'uuid' => $other, 'name' => 'Remote node', 'daemon_base' => $dir.'/volumes'],
]);
foreach ([[$uuid, 1], [$other, 2], [$bad, 1], [$future, 1]] as [$id, $node]) {
    DB::table('servers')->insert(['uuid' => $id, 'node_id' => $node, 'name' => $id]);
}
$local = new LocalNode($dir.'/approval.json', fileowner($dir));
$app->instance(LocalNode::class, $local);
check(! $local->status()['ready'], 'Missing host approval passed');
$zip = new ZipArchive;
$zip->open($dir.'/fixture.zip', ZipArchive::CREATE);
$zip->addFromString('config/example.cfg', 'verified upstream');
$zip->close();
$approval = ['schema' => 1, 'node_id' => 1, 'node_uuid' => $uuid, 'volume_root' => $dir.'/volumes',
    'staging_parent' => $dir.'/stages', 'uid' => fileowner($dir), 'allowed_hosts' => ['github.com'], 'artifacts' => ['fixture' => $dir.'/fixture.zip']];
file_put_contents($dir.'/approval.json', json_encode($approval)); chmod($dir.'/approval.json', 0644);
config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'retry_after' => 90]]);
check($local->status()['ready'], 'Host readiness failed');
check(config('queue.connections.database.retry_after') === 14520, 'Host queue visibility was not configured without env edits');
check(count($local->status()['servers']) === 3, 'Remote-node server appeared in discovery');
check(! is_dir($dir.'/stages/'.$uuid), 'Readiness created per-server staging');
rejects(fn () => $local->entry($other), 'Foreign node accepted despite matching directory');
rejects(fn () => $local->entry('../'.$uuid), 'Untrusted path accepted as UUID');
rejects(fn () => $local->entry($bad), 'Symlink UUID volume accepted');
rejects(fn () => $local->entry($future), 'Missing UUID directory accepted');
mkdir($dir.'/volumes/'.$future, 0755);
check($local->entry($future)['root'] === $dir.'/volumes/'.$future, 'Future database server required a per-server mapping');
$before = file_get_contents($dir.'/approval.json');
DB::table('nodes')->where('id', 2)->update(['daemon_base' => $dir.'/stages']);
check(! $local->status()['ready'], 'Staging overlapping another node root accepted');
DB::table('nodes')->where('id', 2)->update(['daemon_base' => $dir.'/volumes']);
file_put_contents($dir.'/approval.json', '{malformed');
check(! $local->status()['ready'], 'Malformed host approval accepted');
file_put_contents($dir.'/approval.json', $before);
symlink($dir.'/approval.json', $dir.'/approval-link.json');
check(! (new LocalNode($dir.'/approval-link.json', fileowner($dir)))->status()['ready'], 'Symlink host approval accepted');
unlink($dir.'/approval-link.json');
DB::table('nodes')->where('id', 1)->update(['daemon_base' => $dir.'/volumes/elsewhere']);
rejects(fn () => $local->entry($uuid), 'Changed daemon root accepted');
DB::table('nodes')->where('id', 1)->update(['daemon_base' => $dir.'/volumes']);
chmod($dir.'/approval.json', 0666); clearstatcache();
check(! $local->status()['ready'], 'Writable host approval accepted');
chmod($dir.'/approval.json', 0644); clearstatcache();
check(! (new LocalNode($dir.'/approval.json', fileowner($dir) + 1))->status()['ready'], 'Wrong approval owner accepted');
$app->make(Illuminate\Contracts\Console\Kernel::class)->registerCommand(app(DescribeLocalNode::class));
Artisan::call('arcturus-modpack:describe-local-node', ['node' => 1]);
$snapshot = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
check($snapshot['node_uuid'] === $uuid && count($snapshot['servers']) === 3 && $snapshot['active_operations'] === 0, 'Unprivileged metadata export failed');
check(! str_contains(Artisan::output(), 'daemon_token'), 'Metadata export included node credentials');
Auth::guard()->setUser(new class extends User {
    public function isRootAdmin(): bool { return true; }
    public function checkPermissionTo($permission, $guardName = null): bool { return true; }
});
$admin = Filament::getPanel('admin');
(new ModpackManagerPlugin)->register($admin); Filament::setCurrentPanel($admin);
app('view')->addNamespace('arcturus-modpack-manager', dirname(__DIR__, 2).'/resources/views');
foreach (['index', 'create', 'view', 'edit'] as $page) {
    Route::get('/fixture/'.$page.'/{record?}', fn () => 'fixture')->name('filament.admin.resources.arcturus-managed-modpacks.'.$page);
}
$screen = Livewire\Livewire::test(LocalFilesystem::class);
check(str_contains($screen->html(), 'One-time Ubuntu host setup'), 'Native setup screen did not render');
chmod($dir.'/approval.json', 0666); clearstatcache();
$screen->call('mountAction', 'localDefault')->call('callMountedAction');
check(config('arcturus-modpack-manager.default_filesystem_backend') === 'wings', 'Stale UI readiness bypassed protected-approval recheck');
chmod($dir.'/approval.json', 0644); clearstatcache();
$screen->call('refreshReadiness');
$screen->call('mountAction', 'localDefault')->call('callMountedAction');
check(config('arcturus-modpack-manager.default_filesystem_backend') === 'local', 'Native local-default action failed');
check((new PluginSettings)->all()['default_filesystem_backend'] === 'local', 'Default was not persisted');
// Runtime inspection is unrelated to provisioning; isolate it without contacting Wings.
$app->instance(Arcturus\ModpackManager\Services\AdoptionService::class, new class {
    public function inspect(App\Models\Server $server): array { return ['inspection_complete' => false, 'warnings' => ['Fixture runtime unavailable']]; }
});
$wizard = Livewire\Livewire::test(CreateManagedServer::class);
$data = ['pelican_server_uuid' => $uuid, 'label' => 'Local adopted server', 'provider' => 'custom',
    'source' => 'https://recipes.fixture.test/pack.json', 'installed_version' => '0.13.5', 'backup_mode' => 'pelican', 'adoption_mode' => 'draft',
    'file_rules' => (new Arcturus\ModpackManager\Services\ProtectionPolicy)->normalize([['pattern' => 'mods/Paradigm.jar', 'mode' => 'protect']]), 'disabled_mods' => [],
    'backup_excluded_directories' => ['backup', 'backups'], 'filesystem_backend' => 'default'];
$wizard->set('data', $data)->call('create');
$record = ManagedServer::where('pelican_server_uuid', $uuid)->first();
check($record !== null && $record->transport === 'local', 'Native default adoption did not choose local');
check($record->local_root === $dir.'/volumes/'.$uuid && $record->staging_parent === $dir.'/stages/'.$uuid, 'Adoption did not derive and pin UUID paths');
check(is_dir($record->staging_parent) && (fileperms($record->staging_parent) & 0077) === 0, 'Adoption did not create private staging');
check($record->baseline_status === 'unverified' && ! $record->deploy_enabled && count($record->file_rules) === 1, 'Provisioning bypassed verification or altered policies');
check(app(ServerFilesFactory::class)->worker($record) instanceof LocalWorker, 'Existing local backend not reused');
check((new LocalWorker($record))->request('capabilities')['configuration_ready'], 'Discovered local preparation backend unavailable');

$app->forgetInstance(Arcturus\ModpackManager\Services\AdoptionService::class);
$app->instance(Arcturus\ModpackManager\Modpacks\JsonClient::class, new class(hash_file('sha256', $dir.'/fixture.zip')) extends Arcturus\ModpackManager\Modpacks\JsonClient {
    public function __construct(private string $hash) {}
    public function get(string $url, array $headers = []): array {
        return ['schema' => 2, 'version' => '0.13.5', 'format' => 'zip',
            'downloads' => [['artifact_id' => 'fixture', 'archive' => true, 'hashes' => ['sha256' => $this->hash]]]];
    }
});
$native = App\Models\Server::where('uuid', $uuid)->firstOrFail();
$daemon = (new App\Models\Node)->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080,
    'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$native->setRelation('node', $daemon);
$native->setRelation('egg', (new App\Models\Egg)->forceFill(['name' => 'Existing runtime']));
$record->setRelation('pelicanServer', $native);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(fn ($request) => Illuminate\Support\Facades\Http::response(
    str_contains($request->url(), '/files/list-directory') ? [] : ['state' => 'running'], 200,
    ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)']));
$operation = app(Arcturus\ModpackManager\Services\OperationTracker::class)->create($record, 'baseline',
    ['provider' => $record->provider, 'source' => $record->source, 'version' => $record->installed_version]);
check(app(Arcturus\ModpackManager\Services\PrepareRelease::class)->tick($record, $operation), 'Discovered server baseline preparation did not complete');
$record = ManagedServer::findOrFail($record->id);
check($record->baseline_status === 'verified' && $record->baseline_provenance['transport'] === 'local', 'Automatically provisioned baseline was not verified');
$manifest = app(Arcturus\ModpackManager\Services\ReleaseStore::class)->load($record->base_manifest_path);
check($manifest['files']['config/example.cfg'] === hash('sha256', 'verified upstream'), 'Automatically provisioned baseline lost upstream checksum verification');
check(! $record->deploy_enabled && count($record->file_rules) === 1, 'Baseline changed deployment restrictions or saved policies');

$remote = $local->adoptionAttributes($other, 'default');
check($remote === ['transport' => 'wings'], 'Local default changed remote-node selection');
check(app(ServerFilesFactory::class)->worker(new ManagedServer(['transport' => 'wings', 'pelican_server_uuid' => $other])) instanceof NodeWorker, 'Remote worker behavior changed');
check(file_get_contents($dir.'/approval.json') === $before, 'Web actions changed privileged host approval');
Auth::guard()->forgetUser();
check(! LocalFilesystem::canAccess(), 'Guest can access filesystem setup');
rejects(fn () => $screen->instance()->refreshReadiness(), 'Guest refreshed privileged status');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $file) { $file->isLink() || ! $file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname()); }
rmdir($dir);
echo "Node-wide trusted discovery, private staging, native default/adoption, host approval, queue visibility and remote isolation passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
