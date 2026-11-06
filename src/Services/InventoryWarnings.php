<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** Informational logical paths only; never include a link target or untrusted worker text. */
final class InventoryWarnings
{
    public static function symlinks(array $inventory): array
    {
        $warnings = [];
        foreach ($inventory['skipped_symlinks'] ?? [] as $path) {
            if (! is_string($path)) { continue; }
            $detail = PreparationDiagnostic::normalize(['code' => 'SYMLINK_PATH', 'context' => [
                'file' => $path, 'file_id' => substr(hash('sha256', $path), 0, 12),
            ]]);
            $warnings[] = 'Preserved symlink: '.($detail['context']['file'] ?? 'file ID '.$detail['context']['file_id']);
        }

        return array_values(array_unique($warnings));
    }
}
