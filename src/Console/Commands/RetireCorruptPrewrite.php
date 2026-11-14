<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Console\Commands;

use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\NativeScheduleRun;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Native\Qualification;
use Arcturus\ModpackManager\Native\Transaction;
use Arcturus\ModpackManager\Native\Wings;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Arcturus\ModpackManager\Services\SameHostDeployment;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Exceptional operator-only retirement of a damaged, PRE-BACKUP native receipt.
 * Never restore, delete a file, retry apply, or start Minecraft.
 */
final class RetireCorruptPrewrite extends Command
{
    protected $signature = 'arcturus-modpack:retire-corrupt-prewrite
        {operation : Exact damaged deployment operation UUID}
        {--apply : Retire the verified pre-write checkpoint without changing Minecraft files}
        {--confirm= : SHA-256 proof printed by a previous read-only run}
        {--expect-count= : Exact independently observed number of original candidate paths}';

    protected $description = 'Verify and explicitly retire an unreadable native pre-backup receipt; fail closed otherwise';

    public function handle(): int
    {
        $id = (string) $this->argument('operation');
        if (! Str::isUuid($id)) { $this->error('An exact operation UUID is required.'); return self::FAILURE; }
        $apply = (bool) $this->option('apply');
        $lease = null;
        try {
            if ($apply) {
                // The deployment worker uses this same atomic cache lease. Never force-unlock.
                $serverId = DB::table('arcturus_modpack_operations')->where('id', $id)->value('managed_server_id');
                if (! $serverId) { throw new RuntimeException('Operation not found.'); }
                $lease = new \Arcturus\ModpackManager\Native\DeploymentLease((int) $serverId, $id, 'retire_prewrite');
                if (! $lease->get()) { throw new RuntimeException('Deployment lease is held. Keep the server offline; do not force-unlock.'); }
            }
            $check = $this->inspect($id);
            $this->line('Verified native pre-backup checkpoint: '.$id);
            $this->line('Original candidate paths verified: '.$check['count']);
            $this->line('Original Panel startup/image/egg/variables verified. No new backup or native stage found.');
            $this->line('Proof: '.$check['proof']);
            if (! $apply) {
                $this->warn('READ-ONLY. No lock or server setting was changed. Use --apply only after independently reviewing this exact server.');
                return self::SUCCESS;
            }
            if (! hash_equals($check['proof'], (string) $this->option('confirm')) ||
                ! ctype_digit((string) $this->option('expect-count')) ||
                (int) $this->option('expect-count') !== $check['count']) {
                throw new RuntimeException('Proof or expected path count does not match the independently reviewed read-only report.');
            }
            DB::transaction(function () use ($check, $id): void {
                $operation = Operation::query()->lockForUpdate()->findOrFail($id);
                $managed = ManagedServer::query()->lockForUpdate()->findOrFail($check['managed_id']);
                if (! in_array($operation->phase, ['stopping', 'verifying_before_backup'], true) || $operation->completed_at !== null ||
                    $operation->backup_uuid !== null || $managed->active_operation_id !== $id ||
                    $managed->installed_version !== $check['installed_version'] ||
                    ($managed->preview_summary['plan_sha256'] ?? null) !== $check['plan_sha256'] ||
                    ($managed->approved_plan_sha256 !== null &&
                        ! hash_equals($check['plan_sha256'], $managed->approved_plan_sha256))) {
                    throw new RuntimeException('Deployment state changed while being reviewed. No lock was released.');
                }
                $raw = DB::table('arcturus_modpack_operations')->where('id', $id)->value('context');
                if (! is_string($raw) || ! hash_equals($check['cipher_sha256'], hash('sha256', $raw))) {
                    throw new RuntimeException('Damaged receipt changed while being reviewed. No lock was released.');
                }
                if ($managed->pelicanServer->backups()->where('created_at', '>=', $operation->created_at)->exists() ||
                    $managed->pelicanServer->backups()->whereNull('completed_at')->exists()) {
                    throw new RuntimeException('Backup appeared while retiring the receipt. No lock was released.');
                }
                // Preserve the exact truncated ciphertext, operation UUID and audit record.
                DB::table('arcturus_modpack_operations')->where('id', $id)->update([
                    'phase' => 'cancelled', 'completed_at' => now(),
                    'error' => 'Operator verified a pre-backup, pre-write interruption; corrupt encrypted evidence retained. Never replay this operation.',
                    'updated_at' => now(),
                ]);
                $updated = DB::table('arcturus_modpack_servers')
                    ->where('id', $managed->id)->where('active_operation_id', $id)
                    ->update([
                        'active_operation_id' => null, 'approved_plan_sha256' => null,
                        'approved_at' => null, 'approved_by' => null,
                        'last_preview_at' => null, 'comparison_status' => 'incomplete',
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) { throw new RuntimeException('Server lock changed during retirement.'); }
                $managed->events()->create([
                    'kind' => 'deploy', 'status' => 'failed',
                    'message' => 'Damaged pre-backup deployment abandoned after independent file and startup verification; no update was applied.',
                    'details' => ['operation_id' => $id, 'verified_paths' => $check['count'],
                        'review_proof' => $check['proof'], 'cipher_sha256' => $check['cipher_sha256'],
                        'original_version' => $check['installed_version']],
                ]);
            });
            // A successful retirement does not start Minecraft. Synchronize the
            // ordinary configuration separately; failures remain visible to operator.
            try {
                app(PelicanPowerController::class)->sync($check['server']);
            } catch (Throwable) {
                $this->warn('Operation retired, but Wings config sync failed. Check normal Panel/Wings sync before manually starting the original server.');
                return self::SUCCESS;
            }
            $this->info('Pre-write operation retired. Minecraft remains offline. Review and start the ORIGINAL installation manually; re-compare before any new update.');
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof RuntimeException ? $error->getMessage()
                : 'Verification failed; inspect the private Panel log. No deliberate lock release was performed.');
            report($error);
            return self::FAILURE;
        } finally {
            if ($lease !== null) { $lease->release(); }
        }
    }

    /** All expensive reads are repeated inside the deployment lease when --apply is used. */
    private function inspect(string $id): array
    {
        $operation = Operation::query()->findOrFail($id);
        if ($operation->kind !== 'deploy' || ! in_array($operation->phase, ['stopping', 'verifying_before_backup'], true) ||
            $operation->completed_at !== null || $operation->backup_uuid !== null ||
            ! $operation->heartbeat_at || $operation->heartbeat_at->gt(now()->subMinutes(30))) {
            throw new RuntimeException('Only an old, unfinished native stopping or pre-backup verification checkpoint without a recorded backup can be retired.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) &&
            Schema::getColumnType('arcturus_modpack_operations', 'context') !== 'longtext') {
            throw new RuntimeException('Apply the LONGTEXT migration before reviewing the damaged receipt.');
        }
        $cipher = DB::table('arcturus_modpack_operations')->where('id', $id)->value('context');
        if (! is_string($cipher) || strlen($cipher) !== 65535) {
            throw new RuntimeException('Receipt does not match the known 65,535-byte truncation pattern.');
        }
        try {
            $operation->context;
        } catch (DecryptException) {
            $unreadable = true;
        }
        if (! ($unreadable ?? false)) {
            throw new RuntimeException('Receipt is readable or does not match the known corruption; use ordinary recovery instead.');
        }
        $managed = ManagedServer::query()->findOrFail($operation->managed_server_id);
        if ($managed->transport !== 'native' || $managed->active_operation_id !== $id) {
            throw new RuntimeException('Operation is not the active native deployment lock.');
        }
        if (NativeScheduleRun::where('operation_id', $id)->whereNull('completed_at')->exists()) {
            throw new RuntimeException('An active native schedule still references this operation.');
        }
        $server = $managed->pelicanServer;
        if (! $server || $server->status !== null ||
            $server->backups()->where('created_at', '>=', $operation->created_at)->exists() ||
            $server->backups()->whereNull('completed_at')->exists()) {
            throw new RuntimeException('Server state or backup history cannot prove a pre-backup interruption.');
        }
        $details = app(PelicanPowerController::class)->details($server);
        if (($details['state'] ?? null) !== 'offline') {
            throw new RuntimeException('Wings must independently report the server offline.');
        }
        $preview = $managed->preview_summary ?? [];
        $qual = $preview['qualification'] ?? [];
        $plan = $preview['plan_sha256'] ?? null;
        $approval = $managed->approved_plan_sha256;
        if (($preview['from'] ?? null) !== $managed->installed_version ||
            ($qual['protocol'] ?? null) !== Qualification::PROTOCOL ||
            ! is_array($preview['changes'] ?? null) ||
            ! is_string($qual['panel_sha256'] ?? null) ||
            ! is_string($plan) || preg_match('/^[a-f0-9]{64}$/D', $plan) !== 1) {
            throw new RuntimeException('An original, exact native comparison is required for independent verification.');
        }
        // Deployment failure intentionally clears approved_plan_sha256 in its
        // error handler. Retirement is not an approval or a deployment: rely
        // on independently reverified original files and runtime instead.
        // A *present but different* approval is still a hard inconsistency.
        if ($approval !== null && (! is_string($approval) || ! hash_equals($plan, $approval))) {
            throw new RuntimeException('Saved approval conflicts with the retained plan. Do not retire without inspection.');
        }
        $before = Transaction::scope($preview['changes'])['before'];
        if (count($before) === 0) {
            throw new RuntimeException('Original candidate comparison contains no paths; inspect manually.');
        }
        $files = new Wings($server, app(DaemonFileRepository::class));
        foreach ($files->listing() as $entry) {
            if (str_starts_with($entry['name'], '.arcturus-update-') ||
                str_starts_with($entry['name'], '.arcturus-recovery-')) {
                throw new RuntimeException('A native mutation/recovery stage exists. Do not retire this operation automatically.');
            }
        }
        (new Transaction($files))->verify($before);
        if (! is_string($qual['properties_sha256'] ?? null) ||
            $files->hash('server.properties') !== $qual['properties_sha256']) {
            throw new RuntimeException('Original world-properties fingerprint differs.');
        }
        $snapshot = app(SameHostDeployment::class)->panelSnapshot($server);
        $snapshotSha = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        if (! hash_equals($qual['panel_sha256'], $snapshotSha)) {
            throw new RuntimeException('Original Panel startup/image/egg/variables differ from the approved snapshot.');
        }
        $proof = hash('sha256', json_encode([$id, $managed->id,
            $operation->created_at?->toIso8601String(), $managed->installed_version,
            $snapshotSha, $plan, $before, hash('sha256', $cipher)], JSON_THROW_ON_ERROR));
        return ['proof' => $proof, 'plan_sha256' => $plan, 'count' => count($before), 'cipher_sha256' => hash('sha256', $cipher),
            'managed_id' => $managed->id, 'installed_version' => $managed->installed_version, 'server' => $server];
    }
}
