<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Remote\NodeWorker;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\FilesystemBackendSettings;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\QueuePreparation;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

Schema::create('webhook_configurations', function (Blueprint $t): void {
    $t->id(); $t->string('scope'); $t->json('events'); $t->softDeletes();
});
Schema::create('servers', function (Blueprint $t): void {
    $t->id(); $t->string('uuid');
});
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) {
    (require $path)->up();
}
$dir = sys_get_temp_dir().'/arcturus-local-'.bin2hex(random_bytes(8));
mkdir($dir, 0700);
foreach (['live', 'stage', 'live/config', 'live/mods', 'live/world'] as $path) {
    mkdir($dir.'/'.$path, 0700);
}
file_put_contents($dir.'/live/config/custom.cfg', 'customized');
file_put_contents($dir.'/live/mods/Paradigm.jar', 'preserved paradigm');
file_put_contents($dir.'/live/mods/ArcturusTokens.jar', 'preserved tokens');
file_put_contents($dir.'/live/world/level.dat', 'untouched world');
$source = 'https://recipes.fixture.test/local.json';
$zip = new ZipArchive;
$zip->open($dir.'/pack.zip', ZipArchive::CREATE);
$zip->addFromString('config/custom.cfg', 'upstream');
$zip->addFromString('mods/base.jar', 'upstream jar');
$zip->close();
$recipe = ['downloads' => [['artifact_id' => 'fixture', 'archive' => true, 'hashes' => ['sha256' => hash_file('sha256', $dir.'/pack.zip')]]], 'format' => 'zip'];
$app->instance(JsonClient::class, new class($recipe) extends JsonClient {
    public function __construct(private array $recipe) {}

    public function get(string $url, array $headers = []): array
    {
        return ['schema' => 2, 'releases' => [
            ['version' => '1.0', ...$this->recipe], ['version' => '2.0', ...$this->recipe],
        ]];
    }
});
$record = ManagedServer::create(['label' => 'Same-host fixture', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'provider' => 'custom', 'source' => $source, 'installed_version' => '1.0', 'available_version' => '2.0',
    'backup_mode' => 'pelican', 'file_rules' => [['pattern' => 'mods/Paradigm.jar', 'mode' => 'protect'], ['pattern' => 'mods/ArcturusTokens.jar', 'mode' => 'protect']]]);
$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080,
    'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$server = new Server;
$server->forceFill(['uuid' => $record->pelican_server_uuid, 'startup' => 'bash startserver.sh', 'image' => 'java_17']);
$server->setRelation('node', $node);
$server->setRelation('egg', (new Egg)->forceFill(['name' => 'Existing egg']));
$record->setRelation('pelicanServer', $server);
$factory = app(ServerFilesFactory::class);
check($factory->worker($record) instanceof NodeWorker, 'Default remote behavior changed');
rejects(fn () => (new LocalWorker($record))->request('capabilities'), 'Missing local mapping accepted');
$mapping = ['root' => $dir.'/live', 'staging' => $dir.'/stage', 'artifacts' => ['fixture' => $dir.'/pack.zip']];
config(['arcturus-modpack-manager.local_servers' => [$record->pelican_server_uuid => $mapping]]);
rejects(fn () => app(FilesystemBackendSettings::class)->save($record, 'local'), 'Guest selected local filesystem');
Auth::guard()->setUser(new class extends User {
    public function isRootAdmin(): bool { return true; }
});
$settings = app(FilesystemBackendSettings::class);
$rules = $record->file_rules;
$settings->save($record, 'local');
$record->refresh()->setRelation('pelicanServer', $server);
check($factory->worker($record) instanceof LocalWorker, 'Explicit local selection did not select local helper');
check($record->local_root === $dir.'/live' && $record->staging_parent === $dir.'/stage', 'Validated paths were not pinned');
check($record->file_rules === $rules && ! $record->deploy_enabled, 'Backend selection modified policies or enabled deployment');
check($factory->worker($record)->request('capabilities')['configuration_ready'] === true, 'Local permission preflight failed');
Bus::fake([PrepareUpdate::class]);
$queue = app(QueuePreparation::class);
rejects(fn () => $queue->queue($record, true), 'Synchronous or short-visibility queue accepted');
check(Bus::dispatched(PrepareUpdate::class)->isEmpty(), 'Doomed preparation was dispatched');
config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'retry_after' => 14520]]);
chmod($dir.'/stage', 0755);
rejects(fn () => $queue->queue($record, true), 'Public staging accepted or doomed preparation queued');
check(Bus::dispatched(PrepareUpdate::class)->isEmpty(), 'Doomed preparation was dispatched');
chmod($dir.'/stage', 0700);
$queue->queue($record, true);
check(Bus::dispatched(PrepareUpdate::class)->contains(fn ($job) => $job->timeout > LocalWorker::TIMEOUT && $job->operationId !== null), 'Local job timeout or durable operation ID missing');
$record->refresh()->setRelation('pelicanServer', $server);
rejects(fn () => $settings->save($record, 'wings'), 'Backend changed while an operation was active');
$operation = $record->operations()->findOrFail($record->active_operation_id);
Http::preventStrayRequests();
Http::fake(function ($request) {
    check(str_contains($request->url(), 'wings.fixture.test'), 'Local preparation contacted a remote worker');
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    return Http::response(str_contains($request->url(), '/files/list-directory') ? [] : ['state' => 'running'], 200, $headers);
});
check(app(PrepareRelease::class)->tick($record, $operation), 'Local baseline did not complete');
$record->refresh()->setRelation('pelicanServer', $server);
$persistedProgress = $operation->fresh()->progress;
check(isset($persistedProgress['stages']['downloading'], $persistedProgress['stages']['verifying'], $persistedProgress['stages']['extracting'], $persistedProgress['stages']['hashing'], $persistedProgress['stages']['comparing']), 'Preparation phases did not survive a model refresh');
check($persistedProgress['current']['phase'] === 'comparing', 'Inventory comparison did not report progress');
check(\Arcturus\ModpackManager\Services\PreparationProgress::percentage(['phase' => 'downloading', 'bytes_done' => 100, 'bytes_total' => null, 'files_done' => 1, 'files_total' => 1]) === null, 'Unknown download total fabricated a percentage');
check(\Arcturus\ModpackManager\Services\PreparationProgress::percentage(['phase' => 'downloading', 'bytes_done' => 50, 'bytes_total' => 100]) === 50.0, 'Known byte total progress is incorrect');
check($record->baseline_status === 'verified' && $record->baseline_provenance['transport'] === 'local', 'Local baseline provenance not verified');
$manifest = app(ReleaseStore::class)->load($record->base_manifest_path);
check($manifest['files']['config/custom.cfg'] === hash('sha256', 'upstream'), 'Live customization substituted for upstream baseline');
check($record->file_inventory['files'] === [] && $record->file_inventory['requested_paths'] === [], 'Adoption scanned unrelated local mods');
check(! isset($record->file_inventory['files']['world/level.dat']), 'World entered local inventory');
$tracker = app(OperationTracker::class);
$target = $tracker->create($record, 'prepare', ['provider' => 'custom', 'source' => $source, 'version' => '2.0']);
check(app(PrepareRelease::class)->tick($record, $target), 'Local target preparation did not complete');
$record->refresh()->setRelation('pelicanServer', $server);
check(str_starts_with($record->prepared_root, 'local:'), 'Local stage confused with deployable legacy root');
$preview = app(PreviewService::class)->generate($record);
check(! $preview['applicable'] && $record->file_rules === $rules, 'Local preparation bypassed deployment gate or rewrote rules');
rejects(fn () => (new DeploymentGate)->require($record, null), 'New local backend enabled deployment');
foreach (['config/custom.cfg' => 'customized', 'mods/Paradigm.jar' => 'preserved paradigm', 'mods/ArcturusTokens.jar' => 'preserved tokens', 'world/level.dat' => 'untouched world'] as $path => $content) {
    check(file_get_contents($dir.'/live/'.$path) === $content, 'Live file modified: '.$path);
}
$mapping['root'] = $dir.'/live/../live';
config(['arcturus-modpack-manager.local_servers.'.$record->pelican_server_uuid => $mapping]);
rejects(fn () => $factory->worker($record)->request('inventory'), 'Changed or noncanonical mapping accepted');
check($server->startup === 'bash startserver.sh' && $server->image === 'java_17', 'Runtime settings changed');
// All created files are test-only; preserve failure evidence, clean only after success.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
rmdir($dir);
echo "Local stdio baseline/target preparation, verified manifests, inventory, queue/preflight, policy preservation and remote defaults passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
