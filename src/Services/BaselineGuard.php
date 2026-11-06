<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use RuntimeException;

final class BaselineGuard
{
    public function require(array $manifest, string $provider, string $project, string $version): void
    {
        if (($manifest['schema'] ?? null) !== 2 || ($manifest['provider'] ?? null) !== $provider ||
            ($manifest['project'] ?? null) !== $project || ($manifest['version'] ?? null) !== $version ||
            ($manifest['verification'] ?? '') !== 'upstream-checksums' || empty($manifest['files']) ||
            empty($manifest['artifacts'])) {
            throw new RuntimeException('Exact independently verified upstream baseline is required');
        }
        foreach ($manifest['artifacts'] as $artifact) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'] ?? '') || empty($artifact['hashes'])) {
                throw new RuntimeException('Baseline artifact has no upstream integrity evidence');
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
