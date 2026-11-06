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
(new SendDiscordNotification($rate->id))->handle($notifier);
check($rate->refresh()->attempts === 1, 'Rate-limited request retried before deadline');
Carbon::setTestNow(now()->addSeconds(3));
(new SendDiscordNotification($rate->id))->handle($notifier);
Carbon::setTestNow();
check($rate->refresh()->status === 'delivered' && $rate->attempts === 2, 'Safe rate limit retry failed');
Cache::flush();
foreach ([400 => 'failed', 404 => 'failed', 500 => 'uncertain', 204 => 'uncertain'] as $status => $expected) {
    fakeDiscord(fn () => Http::response(null, $status));
    $row = $notifier->forEvent($event());
    (new SendDiscordNotification($row->id))->handle($notifier);
    check($row->refresh()->status === $expected, 'HTTP error handling incorrect: '.$status);
    $count = Http::recorded()->count();
    (new SendDiscordNotification($row->id))->handle($notifier);
    check(Http::recorded()->count() === $count, 'Ambiguous/permanent response retried blindly');
}
fakeDiscord(fn () => throw new ConnectionException('Sensitive '.$webhook));
$row = $notifier->forEvent($event());
(new SendDiscordNotification($row->id))->handle($notifier);
check($row->refresh()->status === 'uncertain' && ! str_contains($row->last_error, 'fixturetoken'), 'Interrupted transfer leaked credentials or was retried');
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

// Actual native Filament form hydration and test action; credentials stay blank.
$server->update(['discord_webhook' => $webhook]);
$edit = Livewire\Livewire::test(EditManagedServer::class, ['record' => $server->id]);
check($edit->instance()->data['discord_webhook'] === '' && ! str_contains($edit->html(), 'fixturetoken') && str_contains($edit->html(), 'Discord Notifications'), 'Native settings hydrated a token or did not render');
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
check($edit->instance()->data['discord_webhook'] === '' && $edit->instance()->data['clear_discord_webhook'] === false, 'Secrets remained in form after saving');
$edit->call('mountAction', 'testDiscord', [], $context)->call('callMountedAction');
check($server->events()->where('kind', 'notification_test')->count() === 2, 'Native test action did not queue a test');
Auth::guard()->forgetUser();
rejects(fn () => $notifier->test($server), 'Unauthorized test notification allowed');
check(! $server->events()->where('kind', 'notification')->where('message', 'like', '%fixturetoken%')->exists(), 'History exposed webhook credentials');
echo "Queued bilingual embeds, mention isolation, encryption, idempotency, rate limits, ambiguous errors, recovery and native settings/test action passed\n";
$GLOBALS['arcturus_integration_passed'] = true;
