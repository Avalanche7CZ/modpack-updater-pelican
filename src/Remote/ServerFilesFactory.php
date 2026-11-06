<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\PreparationProgress;

final class ServerFilesFactory
{
    public function worker(ManagedServer $server): IntegrityWorker
    {
        return match ($server->transport ?? 'wings') {
            'local' => new LocalWorker($server),
            'wings' => new NodeWorker($server->pelican_server_uuid, (new \Arcturus\ModpackManager\Services\DirectoryPolicy)->scope($server)),
            default => throw new RuntimeException('Unknown server filesystem backend; select an explicit backend in server settings'),
        };
    }

    public function managed(ManagedServer $server, ?Operation $operation = null): WingsFiles
    {
        $worker = $this->worker($server);
        if ($operation && $worker instanceof LocalWorker) {
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
