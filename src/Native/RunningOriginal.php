<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\ActivityLog;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\ManagedServer;
use Arcturus\ModpackManager\Models\Operation;
use Arcturus\ModpackManager\Services\BackupExclusions;
use Arcturus\ModpackManager\Services\BackupSafetyGate;
use Arcturus\ModpackManager\Services\OperationTracker;
use Arcturus\ModpackManager\Services\PelicanPowerController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Read-only evidence collection; only explicit apply may close a verified recovery and sync configuration. */
final class RunningOriginal
{
    public function run(string $id, bool $apply = false, ?int $actor = null, bool $syncOnly = false): array
    {
        if (!\Illuminate\Support\Str::isUuid($id)) { throw new RuntimeException('Invalid operation UUID.'); }
        if ($apply && (!$actor || !User::find($actor)?->isRootAdmin())) { throw new RuntimeException('Apply requires an identified root administrator (--actor).'); }
        $operation = Operation::findOrFail($id);
        $managed = ManagedServer::lightweight()->findOrFail($operation->managed_server_id);
        $lease = Cache::lock('arcturus-modpack-manager-deploy-'.$managed->id, 14400);
        if (!$lease->get()) { throw new RuntimeException('Another deploy/recovery worker owns the server lease. No action taken.'); }
        try {
            $operation->refresh(); $managed->refresh(); $context = $operation->context ?? [];
            if ($managed->transport !== 'native' || $operation->kind !== 'deploy' || ($context['protocol'] ?? '') !== Qualification::PROTOCOL || ($context['manual_approval'] ?? false) !== true) { throw new RuntimeException('Not a Native Wings deployment recovery receipt.'); }
            $retry = $syncOnly && $operation->phase === 'restored' && ($context['running_original_sync_pending'] ?? false);
            if ($syncOnly && !$retry) { throw new RuntimeException('Sync-only requires an already restored receipt with a pending verified sync. No recovery was closed.'); }
            if ((!$retry && ($operation->phase !== 'manual_intervention' || $managed->active_operation_id !== $id)) ||
                ($retry && $managed->active_operation_id !== null)) { throw new RuntimeException('Receipt no longer exclusively owns this recovery, or sync retry is unavailable.'); }
            $server = $managed->pelicanServer;
            if (!$server || $server->uuid !== $managed->pelican_server_uuid || $server->status !== null || $server->isSuspended() ||
                $server->backups()->whereNull('completed_at')->exists()) { throw new RuntimeException('Server identity, administrator suspension, or active backup/restore blocks verification.'); }
            if ($managed->operations()->where('id', '!=', $id)->whereNull('completed_at')->exists()) { throw new RuntimeException('Another operation is unfinished.'); }
            $assertLease = function () use ($lease): void { if (!$lease->isOwnedByCurrentProcess()) { throw new RuntimeException('Verification lease expired or changed owner. No release is authorized.'); } };
            $power = app(PelicanPowerController::class); $files = new Wings($server, app(DaemonFileRepository::class), $assertLease);
            $sample = function () use ($power, $server): array {
                $before = microtime(true); $details = $power->details($server);
                return [$details, RunningInstance::sample($details, $before, microtime(true))];
            };
            [$details, $instance] = $sample();
            $original = $context['runtime_original'] ?? null; $runtime = $context['native_original_runtime'] ?? null;
            if (!is_array($original) || !is_array($runtime) || empty($runtime['minecraft']) || empty($runtime['loader']) || !is_int($runtime['java'] ?? null) || $runtime['java'] < 1) { throw new RuntimeException('Missing original Panel snapshot or expected runtime receipt.'); }
            app(Runtime::class)->requireSnapshot($server, $original);
            $this->requireConfiguration($details, $original, $server);
            if ($retry) {
                $proof = $context['running_original_verification'];
                if (($proof['server_uuid'] ?? null) !== $server->uuid || ($proof['server_id'] ?? null) !== $server->id || ($proof['operation_id'] ?? null) !== $id) { throw new RuntimeException('Sync proof belongs to a different server/operation.'); }
                $instance = RunningInstance::same($instance, RunningInstance::receipt($proof['instance']));
            } else {
                $confirmation = $this->restoreProof($operation, $server, $context);
                $critical = $context['native_restore_receipt'] ?? $context['native_before'] ?? null;
                if (!is_array($critical) || $critical === []) { throw new RuntimeException('Missing original scoped file receipt (native_restore_receipt/native_before).'); }
                if (isset($context['native_world_properties_sha256'])) { $critical['server.properties'] = $context['native_world_properties_sha256']; }
                // Only an independent exact-attempt receipt can replace the scoped recheck; never the last checkpoint.
                $fileProof = $context['native_original_files_verified'] ?? [];
                $proven = ($fileProof['backup_uuid'] ?? null) === $confirmation['backup_uuid']
                    && !empty($context['native_restore_confirmation'])
                    && ($fileProof['confirmation'] ?? null) === $context['native_restore_confirmation']
                    && ($fileProof['scope_sha256'] ?? null) === hash('sha256', json_encode($critical, JSON_THROW_ON_ERROR))
                    && ($fileProof['verified_at'] ?? 0) >= $confirmation['timestamp'];
                if (!$proven) { (new Transaction($files))->verify($critical); }
                CrucibleRuntime::verify($files, $context['native_retained_launcher'] ?? []);
                if (preg_match('~@(?:\./)?unix_args\.txt(?:\s|$)~', $original['startup']) && ($files->linkMetadata('unix_args.txt')['symlink'] ?? false) && !isset($context['native_restore_confirmation']['launcher_links']['unix_args.txt'])) { throw new RuntimeException('Missing independent proof of the preserved original unix_args.txt launcher link.'); }
                foreach (($context['native_restore_confirmation']['launcher_links'] ?? []) as $path => $link) {
                    if (!Wings::sameLink($link, $files->linkMetadata($path))) { throw new RuntimeException('Preserved original launcher link differs.'); }
                }
                $attempt = $this->bootProof($operation, $server, $files, $context, $confirmation);
                $instance = RunningInstance::same($instance, $attempt['start_window'] ?? ['min' => $attempt['requested_at'] - 0.01, 'max' => $attempt['accepted_at'] + $attempt['ack_seconds']]);
                if (isset($attempt['instance'])) { $instance = RunningInstance::same($instance, RunningInstance::receipt($attempt['instance'])); }
                $proof = ['server_uuid' => $server->uuid, 'server_id' => $server->id, 'operation_id' => $operation->id, 'backup_uuid' => $confirmation['backup_uuid'], 'restore' => $confirmation, 'boot_id' => $attempt['id'], 'instance' => $instance, 'actor_id' => $actor];
            }
            try { $evidence = $files->bootLogEvidence(); }
            catch (TextChangedDuringInspection) { throw new RuntimeException('Current startup log changed during inspection. Retry the read-only check; no release was attempted.'); }
            $text = $evidence['text'];
            if (!$retry && ($attempt['retired_log_sha256'] ?? null) === $evidence['sha256']) { throw new RuntimeException('Current log matches retired bytes; freshness is unproven.'); }
            if (!$evidence['done']) { throw new RuntimeException('Current log lacks a fresh completed Minecraft startup.'); }
            $observed = (new \Arcturus\ModpackManager\Services\RuntimeInspector)->fromStartupLog($text);
            foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                if (!array_key_exists($key, $runtime) || ($observed[$key] ?? null) !== $runtime[$key]) { throw new RuntimeException('Running original log does not confirm '.$key.'.'); }
            }
            [, $proof['instance']] = RunningInstance::confirm($instance, $sample, microtime(true) + RunningInstance::observationTimeout(), $assertLease);
            $proof['log_sha256'] = $evidence['sha256']; $proof['verified_at'] = microtime(true);
            if (!$apply) { return ['verified' => true, 'applied' => false, 'message' => $retry ? 'Pending sync verified for the same running instance. No changes made.' : 'Original restore, files, configuration and running startup verified. No changes made.']; }
            $assertLease();
            if (!User::find($actor)?->isRootAdmin()) { throw new RuntimeException('Root administrator permission changed during verification.'); }
            if (!$retry) {
                DB::transaction(function () use ($operation, $managed, $proof, $id, $server): void {
                    $fresh = ManagedServer::lightweight()->lockForUpdate()->findOrFail($managed->id);
                    $op = Operation::lockForUpdate()->findOrFail($id);
                    if ($fresh->pelican_server_uuid !== $server->uuid || $fresh->active_operation_id !== $id || $op->phase !== 'manual_intervention' || $op->getRawOriginal('context') !== $operation->getRawOriginal('context')) { throw new RuntimeException('Recovery evidence changed during verification.'); }
                    if ($fresh->pelicanServer->refresh()->status !== null) { throw new RuntimeException('Panel server status changed during verification.'); }
                    app(Runtime::class)->requireSnapshot($fresh->pelicanServer, $op->context['runtime_original']);
                    // Re-open only this audited terminal checkpoint, then use the existing restored transition.
                    $op->update(['phase' => 'checking_recovery', 'completed_at' => null, 'error' => null,
                        'context' => [...$op->context, 'running_original_prior_error' => $op->error, 'running_original_prior_managed_error' => $fresh->last_error, 'running_original_verification' => $proof, 'running_original_sync_pending' => true]]);
                    $fresh->update(['last_error' => null]);
                    app(OperationTracker::class)->transition($op, 'restored', ['restore_verified' => true, 'recovery_startup_verified' => true]);
                });
            }
            // Restored receipt removes ONLY plugin suspension; never edit the administrator suspension field.
            if ($managed->fresh()->active_operation_id !== null || $managed->pelican_server_uuid !== $server->uuid) { throw new RuntimeException('Another operation now owns the server; sync was not attempted.'); }
            $assertLease();
            if ($server->refresh()->status !== null) { throw new RuntimeException('Administrator status changed; configuration sync not attempted.'); }
            // Anchor immediately before sync, including time spent finalizing the receipt.
            [, $beforeSync] = $sample();
            $proof['instance'] = RunningInstance::same($proof['instance'], $beforeSync);
            $power->sync($server);
            $releaseDeadline = microtime(true) + 15;
            do {
                $assertLease();
                [$afterSync, $last] = $sample(); $proof['instance'] = RunningInstance::same($proof['instance'], $last);
                $this->requireConfiguration($afterSync, $original, $server);
                if (($afterSync['configuration']['suspended'] ?? null) === false && RunningInstance::advanced($beforeSync, $last)) { break; }
                if ($server->fresh()->status !== null) { throw new RuntimeException('Administrator status changed; pending sync retained.'); }
                sleep(1);
            } while (microtime(true) < $releaseDeadline);
            if (($afterSync['configuration']['suspended'] ?? null) !== false) { throw new RuntimeException('Recovery closed; Wings suspension release unconfirmed. Retry only sync with --sync-only --apply.'); }
            if (!RunningInstance::advanced($beforeSync, $last)) { throw new RuntimeException('Recovery closed; no advancing Wings uptime sample confirmed the same instance after sync. Pending sync retained; retry only --sync-only --apply.'); }
            $operation->refresh(); $operation->update(['context' => [...$operation->context, 'running_original_sync_pending' => false, 'running_original_sync_verified' => ['instance' => $proof['instance'], 'verified_at' => microtime(true), 'actor_id' => $actor]]]);
            return ['verified' => true, 'applied' => true, 'message' => 'Original recovery closed; Wings suspension cleared and the same running instance confirmed.'];
        } finally { $lease->release(); }
    }

    private function requireConfiguration(array $details, array $original, $server): void
    {
        $config = $details['configuration'] ?? [];
        $eggUuid = \App\Models\Server::findOrFail($server->id)->egg?->uuid;
        if (!$eggUuid) { throw new RuntimeException('Original egg identity is unavailable.'); }
        if (($config['invocation'] ?? null) !== $original['startup'] || ($config['container']['image'] ?? null) !== $original['image'] || ($config['egg']['id'] ?? null) !== $eggUuid) { throw new RuntimeException('Wings startup/image/egg differ from the original configuration.'); }
        foreach ($original['variables'] as $name => $value) {
            if (!array_key_exists($name, $config['environment'] ?? []) || (string)$config['environment'][$name] !== (string)$value) { throw new RuntimeException('Wings original variable mismatch: '.$name); }
        }
    }

    private function restoreProof(Operation $op, $server, array $context): array
    {
        $uuid = $context['backup_uuid'] ?? null;
        $backup = $uuid ? $server->backups()->where('uuid', $uuid)->first() : null;
        if (!$backup || !($context['backup_complete'] ?? false)) { throw new RuntimeException('Missing exact completed backup identity.'); }
        BackupSafetyGate::requireComplete($backup->completed_at !== null, (bool)$backup->is_successful && $backup->status === \App\Enums\BackupStatus::Successful,
            (bool)$backup->is_locked, (array)$backup->ignored_files, (int)$backup->bytes, $backup->checksum, BackupExclusions::patterns($context['excluded_directories'] ?? []));
        $events = ActivityLog::whereIn('event', ['server:backup.restore-complete', 'server.backup.restore-failed'])
            ->whereHas('subjects', fn ($q) => $q->where('subject_type', $backup->getMorphClass())->where('subject_id', $backup->id));
        $durable = $context['native_restore_confirmation'] ?? null;
        if ($durable) {
            if (!is_array($durable) || !is_int($durable['watermark'] ?? null) || !is_int($durable['callback_id'] ?? null) || empty($durable['execution_id'])) { throw new RuntimeException('Missing durable restore watermark, callback ID or execution identity.'); }
            if (($durable['backup_uuid'] ?? '') !== $uuid || ($durable['execution_id'] ?? null) !== ($context['native_execution_id'] ?? null)) { throw new RuntimeException('Restore confirmation belongs to a different backup/execution.'); }
            $events->where('id', '>', $durable['watermark']);
        } else {
            // v0.5.84/85: accept only ONE exact-backup callback since this operation; never choose between attempts.
            $events->where('timestamp', '>=', $op->created_at);
            if (isset($context['restore_event_watermark'])) { $events->where('id', '>', $context['restore_event_watermark']); }
        }
        $rows = $events->orderBy('id')->get();
        if ($rows->count() !== 1 || $rows[0]->event !== 'server:backup.restore-complete' || !$rows[0]->timestamp || ($durable && $rows[0]->id !== $durable['callback_id'])) {
            throw new RuntimeException('Missing unambiguous successful callback for backup '.$uuid.'; found '.$rows->count().' matching callback records, or callback identity/timestamp differs.');
        }
        return ['backup_uuid' => $uuid, 'callback_id' => $rows[0]->id, 'timestamp' => $rows[0]->timestamp->getTimestamp()];
    }

    private function bootProof(Operation $op, $server, Wings $files, array $context, array $restore): array
    {
        $n = $context['native_recovery_attempt'] ?? 0;
        $id = $n > 0 ? $op->node_operation_id.'-r'.base_convert((string)$n, 10, 36) : $op->id.'-old';
        $proof = $context['native_boot_attempts'][$id] ?? null;
        if (!$proof) {
            if ($n !== 1 || !in_array($context['native_checkpoint']['step'] ?? '', ['boot_start_intent', 'boot_verified'], true)) { throw new RuntimeException('Legacy boot attribution missing: need recovery attempt 1 and a recorded boot_start_intent/boot_verified checkpoint.'); }
            $actions = ActivityLog::where('id', '>', $restore['callback_id'])->where('event', 'like', 'server:power.%')
                ->whereHas('subjects', fn ($q) => $q->where('subject_type', $server->getMorphClass())->where('subject_id', $server->id))->orderBy('id')->get();
            $starts = $actions->where('event', 'server:power.start');
            if ($starts->count() !== 1 || $actions->last()?->event !== 'server:power.start' || $actions->contains(fn ($a) => !in_array($a->event, ['server:power.start', 'server:power.stop'], true)) || !$starts->first()->timestamp) { throw new RuntimeException('Legacy start audit is missing or ambiguous: need one start after the exact restore callback and no later conflicting power action.'); }
            $at = (float)$starts->first()->timestamp->getTimestamp();
            $proof = ['id' => $id, 'source' => 'legacy_power_audit', 'activity_id' => $starts->first()->id, 'start_window' => ['min' => $restore['timestamp'], 'max' => $at + 1], 'saved' => 'logs/.arcturus-before-'.$id.'.log'];
        } elseif (($proof['id'] ?? null) !== $id || ($proof['execution_id'] ?? null) !== ($context['native_execution_id'] ?? null) || ($proof['restore_callback_id'] ?? null) !== $restore['callback_id'] || empty($proof['log_absent']) || !isset($proof['requested_at'], $proof['accepted_at'], $proof['ack_seconds']) || $proof['requested_at'] < $restore['timestamp'] - 1) { throw new RuntimeException('Boot evidence is incomplete or predates the restore.'); }
        if (($proof['source'] ?? '') !== 'legacy_power_audit') {
            foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                if (!array_key_exists($key, $proof['target'] ?? []) || $proof['target'][$key] !== ($context['native_original_runtime'][$key] ?? null)) { throw new RuntimeException('Boot attempt targets a different original runtime: '.$key); }
            }
        }
        $saved = 'logs/.arcturus-before-'.$id.'.log';
        if ($proof['saved'] !== $saved || $files->stat($saved) === null) { throw new RuntimeException('Missing attempt-specific retired original log; freshness cannot be verified.'); }
        $proof['retired_log_sha256'] = $files->hash($saved);
        return $proof;
    }
}
