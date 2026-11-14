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
            try { $context = $op->context ?? []; }
            catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Preserve the exact damaged bytes, phase and exclusion lock.
                // No replay or guessed recovery is safe without the receipt.
                $this->warn('Unreadable encrypted receipt; operation retained for operator recovery: '.$op->id);
                continue;
            }
            if ($op->kind === 'comparison') {
                \Arcturus\ModpackManager\Jobs\CompareInstallation::dispatch($op->managed_server_id, $op->id);
                $this->line('Queued installation comparison reconciliation: '.$op->id);
            } elseif (in_array($op->kind, ['prepare', 'baseline'], true)) {
                PrepareUpdate::dispatch($op->managed_server_id, $op->kind === 'baseline', $op->id);
                $this->line('Queued preparation reconciliation: '.$op->id);
            } elseif ($op->kind === 'restart') {
                app(\Arcturus\ModpackManager\Services\OperationTracker::class)->fail($op, 'Restart worker interrupted. Inspect the Minecraft console before another run.');
            } elseif ($op->kind === 'deploy' && ($context['manual_approval'] ?? false) === true) {
                if ($op->phase === \Arcturus\ModpackManager\Native\ManualStart::PHASE) {
                    $this->line('Manual start remains pending; no power or recovery dispatched: '.$op->id);
                    continue;
                }
                if ($op->phase === 'recovery_queued') {
                    \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery::dispatch($op->id);
                    $this->line('Requeued the same recovery verification receipt: '.$op->id);
                } elseif (in_array($op->phase, ['checking_recovery', 'recovery_starting'], true)) {
                    (new \Arcturus\ModpackManager\Jobs\VerifyDeploymentRecovery($op->id))->failed(null);
                } elseif ($op->phase === 'queued') {
                    \Arcturus\ModpackManager\Jobs\DeployManagedServer::dispatch($op->managed_server_id, (bool) ($context['automatic_approval'] ?? false), $context['plan_sha256'], $op->id);
                    $this->line('Dispatched the same administrator-approved queued receipt: '.$op->id);
                } else {
                    (new \Arcturus\ModpackManager\Jobs\DeployManagedServer($op->managed_server_id, false, $context['plan_sha256'], $op->id))->failed(null);
                    $this->warn('Interrupted deployment retained for manual recovery: '.$op->id);
                }
            } else {
                $this->warn('Manual intervention required; destructive operation will not be replayed: '.$op->id);
            }
        }

        foreach (\Arcturus\ModpackManager\Models\NativeScheduleRun::whereNull('completed_at')->get(['id']) as $run) {
            \Arcturus\ModpackManager\Jobs\RunNativeSchedule::dispatch($run->id);
        }
        app(DiscordNotifier::class)->recover();

        return self::SUCCESS;
    }
}
