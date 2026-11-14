<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Fenced durable writes, with no transaction or ambiguous write retries. */
final class DeploymentReceipt
{
    private float $pulseAt = 0;

    public function __construct(private readonly Operation $operation, private readonly string $execution,
        private readonly DeploymentMetrics $metrics) {}

    private function requireOwner(Operation $fresh): array
    {
        $context = $fresh->context;
        if ($fresh->completed_at || ($context['native_execution_id'] ?? null) !== $this->execution) {
            throw new RuntimeException('Native deployment receipt closed; retain its checkpoint.');
        }
        return $context;
    }

    public function heartbeat(bool $force = false): void
    {
        $due = microtime(true) - $this->pulseAt >= 10;
        if (! $force && ! $due) { return; }
        $this->metrics->measure('db-heartbeat', function () use ($due): void {
            if ($due) { $this->save(fn () => [], 'db-heartbeat-write'); }
            else { $this->requireOwner($this->operation->fresh()); }
        });
    }

    public function save(\Closure $attributes, string $category = 'db-checkpoint'): void
    {
        $this->metrics->measure($category, function () use ($attributes): void {
            DB::transaction(function () use ($attributes): void {
                $fresh = Operation::whereKey($this->operation->id)->lockForUpdate()->firstOrFail();
                $context = $this->requireOwner($fresh);
                // Share the same object with phase/progress reporters; merge only fresh encrypted context.
                $this->operation->setRawAttributes($fresh->getAttributes(), true);
                $values = $attributes($context);
                $values['context'] = [...($values['context'] ?? $context), 'native_metrics' => $this->metrics->snapshot()];
                $this->operation->update([...$values, 'heartbeat_at' => now()]);
            }, 1);
        });
        $this->pulseAt = microtime(true);
    }
}
