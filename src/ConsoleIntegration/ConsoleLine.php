<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\ConsoleIntegration;

/** Only fixed vocabulary and validated measurements enter the terminal. Never render receipt messages/errors. */
final class ConsoleLine
{
    public static function make(string $kind, string $phase, array $progress, array $evidence): ?array
    {
        if (! in_array($kind, ['prepare', 'baseline', 'comparison', 'deploy'], true)) { return null; }
        $messages = [
            'queued' => 'Update operation queued', 'verifying' => 'Verifying the approved update plan',
            'downloading' => 'Downloading the verified server pack', 'preparing' => 'Preparing update',
            'extracting' => 'Extracting the verified server pack', 'hashing' => 'Verifying staged files',
            'comparing' => 'Comparing the installation with the prepared plan',
            'waiting_for_approval' => 'Plan ready for review and approval',
            'stopping' => 'Stopping Minecraft · waiting for the maintenance guard',
            'verifying_before_backup' => 'Verifying files before backup',
            'verifying_after_backup' => 'Verifying files after backup',
            'backing_up' => 'Waiting for the locked Pelican backup',
            'applying' => 'Applying and verifying approved files',
            'starting' => 'Starting Minecraft · waiting for verified startup',
            'restoring' => 'Update failed · restoring and verifying the pre-update backup',
            'restarting_original' => 'Restore completed · waiting for verified original Minecraft startup',
            'recovery_queued' => 'Original installation recovery queued',
            'recovery_starting' => 'Restore verified · waiting for verified original Minecraft startup',
            'manual_intervention' => 'Recovery unverified · retaining the safety checkpoint; operator review required',
            'failed' => 'Operation failed · review the retained operation',
            'cancelled' => 'Operation cancelled', 'interrupted' => 'Operation interrupted · review the retained checkpoint',
        ];
        if ($phase === 'completed') {
            $message = $kind === 'deploy'
                ? (($evidence['startup_verified'] ?? false) === true ? 'Update completed · Minecraft startup verified' : 'Operation closed · Minecraft startup unverified')
                : 'Preparation completed · review the current update plan';
        } elseif ($phase === 'restored') {
            $message = ($evidence['restore_verified'] ?? false) === true
                ? 'Rollback verified · original Minecraft startup verified'
                : 'Recovery unverified · retain the safety checkpoint';
        } else { $message = $messages[$phase] ?? null; }
        if ($message === null) { return null; }
        if ($phase === 'verifying' && $kind !== 'deploy') { $message = 'Verifying the server pack'; }
        $current = $progress['current'] ?? [];
        if (! in_array($phase, ['completed', 'restored', 'failed', 'cancelled', 'interrupted', 'manual_intervention', 'restoring', 'starting', 'restarting_original', 'recovery_starting', 'waiting_for_approval'], true) &&
            is_array($current) && in_array($current['phase'] ?? null, ['downloading', 'extracting', 'hashing', 'comparing'], true)) {
            $phase .= ':'.$current['phase'];
            $done = $current['bytes_done'] ?? null; $total = $current['bytes_total'] ?? null;
            if ($current['phase'] === 'downloading' && self::counts($done, $total)) {
                $message = 'Downloaded '.number_format($done / 1048576, 2, '.', '').' / '.number_format($total / 1048576, 2, '.', '').' MiB';
            } elseif ($current['phase'] !== 'downloading' && self::counts($current['files_done'] ?? null, $current['files_total'] ?? null)) {
                $message = ucfirst($current['phase']).' '.$current['files_done'].' / '.$current['files_total'].' files';
            }
        }
        if (in_array($phase, ['verifying_before_backup', 'verifying_after_backup'], true) && self::counts($progress['files_done'] ?? null, $progress['files_total'] ?? null)) {
            $message .= ' · '.number_format($progress['files_done'], 0, '.', ' ').' / '.number_format($progress['files_total'], 0, '.', ' ').' paths';
        }
        $applyStep = $phase === 'applying' ? ($progress['step'] ?? '') : '';
        if (isset(\Arcturus\ModpackManager\Services\ApplyProgress::STEPS[$applyStep])) {
            $message = \Arcturus\ModpackManager\Services\ApplyProgress::STEPS[$applyStep];
            if (self::counts($progress['files_done'] ?? null, $progress['files_total'] ?? null) || (($progress['files_done'] ?? null) === 0 && ($progress['files_total'] ?? null) === 0)) {
                $message .= ' · '.$progress['files_done'].' / '.$progress['files_total'];
            }
            $phase .= ':'.$applyStep;
        }
        if ($phase === 'applying' && self::counts($progress['files_done'] ?? null, $progress['files_total'] ?? null)) {
            $message = 'Applying and verifying '.$progress['files_done'].' / '.$progress['files_total'].' files';
        }
        return ['phase' => $phase, 'message' => '[Modpack] '.$message];
    }

    private static function counts(mixed $done, mixed $total): bool
    {
        return is_int($done) && is_int($total) && $done >= 0 && $total > 0 && $done <= $total;
    }
}
