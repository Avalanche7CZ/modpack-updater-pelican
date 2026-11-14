<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** An admission limit, never permission to replay an interrupted unit. */
final class ApplyBudget
{
    private int $depth = 0;
    private ?float $unitDeadline = null;

    public function __construct(private readonly float $deadline, private readonly int $unitSeconds = 120,
        private readonly ?\Closure $clock = null) {}

    private function now(): float { return $this->clock !== null ? ($this->clock)() : microtime(true); }

    public function run(\Closure $work): mixed
    {
        if ($this->depth === 0) {
            if ($this->unitSeconds < 1 || $this->now() + $this->unitSeconds >= $this->deadline) {
                throw new RuntimeException('Applying soft budget exhausted at a verified boundary; use existing recovery, never replay apply.');
            }
            $this->unitDeadline = $this->now() + $this->unitSeconds;
        }
        $this->depth++;
        try { $this->timeout(600); $result = $work(); $this->timeout(600); return $result; }
        finally { if (--$this->depth === 0) { $this->unitDeadline = null; } }
    }

    public function timeout(int $maximum): float
    {
        $remaining = min($this->deadline, $this->unitDeadline ?? $this->deadline) - $this->now();
        if ($remaining <= 0) { throw new RuntimeException('Applying unit deadline exhausted; retain its intent and recover without replay.'); }
        return min($maximum, $remaining);
    }
}
