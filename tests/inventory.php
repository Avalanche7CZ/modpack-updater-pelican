<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/UpdatePlanner.php';
require_once __DIR__ . '/../src/Services/DirectoryInventory.php';
require_once __DIR__ . '/../src/Services/ManifestJson.php';

use Arcturus\ModpackManager\Services\DirectoryInventory;
use Arcturus\ModpackManager\Services\ManifestJson;

function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function shouldFail(callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/modpack-tests-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir("{$root}/mods");
mkdir("{$root}/config");
file_put_contents("{$root}/mods/A.jar", 'jar-content');
file_put_contents("{$root}/config/a.cfg", 'config-data');
$scanner = new DirectoryInventory();
$serializer = new ManifestJson();
try {
    $tree = $scanner->hashTree($root);
    check($tree['mods/A.jar'] === hash('sha256', 'jar-content'), 'Hash mismatch');
    check(count($tree) === 2, 'Unexpected manifest count');
    $only = $scanner->hashPaths($root, ['mods/A.jar', 'mods/ABSENT.jar']);
    check(count($only) === 1, 'Missing files must not be hashed');
    shouldFail(fn() => $scanner->hashTree($root, 1), 'File count limit not enforced');
    shouldFail(fn() => $scanner->hashTree($root, 10, 1), 'Byte limit not enforced');
    $encoded = $serializer->encode('2.9.0', $tree);
    file_put_contents("{$root}/manifest.json", $encoded);
    $decoded = $serializer->load("{$root}/manifest.json");
    check($decoded['version'] === '2.9.0' && $decoded['files'] === $tree, 'Manifest roundtrip mismatch');
    file_put_contents("{$root}/invalid.json", '{broken');
    shouldFail(fn() => $serializer->load("{$root}/invalid.json"), 'Invalid JSON accepted');
    symlink('/etc/passwd', "{$root}/mods/escape.jar");
    shouldFail(fn() => $scanner->hashPaths($root, ['mods/escape.jar']), 'Symlink accepted');
    shouldFail(fn() => $scanner->hashTree($root), 'Tree symlink accepted');
    symlink($root . '/mods', "{$root}/modslink");
    shouldFail(fn() => $scanner->hashPaths($root, ['modslink/A.jar']), 'Directory symlink accepted');
} finally {
    unlink("{$root}/mods/escape.jar");
    unlink("{$root}/modslink");
    unlink("{$root}/manifest.json");
    unlink("{$root}/invalid.json");
    unlink("{$root}/mods/A.jar");
    unlink("{$root}/config/a.cfg");
    rmdir("{$root}/mods");
    rmdir("{$root}/config");
    rmdir($root);
}
echo "Inventory and manifest tests passed\n";
