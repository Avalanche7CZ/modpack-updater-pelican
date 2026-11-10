<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\PreparationProgress;

final class ServerFilesFactory
{
    public function worker(ManagedServer $server): IntegrityWorker
    {
        // UI records can outlive a backend selection. Jobs carry IDs, not this snapshot;
        // always route persisted records using the authoritative current setting.
        $transport = $server->exists ? ManagedServer::whereKey($server->getKey())->value('transport') : ($server->transport ?? 'wings');
        if ($transport !== $server->transport) { $server = clone $server; $server->transport = $transport; }
        return match ($transport) {
            'native' => new \Arcturus\ModpackManager\Native\Preparation($server),
            'local' => new LocalWorker($server),
            'wings' => new NodeWorker($server->pelican_server_uuid, (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->scope($server)),
            default => throw new \Arcturus\ModpackManager\Services\PreparationException(['code' => 'BACKEND_UNAVAILABLE', 'phase' => 'queued']),
        };
    }

    public function managed(ManagedServer $server, ?Operation $operation = null): WingsFiles
    {
        $worker = $this->worker($server);
        if ($operation && ($worker instanceof LocalWorker || $worker instanceof \Arcturus\ModpackManager\Native\Preparation)) {
            $worker->withProgress(fn (array $snapshot) => PreparationProgress::record($operation, $snapshot));
            $worker->withHeartbeat(function () use ($operation, $server): void {
                $operation->update(['heartbeat_at' => now()]);
                if (Operation::whereKey($operation->id)->value('cancel_requested_at')) {
                    try { $this->worker($server)->request('cancel', ['operation_id' => $operation->node_operation_id]); }
                    catch (\Throwable) { /* Pending until the helper confirms cancellation. */ }
                }
            });
        }
        return new WingsFiles($server->pelicanServer, app(DaemonFileRepository::class), $worker, $operation?->node_operation_id);
    }

    public function wings(Server $server): WingsFiles
    {
        return new WingsFiles($server, app(DaemonFileRepository::class), new NodeWorker($server->uuid));
    }
}
