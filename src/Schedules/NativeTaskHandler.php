<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Schedules;

use App\Extensions\Tasks\TaskService;
use App\Jobs\Schedule\RunTaskJob;

/** Bus adapter for the registered task; all built-in tasks keep their original handler. */
final class NativeTaskHandler
{
    public function handle(RunTaskJob $job): void
    {
        if ($job->task->action !== UpdateModpackTask::ID) {
            $job->handle(app(TaskService::class));
            return;
        }
        app(NativeSchedules::class)->accept($job);
    }
}
