<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Remote;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

final class ServerFilesFactory
{
    public function wings(Server $server): WingsFiles
    {
        return new WingsFiles($server, app(DaemonFileRepository::class), new NodeWorker($server->uuid));
    }
}
