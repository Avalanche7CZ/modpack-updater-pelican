<?php

declare(strict_types=1);

$app = require __DIR__.'/bootstrap.php';

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Modpacks\JsonClient;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
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
config(['arcturus-modpack-manager.curseforge_api_key' => 'fixture-api-key']);
$app->instance(JsonClient::class, new class extends JsonClient
{
    public function get(string $url, array $headers = []): array
    {
        check(($headers['x-api-key'] ?? '') === 'fixture-api-key', 'CurseForge API request omitted the protected credential');
        preg_match('~/mods/(\d+)/files/(\d+)$~', $url, $parts);
        if (! $parts) {
            throw new RuntimeException('Unexpected provider request');
        }
        $pack = $parts[1] === '123';

        return ['data' => ['modId' => (int) $parts[1], 'id' => (int) $parts[2], 'isAvailable' => true,
            'fileName' => $pack ? 'client.zip' : 'mod-'.$parts[1].'.jar', 'displayName' => 'Exact release',
            'downloadUrl' => 'https://edge.forgecdn.net/files/'.$parts[2].'/artifact',
            'fileLength' => 10, 'hashes' => [['algo' => 1, 'value' => str_repeat('a', 40)]]]];
    }
});
$managed = ManagedServer::create(['label' => 'CurseForge fixture', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'provider' => 'curseforge', 'source' => '123', 'installed_version' => '999', 'enabled' => false, 'backup_mode' => 'pelican']);
$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080,
    'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
$server = new Server;
$server->forceFill(['uuid' => $managed->pelican_server_uuid, 'startup' => 'java -jar server.jar', 'image' => 'java_17']);
$egg = new Egg;
$egg->forceFill(['name' => 'Custom Forge']);
$server->setRelation('node', $node);
$server->setRelation('egg', $egg);
$managed->setRelation('pelicanServer', $server);
config(['arcturus-modpack-manager.nodes.'.$server->uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('a', 64)]]);
$tracker = app(OperationTracker::class);
$operation = $tracker->create($managed, 'baseline', ['provider' => 'curseforge', 'source' => '123', 'version' => '999']);
$requirements = ['manifestType' => 'minecraftModpack', 'manifestVersion' => 1, 'files' => []];
$upstreamFiles = [];
for ($i = 1; $i <= 4; $i++) {
    $requirements['files'][] = ['projectID' => 1000 + $i, 'fileID' => 2000 + $i, 'required' => true];
    $upstreamFiles['mods/mod-'.(1000 + $i).'.jar'] = hash('sha256', 'upstream-'.$i);
}
$upstream = ['schema' => 2, 'provider' => 'curseforge', 'project' => '123', 'version' => '999',
    'verification' => 'upstream-checksums', 'files' => $upstreamFiles, 'identities' => [], 'runtime' => ['runtime_prepared' => false],
    'artifacts' => [['sha256' => str_repeat('b', 64), 'hashes' => ['sha1' => str_repeat('a', 40)]]]];
$submitted = false;
Http::preventStrayRequests();
Http::fake(function ($request) use ($operation, $requirements, $upstream, $upstreamFiles, &$submitted) {
    check(! str_contains($request->body(), 'fixture-api-key'), 'Provider API credential leaked to a node request');
    $url = $request->url();
    if (str_ends_with($url, '/capabilities')) {
        return Http::response(['curseforge_resolution' => true]);
    }
    if (str_ends_with($url, '/prepare')) {
        return Http::response(['operation_id' => $operation->node_operation_id,
            'state' => $submitted ? 'completed' : 'awaiting_resolution', 'requirements_sha256' => str_repeat('c', 64),
            'manifest_sha256' => str_repeat('d', 64)]);
    }
    if (str_ends_with($url, '/requirements')) {
        return Http::response(['manifest' => $requirements, 'sha256' => str_repeat('c', 64)]);
    }
    if (str_ends_with($url, '/resolve')) {
        check(count($request['files']) === 4, 'Node received an incomplete required-file resolution');
        $submitted = true;

        return Http::response(['operation_id' => $operation->node_operation_id, 'state' => 'downloading']);
    }
    if (str_ends_with($url, '/manifest')) {
        return Http::response($upstream);
    }
    if (str_ends_with($url, '/inventory')) {
        return Http::response(['complete' => true, 'scope' => 'managed-pack-files',
            'excluded_roots' => ['world'], 'notice' => 'Fixture inventory', 'identities' => [], 'files' => $upstreamFiles]);
    }
    if (str_contains($url, 'wings.fixture.test')) {
        return Http::response(['state' => 'running'], 200, ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)']);
    }
    throw new RuntimeException('Unexpected node operation');
});
$preparer = app(PrepareRelease::class);
check(! $preparer->tick($managed, $operation), 'Preparation completed before resolving every required file');
$operation = Operation::findOrFail($operation->id);
check(count($operation->context['required_files']['files']) === 3 && ! $submitted, 'Resolution checkpoint was not persisted');
check(! $preparer->tick($managed, $operation), 'Node download submission was treated as completed preparation');
$operation = Operation::findOrFail($operation->id);
check($preparer->tick($managed, $operation), 'Completed node manifest was not reconciled');
check(ManagedServer::findOrFail($managed->id)->baseline_status === 'verified', 'Exact upstream baseline was not stored');
foreach (Http::recorded() as [$request, $response]) {
    check(! preg_match('~/power|/reinstall|files/write|files/delete|/backup~', $request->url()), 'Preparation mutated the live server');
}
$cancelled = $tracker->create($managed, 'baseline', ['provider' => 'curseforge', 'source' => '123', 'version' => '999']);
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(function ($request) use ($cancelled) {
    if (str_ends_with($request->url(), '/capabilities')) {
        return Http::response(['curseforge_resolution' => true]);
    }

    return Http::response(['operation_id' => $cancelled->node_operation_id, 'state' => 'cancelled', 'error' => 'Cancelled fixture']);
});
check($preparer->tick($managed, $cancelled), 'Confirmed cancellation did not terminate the queue tick');
check(Operation::findOrFail($cancelled->id)->phase === 'cancelled', 'Confirmed cancellation was reported as success/failure');
echo "CurseForge staged resolution survives queue checkpoints, waits for node verification and keeps credentials on the Panel\n";
$GLOBALS['arcturus_integration_passed'] = true;
