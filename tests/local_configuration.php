<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

function env(string $key, mixed $default = null): mixed
{
    return $key === 'ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG' ? ($GLOBALS['local_config_path'] ?? null) : $default;
}
$path = sys_get_temp_dir().'/arcturus-local-config-'.bin2hex(random_bytes(6));
$GLOBALS['local_config_path'] = $path;
$load = fn () => require dirname(__DIR__).'/config/arcturus-modpack-manager.php';
check($load()['local_config_error'], 'Missing local config did not fail closed');
file_put_contents($path, '{invalid json with private data');
chmod($path, 0600);
check($load()['local_config_error'], 'Malformed JSON accepted');
file_put_contents($path, '[{"root":"/unsafe"}]');
check($load()['local_config_error'], 'List accepted in place of UUID mapping');
file_put_contents($path, '{"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa":{"root":"/fixture/live","staging":"/fixture/stage"}}');
chmod($path, 0660);
clearstatcache(true, $path);
check($load()['local_config_error'], 'Group-writable local mapping accepted');
chmod($path, 0640);
clearstatcache(true, $path);
check(! $load()['local_config_error'] && count($load()['local_servers']) === 1, 'Valid operator mapping rejected');
symlink($path, $path.'.link');
$GLOBALS['local_config_path'] = $path.'.link';
check($load()['local_config_error'], 'Symlink configuration accepted');
unlink($path.'.link');
unlink($path);
echo "Local mapping loader fails closed on missing, malformed, writable and linked configuration\n";
