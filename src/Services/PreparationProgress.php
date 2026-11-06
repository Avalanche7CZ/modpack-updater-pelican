<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\Operation;

/** Small sanitized snapshots; recipes/credentials are not rewritten for progress updates. */
final class PreparationProgress
{
    public static function normalize(array $value): ?array
    {
        $phase = $value['phase'] ?? null;
        if (! in_array($phase, PreparationDiagnostic::PHASES, true)) { return null; }
        $result = ['phase' => $phase];
        foreach (['bytes_done', 'bytes_total', 'files_done', 'files_total', 'entries_scanned', 'entries_validated', 'artifact_index', 'downloaded_bytes', 'download_total_bytes'] as $key) {
            $n = $value[$key] ?? null;
            $result[$key] = is_int($n) && $n >= 0 ? $n : null;
        }
        foreach (['speed_bytes_per_second', 'eta_seconds', 'updated_at'] as $key) {
            $n = $value[$key] ?? null;
            $result[$key] = is_numeric($n) && is_finite((float) $n) && $n >= 0 ? (float) $n : null;
        }
        $steps = ['comparing_streamed_checksums', 'validating_archive', 'extracting_files', 'inventory_enumeration', 'inventory_hashing', 'reading_mod_metadata', 'planning', 'resolving_required_files'];
        $result['step'] = in_array($value['step'] ?? null, $steps, true) ? $value['step'] : null;
        $result['finished'] = ($value['finished'] ?? false) === true;
        return $result;
    }

    public static function record(Operation $operation, array $snapshot): void
    {
        if (($snapshot['operation_id'] ?? null) !== $operation->node_operation_id || ! is_array($snapshot['progress'] ?? null)) { return; }
        $current = self::normalize($snapshot['progress']);
        if (! $current || $operation->completed_at) { return; }
        $stored = $operation->progress ?? [];
        $stages = $stored['stages'] ?? [];
        foreach ((is_array($snapshot['stages'] ?? null) ? $snapshot['stages'] : []) as $phase => $stage) {
            if (is_array($stage) && ($clean = self::normalize($stage)) && $clean['phase'] === $phase) { $stages[$phase] = $clean; }
        }
        $stages[$current['phase']] = $current;
        $stored = ['current' => $current, 'stages' => $stages];
        if (isset($snapshot['diagnostic'])) { $stored['diagnostic'] = PreparationDiagnostic::normalize($snapshot['diagnostic']); }
        $operation->update(['progress' => $stored, 'heartbeat_at' => now()]);
    }

    public static function phase(Operation $operation, string $phase, string $step = '', ?int $done = null, ?int $total = null): void
    {
        self::record($operation, ['operation_id' => $operation->node_operation_id,
            'progress' => ['phase' => $phase, 'step' => $step, 'files_done' => $done, 'files_total' => $total, 'updated_at' => time(), 'finished' => $total !== null && $done === $total]]);
    }

    public static function percentage(array $progress): ?float
    {
        foreach (['bytes', 'files'] as $unit) {
            if ($unit === 'files' && ($progress['phase'] ?? null) === 'downloading') { return null; }
            $done = $progress[$unit.'_done'] ?? null; $total = $progress[$unit.'_total'] ?? null;
            if (is_int($done) && is_int($total) && $total > 0 && $done <= $total) { return round(100 * $done / $total, 1); }
        }
        return null;
    }

    public static function size(?int $bytes): string
    {
        return $bytes === null ? 'Unknown' : number_format($bytes / 1048576, 2).' MiB';
    }
}
