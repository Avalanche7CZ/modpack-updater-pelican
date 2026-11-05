<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DiscordNotifier
{
    public function announce(ManagedServer $server, string $kind, string $message): void
    {
        if (!config('arcturus-modpack-manager.discord_events', true)) {
            return;
        }
        $webhook = config('arcturus-modpack-manager.discord_webhook');
        if (!$webhook) {
            return;
        }
        $url = parse_url($webhook);
        if (($url['scheme'] ?? '') !== 'https' ||
            !in_array(strtolower((string) ($url['host'] ?? '')), ['discord.com', 'discordapp.com'], true) ||
            !preg_match('~^/api/webhooks/\d+/[A-Za-z0-9_-]+$~D', (string) ($url['path'] ?? ''))) {
            throw new RuntimeException('Discord webhook must be an official Discord webhook URL');
        }
        $colors = ['update_available' => 3447003, 'updated' => 3066993,
            'failed' => 15158332, 'info' => 9807270];
        $response = Http::timeout(10)->withoutRedirecting()->post($webhook, [
            'username' => 'Modpack Manager',
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => match ($kind) {
                    'update_available' => 'Modpack update available',
                    'updated' => 'Modpack updated',
                    'failed' => 'Modpack update issue',
                    default => 'Modpack Manager',
                },
                'description' => mb_substr($message, 0, 1800),
                'color' => $colors[$kind] ?? $colors['info'],
                'fields' => [
                    ['name' => 'Server', 'value' => mb_substr($server->label, 0, 180), 'inline' => true],
                    ['name' => 'Installed', 'value' => $server->installed_version, 'inline' => true],
                    ['name' => 'Available', 'value' => $server->available_version ?? '—', 'inline' => true],
                ],
                'footer' => ['text' => 'Pelican Modpack Manager'],
            ]],
        ]);
        if ($response->failed()) {
            throw new RuntimeException('Discord returned HTTP ' . $response->status());
        }
    }
}
