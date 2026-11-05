<?php
declare(strict_types=1);

namespace Arcturus\ModpackManager\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ModpackManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('modpack-manager', 'config/modpack-manager.php'), 'modpack-manager');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(plugin_path('modpack-manager', 'resources/views'), 'modpack-manager');
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
