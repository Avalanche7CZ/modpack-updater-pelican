<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Closure;
use RuntimeException;

/** Wings uptime is sampled, not measured at the instant /details is requested. */
final class RunningInstance
{
    public static function observationTimeout(): float
    {
        return max(1, min(60, (float) config('arcturus-modpack-manager.running_instance_sample_timeout', 10)));
    }

    public static function sample(array $details, float $before, float $after): array
    {
        $uptime = $details['utilization']['uptime'] ?? null;
        if (($details['state'] ?? '') !== 'running' || !is_numeric($uptime) || !is_finite((float) $uptime) || $uptime <= 0 || $after < $before) {
            throw new RuntimeException('Missing running-instance evidence: Wings running state and positive uptime are required.');
        }
        // A cached observation can predate the HTTP request. Its age is bounded, never unlimited.
        return ['version' => 2, 'min' => $before - $uptime / 1000 - self::observationTimeout() - 0.002,
            'max' => $after - $uptime / 1000 + 0.002, 'uptime' => (float) $uptime,
            'before' => $before, 'after' => $after, 'unchanged_since' => $before];
    }

    /** Only for persisted v1 uptime-derived bounds; never use for an authoritative start/audit window. */
    public static function receipt(array $proof): array
    {
        if (!isset($proof['version'])) { $proof['min'] -= self::observationTimeout(); }
        return $proof;
    }

    public static function same(array $a, array $b): array
    {
        $latest = isset($b['uptime']) ? $b : $a;
        if (isset($a['uptime'], $b['uptime'])) {
            [$old, $new] = $a['before'] <= $b['before'] ? [$a, $b] : [$b, $a];
            if ($new['uptime'] < $old['uptime']) { throw new RuntimeException('Wings uptime decreased; a restart or inconsistent sample makes running-instance identity unverified.'); }
            $latest = $new;
            if ($new['uptime'] === $old['uptime']) {
                // Re-reading one resource sample is neither a new container nor fresh identity evidence.
                $latest['unchanged_since'] = $old['unchanged_since'];
                if ($new['after'] - $latest['unchanged_since'] > self::observationTimeout()) { throw new RuntimeException('Wings uptime sample did not advance before the running-instance observation timeout.'); }
                $latest['min'] = $old['min']; $latest['max'] = $old['max'];
                $a = $old; $b = $latest;
            } else {
                // The new sample was produced after the request which still returned the old sample.
                // This observed transition tightens the birth window without assuming HTTP freshness.
                $latest['min'] = max($new['min'], $old['before'] - $new['uptime'] / 1000 - 0.002);
                $a = $old; $b = $latest;
            }
        }
        $result = [...$latest, 'min' => max($a['min'], $b['min']), 'max' => min($a['max'], $b['max'])];
        if ($result['min'] > $result['max']) { throw new RuntimeException('Running-instance uptime windows do not overlap; identity is unverified.'); }
        return $result;
    }

    public static function advanced(array $before, array $after): bool
    {
        return isset($before['uptime'], $after['uptime']) && $after['uptime'] > $before['uptime'];
    }

    /** Require an advancing resource sample across an evidence boundary, with no power actions. */
    public static function confirm(array $instance, Closure $sample, float $deadline, ?Closure $pulse = null): array
    {
        $baseline = $instance;
        $deadline = min($deadline, microtime(true) + self::observationTimeout());
        do {
            $pulse?->__invoke();
            [$details, $next] = $sample();
            $instance = self::same($instance, $next);
            if (self::advanced($baseline, $instance)) { return [$details, $instance]; }
            if (microtime(true) >= $deadline) { break; }
            usleep(250000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Wings uptime sample did not advance before the running-instance observation timeout.');
    }
}
