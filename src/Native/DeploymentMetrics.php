<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

/** Process-local measurements. Persisted snapshots are diagnostic, never recovery proof. */
final class DeploymentMetrics
{
    public const PERFORMANCE_TARGET_SECONDS = 900;
    private array $totals = [];
    private array $stack = [];
    private array $phases = [];
    private array $milestones = [];
    private ?string $phase = null;
    private float $phaseAt;
    private float $started;

    public function __construct() { $this->started = $this->phaseAt = microtime(true); }

    public function mark(string $name): void { $this->milestones[$name] ??= microtime(true) - $this->started; }

    public function phase(string $phase): void
    {
        $now = microtime(true);
        if ($this->phase !== null) { $this->phases[$this->phase] = ($this->phases[$this->phase] ?? 0) + $now - $this->phaseAt; }
        $this->phase = $phase; $this->phaseAt = $now;
    }

    public function measure(string $category, \Closure $work): mixed
    {
        $start = microtime(true); $this->stack[] = 0.0; $failed = true;
        try { $result = $work(); $failed = false; return $result; }
        finally {
            $elapsed = microtime(true) - $start; $children = array_pop($this->stack);
            if ($this->stack !== []) { $this->stack[array_key_last($this->stack)] += $elapsed; }
            $row = $this->totals[$category] ?? ['calls' => 0, 'seconds' => 0.0, 'bytes' => 0, 'failed' => 0];
            $row['calls']++; $row['seconds'] += max(0, $elapsed - $children); $row['failed'] += (int) $failed;
            $this->totals[$category] = $row;
        }
    }

    public function record(string $category, float $seconds, int $bytes = 0, bool $failed = false): void
    {
        $row = $this->totals[$category] ?? ['calls' => 0, 'seconds' => 0.0, 'bytes' => 0, 'failed' => 0];
        $row['calls']++; $row['seconds'] += $seconds; $row['bytes'] += $bytes; $row['failed'] += (int) $failed;
        $this->totals[$category] = $row;
    }

    public function bytes(string $category, int $bytes): void
    {
        $this->totals[$category] ??= ['calls' => 0, 'seconds' => 0.0, 'bytes' => 0, 'failed' => 0];
        $this->totals[$category]['bytes'] += $bytes;
    }

    public function snapshot(): array
    {
        $now = microtime(true); $phases = $this->phases;
        if ($this->phase !== null) { $phases[$this->phase] = ($phases[$this->phase] ?? 0) + $now - $this->phaseAt; }
        $boot = $this->milestones['original_boot_verified'] ?? $this->milestones['target_boot_verified'] ?? null;
        $downtime = $boot !== null && isset($this->milestones['offline_confirmed']) ? $boot - $this->milestones['offline_confirmed'] : null;
        return ['performance_target_seconds' => self::PERFORMANCE_TARGET_SECONDS, 'started_at_unix' => $this->started, 'milestones_seconds' => $this->milestones, 'stop_to_verified_boot_seconds' => $boot !== null && isset($this->milestones['stop_requested']) ? $boot - $this->milestones['stop_requested'] : null, 'offline_to_verified_boot_seconds' => $downtime, 'elapsed_seconds' => $now - $this->started, 'phase' => $this->phase, 'phases_seconds' => $phases, 'work' => $this->totals];
    }
}
