<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use Arcturus\ModpackManager\Services\JsonStream;

$rows = [['path' => 'config/žluť/"quoted".json', 'value' => [null, true, false, 1.5, 'a\\/b', "new\nline"]], ['path' => 'mods/example.jar', 'value' => []]];
$json = json_encode(['ignored' => ['nested' => str_repeat('a\\/b', 20000)], 'evidence' => ['changes' => $rows]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach ([1, 7, 65536] as $chunk) {
    $reader = new JsonStream(fn ($offset, $size) => substr($json, $offset, min($size, $chunk)));
    check(iterator_to_array($reader->entries(['evidence', 'changes'])) === $rows, 'JSON chunk boundary changed evidence');
}
$reader = new JsonStream(fn ($offset, $size) => substr($json, $offset, $size));
check(iterator_to_array($reader->entries(['absent'])) === [], 'Missing evidence path fabricated rows');
$reader = new JsonStream(fn ($offset, $size) => substr('{"rows":[{"path":"truncated', $offset, $size));
rejects(fn () => iterator_to_array($reader->entries(['rows'])), 'Truncated evidence was accepted');
echo "Bounded evidence reads preserve escaped Unicode, nested values and chunk boundaries\n";
