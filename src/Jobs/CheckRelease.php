<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Services\ReleaseChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckRelease implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;
    public int $tries = 2;
    public array $backoff = [30, 120];

    public function __construct(public readonly int $managedServerId) {}

    public function handle(ReleaseChecker $checker): void
    {
        $server = ManagedServer::find($this->managedServerId);
        if ($server && $server->enabled) {
            $checker->check($server);
        }
    }
}
