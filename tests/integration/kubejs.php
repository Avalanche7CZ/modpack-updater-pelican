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
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\ViewManagedServer;
use Arcturus\ModpackManager\Jobs\CompareInstallation;
use Arcturus\ModpackManager\Services\DeploymentGate;
use Arcturus\ModpackManager\Services\KubeJsDiff;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ProtectionPolicy;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
DB::table('servers')->insert(['uuid' => $uuid, 'name' => 'TFG fixture']);
$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
Server::retrieved(fn (Server $server) => $server->setRelation('node', $node));
$path = 'kubejs/server_scripts/recipes "local" & upstream.js';
$local = "// customized\nevent.remove('local')\n";
$live = $local;
$upstream = "// updated upstream\nevent.remove('upstream')\n";
$hash = fn ($text) => hash('sha256', $text);
$runtime = ['loader' => 'neoforge', 'minecraft' => '1.20.1'];
$manifest = fn ($version, $text) => ['schema' => 2, 'provider' => 'custom', 'project' => 'fixture', 'version' => $version,
    'verification' => 'upstream-checksums', 'files' => [$path => $hash($text)], 'runtime' => $runtime,
    'artifacts' => [['sha256' => $hash($text), 'hashes' => ['sha256' => $hash($text)]]]];
$store = app(ReleaseStore::class);
$server = ManagedServer::create(['label' => 'TFG KubeJS', 'pelican_server_uuid' => $uuid, 'provider' => 'custom', 'source' => 'fixture',
    'installed_version' => '1', 'selected_version' => '2', 'baseline_status' => 'verified', 'transport' => 'wings', 'adopted_at' => now(),
    'base_manifest_path' => $store->put($manifest('1', '// old')), 'target_manifest_path' => $store->put($manifest('2', $upstream)),
    'target_metadata' => ['prepared' => ['node_operation_id' => str_repeat('b', 32)]],
    'runtime_report' => $runtime, 'file_rules' => (new ProtectionPolicy)->template('tfg_arcturus')]);
config(['arcturus-modpack-manager.nodes.'.$uuid => ['url' => 'https://worker.fixture.test', 'token' => str_repeat('x', 64)],
    'queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'retry_after' => 14520]]);
Bus::fake([CompareInstallation::class]);
Http::preventStrayRequests();
$reads = 0;
Http::fake(function ($request) use ($path, $hash, &$live, &$upstream, &$reads) {
    $url = $request->url();
    if (str_ends_with($url, '/inventory')) {
        return Http::response(['complete' => true, 'files' => [$path => $hash($live)], 'identities' => [], 'metadata' => [], 'excluded_roots' => ['world']], 200);
    }
    if (str_ends_with($url, '/stage_file')) { $reads++; return Http::response(['content' => $upstream, 'sha256' => $hash($upstream)], 200); }
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    if (str_contains($url, '/files/list-directory')) {
        return Http::response([['name' => basename($path), 'file' => true, 'directory' => false, 'symlink' => false, 'size' => strlen($live)]], 200, $headers);
    }
    if (str_contains($url, '/files/contents')) { $reads++; return Http::response($live, 200, $headers); }
    throw new RuntimeException('Unexpected HTTP operation: '.$url);
});
$plan = app(PreviewService::class)->generate($server);
check($plan['blocked'] === 1 && ! $plan['applicable'], 'Locally customized upstream change was not blocked');
$test = Livewire\Livewire::test(ViewManagedServer::class, ['record' => $server->id])->call('selectTab', 'changes');
check(str_contains($test->html(), 'Keep local') && str_contains($test->html(), 'Use upstream') && ! str_contains($test->html(), 'Review text merge'), 'KubeJS decision buttons missing or merge UI offered');
// Inspect real rendered expressions, including a filename containing quotes and ampersands.
$dom = new DOMDocument;
@$dom->loadHTML('<meta charset="utf-8">'.$test->html());
$buttons = (new DOMXPath($dom))->query('//button[starts-with(@*[name()="wire:click"], "resolve(")]');
check($buttons->length === 2 && str_contains($buttons->item(1)->getAttribute('wire:click'), 'replace'), 'Native decision expressions did not render');
$test->call('reviewFileDiff', $path);
check($reads === 2 && str_contains($test->html(), '-// customized') && str_contains($test->html(), '+// updated upstream'), 'Native diff did not read verified snapshots');
check(! str_contains($test->html(), 'event.remove(&#039;local&#039;)event.remove'), 'Script contents concatenated');
$server->update(['approved_plan_sha256' => str_repeat('a', 64), 'approved_at' => now()]);
$test->call('resolve', $path, 'protect');
$server->refresh();
check(! $server->approved_plan_sha256 && $server->active_operation_id && ! $server->deploy_enabled, 'Keep local did not invalidate approval and queue comparison');
$job = new CompareInstallation($server->id, $server->active_operation_id);
app()->call([$job, 'handle']);
$test->call('$refresh');
check($server->refresh()->preview_summary['changes'][0]['action'] === 'preserve', 'Keep local button did not produce a preserving plan');
// Exercise the other choice through the same public action used by the rendered button.
$server->update(['file_rules' => (new ProtectionPolicy)->template('tfg_arcturus')]);
app(PreviewService::class)->generate($server);
$test->call('$refresh');
check(str_contains($test->html(), 'Use upstream'), 'Use upstream not available for a fresh conflict');
$test->call('resolve', $path, 'replace');
$server->refresh();
app()->call([new CompareInstallation($server->id, $server->active_operation_id), 'handle']);
check($server->refresh()->preview_summary['changes'][0]['action'] === 'replace_with_override', 'Use upstream button did not produce upstream replacement');
check(! $server->preview_summary['applicable'], 'Decision bypassed deployment qualification');
rejects(fn () => (new DeploymentGate)->require($server, null), 'KubeJS decisions enabled deployment');
$upstream = 'stage tampered';
rejects(fn () => app(KubeJsDiff::class)->review($server, $path), 'Changed upstream bytes accepted by diff');
$live = 'changed after comparison';
rejects(fn () => app(KubeJsDiff::class)->review($server, $path), 'Changed local bytes accepted by diff');
rejects(fn () => app(KubeJsDiff::class)->review($server, 'kubejs/playerdata/private.js'), 'Protected persistent data read');
rejects(fn () => app(KubeJsDiff::class)->review($server, 'kubejs/assets/template.nbt'), 'NBT offered as editable text');
check(collect(Http::recorded())->every(fn ($pair) => $pair[0]->method() === 'GET' || str_ends_with($pair[0]->url(), '/inventory') || str_ends_with($pair[0]->url(), '/stage_file')), 'Review wrote to live files');
echo "Native KubeJS diff/decisions, queued replanning, snapshot validation and disabled deployment passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
