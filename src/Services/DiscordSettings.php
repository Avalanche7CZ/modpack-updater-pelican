<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Support\Facades\Validator;

final class DiscordSettings
{
    public const FIELDS = ['discord_enabled', 'discord_webhook', 'discord_role_id', 'discord_notify_events', 'clear_discord_webhook'];

    public function attributes(ManagedServer $server, array $data): array
    {
        Validator::make($data, [
            'discord_enabled' => ['sometimes', 'boolean'], 'discord_webhook' => ['nullable', 'string', 'max:512', function ($attribute, $value, $fail): void {
                if ($value !== '') {
                    try {
                        DiscordNotifier::validateWebhook($value);
                    } catch (\Throwable) {
                        $fail('Enter an official HTTPS Discord webhook URL');
                    }
                }
            }],
            'discord_role_id' => ['nullable', 'string', function ($attribute, $value, $fail): void {
                if ($value !== '') {
                    try {
                        DiscordNotifier::validateSnowflake($value);
                    } catch (\Throwable) {
                        $fail('Enter a valid Discord role snowflake ID');
                    }
                }
            }],
            'discord_notify_events' => ['sometimes', 'array', 'max:4'],
            'discord_notify_events.*' => ['string', 'in:'.implode(',', array_keys(DiscordNotifier::EVENTS))],
            'clear_discord_webhook' => ['sometimes', 'boolean'],
        ])->validate();
        $attributes = array_intersect_key($data, array_flip(['discord_enabled', 'discord_role_id', 'discord_notify_events']));
        if (array_key_exists('discord_role_id', $attributes)) {
            $attributes['discord_role_id'] = $attributes['discord_role_id'] ?: null;
        }
        if (($data['clear_discord_webhook'] ?? false) === true) {
            $attributes['discord_webhook'] = null;
        } elseif (! empty($data['discord_webhook'])) {
            $attributes['discord_webhook'] = $data['discord_webhook'];
        }

        return $attributes;
    }
}
