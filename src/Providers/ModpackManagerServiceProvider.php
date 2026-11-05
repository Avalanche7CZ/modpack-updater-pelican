<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ModpackManagerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Registered by Pelican's plugin auto-discovery, no panel-core patches.
        $this->app->booted(function (): void {
            if (!$this->app->runningInConsole()) {
                return;
            }
            $hours = max(1, min(24, (int) config('modpack-manager.check_interval_hours', 6)));
            $this->app->make(Schedule::class)->command('modpack:check')
                ->cron('0 */' . $hours . ' * * *')->withoutOverlapping(60);
        });
    }
}
