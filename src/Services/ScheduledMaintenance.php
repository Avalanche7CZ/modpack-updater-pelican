<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Services;

use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use RuntimeException;

/** Explicit per-server opt-in. All updates use the ordinary backup/deploy/recovery job. */
final class ScheduledMaintenance
{
    public function requireEnabled(ManagedServer $server): void
    {
        $actor = $server->automation_actor_id ? \App\Models\User::find($server->automation_actor_id) : null;
        if (! $server->enabled || ! $server->deploy_enabled || $server->policy !== 'auto' ||
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
        throw new RuntimeException('The plugin daily timer has been replaced by Pelican Schedules > Update Modpack.');
    }
}
