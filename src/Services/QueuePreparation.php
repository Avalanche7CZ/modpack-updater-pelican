<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;

final class QueuePreparation
{
    public function requireLocalQueue(): void
    {
        $connection = config('queue.default');
        $queue = config('queue.connections.'.$connection, []);
        if (! in_array($queue['driver'] ?? '', ['database', 'redis'], true) ||
            (int) ($queue['retry_after'] ?? 0) <= LocalWorker::TIMEOUT + 60) {
            throw new RuntimeException('Local preparation requires an asynchronous database/Redis queue with retry_after greater than 14460 seconds (use 14520). Restart pelican-queue after rebuilding config. See docs/local-filesystem.md.');
        }
    }

    public function comparison(ManagedServer $server, bool $preview = false, ?string $requestId = null): \Arcturus\ModpackManager\Models\Operation
    {
        \Arcturus\ModpackManager\Security\Access::requireAdmin();
        $requestId ??= (string) \Illuminate\Support\Str::uuid();
        if (! \Illuminate\Support\Str::isUuid($requestId)) { throw new RuntimeException('Invalid comparison request ID'); }
        return \Illuminate\Support\Facades\DB::transaction(function () use ($server, $preview, $requestId) {
            $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
            $existing = \Arcturus\ModpackManager\Models\Operation::where('managed_server_id', $fresh->id)->find($requestId);
            if ($existing) {
                if ($existing->kind !== 'comparison' || ($existing->context['mode'] ?? 'adoption') !== ($preview ? 'preview' : 'adoption')) { throw new RuntimeException('Request ID belongs to a different operation'); }
                return $existing;
            }
            if ($fresh->baseline_status !== 'verified' || ! $fresh->base_manifest_path || ($preview && ! $fresh->target_manifest_path)) {
                throw new RuntimeException('Prepare the exact upstream baseline and requested target before comparison');
            }
            // Enforce an asynchronous queue for every backend; validation/planning belongs to its worker.
            if (in_array($fresh->transport, ['local', 'native'], true)) { $this->requireLocalQueue(); }
            elseif (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis'], true)) {
                throw new RuntimeException('Installation comparison requires an asynchronous database or Redis queue');
            }
            $operation = app(OperationTracker::class)->create($fresh, 'comparison', ['baseline' => $fresh->base_manifest_path,
                'policy_revision' => $fresh->policy_revision, 'provider' => $fresh->provider, 'source' => $fresh->source, 'version' => $fresh->installed_version,
                'mode' => $preview ? 'preview' : 'adoption', 'target' => $preview ? $fresh->target_manifest_path : null,
                'target_version' => $preview ? $fresh->targetVersion() : null], $requestId);
            ManagedServer::whereKey($fresh->id)->where('active_operation_id', $operation->id)->update([
                'comparison_status' => 'queued', 'comparison_diagnostic' => null]);
            $fresh->events()->create(['kind' => 'comparison', 'status' => 'queued', 'message' => 'Read-only comparison queued', 'details' => ['operation_id' => $operation->id]]);
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($fresh, $operation): void {
                try { \Arcturus\ModpackManager\Jobs\CompareInstallation::dispatch($fresh->id, $operation->id); }
                catch (\Throwable $error) {
                    // The durable queued operation is the outbox. Recovery dispatches the SAME ID.
                    $operation->update(['error' => 'Comparison saved and awaiting queue dispatch; use Reconcile preparation or scheduled recovery.']);
                }
            });
            return $operation;
        });
    }

    public function queue(ManagedServer $server, bool $baseline = false): void
    {
        $server->refresh();
        if (! in_array($server->transport, ['local', 'native'], true)) {
            PrepareUpdate::dispatch($server->id, $baseline);

            return;
        }
        app(\Arcturus\ModpackManager\Remote\ServerFilesFactory::class)->worker($server)->request('capabilities');
        // Local preparation runs inside this queue job, never inside an HTTP request.
        $this->requireLocalQueue();
        if (! $baseline && $server->baseline_status !== 'verified') {
            throw new RuntimeException('Prepare and verify the installed baseline first');
        }
        $version = $baseline ? $server->installed_version : $server->targetVersion();
        if (! $version) {
            throw new RuntimeException('Select an exact release first');
        }
        $tracker = app(OperationTracker::class);
        $operation = $tracker->create($server, $baseline ? 'baseline' : 'prepare',
            ['version' => $version, 'provider' => $server->provider, 'source' => $server->source]);
        try {
            PrepareUpdate::dispatch($server->id, $baseline, $operation->id);
        } catch (\Throwable) {
            $tracker->fail($operation, 'Local preparation could not be queued. Check the Panel queue connection and restart pelican-queue.');
            throw new RuntimeException($operation->error);
        }
    }
}
