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

    public static function unreadable(array $inventory): array
    {
        $warnings = [];
        foreach (array_slice($inventory['unreadable_paths'] ?? [], 0, 250) as $entry) {
            $path = $entry['path'] ?? null;
            if (! is_string($path) || ! in_array($entry['errno'] ?? null, [1, 13], true)) { continue; }
            $detail = PreparationDiagnostic::normalize(['code' => 'PERMISSION_DENIED', 'context' => [
                'file' => $path, 'file_id' => substr(hash('sha256', $path), 0, 12),
            ]]);
            $phase = match ($entry['phase'] ?? '') {
                'hashing' => 'file hashing', 'metadata' => 'metadata inspection', default => 'directory traversal',
            };
            $reason = $entry['errno'] === 13 ? 'EACCES' : 'EPERM';
            $warnings[] = 'Protected unreadable path: '.($detail['context']['file'] ?? 'file ID '.$detail['context']['file_id']).' — '.$reason.' during '.$phase;
        }

        return array_values(array_unique($warnings));
    }

    public static function summary(array $inventory): array
    {
        $summary = ['files_hashed' => $inventory['files_count'] ?? count($inventory['files'] ?? [])];
        foreach (['unreadable_files_skipped', 'unreadable_directories', 'unreadable_entries_unknown'] as $key) {
            $value = $inventory['inventory_summary'][$key] ?? 0;
            $summary[$key] = is_int($value) && $value >= 0 && $value <= 100000 ? $value : 0;
        }

        return $summary;
    }

}
