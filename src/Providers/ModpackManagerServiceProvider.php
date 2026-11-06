<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Providers;

use Arcturus\ModpackManager\Services\PluginSettings;
use Arcturus\ModpackManager\Local\LocalNode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class ModpackManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('arcturus-modpack-manager', 'config/arcturus-modpack-manager.php'), 'arcturus-modpack-manager');
    }

    public function boot(): void
    {
        $this->app->make(LocalNode::class)->configureQueue();
        $this->loadViewsFrom(plugin_path('arcturus-modpack-manager', 'resources/views'), 'arcturus-modpack-manager');
        // Configuration is refreshed on each job in long-lived workers as well as web requests.
        $loadSettings = function (): void {
            foreach ($this->app->make(PluginSettings::class)->all() as $key => $value) {
                config(['arcturus-modpack-manager.'.$key => $value]);
            }
        };
        $this->app->booted($loadSettings);
        Queue::before(fn () => $loadSettings());
        // Registered by Pelican's plugin auto-discovery, no panel-core patches.
        $this->app->booted(function (): void {
            if (! $this->app->runningInConsole()) {
                return;
            }
            $this->app->make(Schedule::class)->command('arcturus-modpack:recover')->everyFiveMinutes()->withoutOverlapping();
            $hours = max(1, min(24, (int) config('arcturus-modpack-manager.check_interval_hours', 6)));
            $this->app->make(Schedule::class)->command('arcturus-modpack:check')
                ->cron('0 */'.$hours.' * * *')->withoutOverlapping(60);
        });
    }
}
