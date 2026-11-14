<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Console\Commands;

use Arcturus\ModpackManager\Models\{ManagedServer, Operation};
use Arcturus\ModpackManager\Native\Qualification;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB};
use RuntimeException;

/** Explicit host-operator attestation. Stock Wings has no archive job cancellation/status API. */
final class ConfirmArchiveQuiesced extends Command
{
    protected $signature = 'arcturus-modpack:confirm-archive-quiesced {operation}
        {--apply : Record operator clearance; does not restore, delete or start anything}
        {--daemon-restarted : Attest Wings was restarted AFTER the uncertain archive request ended}
        {--confirm= : Exact proof from the read-only invocation}';
    protected $description = 'Inspect an uncertain native archive receipt and record explicit operator clearance after Wings restart';

    public function handle(): int
    {
        $lock = null;
        try {
            $op = Operation::findOrFail((string) $this->argument('operation'));
            $managed = ManagedServer::lightweight()->findOrFail($op->managed_server_id);
            $lock = Cache::lock('arcturus-modpack-manager-deploy-'.$managed->id, 14400);
            if (! $lock->get()) { throw new RuntimeException('Worker lease is held; never force-unlock it.'); }
            $op->refresh();
            if ($managed->refresh()->active_operation_id !== $op->id || $op->phase !== 'manual_intervention' ||
                ($op->context['protocol'] ?? '') !== Qualification::PROTOCOL || ! ($op->context['native_archive_pending'] ?? false)) {
                throw new RuntimeException('An active manual-intervention native archive receipt is required.');
            }
            $power = app(PelicanPowerController::class);
            $details = $power->details($managed->pelicanServer);
            if (! in_array($details['state'] ?? '', ['offline', 'exited'], true) || ($details['configuration']['suspended'] ?? null) !== true) {
                throw new RuntimeException('Wings must confirm the server stopped and suspended.');
            }
            $cipher = $op->getRawOriginal('context'); $proof = hash('sha256', $op->id."\n".$cipher);
            $this->line('Operation: '.$op->id.'; server: '.$managed->pelican_server_uuid.'; proof: '.$proof);
            $this->line('Stock Wings cannot prove archive completion through its API. Retain stage, backup and maintenance.');
            if (! $this->option('apply')) { return self::SUCCESS; }
            if (! $this->option('daemon-restarted') || ! hash_equals($proof, (string) $this->option('confirm'))) {
                throw new RuntimeException('Exact proof and explicit Wings restart attestation are required.');
            }
            DB::transaction(function () use ($op, $cipher, $lock): void {
                $fresh = Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                if (! $lock->isOwnedByCurrentProcess() || $fresh->phase !== 'manual_intervention' || $fresh->getRawOriginal('context') !== $cipher) {
                    throw new RuntimeException('Receipt or lease changed; no clearance saved.');
                }
                $fresh->update(['context' => [...$fresh->context, 'native_archive_pending' => false,
                    'native_archive_clearance' => ['operator_attested_daemon_restart' => true, 'at' => microtime(true), 'prior_cipher_sha256' => hash('sha256', $cipher)]]]);
            }, 1);
            $this->line('Clearance recorded. Maintenance remains held. Use the existing administrator recovery flow; never replay apply.');
            return self::SUCCESS;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
        finally { $lock?->release(); }
    }
}
