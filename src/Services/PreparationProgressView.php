<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** Presentation only: never extrapolate one phase's totals into another. */
final class PreparationProgressView
{
    private const LABELS = [
        'downloading' => 'Download', 'verifying' => 'Checksum verification', 'extracting' => 'Extraction',
        'awaiting_resolution' => 'Required files', 'hashing' => 'Hash staged files', 'comparing' => 'Inventory comparison',
    ];

    public static function make(array $report, string $operationPhase, bool $terminal, bool $cancelling, int $now): array
    {
        $current = PreparationProgress::normalize($report['current'] ?? []) ?? [];
        $phase = $current['phase'] ?? $operationPhase;
        $status = $terminal ? match ($operationPhase) {
            'completed' => ['Completed', 'success'], 'failed' => ['Failed', 'danger'],
            'cancelled' => ['Cancelled', 'gray'], default => ['Interrupted', 'warning'],
        } : ($cancelling ? ['Cancellation requested', 'warning'] : ['In progress', 'primary']);
        $rows = [];
        foreach (self::LABELS as $key => $label) {
            $snapshot = $key === $phase && $current ? $current : ($report['stages'][$key] ?? null);
            $snapshot = is_array($snapshot) ? PreparationProgress::normalize($snapshot) : null;
            if ($key === 'awaiting_resolution' && ! $snapshot) { continue; }
            $selected = $key === $phase;
            $active = $selected && ! $terminal;
            $finished = $snapshot && (($snapshot['finished'] ?? false) || ($terminal && $operationPhase === 'completed'));
            [$state, $color] = match (true) {
                $selected && $terminal && $operationPhase !== 'completed' => $status,
                $active && $cancelling => ['Cancelling', 'warning'],
                $active => ['In progress', 'primary'],
                (bool) $finished => ['Completed', 'success'],
                (bool) $snapshot => ['Recorded', 'gray'],
                default => ['No sample', 'gray'],
            };
            $fresh = $active && isset($snapshot['updated_at']) && abs($now - $snapshot['updated_at']) <= 15;
            $metrics = [];
            if ($snapshot) {
                $done = $snapshot['bytes_done'] ?? null; $total = $snapshot['bytes_total'] ?? null;
                // Zero is meaningful with a known total; phase-entry placeholder zeroes are not work counts.
                if ($done !== null && ($done > 0 || $total !== null)) {
                    $metrics[] = PreparationProgress::size($done).($total !== null ? ' / '.PreparationProgress::size($total) : ($key === 'downloading' ? ' downloaded' : ' processed'));
                }
                $done = $snapshot['files_done'] ?? null; $total = $snapshot['files_total'] ?? null;
                if ($key !== 'downloading' && $done !== null && ($done > 0 || $total !== null)) {
                    $unit = $key === 'verifying' ? 'checksums compared' : 'files processed';
                    $metrics[] = number_format($done).($total !== null ? ' / '.number_format($total) : '').' '.$unit;
                } elseif ($selected && $key === 'hashing') {
                    $metrics[] = $active ? 'Counting staged files — hashing total not recorded yet' : 'No hashing file count was recorded';
                }
                foreach (['entries_validated' => 'archive entries validated', 'entries_scanned' => 'entries scanned'] as $field => $unit) {
                    if (isset($snapshot[$field])) { $metrics[] = number_format($snapshot[$field]).' '.$unit; }
                }
            }
            $download = null;
            if ($key === 'downloading' && $snapshot) {
                $speed = $snapshot['speed_bytes_per_second'] ?? null;
                $eta = $snapshot['eta_seconds'] ?? null;
                $download = [
                    'live' => $fresh,
                    'speed' => $speed !== null ? number_format($speed / 1048576, 2).' MiB/s' : null,
                    'eta' => $eta !== null ? ($eta > 86400 ? 'More than 24h' : '~'.gmdate('H:i:s', (int) ceil($eta))) : null,
                    'downloaded' => $snapshot['downloaded_bytes'] ?? null, 'total' => $snapshot['download_total_bytes'] ?? null,
                ];
            }
            $rows[] = compact('key', 'label', 'state', 'color', 'active', 'fresh', 'metrics', 'download', 'snapshot') + [
                'percent' => $active && $snapshot ? PreparationProgress::percentage($snapshot) : null,
            ];
        }
        return ['status' => $status[0], 'color' => $status[1], 'rows' => $rows, 'phase' => $phase];
    }
}
