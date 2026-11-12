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
        $this->app->bind(\App\Repositories\Daemon\DaemonServerRepository::class, \Arcturus\ModpackManager\Services\Pelican\GuardedDaemonServerRepository::class);
        $this->app->bind(\App\Services\Servers\ServerConfigurationStructureService::class, \Arcturus\ModpackManager\Services\Pelican\GuardedServerConfiguration::class);
        $this->mergeConfigFrom(plugin_path('arcturus-modpack-manager', 'config/arcturus-modpack-manager.php'), 'arcturus-modpack-manager');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([\Arcturus\ModpackManager\Console\Commands\RetireCorruptPrewrite::class]);
        }
        $this->app->make(LocalNode::class)->configureQueue();
        $queueKey = 'queue.connections.'.config('queue.default');
        if (in_array(config($queueKey.'.driver'), ['database', 'redis'], true)) {
            config([$queueKey.'.retry_after' => max(14520, (int) config($queueKey.'.retry_after', 0))]);
        }
        $this->app->booted(function (): void {
            $this->app->make(\App\Extensions\Tasks\TaskService::class)->register(new \Arcturus\ModpackManager\Schedules\UpdateModpackTask);
            \Illuminate\Support\Facades\Bus::map([\App\Jobs\Schedule\RunTaskJob::class => \Arcturus\ModpackManager\Schedules\NativeTaskHandler::class]);
        });
        \App\Models\Task::saving(function ($task): void {
            if (($task->action === \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID || $task->getOriginal('action') === \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID) &&
                (! $task->exists || $task->isDirty(['action', 'payload', 'schedule_id']))) {
                \Arcturus\ModpackManager\Security\Access::requireAdmin();
                if ($task->action === \Arcturus\ModpackManager\Schedules\UpdateModpackTask::ID && ! in_array($task->payload, ['update', 'update_restart'], true)) {
                    throw new \RuntimeException('Invalid Update Modpack task payload.');
                }
            }
        });
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
