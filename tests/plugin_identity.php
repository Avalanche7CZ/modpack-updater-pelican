<?php
declare(strict_types=1);

/**
 * Zero-dependency guard for the distinct plugin identity.
 * The upstream cosmii02/pelican-modpackmanager uses id "modpack-manager";
 * Arcturus MUST NEVER ship that ID or reuse that plugin's directories.
 */

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function source(string $path): string
{
    $data = file_get_contents(__DIR__ . '/../' . $path);
    if (!is_string($data)) {
        throw new RuntimeException('Missing source file ' . $path);
    }
    return $data;
}

$id = 'arcturus-modpack-manager';
$data = json_decode(source('plugin.json'), true, 512, JSON_THROW_ON_ERROR);
expect(($data['id'] ?? null) === $id, 'Plugin must not reuse the cosmii02 modpack-manager ID');
expect(($data['name'] ?? null) === 'Arcturus Modpack Manager', 'Name must distinguish plugin');
expect(($data['namespace'] ?? null) === 'Arcturus\\ModpackManager', 'Plugin namespace must be isolated');
expect(($data['api_version'] ?? null) === 1, 'Pelican API version must be declared');
expect(($data['panels'] ?? null) === ['admin'], 'Admin-only plugin must not register on server panel');
expect(str_contains(source('src/ModpackManagerPlugin.php'), "return '{$id}';"),
    'Filament plugin getId must match plugin.json');

expect(is_file(__DIR__ . '/../config/' . $id . '.php'), 'Config must match the new plugin ID');
expect(!file_exists(__DIR__ . '/../config/modpack-manager.php'),
    'Generic modpack-manager config file must not be present');

$provider = source('src/Providers/ModpackManagerServiceProvider.php');
expect(str_contains($provider, "plugin_path('{$id}', 'config/{$id}.php')"), 'Plugin config path mismatch');
expect(str_contains($provider, "'{$id}'"), 'Plugin config namespace mismatch');
expect(str_contains($provider, "plugin_path('{$id}', 'resources/views')"), 'View path mismatch');
expect(str_contains(source('src/Filament/Admin/Pages/Overview.php'), "'{$id}::overview'"),
    'Blade namespace mismatch');
expect(str_contains(source('src/Filament/Admin/Pages/Overview.php'), "\$slug = '{$id}'"),
    'Dashboard must have unique route slug');
expect(str_contains(source('src/Console/Commands/CheckModpacks.php'), 'arcturus-modpack:check'),
    'Artisan command must not conflict with another plugin');

$migrationNames = [
    'database/migrations/2026_11_05_000000_create_arcturus_modpack_tables.php',
    'database/migrations/2026_11_05_010000_add_backup_mode_to_arcturus_modpack_servers.php',
    'database/migrations/2026_11_05_020000_add_backup_exclusions_to_arcturus_modpack_servers.php',
];
foreach ($migrationNames as $path) {
    $code = source($path);
    expect(str_contains($code, 'arcturus_modpack_servers'),
        "Migration does not reference isolated Arcturus table: {$path}");
}
expect(!file_exists(__DIR__ . '/../database/migrations/2026_11_05_000000_create_modpack_manager_tables.php'),
    'Generic old migration filename must not ship');
expect(str_contains(source($migrationNames[0]), "Schema::create('arcturus_modpack_events'"),
    'Audit table should be isolated');
expect(str_contains(source('src/Models/ManagedServer.php'), "\$table = 'arcturus_modpack_servers'"),
    'ManagedServer model table mismatch');
expect(str_contains(source('src/Models/UpdateEvent.php'), "\$table = 'arcturus_modpack_events'"),
    'UpdateEvent model table mismatch');

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)
);
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    expect(!preg_match("/(?<!ARCTURUS_)MODPACK_MANAGER_/", $code),
        'Legacy environment variable reference in ' . $file->getPathname());
    expect(!preg_match("~(?<!arcturus-)modpack-manager[.:]~", $code),
        'Legacy plugin config or cache namespace in ' . $file->getPathname());
}
$builder = source('scripts/build-release.sh');
expect(str_contains($builder, 'dist/' . $id . '.zip'), 'Release ZIP must use isolated filename');
expect(str_contains($builder, 'dist/' . $id), 'Release ZIP root must use isolated directory');

echo "Plugin identity and coexistence checks passed\n";
