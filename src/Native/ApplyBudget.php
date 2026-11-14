<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use RuntimeException;

/** Explicit bounded budgets remain available to callers; native deployment uses independent request deadlines. */
final class ApplyBudget
{
    private int $depth = 0;
    private ?float $unitDeadline = null;

    public function __construct(private readonly float $deadline, private readonly int $unitSeconds = 120,
        private readonly ?\Closure $clock = null, private readonly bool $requestScoped = false) {}

    /** No accumulated phase/boot/recovery reserve may reject the next verified checkpoint. */
    public static function requestsOnly(): self { return new self(INF, 120, null, true); }

    private function now(): float { return $this->clock !== null ? ($this->clock)() : microtime(true); }

    public function run(\Closure $work): mixed
    {
        if ($this->requestScoped) { return $work(); }
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
        if ($maximum < 1) { throw new RuntimeException('Request timeout must be positive.'); }
        if ($this->requestScoped) { return (float) $maximum; }
        $remaining = min($this->deadline, $this->unitDeadline ?? $this->deadline) - $this->now();
        if ($remaining <= 0) { throw new RuntimeException('Applying unit deadline exhausted; retain its intent and recover without replay.'); }
        return min($maximum, $remaining);
    }
}
