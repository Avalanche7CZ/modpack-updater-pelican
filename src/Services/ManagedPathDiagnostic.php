<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

/** Reconstruct display text; never echo exception messages, worker URLs or host paths. */
final class ManagedPathDiagnostic
{
    public static function normalize(mixed $value): ?array
    {
        if (! is_array($value) || ($value['schema'] ?? null) !== 1 ||
            ! in_array($value['code'] ?? null, ['UNAPPROVED_MANAGED_DIRECTORY', 'PROTECTED_MANAGED_PATH'], true) ||
            ! is_array($value['directories'] ?? null)) {
            return null;
        }
        $names = [];
        foreach (array_slice($value['directories'], 0, 16) as $name) {
            $names[] = is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $name) &&
                ! preg_match('/[A-Za-z0-9_-]{40,}|token|secret|password|credential/i', $name) ? $name : '[redacted-name]';
        }
        $protected = $value['code'] === 'PROTECTED_MANAGED_PATH';

        return ['schema' => 1, 'phase' => 'hashing', 'code' => $value['code'], 'directories' => array_values(array_unique($names)),
            'description' => ($protected ? 'Protected runtime/world paths cannot enter a baseline: ' : 'Unapproved managed directories: ').implode(', ', $names),
            'action' => $protected ? 'World/runtime exclusions cannot be overridden. Retain the stage and review the official pack contents.'
                : 'Upgrade the plugin and worker for per-server directory discovery. Review upstream directories in adoption. Retain the failed archive; a matching preparation can reuse it after checksum verification. The Panel cannot grant OS read permissions.'];
    }

    public static function message(array $diagnostic): string
    {
        return '['.$diagnostic['code'].'] '.$diagnostic['description'].'. '.$diagnostic['action'];
    }
}
