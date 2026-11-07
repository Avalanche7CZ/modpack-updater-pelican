<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit per-server opt-in. All updates use the ordinary backup/deploy/recovery job. */
final class ScheduledMaintenance
{
    public function requireEnabled(ManagedServer $server): void
    {
        $actor = $server->automation_actor_id ? \App\Models\User::find($server->automation_actor_id) : null;
        if (! $server->enabled || ! $server->deploy_enabled || $server->policy !== 'auto' || ! $server->maintenance_time ||
            $server->transport !== 'local' || ! $server->local_root || ! is_dir($server->local_root) || $server->backup_mode !== 'pelican' || $server->baseline_status !== 'verified' || ! $server->adopted_at ||
            ! $actor || ! $actor->isRootAdmin()) {
            throw new RuntimeException('Scheduled maintenance requires per-server administrator opt-in, a verified local installation and Pelican backups.');
        }
        (new \Arcturus\ModpackManager\Local\LocalWorker($server))->configuration();
        app(QueuePreparation::class)->requireLocalQueue();
    }

    public function deployPrepared(ManagedServer $server, ?Operation $preparation = null): void
    {
        $server->refresh();
        $this->requireEnabled($server);
        if ($preparation && (($preparation->context['policy_revision'] ?? null) !== $server->policy_revision ||
            ($preparation->context['actor_id'] ?? null) !== $server->automation_actor_id)) {
            throw new RuntimeException('Automatic update settings changed during preparation; review the prepared update manually.');
        }
        app(DeploymentApproval::class)->queueAutomatic($server);
    }

    public function run(int $serverId, string $day): void
    {
        $server = ManagedServer::findOrFail($serverId);
        $this->requireEnabled($server);
        $claimed = DB::transaction(function () use ($server, $day): bool {
            $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($server->id);
            $this->requireEnabled($fresh);
            $clock = now($fresh->maintenance_timezone);
            if ($fresh->active_operation_id || $fresh->last_maintenance_on === $day || $clock->toDateString() !== $day ||
                $clock->format('H:i') !== $fresh->maintenance_time) { return false; }
            $fresh->update(['last_maintenance_on' => $day]);
            return true;
        });
        if (! $claimed) { return; }
        $result = app(ReleaseChecker::class)->check($server);
        if (! in_array($result['state'], ['available', 'current'], true)) { throw new RuntimeException('Scheduled release check did not complete; no restart or update attempted.'); }
        $server->refresh();
        $this->requireEnabled($server);
        if ($server->active_operation_id) { return; }
        if ($server->targetVersion() === $server->installed_version) {
            if (! $server->restart_when_current) { return; }
            $operation = app(OperationTracker::class)->create($server, 'restart', ['scheduled_on' => $day, 'actor_id' => $server->automation_actor_id]);
            try {
                $power = app(PelicanPowerController::class);
                if ($power->state($server->pelicanServer) === 'running') {
                    $power->stop($server->pelicanServer);
                    $power->startAndVerify($server->pelicanServer, $server->local_root);
                }
                app(OperationTracker::class)->transition($operation, 'completed');
            } catch (\Throwable $error) {
                app(OperationTracker::class)->fail($operation, 'Scheduled restart failed; inspect the server console.');
                throw $error;
            }
            return;
        }
        if ($server->target_manifest_path && ($server->target_metadata['prepared']['version'] ?? null) === $server->targetVersion()) {
            app(PreviewService::class)->generate($server);
            $this->deployPrepared($server);
            return;
        }
        // A fresh preparation reuses the worker's verified artifact cache. Compare uses only the manifest delta.
        $operation = app(OperationTracker::class)->create($server, 'prepare', [
            'version' => $server->targetVersion(), 'provider' => $server->provider, 'source' => $server->source,
            'scheduled_on' => $day, 'automatic' => true, 'policy_revision' => $server->policy_revision,
            'actor_id' => $server->automation_actor_id,
        ]);
        \Arcturus\ModpackManager\Jobs\PrepareUpdate::dispatch($server->id, false, $operation->id);
    }
}
