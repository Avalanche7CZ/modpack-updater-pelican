<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Schedules;

use App\Jobs\Schedule\RunTaskJob;
use App\Models\Task;
use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Jobs\RunNativeSchedule;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\NativeScheduleRun;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\DeploymentApproval;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\ReleaseChecker;
use Arcturus\ModpackManager\Services\ScheduledMaintenance;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/** Durable bridge: native tasks advance only after the modpack operation finishes. */
final class NativeSchedules
{
    public function accept(RunTaskJob $job): void
    {
        $queueId = $job->job?->getJobId();
        $key = $queueId !== null && $queueId !== '' ? $job->task->id.':'.$job->job->getConnectionName().':'.$queueId : $job->task->id.':manual:'.Str::uuid();
        $existing = NativeScheduleRun::where('dispatch_key', $key)->first();
        if ($existing) {
            if (! $existing->completed_at) { RunNativeSchedule::dispatch($existing->id); }
            return;
        }
        $task = $job->task->fresh();
        if (! $task || (! $task->schedule->is_active && ! $job->manualRun) || $task->server->status !== null) {
            $job->failed(); return;
        }
        if (! in_array($task->payload, ['update', 'update_restart'], true)) { throw new RuntimeException('Invalid Update Modpack task payload.'); }
        $server = ManagedServer::where('pelican_server_uuid', $task->server->uuid)->firstOrFail();
        app(ScheduledMaintenance::class)->requireEnabled($server);
        $run = DB::transaction(function () use ($key, $task, $server, $job): NativeScheduleRun {
            Task::lockForUpdate()->findOrFail($task->id);
            $existing = NativeScheduleRun::where('dispatch_key', $key)->first() ??
                NativeScheduleRun::where('task_id', $task->id)->whereNull('completed_at')->first();
            if ($existing) { return $existing; }
            return NativeScheduleRun::create([
                'dispatch_key' => $key, 'id' => (string) Str::uuid(), 'task_id' => $task->id, 'managed_server_id' => $server->id,
                'task_snapshot' => ['schedule_id' => $task->schedule_id, 'sequence_id' => $task->sequence_id, 'payload' => $task->payload, 'server_uuid' => $task->server->uuid],
                'manual_run' => $job->manualRun, 'policy_revision' => $server->policy_revision, 'actor_id' => $server->automation_actor_id,
            ]);
        });
        if (! $run->completed_at) { RunNativeSchedule::dispatch($run->id); }
    }

    public function tick(string $id): void
    {
        $lock = Cache::lock('arcturus-native-schedule-'.$id, 1830);
        if (! $lock->get()) { return; }
        try {
            $run = NativeScheduleRun::findOrFail($id);
            if ($run->completed_at) { return; }
            $server = ManagedServer::findOrFail($run->managed_server_id);
            if ($run->operation_id) {
                $operation = $server->operations()->findOrFail($run->operation_id);
                if (! $operation->completed_at) { $this->poll($run); return; }
                if ($operation->phase !== 'completed') { $this->finish($run, false, 'Modpack operation ended as '.$operation->phase.'. Inspect modpack History before retrying.'); return; }
                if ($operation->kind === 'prepare') {
                    $this->authorize($run, $server);
                    DB::transaction(function () use ($run, $server): void {
                        $deploymentId = (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, $run->id.':deployment');
                        $deployment = app(DeploymentApproval::class)->queueAutomatic($server, $deploymentId);
                        $run->update(['operation_id' => $deployment->id]);
                    });
                    $this->poll($run); return;
                }
                $this->finish($run, true); return;
            }
            $this->authorize($run, $server);
            $task = Task::find($run->task_id);
            if (! $task || ! $task->is_queued || ! $task->schedule->is_processing || ! $this->matches($run, $task) || (! $task->schedule->is_active && ! $run->manual_run) || $task->server->status !== null) {
                $this->finish($run, false, 'Native task or server changed before maintenance started.'); return;
            }
            $result = app(ReleaseChecker::class)->check($server);
            if (! in_array($result['state'], ['available', 'current'], true)) { throw new RuntimeException('Release check unavailable or server busy. No update or restart started.'); }
            $server->refresh();
            $this->authorize($run, $server);
            $version = $server->available_version;
            if (! $version) { throw new RuntimeException('Release check returned no target version.'); }
            if ($version === $server->installed_version && $task->payload === 'update') { $this->finish($run, true); return; }
            DB::transaction(function () use ($run, $server, $version): void {
                $fresh = ManagedServer::lockForUpdate()->findOrFail($server->id);
                $this->authorize($run, $fresh);
                if ($fresh->active_operation_id) { throw new RuntimeException('A server operation is already active.'); }
                if ($version !== $fresh->installed_version && $fresh->selected_version !== $version) {
                    $fresh->update(['selected_version' => $version, 'target_metadata' => $fresh->release_metadata,
                        'target_manifest_path' => null, 'prepared_root' => null, 'preview_summary' => null]);
                }
                $restart = $version === $fresh->installed_version;
                $operation = app(OperationTracker::class)->create($fresh, $restart ? 'restart' : 'prepare', [
                    'version' => $version, 'provider' => $fresh->provider, 'source' => $fresh->source,
                    'automatic' => true, 'native_schedule_run_id' => $run->id,
                    'policy_revision' => $fresh->policy_revision, 'actor_id' => $fresh->automation_actor_id,
                ], $run->id);
                $run->update(['state' => 'waiting', 'operation_id' => $operation->id]);
                DB::afterCommit(function () use ($fresh, $operation, $restart): void {
                    if (! $restart) { PrepareUpdate::dispatch($fresh->id, false, $operation->id); }
                });
            });
            $operation = Operation::findOrFail($run->operation_id);
            if ($operation->kind === 'restart') {
                app(OperationTracker::class)->transition($operation, 'restarting');
                $power = app(PelicanPowerController::class);
                try {
                    if ($power->state($server->pelicanServer) === 'running') {
                        $heartbeat = fn () => $operation->update(['heartbeat_at' => now()]);
                        $power->stop($server->pelicanServer, heartbeat: $heartbeat);
                        if ($server->transport === 'native') {
                            $files = new \Arcturus\ModpackManager\Native\Wings($server->pelicanServer, app(\App\Repositories\Daemon\DaemonFileRepository::class), $heartbeat);
                            app(\Arcturus\ModpackManager\Native\Boot::class)->start($server->pelicanServer, $files, $operation->id,
                                [], fn (array $row) => $operation->update(['context' => [...$operation->context, 'native_checkpoint' => $row]]), $heartbeat);
                        } else { $power->startAndVerify($server->pelicanServer, $server->local_root, heartbeat: $heartbeat); }
                    }
                    app(OperationTracker::class)->transition($operation, 'completed');
                } catch (\Throwable $error) {
                    app(OperationTracker::class)->fail($operation, 'Scheduled restart failed. Check the Minecraft console.'); throw $error;
                }
            }
            $this->poll($run);
        } catch (\Throwable $error) {
            Log::error('Arcturus native schedule failed', ['schedule_run_id' => $id, 'exception' => $error]);
            $run = NativeScheduleRun::find($id);
            if ($run && ! $run->completed_at) {
                $operation = $run->operation_id ? Operation::find($run->operation_id) : null;
                if ($operation && ! $operation->completed_at) { $run->update(['error' => 'Awaiting operation reconciliation; inspect administrator logs.']); $this->poll($run); }
                else { $this->finish($run, false, 'Update Modpack failed. Inspect modpack History and administrator logs using schedule run '.$id.'.'); }
            }
        } finally { $lock->release(); }
    }

    private function authorize(NativeScheduleRun $run, ManagedServer $server): void
    {
        app(ScheduledMaintenance::class)->requireEnabled($server);
        if ($server->pelican_server_uuid !== $run->task_snapshot['server_uuid'] || $server->policy_revision !== $run->policy_revision || $server->automation_actor_id !== $run->actor_id) {
            throw new RuntimeException('Scheduled update permission or policies changed.');
        }
    }
    private function poll(NativeScheduleRun $run): void { RunNativeSchedule::dispatch($run->id)->delay(now()->addSeconds(10)); }
    private function matches(NativeScheduleRun $run, Task $task): bool
    {
        return $task->server?->uuid === $run->task_snapshot['server_uuid'] && $task->action === UpdateModpackTask::ID && $task->schedule_id === $run->task_snapshot['schedule_id'] &&
            $task->sequence_id === $run->task_snapshot['sequence_id'] && $task->payload === $run->task_snapshot['payload'];
    }
    private function finish(NativeScheduleRun $run, bool $success, ?string $error = null): void
    {
        DB::transaction(function () use ($run, $success, $error): void {
            $fresh = NativeScheduleRun::lockForUpdate()->findOrFail($run->id);
            if ($fresh->completed_at) { return; }
            $task = Task::lockForUpdate()->find($fresh->task_id);
            $fresh->update(['state' => $success ? 'completed' : 'failed', 'error' => $error, 'completed_at' => now()]);
            ManagedServer::find($fresh->managed_server_id)?->events()->create(['kind' => 'schedule', 'status' => $success ? 'success' : 'failed',
                'message' => $success ? 'Native Update Modpack task completed.' : ($error ?? 'Native Update Modpack task failed.'),
                'details' => ['schedule_run_id' => $fresh->id, 'task_id' => $fresh->task_id, 'operation_id' => $fresh->operation_id]]);
            $schedule = \App\Models\Schedule::lockForUpdate()->find($fresh->task_snapshot['schedule_id']);
            if (! $schedule) { return; }
            if ($task && $task->schedule_id === $schedule->id) { $task->update(['is_queued' => false]); }
            $next = $success && $task && $this->matches($fresh, $task) && ($schedule->is_active || $fresh->manual_run) && $task->server->status === null
                ? $schedule->tasks()->where('sequence_id', '>', $task->sequence_id)->orderBy('sequence_id')->first() : null;
            if ($next) {
                $next->update(['is_queued' => true]);
                Bus::dispatch((new RunTaskJob($next, $fresh->manual_run))->delay($next->time_offset)->afterCommit());
            } else { $schedule->update(['is_processing' => false, 'last_run_at' => now()]); }
        });
    }
}
