# Verify an already running original installation

Use this separate path when Native Wings recovery is retained in `manual_intervention` but the original Minecraft appears to be running. **Recover original installation** is still the destructive stop/restore/start path. **Verify running original installation** only inspects the retained original evidence; it does not call that recovery path.

No production deployment or inspection was performed for this change. The reported GTNH 2.8.1 → 2.9.0 / Crucible / Java 25 case does not establish the ordering of power actions. The delayed first Wings poll is reproduced as a separate deterministic fixture, not asserted as the historical cause of that incident.

## Existing stuck receipt

After installing the code through the normal operator process, let active workers finish. Do not restart or force-unlock a worker to use this command. On the Panel host, start with the exact operation UUID and the default read-only check:

```sh
php artisan arcturus-modpack:verify-running-original OPERATION_UUID
```

A successful check makes no operation transition, configuration sync or volume mutation. If proof is missing, it reports the missing backup/callback, original snapshot/scope, attributable boot/archive, runtime, or instance evidence. Keep the checkpoint and backup; do not edit its JSON to supply a guessed checkpoint. A held deployment lease blocks verification and must expire or be released by its actual owner.

Only after a successful check, an operator with filesystem/Artisan access may explicitly apply using the ID of a real root administrator for audit attribution:

```sh
php artisan arcturus-modpack:verify-running-original OPERATION_UUID --apply --actor=ROOT_ADMIN_USER_ID
```

The root administrator page also provides **Verify running original installation**, with explicit confirmation. It preserves the locked backup and history, records the previous recovery error, uses the existing audited `restored` transition and clears only this operation's `active_operation_id`. Configuration sync removes the plugin's suspension overlay; it never updates the administrator's suspension status. It checks the original startup/image/egg/variables and the same observed running instance before and after release. It waits up to 15 seconds for Wings to acknowledge the configuration.

If the operation closed but sync failed or was not confirmed, retry **only** the verified sync, without a restart or another restore:

```sh
php artisan arcturus-modpack:verify-running-original OPERATION_UUID --sync-only
php artisan arcturus-modpack:verify-running-original OPERATION_UUID --sync-only --apply --actor=ROOT_ADMIN_USER_ID
```

The page exposes **Retry verified recovery sync** for that retained pending state. Retry refuses if a new operation owns the server, admin suspension is set, configuration drifted or the running instance no longer matches. `--sync-only` cannot close an unverified recovery. A failed inspection never sends power, restore, log rotation or file-write requests.

## Evidence and old v0.5.84/v0.5.85 receipts

Verification holds the same `arcturus-modpack-manager-deploy-{managed ID}` lease as deployment/recovery and checks it remains owned. It requires the same managed server, the exact completed successful checksummed locked backup with its original exclusions, no in-progress backup/restore and no other unfinished operation. `running`, a Discord notification, log mtime or a player's connection cannot satisfy these checks.

New attempts independently retain `native_restore_confirmation` (exact callback ID/watermark, backup and execution ID), `native_original_files_verified` (matching restore confirmation and scoped hash receipt digest), and `native_boot_attempts` (attempt ID, execution/callback binding, retired-log path, absent-log checkpoint, accepted request time, expected runtime and observed uptime window). Overwriting `native_checkpoint` cannot erase these receipts. An exact matching independent file-verification receipt can establish the original file check; otherwise verification rehashes the explicit original scope with the existing Wings batches. Retained Crucible launcher files are checked independently.

Old receipts may have only `boot_start_intent` as their last checkpoint. The compatibility path uses immutable Pelican ActivityLog records for the exact backup, not that last checkpoint as a substitute for restore proof. It requires a unique successful callback since the operation (and recorded watermark, when present), a first original recovery attempt, its specifically named retired log, and one audited server start after that callback with no later conflicting power action. The running start-time window must fit those recorded audit times. Multiple callbacks/starts, later stop/restart actions, pruned audit records or a missing archive are ambiguous and are refused. No missing restore/file checkpoint is synthesized. Legacy original files are checked afresh.

Standard Wings supplies uptime rather than a pinned container identity. Uptime is a sampled resource metric: consecutive fresh HTTP requests can return the same value, followed by a jump. The verifier preserves the original birth window while a sample repeats and intersects it with bounds derived from observed sample transitions. Inspection, startup completion and post-sync confirmation each require actual uptime advancement, never just another `running` response. A decrease, incompatible birth window, missing sample or frozen resource feed fails closed. Sample age/advancement is bounded by `arcturus-modpack-manager.running_instance_sample_timeout` (default 10 seconds, clamped to 1–60); startup also respects its overall timeout. Persisted v1 uptime-derived windows account for this bounded sampling age when loaded; authoritative start/audit windows are never widened. Standard uptime cannot distinguish a restart whose observations are indistinguishable within those bounded windows. It also requires a log different from the retired attempt log, a real Done marker and the expected Minecraft/loader/Java. Large logs are fully hashed as a stream; memory retains only bounded head/tail text, while Done is detected across the whole stream. A changed/truncated log is not accepted. These observations cannot provide an atomic filesystem/container snapshot against an adversarial external actor; no standalone `running` sample is treated as identity proof.

## Startup and worker fencing

Native Boot sends one start request. Initial offline/exited samples wait for observation of starting/running; they do not prove a crash. `deployment_start_ack_timeout` defaults to 60 seconds, bounded also by the existing overall startup timeout. Once a new start is observed, a subsequent exit is a real failure. Failure to observe a start and failure to reach fresh Done after observation have separate diagnostics. A stale log or a changed uptime window cannot confirm startup.

Recovery's catch no longer stops a server when it did not acquire the lease or does not own the recovery. Failure handlers also acquire the lease and verify receipt ownership. New queued recovery jobs carry the execution ID, so a stale job/failure callback cannot take over a newer attempt. Previous error text is retained in history while the new attempt clears its current error.

## Verification

Synthetic identity and disposable RC2 HTTP fixtures cover repeated uptime samples followed by a jump, frozen samples, real resets (including after sync), delayed offline→starting→running, genuine exit, missing start timeout, stale logs, another instance and large logs. Native recovery fixtures exercise new/legacy receipts, dry run, rejected proofs, foreign leases, safe finalization, failed sync and sync-only retry without power/restore/file-write calls. No live Wings/Docker/Minecraft qualification is implied.
