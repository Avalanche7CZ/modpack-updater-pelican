<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Remote\ServerFilesFactory;
use Arcturus\ModpackManager\Security\Access;
use RuntimeException;

/** Bounded, read-only local/upstream comparison. No recipe or script merging. */
final class KubeJsDiff
{
    public const LIMIT = 65536;

    public static function supports(string $path): bool
    {
        return str_starts_with($path, 'kubejs/') && SafetyPaths::reason($path) === null &&
            in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['js', 'json', 'snbt', 'mcmeta', 'txt', 'properties', 'toml', 'cfg'], true);
    }

    public function review(ManagedServer $server, string $path): array
    {
        Access::requireAdmin();
        UpdatePlanner::assertPath($path);
        if (! self::supports($path)) { throw new RuntimeException('Text diff is unavailable for this file type. Binary content is never merged.'); }
        $row = (new FilePolicyCatalog)->selected($server, [$path])[$path] ?? null;
        if (! $row || $row['invariant'] !== null || SafetyPaths::reason($path, $server->runtime_report['protected_roots'] ?? []) !== null) { throw new RuntimeException('This file is not available for review.'); }
        $local = '';
        $upstream = '';
        $files = app(ServerFilesFactory::class)->wings($server->pelicanServer);
        if ($row['local_sha256'] === null && $files->stat($path) !== null) {
            throw new RuntimeException('Local file changed since comparison. Compare files again.');
        }
        if ($row['local_sha256'] !== null) {
            $local = $files->read($path, self::LIMIT);
            if (hash('sha256', $local) !== $row['local_sha256']) { throw new RuntimeException('Local file changed since comparison. Compare files again.'); }
        }
        if ($row['target_sha256'] !== null) {
            $operation = $server->target_metadata['prepared']['node_operation_id'] ?? null;
            if (! $operation) { throw new RuntimeException('Prepare the target release to read verified upstream text.'); }
            $file = app(ServerFilesFactory::class)->worker($server)->request('stage_file', ['operation_id' => $operation, 'path' => $path]);
            $upstream = $file['content'] ?? '';
            if (! is_string($upstream) || strlen($upstream) > self::LIMIT || hash('sha256', $upstream) !== $row['target_sha256']) {
                throw new RuntimeException('Staged upstream file no longer matches the comparison. Prepare and compare again.');
            }
        }

        return ['path' => $path, 'content' => $this->text($local, $upstream),
            'notice' => 'Read-only local → upstream diff. No content is merged or written. Choose Keep local or Use upstream in the file list.'];
    }

    public function text(string $local, string $upstream): string
    {
        foreach ([$local, $upstream] as $value) {
            if (strlen($value) > self::LIMIT || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
                throw new RuntimeException('Readable diff supports UTF-8 text up to 64 KiB per file. Binary/NBT content is never merged.');
            }
        }
        $a = explode("\n", $local); $b = explode("\n", $upstream);
        $start = 0; $endA = count($a); $endB = count($b);
        while ($start < min($endA, $endB) && $a[$start] === $b[$start]) { $start++; }
        if ($start === $endA && $start === $endB) { return 'Local and upstream text are identical.'; }
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $endA--; $endB--; }
        $lines = ['--- Local', '+++ Upstream', '@@ starting at line '.($start + 1).' @@'];
        foreach (array_slice($a, max(0, $start - 3), min(3, $start)) as $line) { $lines[] = ' '.$line; }
        // Show one changed region without a quadratic edit-distance matrix.
        foreach ([['-', $a, $endA], ['+', $b, $endB]] as [$prefix, $source, $end]) {
            for ($i = $start; $i < $end; $i++) {
                $lines[] = $prefix.$source[$i];
                if (count($lines) >= 500) { $lines[] = '… Diff truncated; use the file editor for the full text.'; return implode("\n", $lines); }
            }
        }
        foreach (array_slice($a, $endA, 3) as $line) { $lines[] = ' '.$line; }

        return implode("\n", $lines);
    }
}
