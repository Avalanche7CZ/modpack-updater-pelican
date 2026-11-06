<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Jobs\SendDiscordNotification;
use Arcturus\ModpackManager\Models\DiscordDelivery;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\UpdateEvent;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Existing notifier, now backed by a durable, deduplicated Laravel queue outbox. */
final class DiscordNotifier
{
    public const DEFAULT_EVENTS = ['updated', 'failed', 'update_available'];

    public const EVENTS = ['updated' => 'Successful update (startup verified)', 'failed' => 'Failed update',
        'rollback' => 'Confirmed rollback', 'update_available' => 'New release available'];

    public static function validateSnowflake(string $value): void
    {
        if (! preg_match('/^[1-9][0-9]{16,19}$/D', $value) || (strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0)) {
            throw new RuntimeException('Enter a valid Discord snowflake ID');
        }
    }

    public static function validateWebhook(string $webhook): void
    {
        $url = parse_url($webhook);
        if (strlen($webhook) > 512 || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) ||
            isset($url['query']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443) ||
            ! in_array(strtolower((string) ($url['host'] ?? '')), ['discord.com', 'discordapp.com'], true) ||
            ! preg_match('~^/api/(?:v[0-9]+/)?webhooks/([1-9][0-9]{16,19})/[A-Za-z0-9_-]{20,256}$~D', (string) ($url['path'] ?? ''), $match)) {
            throw new RuntimeException('Enter an official HTTPS Discord webhook URL');
        }
        self::validateSnowflake($match[1]);
    }

    private function text(string $value, int $limit = 180): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);
        $value = str_replace(['@', '`'], ['＠', 'ˋ'], mb_substr($value, 0, $limit));

        return preg_replace('/([\\\\*_~|<>\[\]])/u', '\\\\$1', $value);
    }

    private function releaseUrl(?string $url): ?string
    {
        if (! $url || strlen($url) > 1000) {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            (isset($parts['port']) && $parts['port'] !== 443) || preg_match('/[\s<>\\\\]/', $url) || str_contains($url, '/api/webhooks/')) {
            return null;
        }

        return $url;
    }

    /** Event versions are immutable evidence; never infer the old version from the updated model. */
    public function payload(ManagedServer $server, UpdateEvent $event, string $kind): array
    {
        $name = $this->text($server->label);
        $old = $this->text($event->from_version ?? '—', 120);
        $new = $this->text($event->to_version ?? '—', 120);
        [$title, $description, $color] = match ($kind) {
            'updated' => ['Aktualizace dokončena · Update complete',
                "**{$name}** byl úspěšně aktualizován z `{$old}` na `{$new}`.\n\n**{$name}** has been successfully updated from `{$old}` to `{$new}`.", 3066993],
            'failed' => ['Aktualizace selhala · Update failed', "Aktualizace **{$name}** z `{$old}` na `{$new}` selhala. Podrobnosti a stav obnovy jsou v panelu.\n\nThe update of **{$name}** from `{$old}` to `{$new}` failed. See the panel for recovery status.", 15158332],
            'rollback' => ['Obnova potvrzena · Rollback confirmed', "Původní instalace **{$name}** (`{$old}`) byla obnovena po neúspěšné aktualizaci na `{$new}`.\n\nThe original installation of **{$name}** (`{$old}`) was restored after the failed update to `{$new}`.", 16753920],
            'update_available' => ['Nová verze · New release', "Pro **{$name}** je dostupná verze `{$new}` (nainstalováno `{$old}`).\n\nVersion `{$new}` is available for **{$name}** (installed: `{$old}`).", 3447003],
            default => ['Test oznámení · Test notification', "Discord oznámení pro **{$name}** jsou připojena. Toto je pouze test, server nebyl aktualizován.\n\nDiscord notifications for **{$name}** are connected. This is only a test; the server was not updated.", 9807270],
        };
        $embed = ['title' => $title, 'description' => $description, 'color' => $color,
            'fields' => [['name' => 'Server / modpack', 'value' => $name, 'inline' => true],
                ['name' => 'Verze / Version', 'value' => $kind === 'test' ? '`'.$this->text($server->installed_version, 120).'`' : "`{$old}` → `{$new}`", 'inline' => true]],
            'footer' => ['text' => 'Arcturus Network · Arcturus Modpack Manager']];
        if ($kind === 'updated') {
            $embed['fields'][] = ['name' => 'Stav / Status', 'value' => 'Úspěch / Success · Startup verified', 'inline' => false];
        }
        if ($kind !== 'test' && ($url = $this->releaseUrl($event->details['release_url'] ?? null))) {
            $embed['url'] = $url;
        }

        return $this->mentions(['username' => 'Arcturus Modpack Manager', 'embeds' => [$embed]], $kind === 'test' ? null : $server->discord_role_id);
    }

    private function mentions(array $payload, ?string $role): array
    {
        if ($role !== null && $role !== '') {
            self::validateSnowflake($role);
        }
        $payload['content'] = $role ? '<@&'.$role.'>' : '';
        $payload['allowed_mentions'] = ['parse' => [], 'roles' => $role ? [$role] : [], 'users' => [], 'replied_user' => false];

        return $payload;
    }

    private function kind(UpdateEvent $event): ?string
    {
        return match (true) {
            $event->kind === 'deploy' && $event->status === 'success' && ($event->details['startup_verified'] ?? false) === true => 'updated',
            $event->kind === 'deploy' && $event->status === 'failed' => 'failed',
            $event->kind === 'rollback' && $event->status === 'success' && ($event->details['restore_verified'] ?? false) === true => 'rollback',
            $event->kind === 'release' && $event->status === 'success' => 'update_available',
            $event->kind === 'notification_test' => 'test',
            default => null,
        };
    }

    /** Notification persistence/dispatch errors never escape into update/recovery control flow. */
    public function forEvent(UpdateEvent $event): ?DiscordDelivery
    {
        try {
            $kind = $this->kind($event);
            if (! $kind || ! $event->exists) {
                return null;
            }
            $delivery = DB::transaction(function () use ($event, $kind): DiscordDelivery {
                $source = UpdateEvent::lockForUpdate()->findOrFail($event->id);
                if ($this->kind($source) !== $kind) {
                    throw new RuntimeException('Notification source is not confirmed');
                }
                if ($existing = DiscordDelivery::where('source_event_id', $source->id)->first()) {
                    return $existing;
                }
                $server = $source->managedServer;
                $webhook = $server->discord_webhook ?: config('arcturus-modpack-manager.discord_webhook');
                $enabled = $kind === 'test' || (config('arcturus-modpack-manager.discord_events', true) && $server->discord_enabled && in_array($kind, $server->discord_notify_events ?? self::DEFAULT_EVENTS, true));
                if ($webhook) {
                    self::validateWebhook($webhook);
                }
                $delivery = DiscordDelivery::create(['managed_server_id' => $server->id, 'source_event_id' => $source->id, 'kind' => $kind,
                    'status' => $enabled && $webhook ? 'queued' : 'skipped', 'webhook' => $enabled ? $webhook : null,
                    'payload' => $enabled && $webhook ? $this->payload($server, $source, $kind) : null]);
                $this->history($delivery, $delivery->status, $enabled && $webhook ? 'Discord notification queued' : 'Discord notification skipped (disabled or no webhook)');

                return $delivery;
            });
            if ($delivery->status === 'queued' && $delivery->wasRecentlyCreated) {
                $this->dispatch($delivery);
            }

            return $delivery;
        } catch (Throwable $error) {
            try {
                $event->managedServer->events()->create(['kind' => 'notification', 'status' => 'failed', 'message' => 'Discord notification could not be queued; verify notification settings', 'details' => ['source_event_id' => $event->id]]);
            } catch (Throwable) {
            }

            return null;
        }
    }

    /** Compatibility entry point: success is eligible only through a verified deploy event. */
    public function announce(ManagedServer $server, string $kind, string $message): void
    {
        $event = $server->events()->where('kind', $kind === 'update_available' ? 'release' : 'deploy')
            ->where('status', $kind === 'failed' ? 'failed' : 'success')->latest('id')->first();
        if ($event && $this->kind($event) === $kind) {
            $this->forEvent($event);
        }
    }

    public function test(ManagedServer $server): DiscordDelivery
    {
        Access::requireAdmin();
        $webhook = $server->discord_webhook ?: config('arcturus-modpack-manager.discord_webhook');
        self::validateWebhook((string) $webhook);
        $event = $server->events()->create(['kind' => 'notification_test', 'status' => 'info', 'message' => 'Administrator requested a test notification without role mentions', 'details' => ['actor_id' => auth()->id()]]);
        $delivery = $this->forEvent($event);
        if (! $delivery) {
            throw new RuntimeException('Test notification could not be queued; see history');
        }

        return $delivery;
    }

    private function dispatch(DiscordDelivery $delivery): void
    {
        try {
            $connection = config('queue.default');
            if (! is_string($connection) || ! in_array(config('queue.connections.'.$connection.'.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
                throw new RuntimeException('Asynchronous queue required');
            }
            SendDiscordNotification::dispatch($delivery->id)->onConnection($connection)->delay($delivery->next_attempt_at)->afterCommit();
        } catch (Throwable) {
            $delivery->update(['last_error' => 'Queue dispatch unavailable; scheduler will retry']);
            $this->history($delivery, 'queued', 'Discord dispatch deferred; configure a working asynchronous queue');
        }
    }

    private function history(DiscordDelivery $delivery, string $status, string $message): void
    {
        $delivery->managedServer->events()->create(['kind' => 'notification', 'status' => $status, 'message' => $message,
            'details' => ['delivery_id' => $delivery->id, 'source_event_id' => $delivery->source_event_id, 'event' => $delivery->kind, 'attempts' => $delivery->attempts ?? 0]]);
    }

    private function finish(DiscordDelivery $delivery, string $status, string $message, array $attributes = []): void
    {
        DB::transaction(function () use ($delivery, $status, $message, $attributes): void {
            $fresh = DiscordDelivery::lockForUpdate()->findOrFail($delivery->id);
            if ($fresh->status !== 'sending') {
                return;
            }
            $fresh->update([...$attributes, 'status' => $status, 'last_error' => $status === 'delivered' ? null : $message]);
            $this->history($fresh, $status, $message);
            if ($status === 'retry_wait') {
                DB::afterCommit(fn () => $this->dispatch($fresh));
            }
        });
    }

    public function deliver(int $id): void
    {
        $delivery = DB::transaction(function () use ($id): ?DiscordDelivery {
            $row = DiscordDelivery::lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, ['queued', 'retry_wait'], true) || $row->next_attempt_at?->isFuture()) {
                return null;
            }
            $row->update(['status' => 'sending', 'claimed_at' => now()]);

            return $row;
        });
        if (! $delivery) {
            return;
        }
        try {
            $server = $delivery->managedServer;
            if ($delivery->kind !== 'test' && (! config('arcturus-modpack-manager.discord_events', true) || ! $server->discord_enabled || ! in_array($delivery->kind, $server->discord_notify_events ?? self::DEFAULT_EVENTS, true))) {
                $this->finish($delivery, 'cancelled', 'Discord notifications disabled before delivery');

                return;
            }
            $webhook = $server->discord_webhook ?: config('arcturus-modpack-manager.discord_webhook');
            if (! $webhook || $webhook !== $delivery->webhook) {
                $this->finish($delivery, 'cancelled', 'Discord webhook settings changed before delivery');

                return;
            }
            self::validateWebhook($webhook);
            $key = 'arcturus-modpack-manager-discord-'.hash('sha256', $webhook);
            $lock = Cache::lock($key, 30);
            if (! $lock->get()) {
                $this->finish($delivery, 'retry_wait', 'Discord channel is busy', ['next_attempt_at' => now()->addSeconds(5)]);

                return;
            }
            try {
                $pause = max((int) Cache::get($key.'-pause', 0), (int) Cache::get('arcturus-modpack-manager-discord-global-pause', 0));
                if ($pause > now()->timestamp) {
                    $this->finish($delivery, 'retry_wait', 'Waiting for Discord rate limit', ['next_attempt_at' => now()->setTimestamp($pause)]);

                    return;
                }
                $payload = $this->mentions($delivery->payload, $delivery->kind === 'test' ? null : $server->discord_role_id);
                $delivery->increment('attempts');
                try {
                    // wait=true returns a confirmed message ID. No automatic POST retries or redirects.
                    $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->post($webhook.'?wait=true', $payload);
                } catch (Throwable) {
                    $this->finish($delivery, 'uncertain', 'Discord transport interrupted; delivery may have occurred. Check the channel before any manual resend.');

                    return;
                }
                if ($response->status() === 429) {
                    $delay = $response->json('retry_after') ?? $response->header('Retry-After');
                    if (! is_numeric($delay) || ! is_finite((float) $delay) || $delay < 0 || $delay > 604800 || $delivery->attempts >= 5) {
                        $this->finish($delivery, 'failed', 'Discord rate limit retry budget or delay is invalid');

                        return;
                    }
                    $seconds = max(1, (int) ceil((float) $delay));
                    $until = now()->addSeconds($seconds);
                    Cache::put($response->json('global') === true ? 'arcturus-modpack-manager-discord-global-pause' : $key.'-pause', $until->timestamp, $seconds);
                    $this->finish($delivery, 'retry_wait', 'Discord rate limited the notification; retry scheduled', ['next_attempt_at' => $until]);
                } elseif ($response->successful() && $response->status() === 200) {
                    $messageId = $response->json('id');
                    try {
                        self::validateSnowflake(is_string($messageId) ? $messageId : '');
                    } catch (Throwable) {
                        $this->finish($delivery, 'uncertain', 'Discord response did not confirm a message ID; automatic resend suppressed');

                        return;
                    }
                    $this->finish($delivery, 'delivered', 'Discord notification delivered', ['message_id' => $messageId, 'delivered_at' => now()]);
                    $reset = $response->header('X-RateLimit-Reset-After');
                    if ($response->header('X-RateLimit-Remaining') === '0' && is_numeric($reset) && $reset > 0 && $reset <= 604800) {
                        Cache::put($key.'-pause', now()->addSeconds((int) ceil((float) $reset))->timestamp, (int) ceil((float) $reset));
                    }
                } else {
                    $this->finish($delivery, $response->status() >= 500 || $response->successful() ? 'uncertain' : 'failed', 'Discord returned HTTP '.$response->status().'; no automatic resend');
                }
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            // Failure after an attempted POST is conservatively ambiguous; never leak request exceptions.
            $this->interrupted($delivery->id);
        }
    }

    public function interrupted(int $id): void
    {
        try {
            if ($delivery = DiscordDelivery::find($id)) {
                $this->finish($delivery, 'uncertain', 'Discord worker interrupted; check the channel before any manual resend');
            }
        } catch (Throwable) {
        }
    }

    /** Recover safe rejected/pending requests, never ambiguous in-flight POSTs. */
    public function recover(): void
    {
        foreach (DiscordDelivery::where('status', 'sending')->where('claimed_at', '<', now()->subMinutes(2))->limit(100)->get() as $row) {
            $this->interrupted($row->id);
        }
        foreach (DiscordDelivery::whereIn('status', ['queued', 'retry_wait'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->limit(100)->get() as $row) {
            $this->dispatch($row);
        }
        // Complete the outbox hand-off if a worker died after committing verified success.
        foreach (UpdateEvent::where('created_at', '>=', now()->subDay())->where('details->discord_notification_pending', true)->whereIn('kind', ['deploy', 'rollback', 'release'])
            ->whereNotIn('id', DiscordDelivery::select('source_event_id'))->orderBy('id')->limit(100)->get() as $event) {
            $this->forEvent($event);
        }
    }
}
