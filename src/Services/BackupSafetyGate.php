<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;

/** Pure safety rules shared by Pelican-backed workflows; independently unit tested. */
final class BackupSafetyGate
{
    /** Reject a pending, failed, unlocked, excluded or unverifiable backup. */
    public static function requireComplete(
        bool $completed,
        bool $successful,
        bool $locked,
        array $ignored,
        int $bytes,
        ?string $checksum
    ): void {
        if (!$completed || !$successful || !$locked || $ignored !== [] ||
            $bytes <= 0 || $checksum === null ||
            preg_match('/^[a-zA-Z0-9_-]+:[a-fA-F0-9]{32,128}$/D', $checksum) !== 1) {
            throw new RuntimeException(
                'Pelican backup must be completed, successful, locked, complete, and checksummed'
            );
        }
    }

    /**
     * Pelican clears the server's "restoring" status for BOTH success and error.
     * Only a matching restore-complete callback AND cleared status means success.
     */
    public static function restoreFinished(?string $callbackEvent, bool $serverIdle): bool
    {
        if ($callbackEvent === 'server.backup.restore-failed') {
            throw new RuntimeException('Pelican reported backup restore failure');
        }
        return $serverIdle && $callbackEvent === 'server:backup.restore-complete';
    }
}
