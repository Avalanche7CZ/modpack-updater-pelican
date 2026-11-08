<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Services\QueuePreparation;
use Arcturus\ModpackManager\Services\PreparationDiagnostic;
use Arcturus\ModpackManager\Services\PreparationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PrepareUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 3;

    public array $backoff = [15, 60];

    public readonly string $requestId;

    public function __construct(public readonly int $managedServerId,
        public readonly bool $baseline = false, public readonly ?string $operationId = null)
    {
        $this->requestId = (string) Str::uuid();
        if (in_array(ManagedServer::find($managedServerId)?->transport, ['local', 'native'], true)) {
            $this->timeout = LocalWorker::TIMEOUT + 30;
        }
    }

    public function handle(PrepareRelease $preparer, OperationTracker $tracker): void
    {
        $lock = Cache::lock('arcturus-modpack-manager-operation-'.$this->managedServerId, $this->timeout + 30);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        $operation = null;
        try {
            $server = ManagedServer::findOrFail($this->managedServerId);
            if (in_array($server->transport, ['local', 'native'], true)) {
                app(QueuePreparation::class)->requireLocalQueue();
            }
            if (! $this->baseline && $server->baseline_status !== 'verified') {
                throw new RuntimeException('Prepare and verify the installed upstream baseline first');
            }
            $requestedId = $this->operationId ?? $this->requestId;
            $operation = Operation::where('managed_server_id', $server->id)->find($requestedId);
            if (! $operation) {
                if ($this->operationId) {
                    throw new RuntimeException('Requested operation no longer exists');
                }
                if ($server->active_operation_id) {
                    throw new RuntimeException('Another operation is active or requires recovery');
                }
                $version = $this->baseline ? $server->installed_version : $server->targetVersion();
                if (! $version) {
                    throw new RuntimeException('Select an exact upstream release first');
                }
                $operation = $tracker->create($server, $this->baseline ? 'baseline' : 'prepare',
                    ['version' => $version, 'provider' => $server->provider, 'source' => $server->source], $this->requestId);
            }
            if ($operation->completed_at) {
                return;
            }
            if ($operation->cancel_requested_at && $operation->phase === 'queued') {
                throw new PreparationException(['code' => 'CANCELLED', 'phase' => 'queued']);
            }
            if (! $preparer->tick($server, $operation)) {
                self::dispatch($server->id, $this->baseline, $operation->id)->delay(now()->addSeconds(10));
            } elseif (($operation->context['automatic'] ?? false) && ! isset($operation->context['native_schedule_run_id']) && $operation->phase === 'completed') {
                app(\Arcturus\ModpackManager\Services\ScheduledMaintenance::class)->deployPrepared($server, $operation);
            }
        } catch (Throwable $error) {
            // Keep the real exception in administrator logs; UI and saved evidence stay sanitized.
            if (! $error instanceof PreparationException) {
                Log::error('Arcturus preparation failed', [
                    'operation_id' => $operation?->id ?? $this->operationId ?? $this->requestId,
                    'managed_server_id' => $this->managedServerId,
                    'exception' => $error,
                ]);
            }
            $detail = PreparationDiagnostic::fromException($error, $operation?->progress['current']['phase'] ?? 'queued');
            if ($operation && ! $operation->completed_at) {
                $operation->update(['context' => [...$operation->context, 'diagnostic' => $detail]]);
                $message = PreparationDiagnostic::message($detail);
                if (in_array($detail['code'], ['CANCELLED', 'INTERRUPTED', 'LOCAL_TIMEOUT', 'LOCAL_INTERRUPTED', 'REMOTE_UNAVAILABLE'], true)) {
                    $operation->update(['error' => $message]);
                    $tracker->transition($operation, $detail['code'] === 'CANCELLED' ? 'cancelled' : 'interrupted');
                } else { $tracker->fail($operation, $message); }
            } elseif ($operation && ($operation->context['automatic'] ?? false) && isset($server)) {
                $event = $server->events()->create(['kind' => 'deploy', 'status' => 'failed',
                    'message' => 'Automatic update requires review. Open File Changes and resolve the reported blockers.',
                    'details' => ['operation_id' => $operation->id, 'discord_notification_pending' => true]]);
                app(\Arcturus\ModpackManager\Services\DiscordNotifier::class)->forEvent($event);
            } elseif (! $operation && isset($server)) {
                $server->events()->create(['kind' => $this->baseline ? 'baseline' : 'prepare', 'status' => 'failed',
                    'message' => PreparationDiagnostic::message($detail), 'details' => ['diagnostic' => $detail]]);
            }
            if ($detail['code'] !== 'CANCELLED') { throw new PreparationException($detail); }
        } finally {
            $lock->release();
        }
    }
}
