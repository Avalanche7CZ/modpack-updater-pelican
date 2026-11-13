<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** Standard Wings exposes uptime, not a pinned container ID. Intersect bounded start-time observations. */
final class RunningInstance
{
    public static function sample(array $details, float $before, float $after): array
    {
        $uptime = $details['utilization']['uptime'] ?? null;
        if (($details['state'] ?? '') !== 'running' || !is_numeric($uptime) || !is_finite((float) $uptime) || $uptime <= 0) {
            throw new RuntimeException('Missing running-instance evidence: Wings running state and positive uptime are required.');
        }
        return ['min' => $before - $uptime / 1000 - 0.002, 'max' => $after - $uptime / 1000 + 0.002];
    }

    public static function same(array $a, array $b): array
    {
        $result = ['min' => max($a['min'], $b['min']), 'max' => min($a['max'], $b['max'])];
        if ($result['min'] > $result['max']) { throw new RuntimeException('Running-instance uptime windows do not overlap; identity is unverified.'); }
        return $result;
    }
}
