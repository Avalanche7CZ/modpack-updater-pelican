<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Contracts\IntegrityWorker;
use Arcturus\ModpackManager\Local\LocalWorker;
use Arcturus\ModpackManager\Models\ManagedServer;
use RuntimeException;

final class ServerFilesFactory
{
    public function worker(ManagedServer $server): IntegrityWorker
    {
        return match ($server->transport ?? 'wings') {
            'local' => new LocalWorker($server),
            'wings' => new NodeWorker($server->pelican_server_uuid),
            default => throw new RuntimeException('Unknown server filesystem backend; select an explicit backend in server settings'),
        };
    }

    public function managed(ManagedServer $server): WingsFiles
    {
        return new WingsFiles($server->pelicanServer, app(DaemonFileRepository::class), $this->worker($server));
    }

    public function wings(Server $server): WingsFiles
    {
        return new WingsFiles($server, app(DaemonFileRepository::class), new NodeWorker($server->uuid));
    }
}
