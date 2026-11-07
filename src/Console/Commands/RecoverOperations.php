<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Jobs\PrepareUpdate;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\DiscordNotifier;
use Illuminate\Console\Command;

final class RecoverOperations extends Command
{
    protected $signature = 'arcturus-modpack:recover';

    protected $description = 'Resume read-only node preparations; report interrupted deployment for manual recovery';

    public function handle(): int
    {
        foreach (Operation::whereNull('completed_at')->where('heartbeat_at', '<', now()->subMinutes(5))->get() as $op) {
            if ($op->kind === 'comparison') {
                \Arcturus\ModpackManager\Jobs\CompareInstallation::dispatch($op->managed_server_id, $op->id);
                $this->line('Queued installation comparison reconciliation: '.$op->id);
            } elseif (in_array($op->kind, ['prepare', 'baseline'], true)) {
                PrepareUpdate::dispatch($op->managed_server_id, $op->kind === 'baseline', $op->id);
                $this->line('Queued preparation reconciliation: '.$op->id);
            } elseif ($op->kind === 'deploy' && ($op->context['manual_approval'] ?? false) === true) {
                if ($op->phase === 'recovery_queued') {
                    \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery::dispatch($op->id);
                    $this->line('Requeued the same recovery verification receipt: '.$op->id);
                } elseif ($op->phase === 'checking_recovery') {
                    (new \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery($op->id))->failed(null);
                } elseif ($op->phase === 'queued') {
                    \Arcturus\ModpackManager\Jobs\DeployManagedServer::dispatch($op->managed_server_id, false, $op->context['plan_sha256'], $op->id);
                    $this->line('Dispatched the same administrator-approved queued receipt: '.$op->id);
                } else {
                    (new \Arcturus\ModpackManager\Jobs\DeployManagedServer($op->managed_server_id, false, $op->context['plan_sha256'], $op->id))->failed(null);
                    $this->warn('Interrupted deployment retained for manual recovery: '.$op->id);
                }
            } else {
                $this->warn('Manual intervention required; destructive operation will not be replayed: '.$op->id);
            }
        }

        app(DiscordNotifier::class)->recover();

        return self::SUCCESS;
    }
}
