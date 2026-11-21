<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Jobs\ConfirmDeployment;
use Arcturus\ModpackManager\Models\{DeploymentConfirmation, ManagedServer, Operation};
use Arcturus\ModpackManager\Native\{CheckpointChanged, ConfirmationLeaseHeld, ManualStart};
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class QueueDeploymentConfirmation
{
    public const RUNNING = ['checking_server', 'verifying_files', 'validating_configs', 'finalizing'];
    public const PENDING = ['queued', 'dispatch_pending', ...self::RUNNING];
    private const RETRYABLE = ['dispatch_pending', 'blocked'];

    public function __construct(private readonly ManualStart $manualStart) {}

    private function requireQueue(): int
    {
        $queue = config('queue.connections.'.config('queue.default'), []);
        if (! in_array($queue['driver'] ?? '', ['database', 'redis'], true) ||
            (int) ($queue['retry_after'] ?? 0) <= ConfirmDeployment::TIMEOUT + 60) {
            throw new RuntimeException('Confirmation requires the asynchronous database or Redis queue with retry_after greater than 3660 seconds.');
        }
        return (int) $queue['retry_after'];
    }

    public function enqueue(string $deploymentId, int $actor, int $reviewId, string $proof, bool $configurationSet): DeploymentConfirmation
    {
        $admittedAt = now();
        return DB::transaction(function () use ($deploymentId, $actor, $reviewId, $proof, $configurationSet, $admittedAt): DeploymentConfirmation {
            $operation = Operation::lockForUpdate()->findOrFail($deploymentId);
            $server = ManagedServer::lockForUpdate()->findOrFail($operation->managed_server_id, ['id', 'active_operation_id', 'transport']);
            $previous = DeploymentConfirmation::where('deployment_id', $deploymentId)->where('review_event_id', $reviewId)->lockForUpdate()->first();
            $review = $this->manualStart->admitConfirmation($operation, $server, $actor, $configurationSet, $proof, $admittedAt, $reviewId, $previous);
            $pending = DeploymentConfirmation::where('deployment_id', $deploymentId)->whereIn('state', self::PENDING)->first();
            if ($pending && $pending->id !== $previous?->id) { return $pending; }
            if ($previous) {
                if (in_array($previous->state, self::RETRYABLE, true)) { $this->requeue($previous); }
                return $previous;
            }
            $this->requireQueue();
            $confirmation = DeploymentConfirmation::create(['id' => (string) Str::uuid(), 'deployment_id' => $operation->id,
                'review_event_id' => $review->id, 'actor_id' => $actor, 'configuration_set' => $configurationSet, 'proof' => $proof,
                'checkpoint_sha256' => $review->details['checkpoint_sha256'], 'target_version' => $review->details['target_version'],
                'admitted_at' => $admittedAt, 'state' => 'queued']);
            DB::afterCommit(fn () => $this->dispatch($confirmation->id));
            return $confirmation;
        }, 3);
    }

    private function requeue(DeploymentConfirmation $confirmation): void
    {
        $this->requireQueue();
        $confirmation->update(['state' => 'queued', 'attempt_id' => null, 'dispatched_at' => null, 'error' => null]);
        DB::afterCommit(fn () => $this->dispatch($confirmation->id));
    }

    public function retry(string $id, int $actor): DeploymentConfirmation
    {
        $confirmation = DeploymentConfirmation::findOrFail($id);
        return $this->enqueue($confirmation->deployment_id, $actor, $confirmation->review_event_id, $confirmation->proof, $confirmation->configuration_set);
    }

    private function dispatch(string $id): void
    {
        try {
            Bus::dispatch(new ConfirmDeployment($id));
            DeploymentConfirmation::whereKey($id)->where('state', 'queued')->update(['dispatched_at' => now(), 'error' => null]);
        } catch (Throwable $error) {
            report($error);
            DeploymentConfirmation::whereKey($id)->where('state', 'queued')->update(['state' => 'dispatch_pending',
                'error' => 'Approval saved. Queue dispatch failed. Retry the same confirmation when the queue is available.']);
        }
    }

    /** The durable row is also an outbox if a request dies immediately after committing approval. */
    public function recover(): void
    {
        if (! DeploymentConfirmation::whereIn('state', self::PENDING)->exists()) { return; }
        $visibility = $this->requireQueue();
        DeploymentConfirmation::where(function ($query) use ($visibility): void {
            $query->where(function ($queued) use ($visibility): void {
                $queued->whereIn('state', ['queued', 'dispatch_pending'])->where('updated_at', '<', now()->subMinutes(5))
                    ->where(fn ($dispatch) => $dispatch->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subSeconds($visibility)));
            })->orWhere(function ($interrupted): void {
                $interrupted->whereIn('state', self::RUNNING)->where('attempt_started_at', '<=', $this->expiredAttempt());
            });
        })->eachById(function (DeploymentConfirmation $row): void {
            $resumed = DeploymentConfirmation::whereKey($row->id)->where('state', $row->state)
                ->where('attempt_id', $row->attempt_id)->where('updated_at', $row->getRawOriginal('updated_at'))
                ->update(['state' => 'queued', 'dispatched_at' => null]);
            if ($resumed) { $this->dispatch($row->id); }
        });
    }

    private function expiredAttempt(): \Carbon\CarbonInterface
    {
        return now()->subSeconds(ConfirmDeployment::TIMEOUT + 60);
    }

    public function run(string $id): void
    {
        $confirmation = DB::transaction(function () use ($id): ?DeploymentConfirmation {
            $row = DeploymentConfirmation::lockForUpdate()->find($id);
            if (! $row || ! in_array($row->state, ['queued', ...self::RUNNING], true)) { return null; }
            if ($row->state !== 'queued' && $row->attempt_started_at?->gt($this->expiredAttempt())) { return null; }
            $row->update(['attempt_id' => (string) Str::uuid(), 'attempt_started_at' => now(), 'state' => 'checking_server',
                'worker' => substr(gethostname().':'.getmypid(), 0, 255), 'error' => null]);
            return $row;
        }, 3);
        if (! $confirmation) { return; }

        $attempt = DeploymentConfirmation::whereKey($id)->where('attempt_id', $confirmation->attempt_id);
        $progress = function (string $state) use ($attempt): void {
            // The attempt fence is also checked with a row lock in the final transaction.
            $changed = (clone $attempt)->whereIn('state', self::RUNNING)->where('state', '!=', $state)->update(['state' => $state]);
            if (! $changed && ! (clone $attempt)->where('state', $state)->exists()) {
                throw new CheckpointChanged('This confirmation attempt was replaced.');
            }
        };
        try {
            $this->manualStart->confirmRunningDeployment($confirmation->deployment_id, $confirmation->actor_id, true,
                $confirmation->proof, true, $confirmation->target_version, $confirmation->configuration_set, $confirmation, $progress);
        } catch (Throwable $error) {
            report($error);
            $state = $error instanceof ConfirmationLeaseHeld ? 'blocked' : 'failed';
            $message = get_class($error) === RuntimeException::class || $error instanceof CheckpointChanged || $error instanceof ConfirmationLeaseHeld
                ? $error->getMessage() : 'Verification could not finish. Check Panel and Wings availability, then review the running deployment again.';
            (clone $attempt)->whereIn('state', self::RUNNING)->update(['state' => $state, 'error' => Str::limit($message, 1500)]);
        }
    }
}
