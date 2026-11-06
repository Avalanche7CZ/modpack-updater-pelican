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
use Illuminate\Support\Facades\Event;
use App\Events\ActivityLogged;
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
/**
 * Real Pelican DaemonServerRepository::power() writes activity records.
 * Supply the same audit tables it needs in this disposable in-memory DB,
 * instead of mocking out the repository's native side effects.
 */
Schema::create('activity_logs', function (Blueprint $t) {
    $t->id();
    $t->string('ip');
    $t->text('properties');
    $t->unsignedBigInteger('api_key_id')->nullable();
    $t->unsignedBigInteger('actor_id')->nullable();
    $t->string('actor_type')->nullable();
    $t->string('event');
    $t->text('description')->nullable();
    $t->timestamp('timestamp')->nullable();
});
Schema::create('activity_log_subjects', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('activity_log_id');
    $t->unsignedBigInteger('subject_id');
    $t->string('subject_type');
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
use Arcturus\ModpackManager\Jobs\DeployManagedServer;
use Arcturus\ModpackManager\Jobs\SendDiscordNotification;
use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\PreviewService;
use Arcturus\ModpackManager\Services\ReleaseStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$dir = sys_get_temp_dir().'/arcturus-discord-deploy-'.bin2hex(random_bytes(6));
foreach (['live/config', 'stages/pack/config', 'backups'] as $path) {
    mkdir($dir.'/'.$path, 0700, true);
}
file_put_contents($dir.'/live/config/example.properties', 'value=old');
file_put_contents($dir.'/stages/pack/config/example.properties', 'value=new');
$uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
DB::table('servers')->insert(['uuid' => $uuid, 'name' => 'Disposable fixture']);
$node = new Node;
$node->forceFill(['scheme' => 'https', 'fqdn' => 'wings.fixture.test', 'daemon_connect' => 8080, 'daemon_token' => 'fixture-token', 'daemon_token_id' => 'fixture']);
Server::retrieved(fn (Server $server) => $server->setRelation('node', $node));
$hash = fn ($content) => hash('sha256', $content);
$runtime = ['loader' => 'forge', 'loader_version' => '47.0', 'minecraft' => '1.20.1', 'java' => 17, 'java_confidence' => 'verified', 'runtime_prepared' => true];
$manifest = fn ($version, $content) => ['schema' => 2, 'provider' => 'ftb', 'project' => '129', 'version' => $version, 'verification' => 'upstream-checksums',
    'files' => ['config/example.properties' => $hash($content)], 'runtime' => $runtime,
    'artifacts' => [['sha256' => $hash($content), 'hashes' => ['sha256' => $hash($content)]]]];
$store = app(ReleaseStore::class);
$managed = ManagedServer::create(['label' => 'TFG Modern fixture', 'pelican_server_uuid' => $uuid, 'provider' => 'ftb', 'source' => '129',
    'installed_version' => '0.13.5', 'selected_version' => '0.13.17', 'baseline_status' => 'verified', 'adopted_at' => now(), 'runtime_report' => $runtime,
    'base_manifest_path' => $store->put($manifest('0.13.5', 'value=old')), 'target_manifest_path' => $store->put($manifest('0.13.17', 'value=new')),
    'transport' => 'local', 'local_root' => $dir.'/live', 'prepared_root' => $dir.'/stages/pack', 'staging_parent' => $dir.'/stages', 'backup_root' => $dir.'/backups',
    'backup_mode' => 'filesystem', 'enabled' => true, 'deploy_enabled' => true, 'discord_enabled' => true,
    'discord_notify_events' => ['updated'], 'target_metadata' => ['release_url' => 'https://github.com/fixture/pack/releases/tag/0.13.17']]);
config(['arcturus-modpack-manager.local_server_roots.'.$uuid => $dir.'/live', 'arcturus-modpack-manager.enable_deploy' => true,
    'arcturus-modpack-manager.discord_events' => true, 'arcturus-modpack-manager.discord_webhook' => 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('x', 64),
    'queue.default' => 'fixture', 'queue.connections.fixture.driver' => 'database']);
Queue::fake();
Http::preventStrayRequests();
$preview = app(PreviewService::class)->generate($managed);
check($preview['applicable'], 'Disposable deployment preflight did not pass');
$managed->update(['approved_plan_sha256' => $preview['plan_sha256'], 'approved_at' => now()]);
$state = 'exited';
$powerCalls = [];
Http::fake(function ($request) use (&$state, &$powerCalls, $dir) {
    check(str_contains($request->url(), 'wings.fixture.test'), 'Update process attempted to contact Discord synchronously');
    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:fixture)'];
    if (str_ends_with($request->url(), '/power')) {
        $powerCalls[] = $request['action'];
        if ($request['action'] === 'start') {
            check(! DiscordDelivery::where('kind', 'updated')->exists(), 'Success queued before startup verification');
            $state = 'running';
            mkdir($dir.'/live/logs');
            file_put_contents($dir.'/live/logs/latest.log', "Done (0.05s)!\n");
        } else {
            $state = 'exited';
        }

        return Http::response([], 200, $headers);
    }

    return Http::response(['state' => $state], 200, $headers);
});
// Native Pelican power actions write their audit rows. The ordinary Panel
// ActivityLogged webhook listener is unrelated to Discord queue validation,
// and would require a complete Pelican server-webhook configuration schema.
// Isolate only that downstream listener in this disposable integration test.
Event::fake([ActivityLogged::class]);

// A queued deployment is executed without a logged-in panel user.
// This also avoids introducing a non-canonical anonymous test User morph.
Auth::guard()->forgetUser();
app()->call([new DeployManagedServer($managed->id, false, $preview['plan_sha256']), 'handle']);
$managed->refresh();
$delivery = DiscordDelivery::where('kind', 'updated')->first();
check($managed->installed_version === '0.13.17' && $managed->last_success_at && $delivery && $delivery->status === 'queued', 'Verified update did not queue one success');
check($powerCalls === ['start', 'stop'] && $state === 'exited', 'Fixture power state was not restored');
// rc.1 and rc.2 differ in native activity persistence. Exact start/stop
// requests are asserted above; audit persistence is outside this Discord
// integration test and is covered by Pelican's own test suite.
check($delivery->sourceEvent->details['startup_verified'] === true && $delivery->sourceEvent->from_version === '0.13.5', 'Verified success evidence/old version missing');
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fake(fn () => Http::response(null, 500));
(new SendDiscordNotification($delivery->id))->handle(app(DiscordNotifier::class));
check($delivery->refresh()->status === 'uncertain' && $managed->refresh()->installed_version === '0.13.17' && ! $managed->last_error && $managed->last_success_at,
    'Discord failure changed the successful update result');
check(! $managed->events()->where('status', 'failed')->whereIn('kind', ['deploy', 'rollback'])->exists(), 'Discord failure triggered rollback/failure');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($dir);
echo "Actual offline deployment queues only after fresh startup verification; Discord failure cannot trigger rollback\n";
$GLOBALS['arcturus_integration_passed'] = true;
