<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Jobs;

use Arcturus\ModpackManager\Services\ScheduledMaintenance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunScheduledMaintenance implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 1800;
    public function __construct(public readonly int $managedServerId, public readonly string $day) {}
    public function handle(ScheduledMaintenance $maintenance): void { \Illuminate\Support\Facades\Log::notice('Skipped deprecated plugin timer job; configure a native Pelican Update Modpack task.', ['managed_server_id' => $this->managedServerId]); }
    public function failed(?\Throwable $error): void
    {
        $server = \Arcturus\ModpackManager\Models\ManagedServer::find($this->managedServerId);
        if (! $server) { return; }
        if ($server->active_operation_id) {
            $operation = $server->operations()->find($server->active_operation_id);
            if ($operation && $operation->kind === 'restart' && ($operation->context['scheduled_on'] ?? null) === $this->day && ! $operation->completed_at) {
                app(\Arcturus\ModpackManager\Services\OperationTracker::class)->fail($operation, 'Scheduled restart interrupted; inspect the server console.');
            }
        }
        $event = $server->events()->create(['kind' => 'deploy', 'status' => 'failed',
            'message' => 'Scheduled maintenance did not finish. Review the server history before retrying.',
            'details' => ['scheduled_on' => $this->day, 'discord_notification_pending' => true]]);
        app(\Arcturus\ModpackManager\Services\DiscordNotifier::class)->forEvent($event);
    }
}
