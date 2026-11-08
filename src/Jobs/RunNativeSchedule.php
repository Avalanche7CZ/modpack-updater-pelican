<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Models\NativeScheduleRun;
use Arcturus\ModpackManager\Schedules\NativeSchedules;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunNativeSchedule implements ShouldQueue
{
    use Queueable;
    public int $timeout = 1800;
    public int $tries = 1;
    public function __construct(public readonly string $runId) {}
    public function handle(NativeSchedules $schedules): void { $schedules->tick($this->runId); }
    public function failed(?\Throwable $error): void
    {
        // The receipt is an outbox. Recovery resumes observation, never blindly reapplies a deployment.
        NativeScheduleRun::whereKey($this->runId)->whereNull('completed_at')->update(['error' => 'Schedule worker interrupted; awaiting reconciliation.']);
    }
}
