<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Modpacks\Providers\GtnhArtifact;
use Arcturus\ModpackManager\Security\Access;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class QueuePreparation
{
    public function decideArchive(int $serverId, string $reviewId, string $artifactId, string $sha, int $size, bool $approve): ?Operation
    {
        Access::requireAdmin();
        return DB::transaction(function () use ($serverId, $reviewId, $artifactId, $sha, $size, $approve): ?Operation {
            $server = ManagedServer::lightweight()->lockForUpdate()->findOrFail($serverId);
            $latest = $server->events()->whereIn('kind', ['baseline', 'prepare', 'comparison'])->latest('id')->first(['details']);
            if ($server->active_operation_id || ($latest?->details['operation_id'] ?? null) !== $reviewId) {
                throw new RuntimeException('This archive review is no longer current. Check the updated preparation status.');
            }
            $review = $server->operations()->whereIn('kind', ['baseline', 'prepare'])
                ->where('phase', 'archive_review_required')->whereNull('cancel_requested_at')->lockForUpdate()->findOrFail($reviewId);
            $artifact = GtnhArtifact::fromArray($review->progress['gtnh_archive']);
            $baseline = $review->kind === 'baseline';
            $version = $baseline ? $server->installed_version : $server->targetVersion();
            if ($server->provider !== 'gtnh' || $server->source !== 'gtnh' || $server->asset_pattern !== $artifact->variant ||
                $version !== $artifact->version || $artifact->id() !== $artifactId) {
                throw new RuntimeException('The selected archive has changed. Review the current release before deciding.');
            }
            if ($approve) {
                if (! $baseline && $server->baseline_status !== 'verified') {
                    throw new RuntimeException('Verify the installed baseline before continuing update preparation.');
                }
                if (in_array($server->transport, ['local', 'native'], true)) {
                    $this->requireLocalQueue();
                } elseif (! in_array(config('queue.connections.'.config('queue.default').'.driver'), ['database', 'redis'], true)) {
                    throw new RuntimeException('Continuing preparation requires an asynchronous database or Redis queue.');
                }
            }
            app(GtnhArchiveTrust::class)->decide($artifact, $sha, $size, (int) auth()->id(), $approve);
            if (! $approve) {
                return null;
            }

            // Keep the terminal review and its worker stage as evidence. The continuation
            // is a read-only preparation, independent of any originating scheduled update.
            $previous = $review->context;
            $operation = app(OperationTracker::class)->create($server, $review->kind, [
                'provider' => 'gtnh', 'source' => 'gtnh', 'version' => $version,
                'archive_approval' => ['review_id' => $review->id, 'artifact_id' => $artifact->id(), 'sha256' => $sha, 'size' => $size],
                'recipe' => $previous['recipe'] ?? null,
                'quarantine_id' => ($previous['recipe']['gtnh_quarantine'] ?? false) ? $review->node_operation_id : null,
                'release_name' => $previous['release_name'] ?? null,
            ]);
            $operation->update(['progress' => ['gtnh_archive' => $artifact->identity()]]);

            DB::afterCommit(function () use ($server, $baseline, $operation): void {
                try {
                    Bus::dispatch(new PrepareUpdate($server->id, $baseline, $operation->id));
                } catch (Throwable $error) {
                    report($error);
                    // Scheduled recovery can dispatch the same durable operation ID.
                    Operation::whereKey($operation->id)->where('phase', 'queued')->update([
                        'error' => 'Archive approval saved. Preparation is waiting for queue dispatch.',
                    ]);
                }
            });
            return $operation;
        }, 3);
    }

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
