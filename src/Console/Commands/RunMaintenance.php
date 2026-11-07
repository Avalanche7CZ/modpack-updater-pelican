<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Jobs\RunScheduledMaintenance;
use Arcturus\ModpackManager\Models\ManagedServer;
use Illuminate\Console\Command;

final class RunMaintenance extends Command
{
    protected $signature = 'arcturus-modpack:maintenance';
    protected $description = 'Queue opted-in servers at their configured daily update/restart time';
    public function handle(): int
    {
        foreach (ManagedServer::lightweight()->where('enabled', true)->where('deploy_enabled', true)->where('policy', 'auto')->whereNotNull('maintenance_time')->get() as $server) {
            $clock = now($server->maintenance_timezone);
            if ($clock->format('H:i') === $server->maintenance_time && $server->last_maintenance_on !== $clock->toDateString() && ! $server->active_operation_id) {
                RunScheduledMaintenance::dispatch($server->id, $clock->toDateString());
            }
        }
        return self::SUCCESS;
    }
}
