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
            if (in_array($op->kind, ['prepare', 'baseline'], true)) {
                PrepareUpdate::dispatch($op->managed_server_id, $op->kind === 'baseline', $op->id);
                $this->line('Queued preparation reconciliation: '.$op->id);
            } else {
                $this->warn('Manual intervention required; destructive operation will not be replayed: '.$op->id);
            }
        }

        app(DiscordNotifier::class)->recover();

        return self::SUCCESS;
    }
}
