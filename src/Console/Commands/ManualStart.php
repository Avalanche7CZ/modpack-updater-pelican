<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Models\{ManagedServer, Operation};
use Illuminate\Console\Command;

final class ManualStart extends Command
{
    protected $signature = 'arcturus-modpack:manual-start {operation} {--action=handoff : handoff, verify, observe, arm-start, diagnostics, lease-inspect, release-orphan or rollback}
        {--writers-quiesced : Attest all Panel/queue/Artisan writers quiesced and no pending Wings work} {--seconds=900 : Read-only console observation limit} {--actor= : Identified root administrator ID} {--apply : Apply the reviewed handoff or verification}
        {--confirm= : Exact proof printed by the read-only command} {--reference= : Successful original recovery UUID}
        {--managed-server= : Expected managed server ID} {--backup= : Expected exact locked backup UUID}';
    protected $description = 'Delegate manual power after verified restore/deploy, or verify a fresh manual boot without power/restore';

    public function handle(\Arcturus\ModpackManager\Native\ManualStart $service): int
    {
        try {
            $id = (string) $this->argument('operation'); $actor = (int) $this->option('actor');
            $operation = Operation::findOrFail($id);
            if ($this->option('managed-server') !== null && (string) $operation->managed_server_id !== (string) $this->option('managed-server')) { throw new \RuntimeException('Managed server identity mismatch.'); }
            if ($this->option('backup') !== null && ($operation->context['backup_uuid'] ?? null) !== $this->option('backup')) { throw new \RuntimeException('Backup identity mismatch.'); }
            $result = match ($this->option('action')) {
                'diagnostics' => $this->diagnostics($operation, $actor),
                'lease-inspect' => $service->orphanLease($id, $actor, false, null, false, $this->option('reference')),
                'release-orphan' => $service->orphanLease($id, $actor, (bool) $this->option('apply'), $this->option('confirm'), (bool) $this->option('writers-quiesced'), $this->option('reference')),
                'handoff' => $service->handoff($id, $actor, (bool) $this->option('apply'), $this->option('confirm'), $this->option('reference')),
                'verify' => $service->verify($id, $actor, (bool) $this->option('apply'), $this->option('confirm')),
                'observe' => $service->observe($id, $actor, (int) $this->option('seconds')),
                'arm-start' => $this->arm($service, $operation, $actor),
                'rollback' => $this->rollback($service, $operation, $actor),
                default => throw new \RuntimeException('Unknown manual-start action.'),
            };
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            report($error); $this->error($error instanceof \RuntimeException ? $error->getMessage() : 'Manual start infrastructure unavailable; retain the checkpoint and inspect the private Panel log.'); return self::FAILURE;
        }
    }

    private function diagnostics(Operation $operation, int $actor): array
    {
        if (! \App\Models\User::find($actor)?->isRootAdmin()) { throw new \RuntimeException('An identified root administrator is required.'); }
        return \Arcturus\ModpackManager\Native\HandoffDiagnostics::report(ManagedServer::lightweight()->findOrFail($operation->managed_server_id));
    }

    private function arm($service, Operation $op, int $actor): array
    {
        if (! $this->option('apply')) { throw new \RuntimeException('Arming records a boot boundary; specify --apply. It never starts Minecraft.'); }
        $service->arm($op->id, ManagedServer::lightweight()->findOrFail($op->managed_server_id)->pelicanServer, 'start', $actor);
        return ['message' => 'Boundary recorded. Use normal Pelican Start now, then verify after fresh Done. No power was sent.'];
    }

    private function rollback($service, Operation $op, int $actor): array
    {
        if (! $this->option('apply')) { throw new \RuntimeException('Explicit rollback requires --apply and restores the exact recorded backup. Stop Minecraft first.'); }
        $service->requestRollback($op->id, $actor);
        return ['message' => 'Exact-backup rollback explicitly queued.'];
    }
}
