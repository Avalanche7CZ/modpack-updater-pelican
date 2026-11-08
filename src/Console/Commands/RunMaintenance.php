<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Illuminate\Console\Command;

final class RunMaintenance extends Command
{
    protected $signature = 'arcturus-modpack:maintenance';
    protected $description = 'Deprecated: use the native Pelican Update Modpack schedule task';
    public function handle(): int
    {
        $this->warn('Use Pelican Schedules > Create Task > Update Modpack. The old plugin timer is disabled.');
        return self::SUCCESS;
    }
}
