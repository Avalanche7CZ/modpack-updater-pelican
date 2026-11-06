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

    public function queue(ManagedServer $server, bool $baseline = false): void
    {
        $server->refresh();
        if ($server->transport !== 'local') {
            PrepareUpdate::dispatch($server->id, $baseline);

            return;
        }
        (new LocalWorker($server))->request('capabilities');
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
