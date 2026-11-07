# Manual same-host deployment

Apply Update is available only for a current, conflict-free, qualified plan on an approved same-host Pelican installation. It requires a root administrator to approve that exact plan. Automatic deployment is disabled, including for servers with a previously saved automatic policy. Remote destructive deployment remains disabled.

## Supported runtime and storage

The initial workflow qualifies **Minecraft 1.20.1 / Forge / Java 17 with the existing installed Forge runtime reused unchanged**. The verified target server pack must declare the same Forge version that the running installation demonstrates. The current startup log, Wings container uptime, installed Forge argument file and referenced JAR hashes corroborate the runtime. A Docker image name alone is insufficient evidence. Current Panel startup/image/egg variables must match Wings and the observed boot. The next boot requires a Java 17 image reference or a digest-pinned image corroborated by that boot.

No installer runs and no startup command, image, egg or variables are rewritten. Changes to active launcher/runtime files block qualification; use Keep local only after reviewing whether those upstream changes are needed. A different Forge/Minecraft/Java version, an unresolved custom launcher or missing installed dependencies requires separate runtime preparation and qualification. The button stays disabled with the specific comparison blocker.

Both installed baseline and target need authenticated manifests prepared through the approved local backend. The retained target completion receipt and every staged file are verified again. The live UUID volume, private staging and excluded backup folders must resolve safely. Additional container mounts and nested host mounts are blocked because their runtime and recovery coverage is not qualified. The native **Wings backup adapter** is supported; other backup adapters, including S3, remain blocked on this new manual path pending complete restore qualification.

## Host and queue requirements

Preparation's existing read-only ACL setup does not grant deployment permission. A host operator must review the UUID volume and provision scoped write/traverse access for the unprivileged Panel/queue account to the UUID root and affected managed directories. Newly created files/directories must inherit usable default ACLs for the Minecraft volume owner as well as the queue account. Qualification checks those ACLs with `/usr/bin/getfacl` when the queue account does not own the volume. PHP needs POSIX support. Do not run PHP as root or use blanket `chmod 777`.

Worlds, player data and other recovery content need **read/traverse access** for verification, not permission for the updater to modify them. All non-excluded content must be readable; comparison can tolerate inaccessible protected paths, but complete restore verification cannot. Keep custom world paths declared in readable `server.properties`. Excluded root backup folders must be safe, non-world regular directories on the same filesystem as private staging, so they can be preserved with rename during a full restore.

Use an asynchronous Laravel queue, a shared persistent database/cache and the configured scheduler. The existing local queue configuration requires an effective visibility/retry window above the 10,800-second deployment timeout (14,520 seconds minimum). Restart queue workers after upgrading this plugin so the configuration service binding and new job code are loaded. Ensure supervisor timeouts permit the job to finish. The queue worker also needs enough memory for the full file plan; the bounded HTTP detail page does not run filesystem verification or deployment.

Free a native Pelican backup slot without deleting a recovery backup. The job never evicts another backup to make space. Live-filesystem headroom for growth and atomic temporary copies is checked before approval and again after backup. Backup adapter capacity and Wings access must also be operational.

## Review and approve

1. Prepare the exact installed baseline and target, complete verified adoption, then Compare files.
2. Resolve genuine local/upstream collisions with Keep local or Use upstream. Review protected files and any unavailable binary/large diffs explicitly. Local-only scripts, Arcturus mods, worlds, player data and protected configurations remain protected by the existing planner.
3. Inspect runtime and backup blockers on Updates. A qualifying comparison is current for 15 minutes; changed versions, policies, staging, live hashes or launch settings invalidate readiness.
4. Open Apply Update, inspect its exact versions and plan SHA-256, and explicitly accept the stop/backup/apply/start workflow. Approval creates one durable operation receipt. Duplicate form submissions return that receipt. Before stopping Minecraft, the worker recomputes qualification and requires the same approved digest. Approval older than 30 minutes is rejected; a fresh comparison is still required within its shorter freshness window.

Neither decision buttons nor Compare files apply an update. No environment boolean bypasses these checks.

## Transaction and progress

The worker installs a temporary **per-server** Wings maintenance guard using standard `suspended` and `crash_detection_enabled` configuration fields. It requires daemon acknowledgement, disables automatic crash restarts and confirms the server stopped. Saved startup/image/egg settings remain unchanged. Controlled startup is temporarily allowed with crash restart still disabled. Other UUIDs keep their normal configuration.

While offline, the worker fingerprints the complete recoverable volume, including worlds, player data and custom/protected content. It creates a locked Pelican backup and waits for that exact backup's successful, locked, checksummed completion callback. An HTTP success response alone is insufficient. It compares the full offline inventory again after backup to detect concurrent writes.

Only the approved mutations are applied, with conditional local hashes, verified staging and atomic copies. An append-only, flushed/fsynced private `deploy-<backup UUID>.jsonl` journal records intent before writes. The job then starts the unchanged launcher and requires a fresh Minecraft Done marker. Installed version, baseline and successful operation result commit together only after startup verification. The temporary guard is released after completion.

Pelican shows recorded phases, heartbeat, errors, final result, backup UUID and retained recovery locations. It does not invent an overall percentage or ETA. Scheduler recovery may redispatch the same untouched queued receipt. Interrupted destructive work is never automatically replayed; it becomes a recovery checkpoint.

## Failed update and recovery

A failed apply/start first stops Minecraft. Before a full truncating restore, excluded backup folders are moved into private sibling staging with their preservation journal. The worker requests the exact locked backup, waits for its specific successful restore callback, returns excluded folders and compares both managed hashes and the **entire original offline recovery fingerprint**. A callback alone cannot authorize restart. Only a verified original snapshot with the original launch settings is started and checked. Its result is Restored, with the update failure retained.

A missing/failed callback, corrupt restore, changed launch settings, interrupted worker or failed original startup leaves a locked manual recovery checkpoint. If Wings cannot confirm the guard/offline state, the error explicitly requires a host operator to stop Minecraft first. Do not replay Apply, clear database locks, delete retained stages/journals or remove the locked backup.

For operator recovery:

1. Confirm Minecraft is stopped in Wings. Inspect the operation's exact locked backup UUID, original runtime/image information, transaction journal and preserved-folder location. The original startup/image/egg snapshot remains encrypted in the operation context; review it through trusted operator tooling if settings changed.
2. Wait for pending native backup/restore work to resolve. Restore the exact original locked Pelican backup while stopped, keeping omitted backup folders outside the truncation scope. Retain the private preservation journal. If interruption occurred between folder renames and recording its location, inspect private `mpm-preserved-*` directories and their `.mpm-preserved.json` before restoring.
3. Restore the original launch settings if necessary. Use **Verify restored original snapshot** in Pelican. This queues verification, returns recorded preserved folders, requires the full original inventory and original settings, then checks original startup. It never reapplies the update or guesses which backup to restore.
4. Verification releases the checkpoint only on success. Missing original fingerprint or a mismatched snapshot requires host-operator recovery and remains blocked. Keep all evidence for diagnosis.

## Evidence and limits

`tests/integration/deployment.php` runs native Filament approval/conflict decisions, serialized Laravel database-queue jobs, native Pelican backup/restore callback controllers and the real plugin transaction/guard against synthetic TFG-shaped 0.13.15 → 0.13.17 files and Wings HTTP fixtures. It covers success, failed startup, partial writes, stage/settings drift, incomplete/unlocked/invalid backups, failed/pending/corrupt restores, completion-transaction failure, guard refusal, scheduler redispatch, interruption during backup and explicit operator recovery verification. World/player/custom-file preservation is asserted.

These fixtures are not a real TFG installation or live Wings/Minecraft qualification. Before production use, perform an operator-supervised disposable same-host rehearsal with the actual installed pack, backup adapter, ACLs and restore, and inspect real worlds/dimensions after startup. Runtime migrations, remote transactions, extra mounts and other backup adapters remain blockers. This implementation does not change host deployment scripts or modify production files during preparation/testing.

Logical mod JAR upgrades added after the original same-host workflow are **not yet deployment-qualified**. Rebuild older comparisons with Compare files. New `upgrade_mod` plans are blocked in preview and approval until the new transaction/actions are verified; no configuration switch bypasses this. See [logical mod upgrades](mod-upgrades.md). This also invalidates readiness of retained plans without the new embedded-mod plan schema.
