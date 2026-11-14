<?php

declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

use App\Models\{Server, User};
use App\Repositories\Daemon\DaemonFileRepository;
use Arcturus\ModpackManager\Models\{ManagedServer, Operation};
use Arcturus\ModpackManager\Services\{BackupExclusions, BackupSafetyGate, OperationTracker, PelicanPowerController, ReleaseStore, SameHostDeployment};
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Retains exclusive update ownership while delegating only power/launcher control to Pelican. */
final class ManualStart
{
    public const CODE_ID = 'manual-handoff-leases-v2';
    public const PHASE = 'awaiting_manual_start';

    public static function controlsReleased(Operation $operation): bool
    {
        $context = $operation->context;
        $step = $context['native_checkpoint']['step'] ?? '';
        if (str_ends_with($step, '_intent') && ! in_array($step, ['boot_start_intent'], true)) { return false; }
        return $operation->phase === self::PHASE && ($context['protocol'] ?? '') === Qualification::PROTOCOL
            && ($context['native_archive_pending'] ?? false) === false
            && in_array($context['native_manual_start']['state'] ?? '', ['sync_pending', 'released'], true)
            && ($context['native_manual_start']['operation_id'] ?? null) === $operation->id;
    }

    private function actor(int $actor): void
    { if (! User::find($actor)?->isRootAdmin()) { throw new RuntimeException('An identified root administrator must approve manual handoff/verification.'); } }

    private function locked(string $id, string $action, \Closure $work, bool $mutation = true): mixed
    {
        if (! \Illuminate\Support\Str::isUuid($id)) { throw new RuntimeException('An exact operation UUID is required.'); }
        $operation = Operation::findOrFail($id); $managed = ManagedServer::lightweight()->findOrFail($operation->managed_server_id);
        if (! $mutation) {
            $identity = CheckpointIdentity::of($operation);
            $fence = function () use ($operation, $managed, $id, $identity): void {
                if (ManagedServer::whereKey($managed->id)->value('active_operation_id') !== $id || CheckpointIdentity::of($operation->fresh()) !== $identity) { throw new CheckpointChanged('Checkpoint changed during read-only inspection; retry the inspection.'); }
            };
            // These callbacks perform GETs only. Avoid decrypting the large recovery receipt
            // for every streamed chunk; the final proof/row fence is always unconditional.
            $lastCheck = 0;
            $pulse = function () use ($fence, &$lastCheck): void {
                $at = hrtime(true);
                if ($at - $lastCheck >= 1000000000) { $fence(); $lastCheck = $at; }
            };
            if ($action !== 'observe') { DeploymentLease::diagnostic($managed->id, $id, $action, 'read_begin'); }
            try {
                $pulse(); $result = $work($operation, $managed, $pulse);
                if (! in_array($action, ['arm_start', 'observe'], true)) { $fence(); }
                return $result;
            } catch (\Throwable $error) {
                if ($action !== 'observe') { DeploymentLease::diagnostic($managed->id, $id, $action, 'read_failure', ['error_class' => get_class($error)]); }
                throw $error;
            } finally { if ($action !== 'observe') { DeploymentLease::diagnostic($managed->id, $id, $action, 'read_end'); } }
        }
        $lease = new DeploymentLease($managed->id, $id, $action);
        if (! $lease->get()) { throw new RuntimeException('Deploy/recovery lease is held. Do not force-unlock it.'); }
        $pulse = function () use ($lease, $managed, $id): void {
            if (! $lease->isOwnedByCurrentProcess() || $managed->fresh()->active_operation_id !== $id) { throw new RuntimeException('Manual-start lease or active operation changed.'); }
        };
        $failure = null;
        try { $operation->refresh(); $managed->refresh(); $pulse(); return $work($operation, $managed, $pulse); }
        catch (\Throwable $error) { $failure = $error; DeploymentLease::diagnostic($managed->id, $id, $action, 'action_failure', ['error_class' => get_class($error)]); throw $error; }
        finally {
            if (! $lease->release() && $failure === null) { throw new RuntimeException('Action checkpoint retained, but mutation lease release failed. Inspect the lease diagnostic; never retry restore automatically.'); }
        }
    }

    public function orphanLease(string $id, int $actor, bool $apply = false, ?string $confirm = null, bool $writersQuiesced = false, ?string $reference = null): array
    {
        $this->actor($actor);
        return $this->locked($id, 'orphan_inspect', function ($op, $managed, $pulse) use ($actor, $apply, $confirm, $writersQuiesced, $reference): array {
            if ($op->phase !== 'manual_intervention' || ($op->context['restore_files_verified'] ?? false) !== true || isset($op->context['native_manual_start'])) { throw new RuntimeException('Orphan repair is limited to an exact paused verified-restore checkpoint before handoff. Active deploy/restore is never cleared.'); }
            $check = $this->handoffLocked($op, $managed, $actor, false, null, $reference, $pulse);
            $identity = CheckpointIdentity::of($op).':'.$check['proof'];
            $repair = new FileLeaseRepair; $record = $repair->inspect($managed->id, $identity);
            if (! $apply) { return $record; }
            if (! $writersQuiesced) { throw new RuntimeException('Explicit --writers-quiesced attestation is required: quiesce every Panel, queue and Artisan writer on all Panel hosts and verify no Wings mutation is pending. Age/PID guesses are not proof for legacy locks.'); }
            DB::transaction(function () use ($op, $managed, $actor, $reference, $pulse, $identity, $confirm, $repair, $check): void {
                $fresh = Operation::lockForUpdate()->findOrFail($op->id); $owner = ManagedServer::lockForUpdate()->findOrFail($managed->id);
                $pulse();
                $repair->expire($owner->id, $identity, (string) $confirm, function () use ($fresh, $owner, $actor, $reference, $pulse, $check): void {
                    $current = $this->handoffLocked($fresh, $owner, $actor, false, null, $reference, $pulse);
                    if (! hash_equals($check['proof'], $current['proof'])) { throw new RuntimeException('Checkpoint, managed files or launcher changed; orphan not cleared.'); }
                });
                $owner->events()->create(['kind' => 'orphan_lease', 'status' => 'released', 'message' => 'Operator attested all writers quiesced; exact FileStore record conditionally expired',
                    'details' => ['operation_id' => $op->id, 'actor_id' => $actor, 'pid' => getmypid(), 'at_utc' => gmdate('c')]]);
            }, 1);
            return ['message' => 'Only this exact FileStore lease expired. Checkpoint, backup and Minecraft untouched. Now run handoff inspection and apply.'];
        }, false);
    }

    private function requireOwner(Operation $op, ManagedServer $managed): void
    {
        if ($managed->transport !== 'native' || $op->kind !== 'deploy' || $managed->active_operation_id !== $op->id ||
            ($op->context['protocol'] ?? '') !== Qualification::PROTOCOL || ($op->context['manual_approval'] ?? false) !== true) {
            throw new RuntimeException('This exact active Native deployment checkpoint must still own the server.');
        }
        if ($managed->operations()->where('id', '!=', $op->id)->whereNull('completed_at')->exists()) { throw new RuntimeException('Another operation is unfinished.'); }
        $server = $managed->pelicanServer;
        if ($server->status !== null || $server->isSuspended() || $server->backups()->whereNull('completed_at')->exists()) {
            throw new RuntimeException('Pelican has an active backup/restore or a real administrator suspension. No handoff is allowed.');
        }
        if (($op->context['native_archive_pending'] ?? false) === true) { throw new RuntimeException('A Wings archive write has an unknown outcome; controls remain blocked.'); }
        $step = $op->context['native_checkpoint']['step'] ?? '';
        if (str_ends_with($step, '_intent') && ! in_array($step, ['boot_start_intent'], true)) {
            throw new RuntimeException('Unverified Wings mutation intent '.$step.'; inspect it before handoff.');
        }
    }

    private function backup(Operation $op, ManagedServer $managed): \App\Models\Backup
    {
        $context = $op->context;
        $backup = $managed->pelicanServer->backups()->where('uuid', $context['backup_uuid'] ?? '')->first();
        if (! $backup || ! ($context['backup_complete'] ?? false)) { throw new RuntimeException('The exact completed backup receipt is missing.'); }
        BackupSafetyGate::requireComplete($backup->completed_at !== null, (bool) $backup->is_successful && $backup->status === \App\Enums\BackupStatus::Successful,
            (bool) $backup->is_locked, (array) $backup->ignored_files, (int) $backup->bytes, $backup->checksum, BackupExclusions::patterns($context['excluded_directories'] ?? []));
        return $backup;
    }

    private function expected(Operation $op, ManagedServer $managed, string $role, ?string $reference): array
    {
        $context = $op->context;
        $runtime = $role === 'deploy' ? ($context['native_target_runtime_plan']['target'] ?? null) : ($context['native_original_runtime'] ?? null);
        if ($role === 'recovery' && $managed->installed_version !== ($context['from'] ?? null)) { throw new RuntimeException('Recorded original pack version no longer matches the managed server.'); }
        if ($reference !== null) {
            if ($role !== 'recovery' || ! \Illuminate\Support\Str::isUuid($reference)) { throw new RuntimeException('Reference must be an exact successful recovery UUID.'); }
            $prior = $managed->operations()->where('kind', 'deploy')->where('phase', 'restored')->findOrFail($reference);
            if (($prior->context['protocol'] ?? null) !== Qualification::PROTOCOL || ! $prior->completed_at || ($prior->context['restore_verified'] ?? false) !== true || ($prior->context['from'] ?? null) !== ($context['from'] ?? null)) {
                throw new RuntimeException('Reference recovery is not a verified return to the same original pack version.');
            }
            $runtime = $prior->context['native_original_runtime'] ?? null;
        }
        foreach (['minecraft', 'loader', 'java'] as $key) { if (empty($runtime[$key])) { throw new RuntimeException('Expected pack runtime is missing '.$key.'.'); } }
        if (! is_int($runtime['java']) || $runtime['java'] < 1) { throw new RuntimeException('Expected Java version is not independently recorded.'); }
        return ['minecraft' => $runtime['minecraft'], 'loader' => $runtime['loader'], 'loader_version' => $runtime['loader_version'] ?? null, 'java' => $runtime['java']];
    }

    private function launcherPaths(Operation $op, Server $server): array
    {
        $paths = array_keys($op->context['native_retained_launcher'] ?? []);
        foreach ([$server->startup, $op->context['runtime_original']['startup'] ?? ''] as $startup) {
            try { [$jar, $args] = CrucibleRuntime::launcher($startup); $paths = [...$paths, $jar, ...$args]; }
            catch (RuntimeException) { }
        }
        // Only root launcher/argument paths may be repaired; never exempt world properties, mods or libraries.
        $paths = [...$paths, ...SameHostDeployment::LAUNCH_FILES];
        return array_values(array_filter(array_unique($paths), fn ($p) => ! str_contains($p, '/') && $p !== 'server.properties'));
    }

    private function files(Operation $op, ManagedServer $managed, Wings $files, string $role): array
    {
        $context = $op->context;
        $expected = $role === 'deploy' ? ($context['native_after'] ?? null) : ($context['native_restore_receipt'] ?? $context['native_before'] ?? null);
        if (! is_array($expected)) { throw new RuntimeException('Missing scoped managed-file receipt.'); }
        if (isset($context['native_world_properties_sha256']) && ($role === 'recovery' || ! array_key_exists('server.properties', $expected))) { $expected['server.properties'] = $context['native_world_properties_sha256']; }
        $repairs = $this->launcherPaths($op, $managed->pelicanServer);
        (new Transaction($files))->verify(array_diff_key($expected, array_flip($repairs)));
        if ($role === 'deploy') { (new Transaction($files))->verify($context['native_target_runtime_plan']['dependency_hashes'] ?? []); }
        return ['scope_sha256' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)), 'launcher_exceptions' => $repairs];
    }

    private function runtime(ManagedServer $managed, Wings $files, array $manifest): array
    {
        $runtime = app(Qualification::class)->fileRuntime($managed, $files, $manifest);
        if (isset($runtime['launcher_files'])) { return $runtime; }
        // Persist actual launcher/argument bytes too, including a direct -jar or @argfile Startup.
        $paths = [...SameHostDeployment::LAUNCH_FILES, ...array_keys($manifest['files']), ...array_keys($managed->file_inventory['files'] ?? [])];
        preg_match_all('~(?:@|-jar[ \t]+)([A-Za-z0-9_./+-]+)~', $managed->pelicanServer->startup, $matches);
        $direct = array_map(fn ($p) => preg_replace('~^\./~', '', $p), $matches[1]);
        $paths = array_unique([...$paths, ...$direct]); $audit = [];
        foreach ($paths as $path) {
            if (! in_array($path, SameHostDeployment::LAUNCH_FILES, true) && ! in_array($path, $direct, true) && ! str_ends_with($path, '/unix_args.txt')) { continue; }
            $meta = $files->linkMetadata($path);
            if ($meta === null) { continue; }
            if ($meta['symlink'] ?? false) {
                // Existing native linked unix_args uses its independently verified library coordinate.
                if ($path !== 'unix_args.txt') { throw new RuntimeException('Cannot audit symlink launcher '.$path.'.'); }
                $audit[$path] = ['symlink' => true, 'metadata' => $meta]; continue;
            }
            if (count($audit) >= 64) { throw new RuntimeException('Too many launcher/argument files for manual boot audit.'); }
            $audit[$path] = ['sha256' => $files->hash($path), 'mode' => $files->permissions($path)];
        }
        ksort($audit);
        return [...$runtime, 'launcher_files' => $audit];
    }

    /** Dry-run first; explicit confirmation delegates controls but never closes recovery or starts Minecraft. */
    public function handoff(string $id, int $actor, bool $apply = false, ?string $confirm = null, ?string $reference = null): array
    {
        $this->actor($actor);
        return $this->locked($id, $apply ? 'handoff_apply' : 'handoff_read', fn ($op, $managed, $pulse) => $this->handoffLocked($op, $managed, $actor, $apply, $confirm, $reference, $pulse), $apply);
    }

    /** The native deployment worker already holds this same lease. */
    public function handoffLocked(Operation $op, ManagedServer $managed, int $actor, bool $apply, ?string $confirm, ?string $reference, \Closure $pulse, bool $approvedDeploy = false): array
    {
        $this->actor($actor); $this->requireOwner($op, $managed); $this->backup($op, $managed);
        $context = $op->context;
        $prior = $context['native_manual_start'] ?? null;
        if ($prior && $op->phase === self::PHASE) {
            if (! $apply) { return ['message' => 'Manual start handoff already recorded; retry --apply to confirm Wings sync.', 'proof' => $prior['proof'], 'role' => $prior['role']]; }
            if (! hash_equals($prior['proof'], (string) $confirm)) { throw new RuntimeException('Use the exact retained handoff proof for sync retry.'); }
            $this->syncRelease($op, $managed, $pulse);
            return ['message' => 'Controls handed over; checkpoint and locked backup retained. Startup remains unverified.'];
        }
        $role = ($approvedDeploy || ($op->phase === self::PHASE && ($context['native_deploy_files_verified'] ?? false) === true)) ? 'deploy' : 'recovery';
        if (($role === 'recovery' && ($op->phase !== 'manual_intervention' || ($context['restore_files_verified'] ?? false) !== true)) ||
            ($role === 'deploy' && ($op->phase !== self::PHASE || ($context['native_deploy_files_verified'] ?? false) !== true || ($context['manual_start_after_deploy'] ?? false) !== true || ($context['automatic_approval'] ?? false)))) {
            throw new RuntimeException('Only a verified restore or explicitly approved fully verified manual-start deployment may hand over controls.');
        }
        $power = app(PelicanPowerController::class); $server = $managed->pelicanServer;
        if ($power->state($server) !== 'exited') { throw new RuntimeException('Keep Minecraft stopped until handoff is recorded. No Stop or Start was sent.'); }
        $files = new Wings($server, app(DaemonFileRepository::class), $pulse);
        $restore = $role === 'recovery' ? (new RunningOriginal)->restoreProof($op, $server, $context) : null;
        if ($role === 'recovery') {
            $original = $context['native_original_files_verified'] ?? null;
            if (! is_array($original) || ($original['backup_uuid'] ?? null) !== $restore['backup_uuid'] ||
                ($original['confirmation'] ?? null) !== ($context['native_restore_confirmation'] ?? null) || ($original['verified_at'] ?? 0) < $restore['timestamp']) {
                throw new RuntimeException('Independent file verification for this exact completed restore is missing. Do not repeat restore automatically.');
            }
        }
        $scoped = $this->files($op, $managed, $files, $role);
        if ($role === 'recovery' && ($context['native_original_files_verified']['scope_sha256'] ?? null) !== $scoped['scope_sha256']) { throw new RuntimeException('Restored managed-file proof is bound to a different original scope.'); }
        $expected = $this->expected($op, $managed, $role, $reference);
        $snapshot = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
        try {
            $manifest = app(ReleaseStore::class)->load($role === 'deploy' ? $managed->target_manifest_path : $managed->base_manifest_path);
            $launcher = $this->runtime($managed, $files, $manifest);
        } catch (\Throwable $error) { $launcher = ['inspection_problem' => $error instanceof RuntimeException ? $error->getMessage() : 'Launcher bytes unavailable; verification must succeed after repair.']; }
        $proof = hash('sha256', json_encode([$op->id, $op->getRawOriginal('context'), $snapshot, $launcher, $reference, $expected, $scoped], JSON_THROW_ON_ERROR));
        if (! $apply) { return ['proof' => $proof, 'role' => $role, 'expected_runtime' => $expected, 'launcher' => $launcher, 'startup' => $snapshot['startup'], 'image' => $snapshot['image'], 'message' => 'READ ONLY: exact backup/restore and managed files verified. Review current launcher settings, then repeat with --apply --confirm='.$proof]; }
        if (! $approvedDeploy && ! hash_equals($proof, (string) $confirm)) { throw new RuntimeException('Handoff proof changed; review the read-only report again.'); }
        $pulse(); $cipher = $op->getRawOriginal('context');
        DB::transaction(function () use ($op, $managed, $actor, $role, $reference, $expected, $snapshot, $launcher, $restore, $scoped, $proof, $cipher, $pulse): void {
            $pulse(); $fresh = Operation::lockForUpdate()->findOrFail($op->id);
            if ($fresh->getRawOriginal('context') !== $cipher || $managed->fresh()->active_operation_id !== $op->id) { throw new RuntimeException('Recovery receipt changed during handoff.'); }
            $fresh->update(['phase' => self::PHASE, 'completed_at' => null, 'heartbeat_at' => now(),
                'progress' => ['message' => $role === 'deploy' ? 'Nasazeno, start neověřen' : 'Obnoveno, start neověřen; ovládání předáno operátorovi'],
                'context' => [...$fresh->context, 'native_manual_start' => ['operation_id' => $op->id, 'id' => bin2hex(random_bytes(16)), 'role' => $role,
                    'state' => 'sync_pending', 'actor_id' => $actor, 'reference_operation' => $reference, 'expected_runtime' => $expected,
                    'snapshot_at_handoff' => $snapshot, 'launcher_at_handoff' => $launcher, 'restore_proof' => $restore, 'managed_files_proof' => $scoped,
                    'original_files_proof' => $fresh->context['native_original_files_verified'] ?? null, 'proof' => $proof, 'requested_at' => microtime(true)]]]);
            $managed->events()->create(['kind' => 'manual_start', 'status' => 'waiting', 'message' => 'Controls delegated; boot unverified, operation and backup retained', 'details' => ['operation_id' => $op->id, 'actor_id' => $actor, 'role' => $role, 'reference_operation' => $reference]]);
            $op->setRawAttributes($fresh->getAttributes(), true);
        }, 1);
        $this->syncRelease($op, $managed, $pulse);
        return ['message' => 'Controls handed over. Use normal Pelican Start/Stop/Restart and launcher settings. Checkpoint/backup remain locked; run Verify running server after a fresh boot.'];
    }

    private function syncRelease(Operation $op, ManagedServer $managed, \Closure $pulse): void
    {
        $this->requireOwner($op->refresh(), $managed->refresh()); $pulse();
        $server = $managed->pelicanServer; $power = app(PelicanPowerController::class);
        if (($op->context['native_manual_start']['role'] ?? '') === 'recovery') { (new RunningOriginal)->restoreProof($op, $server, $op->context); }
        if (($op->context['native_manual_start']['state'] ?? '') === 'released') { return; }
        if ($power->state($server) !== 'exited') { throw new RuntimeException('Handoff sync is pending; keep Minecraft stopped until it finishes.'); }
        $files = new Wings($server, app(DaemonFileRepository::class), $pulse);
        $handoff = $op->context['native_manual_start'];
        $this->files($op, $managed, $files, $handoff['role']);
        if (app(SameHostDeployment::class)->panelSnapshot($server->refresh()) !== $handoff['snapshot_at_handoff']) { throw new RuntimeException('Launcher settings changed while handoff sync was pending; no sync was sent.'); }
        if (! isset($handoff['launcher_at_handoff']['inspection_problem'])) {
            $manifest = app(ReleaseStore::class)->load($handoff['role'] === 'deploy' ? $managed->target_manifest_path : $managed->base_manifest_path);
            if ($this->runtime($managed, $files, $manifest) !== $handoff['launcher_at_handoff']) { throw new RuntimeException('Launcher/args bytes changed while handoff sync was pending; no sync was sent.'); }
        }
        $power->sync($server); $deadline = microtime(true) + 15;
        do {
            $pulse(); $details = $power->details($server);
            $snapshot = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
            if ($server->isSuspended() || $server->status !== null) { throw new RuntimeException('Administrator suspension/status changed; handoff sync remains pending.'); }
            if (($details['configuration']['suspended'] ?? null) === false && ($details['configuration']['invocation'] ?? null) === $snapshot['startup'] && ($details['configuration']['container']['image'] ?? null) === $snapshot['image']) {
                $op->refresh(); $handoff = $op->context['native_manual_start']; $handoff['state'] = 'released'; $handoff['synced_at'] = microtime(true);
                $op->update(['context' => [...$op->context, 'native_manual_start' => $handoff]]); return;
            }
            sleep(1);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Wings did not confirm handoff sync; retry the same handoff, never restore automatically.');
    }

    /** Capture before native Panel's ordinary Start/Restart (including its WebSocket controls). */
    public function arm(string $id, Server $server, string $action, ?int $actor = null): void
    {
        $permission = $action === 'restart' ? \App\Enums\SubuserPermission::ControlRestart : \App\Enums\SubuserPermission::ControlStart;
        if ($actor !== null) { $this->actor($actor); }
        elseif (! auth()->user()?->can($permission, $server)) { abort(403); }
        $this->locked($id, 'arm_start', function ($op, $managed, $pulse) use ($server, $action, $actor): void {
            $this->requireOwner($op, $managed);
            if ($managed->pelican_server_uuid !== $server->uuid || ! self::controlsReleased($op) || ($op->context['native_manual_start']['state'] ?? '') !== 'released') { throw new RuntimeException('Manual controls are not released for this server.'); }
            $power = app(PelicanPowerController::class); $state = $power->state($server);
            if (($action === 'start' && $state !== 'exited') || ($action === 'restart' && ! in_array($state, ['running', 'exited'], true))) { throw new RuntimeException('Manual power boundary requires stopped Start or a stable Restart.'); }
            $files = new Wings($server, app(DaemonFileRepository::class), $pulse);
            $boundary = ['id' => bin2hex(random_bytes(16)), 'requested_at' => microtime(true), 'action' => $action, 'actor_id' => $actor ?? auth()->id()];
            try {
                if ($state === 'running') { $at = microtime(true); $boundary['prior_instance'] = RunningInstance::sample($power->details($server), $at, microtime(true)); }
                $manifest = app(ReleaseStore::class)->load($op->context['native_manual_start']['role'] === 'deploy' ? $managed->target_manifest_path : $managed->base_manifest_path);
                $boundary['runtime'] = $this->runtime($managed, $files, $manifest);
                $boundary['snapshot'] = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
                $before = $files->stat('logs/latest.log'); $boundary['log'] = $before === null ? null : ['size' => $before['size'], 'sha256' => $files->hash('logs/latest.log')];
                if ($files->stat('logs/latest.log') !== $before) { throw new RuntimeException('Startup log changed during the manual start boundary. Stop first, then Start.'); }
                try { $boundary['console_baseline'] = $files->consoleTail(100); } catch (\Throwable) { $boundary['console_baseline'] = null; }
                if ($power->state($server) !== $state) { throw new RuntimeException('Power state changed while recording the manual Start boundary.'); }
                $boundary['requested_at'] = microtime(true); $boundary['available'] = true;
            } catch (\Throwable $error) {
                $boundary['available'] = false; $boundary['problem'] = $error instanceof RuntimeException ? $error->getMessage() : 'Manual boot boundary could not be read. Stop, repair the launcher, then use Start again.';
            }
            $pulse(); $identity = CheckpointIdentity::of($op);
            DB::transaction(function () use ($op, $managed, $identity, $boundary): void {
                $fresh = Operation::lockForUpdate()->findOrFail($op->id);
                if ($managed->fresh()->active_operation_id !== $op->id || CheckpointIdentity::of($fresh) !== $identity) { throw new CheckpointChanged('Manual start checkpoint changed; no power was sent.'); }
                $this->requireOwner($fresh, $managed->fresh());
                $handoff = $fresh->context['native_manual_start'];
                $history = $handoff['attempts'] ?? []; $history[] = $boundary; $handoff['attempts'] = array_slice($history, -16);
                unset($handoff['console_evidence']);
                $fresh->update(['context' => [...$fresh->context, 'native_manual_start' => $handoff]]);
            }, 1);
        }, false);
    }

    /** Optional CLI observer for GTNH stdout headers that latest.log does not contain.
     * Network reads never take the mutation lease; evidence commits use a short row lock and exact attempt fencing.
     */
    public function observe(string $id, int $actor, int $seconds = 900): array
    {
        $this->actor($actor); $deadline = microtime(true) + max(1, min(3600, $seconds));
        $console = null; $attemptId = null; $instance = null;
        $serverId = (int) Operation::findOrFail($id)->managed_server_id;
        DeploymentLease::diagnostic($serverId, $id, 'observe', 'read_begin');
        try { do {
            try { $result = $this->locked($id, 'observe', function ($op, $managed, $pulse) use (&$console, &$attemptId, &$instance): ?array {
                $this->requireOwner($op, $managed);
                if (! self::controlsReleased($op) || ($op->context['native_manual_start']['state'] ?? '') !== 'released') { throw new RuntimeException('Manual handoff is not released.'); }
                $handoff = $op->context['native_manual_start']; $attempts = $handoff['attempts'] ?? []; $attempt = end($attempts);
                if (! $attempt || empty($attempt['available']) || ! is_array($attempt['console_baseline'] ?? null)) { return null; }
                $server = $managed->pelicanServer; $files = new Wings($server, app(DaemonFileRepository::class), $pulse);
                if ($attemptId !== $attempt['id']) { $attemptId = $attempt['id']; $instance = null; $console = new BootConsoleEvidence($files, $attempt['console_baseline']); }
                $at = microtime(true); $details = app(PelicanPowerController::class)->details($server);
                if (! in_array($details['state'] ?? '', ['starting', 'running'], true)) { return null; }
                $console->poll(); // Runtime stdout often precedes Wings' running/Done transition.
                if ($details['state'] !== 'running') { return null; }
                $sample = RunningInstance::sample($details, $at, microtime(true));
                $sample = RunningInstance::same($sample, ['min' => $attempt['requested_at'], 'max' => $attempt['requested_at'] + max(1, (int) config('arcturus-modpack-manager.deployment_start_ack_timeout', 60))]);
                if ($instance !== null) { $sample = RunningInstance::same($instance, $sample); }
                $instance = $sample; $proof = $console->proof();
                if ($proof['lines'] > 0 && (($handoff['console_evidence']['attempt_id'] ?? null) !== $attemptId || ($handoff['console_evidence']['proof']['sha256'] ?? null) !== $proof['sha256'])) {
                    $pulse();
                    DB::transaction(function () use ($op, $managed, $handoff, $attemptId, $instance, $proof): void {
                        $fresh = Operation::lockForUpdate()->findOrFail($op->id);
                        $current = $fresh->context['native_manual_start'] ?? [];
                        $attempts = $current['attempts'] ?? []; $latest = end($attempts);
                        if ($managed->fresh()->active_operation_id !== $op->id || ! self::controlsReleased($fresh) || ($current['state'] ?? '') !== 'released' || ($current['id'] ?? null) !== $handoff['id'] || ($latest['id'] ?? null) !== $attemptId) { throw new CheckpointChanged('Observer attempt changed; stale evidence discarded.'); }
                        $current['console_evidence'] = ['attempt_id' => $attemptId, 'instance' => $instance, 'proof' => $proof];
                        $fresh->update(['context' => [...$fresh->context, 'native_manual_start' => $current]]);
                    }, 1);
                }
                return StartupEvidence::matches($proof, $handoff['expected_runtime']) ? ['message' => 'Fresh native console boot facts retained. Now use verify; no operation was closed and no power or restore was sent.'] : null;
            }, false); } catch (CheckpointChanged) { $console = null; $attemptId = null; $instance = null; $result = null; }
            if ($result !== null) { return $result; }
            sleep(1);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Observation ended without complete fresh console runtime/Done evidence. Checkpoint remains pending; no power or restore was sent.');
        } catch (\Throwable $error) { DeploymentLease::diagnostic($serverId, $id, 'observe', 'read_failure', ['error_class' => get_class($error)]); throw $error; }
        finally { DeploymentLease::diagnostic($serverId, $id, 'observe', 'read_end'); }
    }

    public function requestRollback(string $id, int $actor): void
    {
        $this->actor($actor);
        $this->locked($id, 'explicit_rollback', function ($op, $managed, $pulse) use ($actor): void {
            $this->requireOwner($op, $managed); $this->backup($op, $managed);
            if ($op->phase !== self::PHASE || ! isset($op->context['native_manual_start'])) { throw new RuntimeException('An active manual-start checkpoint is required.'); }
            if (app(PelicanPowerController::class)->state($managed->pelicanServer) !== 'exited') { throw new RuntimeException('Stop Minecraft using normal Pelican controls before explicitly requesting rollback.'); }
            $pulse(); $handoff = $op->context['native_manual_start']; $handoff['state'] = 'revoked';
            $snapshot = app(SameHostDeployment::class)->panelSnapshot($managed->pelicanServer->refresh());
            $op->update(['phase' => 'manual_intervention', 'context' => [...$op->context, 'native_manual_start' => $handoff, 'manual_rollback_snapshot' => $snapshot]]);
            // The existing approval service rotates the recovery execution ID and dispatches its ordinary job.
            \Illuminate\Support\Facades\Auth::onceUsingId($actor);
            app(\Arcturus\ModpackManager\Services\DeploymentApproval::class)->verifyRecovery($managed);
        });
    }

    public function verify(string $id, int $actor, bool $apply = false, ?string $confirm = null): array
    {
        $this->actor($actor);
        return $this->locked($id, $apply ? 'verify_apply' : 'verify_read', function ($op, $managed, $pulse) use ($actor, $apply, $confirm): array {
            $this->requireOwner($op, $managed); $backup = $this->backup($op, $managed);
            $handoff = $op->context['native_manual_start'] ?? null;
            if (! self::controlsReleased($op) || ($handoff['state'] ?? '') !== 'released') { throw new RuntimeException('A synced manual-start handoff is required.'); }
            $attempts = $handoff['attempts'] ?? []; $attempt = end($attempts);
            if (! $attempt || ($attempt['available'] ?? false) !== true) { throw new RuntimeException($attempt['problem'] ?? 'No pre-start boundary is recorded. Use the normal Panel Start/Restart, or arm-start from CLI before starting.'); }
            $server = $managed->pelicanServer; $files = new Wings($server, app(DaemonFileRepository::class), $pulse); $power = app(PelicanPowerController::class);
            $sample = function () use ($power, $server): array { $at = microtime(true); $details = $power->details($server); return [$details, RunningInstance::sample($details, $at, microtime(true))]; };
            [$details, $instance] = $sample();
            $instance = RunningInstance::same($instance, ['min' => $attempt['requested_at'], 'max' => $attempt['requested_at'] + max(1, (int) config('arcturus-modpack-manager.deployment_start_ack_timeout', 60))]);
            if (isset($attempt['prior_instance']) && $instance['min'] <= $attempt['prior_instance']['max']) { throw new RuntimeException('Restart did not prove a distinct instance; use Stop then Start and verify its fresh boot.'); }
            $snapshot = app(SameHostDeployment::class)->panelSnapshot($server->refresh());
            if ($snapshot !== ($attempt['snapshot'] ?? null) || ($details['configuration']['invocation'] ?? null) !== $snapshot['startup'] || ($details['configuration']['container']['image'] ?? null) !== $snapshot['image']) { throw new RuntimeException('Startup/image/settings differ from the manual start boundary. Start again after the repair, then verify.'); }
            $manifest = app(ReleaseStore::class)->load($handoff['role'] === 'deploy' ? $managed->target_manifest_path : $managed->base_manifest_path);
            $runtime = $this->runtime($managed, $files, $manifest);
            if ($runtime !== $attempt['runtime']) { throw new RuntimeException('Launcher/args changed after this Start. Stop, repair and Start again before verification.'); }
            $expected = $handoff['expected_runtime'];
            foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                if (($runtime[$key] ?? null) !== ($expected[$key] ?? null)) { throw new RuntimeException('Current launcher/image does not match expected '.$key.' '.(string) ($expected[$key] ?? 'unknown').'.'); }
            }
            $proof = null; $logProblem = '';
            try {
                $candidate = $files->manualBootEvidence($attempt['log'] ?? null);
                if (StartupEvidence::matches($candidate, $expected) && strtotime($candidate['modified']) >= $instance['max']) { $proof = $candidate; }
                else {
                    $missing = [];
                    foreach (['minecraft', 'loader', 'loader_version', 'java'] as $key) {
                        if (($candidate['runtime'][$key] ?? null) !== $expected[$key]) { $missing[] = $key.' expected '.json_encode($expected[$key]).', observed '.json_encode($candidate['runtime'][$key] ?? null); }
                    }
                    if (! ($candidate['done'] ?? false)) { $missing[] = 'no fresh ordered Done'; }
                    if (! ($candidate['valid'] ?? false)) { $missing[] = 'conflicting runtime facts'; }
                    if (strtotime($candidate['modified']) < $instance['max']) { $missing[] = 'log modification predates instance'; }
                    $logProblem = 'latest.log: '.implode('; ', $missing).'.';
                }
            } catch (RuntimeException $error) { $logProblem = $error->getMessage(); }
            $console = $handoff['console_evidence'] ?? null;
            if ($proof === null && ($console['attempt_id'] ?? null) === $attempt['id'] && StartupEvidence::matches($console['proof'] ?? [], $expected)) {
                $instance = RunningInstance::same($console['instance'], $instance); $proof = $console['proof'];
            }
            if ($proof === null) { throw new RuntimeException($logProblem.' No complete fresh Wings console evidence for this manual Start. Use --action=observe during boot when headers exist only on stdout. Old Done is not accepted.'); }
            $scoped = $this->files($op, $managed, $files, $handoff['role']);
            if ($handoff['role'] === 'recovery') { (new RunningOriginal)->restoreProof($op, $server, $op->context); }
            [$details, $instance] = RunningInstance::confirm($instance, $sample, microtime(true) + RunningInstance::observationTimeout(), $pulse);
            if (($details['configuration']['invocation'] ?? null) !== $snapshot['startup'] || ($details['configuration']['container']['image'] ?? null) !== $snapshot['image'] || app(SameHostDeployment::class)->panelSnapshot($server->refresh()) !== $snapshot) { throw new RuntimeException('Panel settings changed during verification.'); }
            $confirmation = hash('sha256', json_encode([$op->id, $handoff['id'], $attempt['id'], $snapshot, $runtime, $expected], JSON_THROW_ON_ERROR));
            if (! $apply) { return ['proof' => $confirmation, 'message' => 'Fresh running instance and expected boot verified. Review actual Startup/image/launcher hashes; repeat with --apply --confirm='.$confirmation, 'snapshot' => $snapshot, 'runtime' => $runtime]; }
            if (! hash_equals($confirmation, (string) $confirm)) { throw new RuntimeException('Review the exact current launcher/runtime with a read-only verification first.'); }
            $this->actor($actor); $pulse();
            DB::transaction(function () use ($op, $managed, $handoff, $attempt, $proof, $runtime, $snapshot, $instance, $actor, $scoped, $backup, $pulse): void {
                $pulse(); $fresh = Operation::lockForUpdate()->findOrFail($op->id); $owner = ManagedServer::lockForUpdate()->findOrFail($managed->id);
                if ($owner->active_operation_id !== $op->id || $fresh->getRawOriginal('context') !== $op->getRawOriginal('context')) { throw new RuntimeException('Operation changed during manual verification.'); }
                $fresh->update(['context' => [...$fresh->context, 'native_manual_start_verification' => ['actor_id' => $actor, 'verified_at' => microtime(true),
                    'handoff_id' => $handoff['id'], 'attempt_id' => $attempt['id'], 'instance' => $instance, 'snapshot' => $snapshot,
                    'runtime' => $runtime, 'startup_evidence' => $proof, 'managed_files' => $scoped]], 'error' => null,
                    'progress' => ['message' => $handoff['role'] === 'deploy' ? 'Target installation and fresh manual boot verified' : 'Restored installation and fresh manual boot verified']]);
                if ($handoff['role'] === 'deploy') {
                    $target = app(ReleaseStore::class)->load($owner->target_manifest_path);
                    $saved = $fresh->context['native_manual_deploy_target'] ?? [];
                    if (($saved['path'] ?? null) !== $owner->target_manifest_path || ($saved['version'] ?? null) !== $target['version'] || ($saved['from'] ?? null) !== $owner->installed_version || ($saved['base'] ?? null) !== $owner->base_manifest_path) { throw new RuntimeException('Target/baseline identity changed while waiting for manual boot.'); }
                    $actual = [...$runtime, ...$proof['runtime'], 'java_confidence' => 'verified', 'loader_confidence' => 'verified_boot'];
                    app(Deployment::class)->complete($owner, $fresh, $target, $actual, $backup, $fresh->context['native_after']);
                } else {
                    $owner->update(['last_error' => null]);
                    app(OperationTracker::class)->transition($fresh, 'restored', ['restore_verified' => true, 'recovery_startup_verified' => true]);
                    $owner->events()->create(['kind' => 'rollback', 'status' => 'success', 'message' => 'Original pack verified after manual launcher repair and fresh boot', 'details' => ['operation_id' => $op->id, 'backup_uuid' => $backup->uuid, 'manual_start' => true]]);
                }
            }, 1);
            // Already released controls. Finalizing ownership never sends power or restore.
            return ['message' => $handoff['role'] === 'deploy' ? 'Completed: target boot verified; installed version/baseline updated.' : 'Recovery verified and closed. Locked backup and original audit retained.'];
        }, $apply);
    }
}
