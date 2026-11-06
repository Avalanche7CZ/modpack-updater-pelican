<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Persistent exclusion survives cache loss, a worker crash, and queue retry. */
final class OperationTracker
{
    public const TERMINAL = ['completed', 'failed', 'cancelled', 'interrupted', 'restored', 'manual_intervention'];

    public function create(ManagedServer $server, string $kind, array $context = [], ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($server, $kind, $context, $requestId): Operation {
            $fresh = ManagedServer::query()->lightweight()->lockForUpdate()->findOrFail($server->id);
            if ($fresh->active_operation_id) {
                throw new RuntimeException('An operation is active or requires recovery; inspect its history before continuing');
            }
            $operation = Operation::create(['id' => $requestId ?? (string) Str::uuid(), 'managed_server_id' => $server->id,
                'kind' => $kind, 'phase' => 'queued', 'context' => $context, 'heartbeat_at' => now(),
                'node_operation_id' => bin2hex(random_bytes(16))]);
            $fresh->update(['active_operation_id' => $operation->id, 'approved_plan_sha256' => null, 'approved_at' => null]);

            return $operation;
        });
    }

    public function transition(Operation $operation, string $phase, array $context = []): void
    {
        DB::transaction(function () use ($operation, $phase, $context): void {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (in_array($current->phase, self::TERMINAL, true)) {
                throw new RuntimeException('Operation is already terminal');
            }
            $current->update(['phase' => $phase, 'context' => array_replace($current->context ?? [], $context),
                'heartbeat_at' => now(), 'completed_at' => in_array($phase, self::TERMINAL, true) ? now() : null]);
            ManagedServer::lightweight()->findOrFail($current->managed_server_id)->events()->create([
                'kind' => $current->kind, 'status' => $phase, 'message' => $current->error ?: str_replace('_', ' ', ucfirst($phase)),
                'details' => ['operation_id' => $current->id, 'diagnostic' => $current->context['diagnostic'] ?? null],
            ]);
            $requiresRecovery = $phase === 'manual_intervention' ||
                ($current->kind === 'deploy' && in_array($phase, ['failed', 'interrupted'], true));
            if (in_array($phase, self::TERMINAL, true) && ! $requiresRecovery) {
                ManagedServer::whereKey($current->managed_server_id)->where('active_operation_id', $current->id)
                    ->update(['active_operation_id' => null]);
            }
            $operation->setRawAttributes($current->getAttributes(), true);
        });
    }

    public function fail(Operation $operation, string $message): void
    {
        $operation->update(['error' => $message]);
        $this->transition($operation, 'failed');
    }
}
