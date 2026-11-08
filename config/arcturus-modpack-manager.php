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

$localServers = [];
$localConfigError = false;
$localConfig = env('ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG');
if ($localConfig) {
    try {
        if (! is_string($localConfig) || ! is_file($localConfig) || is_link($localConfig) || ! is_readable($localConfig) || (fileperms($localConfig) & 0022)) {
            throw new RuntimeException();
        }
        $localServers = json_decode(file_get_contents($localConfig), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($localServers) || ($localServers && array_is_list($localServers))) {
            throw new RuntimeException();
        }
    } catch (Throwable) {
        $localServers = [];
        $localConfigError = true;
    }
}

return [
    'nodes' => $nodes,
    'local_servers' => $localServers,
    'local_config_error' => $localConfigError,
    'default_filesystem_backend' => 'native',
    'local_server_roots' => [],
    'default_policy' => 'approve',
    'default_backup_exclusions' => ['backup', 'backups'],
    'download_concurrency' => 1,
    // Legacy global flags do not enable scheduling; use explicit per-server maintenance settings.
    'automatic_deploy' => false,
    'enable_deploy' => (bool) env('ARCTURUS_MODPACK_MANAGER_ENABLE_DEPLOY', false),
    'ftb_installer_binary' => env('ARCTURUS_MODPACK_MANAGER_FTB_INSTALLER_BINARY'),
    'max_archive_bytes' => (int) env('ARCTURUS_MODPACK_MANAGER_MAX_ARCHIVE_BYTES', 8589934592),
    'discord_webhook' => env('ARCTURUS_MODPACK_MANAGER_DISCORD_WEBHOOK'),
    'discord_events' => (bool) env('ARCTURUS_MODPACK_MANAGER_DISCORD_EVENTS', true),
    'user_agent' => 'Arcturus-Modpack-Manager/0.5 (+https://github.com/Avalanche7CZ/modpack-updater-pelican)',
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
