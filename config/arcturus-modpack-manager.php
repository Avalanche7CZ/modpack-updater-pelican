<?php

declare(strict_types=1);

$nodeConfig = env('ARCTURUS_MODPACK_MANAGER_NODE_CONFIG');
$nodes = [];
if ($nodeConfig) {
    if (! is_file($nodeConfig) || is_link($nodeConfig) || (fileperms($nodeConfig) & 0077) !== 0) {
        throw new RuntimeException('Arcturus node configuration must be a regular operator-owned file with mode 0600');
    }
    $nodes = json_decode(file_get_contents($nodeConfig), true, flags: JSON_THROW_ON_ERROR);
}

return [
    'nodes' => $nodes,
    'local_server_roots' => [],
    'default_policy' => 'approve',
    'default_backup_exclusions' => ['backup', 'backups'],
    'download_concurrency' => 1,
    // The plugin never updates a server just because a release exists.
    'automatic_deploy' => (bool) env('ARCTURUS_MODPACK_MANAGER_AUTOMATIC_DEPLOY', false),
    'enable_deploy' => (bool) env('ARCTURUS_MODPACK_MANAGER_ENABLE_DEPLOY', false),
    'ftb_installer_binary' => env('ARCTURUS_MODPACK_MANAGER_FTB_INSTALLER_BINARY'),
    'max_archive_bytes' => (int) env('ARCTURUS_MODPACK_MANAGER_MAX_ARCHIVE_BYTES', 8589934592),
    'discord_webhook' => env('ARCTURUS_MODPACK_MANAGER_DISCORD_WEBHOOK'),
    'discord_events' => (bool) env('ARCTURUS_MODPACK_MANAGER_DISCORD_EVENTS', true),
    'user_agent' => 'Arcturus-Modpack-Manager/0.4 (+https://github.com/Avalanche7CZ/modpack-updater-pelican)',
    'release_timeout_seconds' => 20,
    'curseforge_api_key' => env('ARCTURUS_MODPACK_MANAGER_CURSEFORGE_API_KEY'),
    'custom_manifest_directory' => env('ARCTURUS_MODPACK_MANAGER_CUSTOM_MANIFEST_DIRECTORY', ''),
    'check_interval_hours' => (int) env('ARCTURUS_MODPACK_MANAGER_CHECK_INTERVAL_HOURS', 6),
    // Panel worker may only scan explicitly allow-listed local roots.
    'allowed_live_roots' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('ARCTURUS_MODPACK_MANAGER_ALLOWED_LIVE_ROOTS', ''))
    ))),
    'allowed_custom_hosts' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('ARCTURUS_MODPACK_MANAGER_ALLOWED_CUSTOM_HOSTS', ''))
    ))),
];
