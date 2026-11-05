<?php
declare(strict_types=1);

return [
    // The plugin never updates a server just because a release exists.
    'automatic_deploy' => (bool) env('MODPACK_MANAGER_AUTOMATIC_DEPLOY', false),
    'discord_webhook' => env('MODPACK_MANAGER_DISCORD_WEBHOOK'),
    'discord_events' => (bool) env('MODPACK_MANAGER_DISCORD_EVENTS', true),
    'user_agent' => 'Pelican-Modpack-Manager/0.2 (+https://github.com/Avalanche7CZ/modpack-updater-pelican)',
    'release_timeout_seconds' => 20,
    'curseforge_api_key' => env('MODPACK_MANAGER_CURSEFORGE_API_KEY'),
    'custom_manifest_directory' => env('MODPACK_MANAGER_CUSTOM_MANIFEST_DIRECTORY', ''),
    'check_interval_hours' => (int) env('MODPACK_MANAGER_CHECK_INTERVAL_HOURS', 6),
    // No arbitrary external URL fetches. Custom sources require an explicit host allow-list.
    'allowed_live_roots' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('MODPACK_MANAGER_ALLOWED_LIVE_ROOTS', ''))
    ))),
    'allowed_custom_hosts' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('MODPACK_MANAGER_ALLOWED_CUSTOM_HOSTS', ''))
    ))),
];
