<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PrepareRelease;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
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
    }

    public function handle(PrepareRelease $preparer, OperationTracker $tracker): void
    {
        $lock = Cache::lock('arcturus-modpack-manager-operation-'.$this->managedServerId, 180);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }
        $operation = null;
        try {
            $server = ManagedServer::findOrFail($this->managedServerId);
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
            if (! $preparer->tick($server, $operation)) {
                self::dispatch($server->id, $this->baseline, $operation->id)->delay(now()->addSeconds(10));
            }
        } catch (Throwable $error) {
            if ($operation && ! $operation->completed_at) {
                $tracker->fail($operation, $error->getMessage());
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }
}
