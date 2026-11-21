<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;

final class BaselineGuard
{
    public function require(array $manifest, string $provider, string $project, string $version): void
    {
        $verification = $manifest['verification'] ?? null;
        $pinnedGtnh = $verification === 'recorded-archive-pin' && $provider === 'gtnh' && $project === 'gtnh';
        if (($manifest['schema'] ?? null) !== 2 || ($manifest['provider'] ?? null) !== $provider ||
            ($manifest['project'] ?? null) !== $project || ($manifest['version'] ?? null) !== $version ||
            (! $pinnedGtnh && $verification !== 'upstream-checksums') || empty($manifest['files']) ||
            empty($manifest['artifacts'])) {
            throw new RuntimeException('Exact upstream baseline with verified artifact integrity is required');
        }
        foreach ($manifest['artifacts'] as $artifact) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'] ?? '') || empty($artifact['hashes'])) {
                throw new RuntimeException('Baseline artifact has no upstream integrity evidence');
            }
        }
        if ($pinnedGtnh) {
            $archive = $manifest['artifacts'][0] ?? [];
            if (count($manifest['artifacts']) !== 1 || ($archive['hashes']['sha256'] ?? null) !== ($archive['sha256'] ?? null)
                || ! is_int($archive['bytes'] ?? null) || $archive['bytes'] <= 0) {
                throw new RuntimeException('GTNH baseline archive does not match its recorded pin.');
            }
        }
        foreach ($manifest['files'] as $path => $hash) {
            UpdatePlanner::assertPath($path);
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/iD', $hash)) {
                throw new RuntimeException('Baseline contains an invalid file hash');
            }
        }
    }
}
