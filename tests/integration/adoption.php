<?php

declare(strict_types=1);
$app = require __DIR__.'/bootstrap.php';

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Services\AdoptionService;
use Arcturus\ModpackManager\Services\BaselineGuard;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

Schema::create('webhook_configurations', function (Blueprint $t): void {
    $t->id();
    $t->string('scope');
    $t->json('events');
    $t->softDeletes();
});
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') as $path) {
    (require $path)->up();
}
$source = 'https://recipes.fixture.test/custom.json';
$original = hash('sha256', 'upstream');
$local = hash('sha256', 'customized');
$app->instance(JsonClient::class, new class($original) extends JsonClient
{
    public function __construct(private string $sha) {}

    public function get(string $url, array $headers = []): array
    {
        return ['schema' => 2, 'version' => '1.0', 'downloads' => [['artifact_id' => 'fixture', 'path' => 'config/a.properties', 'hashes' => ['sha256' => $this->sha]]]];
    }
});
$managed = ManagedServer::create(['label' => 'Existing customized server', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'provider' => 'custom', 'source' => $source, 'installed_version' => '1.0', 'enabled' => false, 'backup_mode' => 'pelican']);
$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080,
    'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$server = new Server;
$server->forceFill(['uuid' => $managed->pelican_server_uuid, 'startup' => 'bash startserver.sh', 'image' => 'java_17']);
$egg = new Egg;
$egg->forceFill(['name' => 'Customized Crucible']);
$server->setRelation('node', $node);
$server->setRelation('egg', $egg);
$managed->setRelation('pelicanServer', $server);
config(['arcturus-modpack-manager.nodes.'.$server->uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('a', 64)]]);
$tracker = app(OperationTracker::class);
$operation = $tracker->create($managed, 'baseline', ['provider' => 'custom', 'source' => $source, 'version' => '1.0']);
$upstream = ['schema' => 2, 'provider' => 'custom', 'project' => $source, 'version' => '1.0',
    'verification' => 'upstream-checksums', 'files' => ['config/a.properties' => $original], 'identities' => [],
    'runtime' => [], 'artifacts' => [['sha256' => $original, 'hashes' => ['sha256' => $original]]]];
Http::preventStrayRequests();
Http::fake(function ($request) use ($operation, $upstream, $local) {
    $url = $request->url();
    if (str_ends_with($url, '/prepare')) {
        return Http::response(['operation_id' => $operation->node_operation_id, 'state' => 'completed', 'manifest_sha256' => hash('sha256', json_encode($upstream))]);
    }
    if (str_ends_with($url, '/manifest')) {
        return Http::response($upstream);
    }
    if (str_ends_with($url, '/inventory')) {
        return Http::response(['complete' => true, 'scope' => 'managed-pack-files',
            'excluded_roots' => ['world'], 'notice' => 'Fixture point-in-time scan', 'identities' => [],
            'files' => ['config/a.properties' => $local, 'mods/local-extra.jar' => hash('sha256', 'local extra')]]);
    }
    if (str_contains($url, 'wings.fixture.test')) {
        return Http::response(['state' => 'running'], 200, ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)']);
    }
    throw new RuntimeException('Unexpected request during adoption');
});
check(app(PrepareRelease::class)->tick($managed, $operation), 'Exact baseline preparation did not complete');
$managed = ManagedServer::findOrFail($managed->id);
$managed->setRelation('pelicanServer', $server);
check($managed->baseline_status === 'verified', 'Upstream baseline was not verified');
check(($managed->adoption_report['counts']['preserve_local_change'] ?? 0) === 1, 'Customized config not identified');
check(($managed->adoption_report['counts']['preserve_local_extra'] ?? 0) === 1, 'Local-only mod not identified');
check(! $managed->adopted_at, 'Preparation silently finalized adoption');
$stored = app(ReleaseStore::class)->load($managed->base_manifest_path);
check($stored['files']['config/a.properties'] === $original && $stored['files']['config/a.properties'] !== $local,
    'The upstream baseline was replaced with the customized live file');
rejects(fn () => (new BaselineGuard)->require($stored, 'custom', $source, 'wrong-version'),
    'A wrong installed version was accepted for adoption');
app(AdoptionService::class)->finish($managed);
check(ManagedServer::findOrFail($managed->id)->adopted_at !== null && ! $managed->deploy_enabled, 'Verified adoption did not preserve the deployment gate');
foreach (Http::recorded() as [$request,$response]) {
    check(! preg_match('~/power|/reinstall|files/write|files/delete|/backup~', $request->url()), 'Adoption mutated the live server');
}
$adopted = ManagedServer::findOrFail($managed->id);
$priorBaseline = $adopted->base_manifest_path;
$beforeRequests = count(Http::recorded());
rejects(fn () => app(AdoptionService::class)->reassociate($adopted, '2.0'), 'An unauthorized user changed installed release identity');
Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool
    {
        return true;
    }
});
$active = $tracker->create($adopted, 'prepare');
rejects(fn () => app(AdoptionService::class)->reassociate($adopted, '2.0'), 'Installed release changed during an active operation');
$tracker->transition($active, 'cancelled');
$adopted->update(['approved_plan_sha256' => str_repeat('f', 64), 'approved_at' => now(), 'file_rules' => [['pattern' => 'config/custom/**', 'mode' => 'protect']]]);
app(AdoptionService::class)->reassociate($adopted, '2.0');
$updated = ManagedServer::findOrFail($managed->id);
check($updated->installed_version === '2.0' && $updated->baseline_status === 'unverified', 'Manual update retained the old verified release identity');
check(! $updated->adopted_at && ! $updated->enabled && ! $updated->deploy_enabled && ! $updated->approved_plan_sha256 && ! $updated->base_manifest_path,
    'Manual association retained approvals or enabled deployment');
check(count($updated->file_rules) === 1 && is_file($priorBaseline), 'Manual association lost protection rules or deleted upstream evidence');
check(count(Http::recorded()) === $beforeRequests, 'Recording a manual update contacted or mutated the node');
echo "Adoption orchestration uses independent upstream baseline, reports customizations and sends no destructive requests\n";
$GLOBALS['arcturus_integration_passed'] = true;
