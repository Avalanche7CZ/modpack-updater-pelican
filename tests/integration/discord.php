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
use Arcturus\ModpackManager\Filament\Admin\Resources\ManagedServers\Pages\EditManagedServer;
use Arcturus\ModpackManager\Jobs\SendDiscordNotification;
use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Arcturus\ModpackManager\Services\DiscordSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeDiscord(callable $callback): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($callback);
}

$webhook = 'https://discord.com/api/webhooks/123456789012345678/'.str_repeat('fixturetoken', 6);
config(['queue.default' => 'fixture', 'queue.connections.fixture.driver' => 'database',
    'arcturus-modpack-manager.discord_events' => true, 'arcturus-modpack-manager.discord_webhook' => $webhook]);
$realQueue = app('queue');
Queue::fake();
Http::preventStrayRequests();
$server = ManagedServer::create(['label' => 'TFG Modern', 'pelican_server_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'provider' => 'github',
    'source' => 'fixture/pack', 'installed_version' => '0.13.17', 'available_version' => '0.14.0', 'discord_enabled' => true]);
DB::table('servers')->insert(['uuid' => $server->pelican_server_uuid, 'name' => 'TFG Modern fixture']);
$server->refresh();
$notifier = app(DiscordNotifier::class);
$event = fn ($status = 'success', $verified = true, $kind = 'deploy') => $server->events()->create(['kind' => $kind, 'status' => $status,
    'from_version' => '0.13.5', 'to_version' => '0.13.17', 'details' => ['startup_verified' => $verified, 'discord_notification_pending' => true,
        'release_url' => 'https://github.com/fixture/pack/releases/tag/0.13.17']]);
check($notifier->forEvent($event('info')) === null && $notifier->forEvent($event('success', false)) === null, 'Notification sent before verified success');
$source = $event();
$delivery = $notifier->forEvent($source);
check($delivery && $delivery->status === 'queued' && Http::recorded()->count() === 0, 'Notification performed HTTP during enqueue');
$payload = $delivery->payload;
check(count($payload['embeds']) === 1 && str_contains($payload['embeds'][0]['description'], 'byl úspěšně aktualizován z `0.13.5` na `0.13.17`') &&
    str_contains($payload['embeds'][0]['description'], 'has been successfully updated from `0.13.5` to `0.13.17`'), 'Bilingual success or immutable old/new versions incorrect');
check(! str_contains(json_encode($payload), '0.14.0') && $payload['embeds'][0]['url'] === 'https://github.com/fixture/pack/releases/tag/0.13.17', 'Wrong release or notes URL');
check(str_contains($payload['embeds'][0]['footer']['text'], 'Arcturus Network') && $payload['allowed_mentions']['parse'] === [] && $payload['allowed_mentions']['roles'] === [], 'Footer or default mention suppression missing');
$second = $notifier->forEvent($source);
check($second->id === $delivery->id && DiscordDelivery::count() === 1, 'Duplicate event created another delivery');
fakeDiscord(fn () => Http::response(['id' => '223456789012345678'], 200));
$job = new SendDiscordNotification($delivery->id);
check(! str_contains(serialize($job), 'fixturetoken'), 'Queue payload exposed credentials');
$job->handle($notifier);
$job->handle($notifier);
check(Http::recorded()->count() === 1 && $delivery->refresh()->status === 'delivered', 'Duplicate queue execution sent another POST');
check($delivery->message_id === '223456789012345678' && $server->events()->where('kind', 'notification')->where('status', 'delivered')->exists(), 'Delivery receipt/history not recorded');

$server->update(['discord_role_id' => '323456789012345678']);
$server->label = '@everyone <@423456789012345678> @here';
$hostile = $source->replicate();
$hostile->from_version = '` @everyone';
$payload = $notifier->payload($server, $hostile, 'updated');
check($payload['content'] === '<@&323456789012345678>' && $payload['allowed_mentions'] === ['parse' => [], 'roles' => ['323456789012345678'], 'users' => [], 'replied_user' => false], 'Role allowlist/content incorrect');
check(! str_contains(json_encode($payload['embeds']), '@everyone') && ! str_contains(json_encode($payload['embeds']), '<@'), 'Untrusted metadata generated mentions');
$server->refresh();
$testDelivery = $notifier->test($server);
check($testDelivery->payload['content'] === '' && $testDelivery->payload['allowed_mentions']['roles'] === [] && str_contains($testDelivery->payload['embeds'][0]['description'], 'only a test'), 'Test pinged a role or claimed an update');

// 429 is known rejected; respect its fractional retry_after and persist the delay.
Http::swap(new Factory);
Http::preventStrayRequests();
Http::fakeSequence()->push(['retry_after' => 1.5, 'global' => true], 429)->push(['id' => '223456789012345679'], 200);
$rate = $notifier->forEvent($event());
(new SendDiscordNotification($rate->id))->handle($notifier);
check($rate->refresh()->status === 'retry_wait' && $rate->attempts === 1 && $rate->next_attempt_at->isFuture(), 'Rate limit not persisted');
check(str_contains($notifier->testStatus($rate), 'Rate Limited') && str_contains($notifier->testStatus($rate), 'HTTP 429'), 'Actual rate-limit status/HTTP code not shown');
(new SendDiscordNotification($rate->id))->handle($notifier);
check($rate->refresh()->attempts === 1, 'Rate-limited request retried before deadline');
Carbon::setTestNow(now()->addSeconds(3));
(new SendDiscordNotification($rate->id))->handle($notifier);
Carbon::setTestNow();
check($rate->refresh()->status === 'delivered' && $rate->attempts === 2, 'Safe rate limit retry failed');
Cache::flush();
foreach ([401 => 'failed', 403 => 'failed', 400 => 'failed', 404 => 'failed', 500 => 'uncertain', 204 => 'uncertain'] as $status => $expected) {
    fakeDiscord(fn () => Http::response(null, $status));
    $row = $notifier->forEvent($event());
    (new SendDiscordNotification($row->id))->handle($notifier);
    check($row->refresh()->status === $expected && str_contains($row->last_error, 'HTTP '.$status), 'HTTP error handling incorrect: '.$status);
    $count = Http::recorded()->count();
    (new SendDiscordNotification($row->id))->handle($notifier);
    check(Http::recorded()->count() === $count, 'Ambiguous/permanent response retried blindly');
}
fakeDiscord(fn () => throw new ConnectionException('Sensitive '.$webhook));
$row = $notifier->forEvent($event());
(new SendDiscordNotification($row->id))->handle($notifier);
check($row->refresh()->status === 'uncertain' && ! str_contains($row->last_error, 'fixturetoken'), 'Interrupted transfer leaked credentials or was retried');
$workerFailure = $notifier->test($server);
$workerFailure->update(['status' => 'sending', 'claimed_at' => now()]);
(new SendDiscordNotification($workerFailure->id))->failed(new RuntimeException('Sensitive '.$webhook));
check($workerFailure->refresh()->status === 'uncertain' && ! str_contains($workerFailure->last_error, 'fixturetoken'), 'Failed worker callback leaked exception secrets or lost status');
$server->refresh();
check($server->installed_version === '0.13.17' && ! $server->last_error, 'Notification error changed update result');

$settings = app(DiscordSettings::class);
$attributes = $settings->attributes($server, ['discord_webhook' => $webhook, 'discord_enabled' => true, 'discord_notify_events' => ['updated'], 'discord_role_id' => '323456789012345678']);
$server->update($attributes);
check(! str_contains(DB::table('arcturus_modpack_servers')->where('id', $server->id)->value('discord_webhook'), 'fixturetoken'), 'Server webhook was stored unencrypted');
check(! array_key_exists('discord_webhook', $server->toArray()) && ! str_contains(DB::table('arcturus_modpack_discord_deliveries')->where('id', $delivery->id)->value('webhook'), 'fixturetoken'), 'Serialization/outbox credential leak');
check(! isset($settings->attributes($server, ['discord_webhook' => ''])['discord_webhook']), 'Blank input erased the saved override');
check($settings->attributes($server, ['clear_discord_webhook' => true])['discord_webhook'] === null, 'Explicit clear did not restore global default');
rejects(fn () => $settings->attributes($server, ['discord_role_id' => '@everyone']), 'Unsafe role accepted');
rejects(fn () => $settings->attributes($server, ['discord_webhook' => 'https://evil.test/token']), 'Unapproved webhook accepted');
check($notifier->forEvent($event('failed'))->status === 'skipped', 'Unselected event was sent');
$server->update(['discord_enabled' => false]);
check($notifier->forEvent($event())->status === 'skipped', 'Disabled notifications sent a message');
$server->update(['discord_enabled' => true]);
$stale = $notifier->forEvent($event());
$stale->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(3)]);
$count = Http::recorded()->count();
$notifier->recover();
check($stale->refresh()->status === 'uncertain' && Http::recorded()->count() === $count, 'Restart replayed an uncertain POST');
$server->update(['discord_webhook' => null]);
$cancel = $notifier->forEvent($event());
$server->update(['discord_enabled' => false]);
(new SendDiscordNotification($cancel->id))->handle($notifier);
check($cancel->refresh()->status === 'cancelled', 'Disable did not cancel a pending notification');

// Actual native Filament hydration, unsaved tests, delivery polling and unrelated saves.
$server->update(['discord_webhook' => $webhook]);
$edit = Livewire\Livewire::test(EditManagedServer::class, ['record' => $server->id]);
check($edit->instance()->data['discord_webhook'] === $webhook && str_contains($edit->html(), 'Copy') && str_contains($edit->html(), 'Discord Notifications'), 'Admin webhook hydration/Copy control did not render');
// Action schema key is checked below from its rendered native button instead of assuming it.
$dom = new DOMDocument;
@$dom->loadHTML('<meta charset="utf-8">'.$edit->html());
$buttons = (new DOMXPath($dom))->query('//button[contains(@*[name()="wire:click"], "testDiscord")]');
check($buttons->length === 1, 'Native Test Notification button did not render');
$click = $buttons->item(0)->getAttribute('wire:click');
preg_match_all("/JSON\\.parse\\('([^']+)'\\)/", $click, $encoded);
$decode = fn ($value) => json_decode(json_decode('"'.$value.'"', true, flags: JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
$context = $decode($encoded[1][count($encoded[1]) - 1]);
$edit->set('data.clear_discord_webhook', true)->set('data.discord_role_id', '523456789012345678')->set('data.discord_notify_events', ['updated'])->call('save');
check($server->refresh()->discord_webhook === null && $server->discord_role_id === '523456789012345678' && $server->discord_notify_events === ['updated'], 'Native settings did not persist clear/role/event selection');
check($edit->instance()->data['discord_webhook'] === null && $edit->instance()->data['clear_discord_webhook'] === false, 'Secrets remained in form after saving');
$unsaved = 'https://discord.com/api/webhooks/623456789012345678/'.str_repeat('unsavedtoken', 5);
$edit->set('data.discord_webhook', $unsaved)->call('mountAction', 'testDiscord', [], $context)->call('callMountedAction');
$test = DiscordDelivery::where('kind', 'test')->latest('id')->firstOrFail();
check($test->webhook === $unsaved && $server->refresh()->discord_webhook === null, 'Native action ignored input or saved the unsaved webhook');
$edit->call('$refresh');
check(str_contains($edit->html(), 'Test #'.$test->id.': Queued') && str_contains($edit->html(), 'wire:poll.3s'), 'Queued result/polling missing');
fakeDiscord(function ($request) use ($test, $notifier, $unsaved) {
    check(str_starts_with($request->url(), $unsaved.'?wait=true'), 'Queued test did not target unsaved URL');
    check(str_contains($notifier->testStatus($test->refresh()), 'Sending'), 'Sending status not observable');
    check($request['allowed_mentions']['roles'] === [] && $request['content'] === '', 'Native test pinged a role');

    return Http::response(['id' => '723456789012345678'], 200);
});
(new SendDiscordNotification($test->id))->handle($notifier);
$edit->call('$refresh');
check(str_contains($edit->html(), 'Delivered') && str_contains($edit->html(), 'HTTP 200'), 'Native polling did not confirm actual delivery');
$edit->set('data.discord_webhook', 'https://invalid.test/secret')->call('mountAction', 'testDiscord', [], $context)->call('callMountedAction');
check(DiscordDelivery::where('kind', 'test')->count() === 3, 'Invalid entered webhook queued a test');
$server->update(['discord_webhook' => $webhook]);
$edit = Livewire\Livewire::test(EditManagedServer::class, ['record' => $server->id]);
$edit->set('data.label', 'Updated label only')->call('save');
check($server->refresh()->discord_webhook === $webhook && $edit->instance()->data['discord_webhook'] === $webhook, 'Unrelated save erased saved webhook or hid it');
$global = app(\Arcturus\ModpackManager\Services\PluginSettings::class)->formData();
check($global['discord_webhook'] === $webhook && $global['curseforge_api_key'] === '', 'Global admin hydration failed or revealed unrelated credentials');
$pluginSettings = app(\Arcturus\ModpackManager\Services\PluginSettings::class);
$pluginSettings->save($global);
check(! str_contains(DB::table('arcturus_modpack_settings')->where('key', 'discord_webhook')->value('value'), 'fixturetoken'), 'Global webhook stored in plaintext');
$global['discord_webhook'] = '';
$pluginSettings->save($global);
check(config('arcturus-modpack-manager.discord_webhook') === $webhook, 'Blank unrelated global edit erased webhook');
$fallback = $notifier->test($server, $unsaved, true);
check($fallback->webhook === $webhook, 'Remove override did not test global fallback');
foreach (['retry_wait' => 'Rate Limited', 'failed' => 'Failed', 'uncertain' => 'Uncertain'] as $state => $label) {
    $fallback->update(['status' => $state, 'last_error' => 'HTTP 429: rate limited', 'next_attempt_at' => now()->addMinute()]);
    check(str_contains($notifier->testStatus($fallback), $label), 'Missing UI status '.$label);
}
config(['queue.default' => 'sync']);
$pending = $notifier->test($server);
check($pending->status === 'queued' && str_contains($pending->last_error, 'Asynchronous queue required'), 'Sync driver diagnosis missing');
$pending->update(['created_at' => now()->subMinutes(2)]);
check(str_contains($notifier->testStatus($pending), 'over a minute'), 'Stalled worker diagnostic missing');

// Real database queue serialization, worker execution and scheduled recovery, with fake HTTPS only.
Schema::create('jobs', function (Blueprint $t) {
    $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload');
    $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
    $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
});
config(['queue.default' => 'discord-integration', 'queue.connections.discord-integration' => [
    'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
]]);
Queue::swap($realQueue);
fakeDiscord(fn () => Http::response(['id' => '823456789012345678'], 200));
DB::beginTransaction();
$edit->set('data.discord_webhook', $unsaved)->call('mountAction', 'testDiscord', [], $context)->call('callMountedAction');
$real = DiscordDelivery::where('kind', 'test')->latest('id')->firstOrFail();
check($real->webhook === $unsaved && $server->refresh()->discord_webhook === $webhook, 'Real queue test ignored form input or saved it');
check(DB::table('jobs')->count() === 0, 'Queue job escaped the outer transaction');
DB::commit();
check(DB::table('jobs')->count() === 1 && ! str_contains(DB::table('jobs')->value('payload'), 'token'), 'Real queue serialization leaked credentials or did not dispatch');
app('queue.worker')->runNextJob('discord-integration', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0));
check($real->refresh()->status === 'delivered' && DB::table('jobs')->count() === 0, 'Real queue worker did not deliver and acknowledge test');
$edit->call('$refresh');
check(str_contains($edit->html(), 'Test #'.$real->id.': Delivered') && str_contains($edit->html(), 'HTTP 200'), 'Real worker result did not reach the native Filament status');
$notifier->recover();
check(Cache::has('arcturus-discord-recovery-at'), 'Recovery execution was not observed');

// Failed queue writes remain visible and recoverable without dumping the secret exception.
config(['queue.connections.broken' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'missing_jobs', 'queue' => 'default', 'retry_after' => 90], 'queue.default' => 'broken']);
$broken = $notifier->test($server, $unsaved);
check($broken->status === 'queued' && str_contains($broken->last_error, 'Queue dispatch failed'), 'Broken connection was reported as successful dispatch');
config(['queue.default' => 'discord-integration']);
$notifier->recover();
while (DB::table('jobs')->count() > 0) {
    app('queue.worker')->runNextJob('discord-integration', 'default', new \Illuminate\Queue\WorkerOptions(sleep: 0));
}
check($broken->refresh()->status === 'delivered', 'Recovery did not hand a failed dispatch to the real worker');

// Register the real provider/scheduler and run the actual recovery command.
$pluginPath = plugin_path('arcturus-modpack-manager');
if (! is_dir(dirname($pluginPath))) { mkdir(dirname($pluginPath), 0700, true); }
if (! file_exists($pluginPath)) { symlink($source, $pluginPath); }
$app->register(\Arcturus\ModpackManager\Providers\ModpackManagerServiceProvider::class);
$events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
check(count(array_filter($events, fn ($event) => str_contains($event->command ?? '', 'arcturus-modpack:recover') && $event->expression === '*/5 * * * *')) === 1, 'Recovery scheduler was not registered every five minutes');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(new \Arcturus\ModpackManager\Console\Commands\RecoverOperations);
$damaged = \Arcturus\ModpackManager\Models\Operation::create([
    'id' => (string) \Illuminate\Support\Str::uuid(), 'managed_server_id' => $server->id,
    'kind' => 'deploy', 'phase' => 'stopping', 'heartbeat_at' => now()->subMinutes(6),
    'context' => ['fixture' => str_repeat('recovery-evidence-', 6000)]]);
$damagedBytes = substr($damaged->getAttributes()['context'], 0, 65535);
DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->update(['context' => $damagedBytes]);
$previousOperation = $server->active_operation_id;
$server->update(['active_operation_id' => $damaged->id]);
Cache::forget('arcturus-discord-recovery-at');
check(\Illuminate\Support\Facades\Artisan::call('arcturus-modpack:recover') === 0 && Cache::has('arcturus-discord-recovery-at'), 'Scheduled recovery command failed');
check(str_contains(\Illuminate\Support\Facades\Artisan::output(), $damaged->id), 'Damaged receipt was silently skipped');
$rawDamaged = DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->first();
check($rawDamaged->context === $damagedBytes && $rawDamaged->phase === 'stopping' &&
    $rawDamaged->completed_at === null && $server->fresh()->active_operation_id === $damaged->id,
    'Recovery command modified unreadable evidence or released its lock');
// Dispose only of the isolated fixture, never normalize a production receipt.
DB::table('arcturus_modpack_operations')->where('id', $damaged->id)->delete();
$server->update(['active_operation_id' => $previousOperation]);


// Actual provider discovery/checker subscriptions: current, repeated and disabled releases never POST.
Queue::fake();
$server->update(['discord_enabled' => true, 'discord_notify_events' => ['update_available'], 'available_version' => null]);
$providerJson = new class extends \Arcturus\ModpackManager\Modpacks\JsonClient
{
    public string $version = '0.15.0';

    public function get(string $url, array $headers = []): array
    {
        return ['tag_name' => $this->version, 'name' => $this->version, 'draft' => false, 'prerelease' => false,
            'html_url' => 'https://github.com/fixture/pack/releases/tag/'.$this->version, 'body' => '', 'assets' => []];
    }
};
$app->instance(\Arcturus\ModpackManager\Modpacks\JsonClient::class, $providerJson);
$checker = app(\Arcturus\ModpackManager\Services\ReleaseChecker::class);
$before = DiscordDelivery::where('kind', 'update_available')->count();
check($checker->check($server)['state'] === 'available', 'Real release discovery failed');
$checker->check($server);
check(DiscordDelivery::where('kind', 'update_available')->count() === $before + 1, 'Repeated release checks generated another notification');
$server->update(['installed_version' => $providerJson->version, 'available_version' => null]);
$checker->check($server);
check(DiscordDelivery::where('kind', 'update_available')->count() === $before + 1, 'Already installed release was notified');
$server->update(['discord_notify_events' => []]);
$providerJson->version = '0.16.0';
$checker->check($server);
check(DiscordDelivery::where('kind', 'update_available')->latest('id')->first()->status === 'skipped', 'Unsubscribed release generated notification');

Auth::guard()->setUser(new class extends User
{
    public function isRootAdmin(): bool { return false; }
});
check(! \Arcturus\ModpackManager\Filament\Forms\DiscordFields::webhook('Webhook')->isVisible(), 'Non-admin could inspect webhook field');
rejects(fn () => $notifier->test($server, $unsaved), 'Non-admin could test webhook');
rejects(fn () => app(\Arcturus\ModpackManager\Services\PluginSettings::class)->formData(), 'Non-admin could hydrate webhook');
Auth::guard()->forgetUser();
rejects(fn () => app(\Arcturus\ModpackManager\Services\PluginSettings::class)->formData(), 'Guest could inspect global webhook');
rejects(fn () => $notifier->test($server), 'Unauthorized test notification allowed');
check(! $server->events()->where('kind', 'notification')->where('message', 'like', '%fixturetoken%')->exists(), 'History exposed webhook credentials');
$critical = $event('failed'); $critical->details = [...$critical->details, 'critical' => true];
$payload = $notifier->payload($server, $critical, 'failed');
check(str_contains($payload['embeds'][0]['title'], 'Critical') && str_contains($payload['embeds'][0]['description'], 'Keep Minecraft stopped') && $payload['allowed_mentions']['parse'] === [], 'Critical recovery notification lacks actionable stopped-state instructions or mention restrictions');

$criticalOperation = (string) \Illuminate\Support\Str::uuid();
$notifier->criticalRecovery($server, 'Keep server stopped', $criticalOperation);
$notifier->criticalRecovery($server, 'Worker failure hook repeated', $criticalOperation);
check($server->events()->where('details->operation_id', $criticalOperation)->where('details->critical', true)->count() === 1, 'Repeated failure hooks duplicated a critical recovery notification');
echo "Native unsaved webhook tests, delivery polling, real queue worker/recovery, release subscriptions, encryption, mention isolation and HTTP diagnostics passed\n";
// Schedule failures before deployment enter the same deduplicated failure outbox.
$server->update(['discord_notify_events' => ['failed'], 'discord_enabled' => true]);
$scheduleFailure = $event('failed', false, 'schedule');
$scheduleAlert = $notifier->forEvent($scheduleFailure);
check($scheduleAlert && $scheduleAlert->kind === 'failed' && str_contains($scheduleAlert->payload['embeds'][0]['title'], 'Scheduled maintenance failed'), 'Scheduled failure was not announced accurately');
check(! str_contains($scheduleAlert->payload['embeds'][0]['description'], 'was restored'), 'Schedule failure invented a rollback');
check($notifier->forEvent($scheduleFailure)->id === $scheduleAlert->id, 'Duplicate schedule alert was queued');
$unhanded = $event('failed', false, 'schedule'); $notifier->recover();
check(DiscordDelivery::where('source_event_id', $unhanded->id)->count() === 1, 'Recovery lost the scheduled failure handoff');
check($notifier->forEvent($event('success', true, 'schedule')) === null, 'Normal scheduled restart announced a modpack upgrade');
$GLOBALS['arcturus_integration_passed'] = true;
