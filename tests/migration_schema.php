<?php
declare(strict_types=1);

/**
 * Prevent regression of the MySQL/MariaDB utf8mb4 ERROR 1118 during
 * initial plugin installation. No framework/dependency is needed.
 *
 * This is a static schema guard; real database integration still must be
 * verified against the operator's Pelican MariaDB/MySQL.
 */
$migration = file_get_contents(
    __DIR__ . '/../database/migrations/2026_11_05_000000_create_modpack_manager_tables.php'
);
if (!is_string($migration)) {
    throw new RuntimeException('Cannot load initial plugin schema');
}

$longColumns = [
    'available_asset_url', 'available_release_url',
    'local_root', 'base_manifest_path', 'target_manifest_path',
    'staging_parent', 'prepared_root', 'backup_root',
];
foreach ($longColumns as $name) {
    $declaration = "\$table->text('{$name}')->nullable();";
    if (!str_contains($migration, $declaration)) {
        throw new RuntimeException("Long path/URL must use TEXT, not VARCHAR: {$name}");
    }
}

// A worst-case utf8mb4 VARCHAR column consumes up to 4 bytes/character.
// Keep ample room for metadata and inline JSON/timestamp values.
preg_match_all('/\$table->string\(\x27[^\x27]+\x27,\s*(\d+)\)/',
    $migration, $matches);
$maxInlineBytes = array_sum(array_map('intval', $matches[1])) * 4;
if ($maxInlineBytes > 32768) {
    throw new RuntimeException(
        "Migration has excessive maximum VARCHAR bytes ({$maxInlineBytes})"
    );
}

echo "Migration row-size regression test passed\n";
