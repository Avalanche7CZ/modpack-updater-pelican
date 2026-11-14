# Native Pelican/Wings updates

Target: **Pelican Panel 1.0.0-rc.2**, PHP 8.4/8.5, existing Panel scheduler and asynchronous database/Redis queue. Python 3.11+ performs fixed, unprivileged upstream archive preparation inside Panel-private storage. It has no live-volume mapping, shell execution capability, listening service or node token. The plugin sets the queue visibility window to at least 14,520 seconds; the Pelican install/upgrade hook requests Laravel’s graceful queue restart. Active jobs finish before workers exit; the ordinary Pelican worker supervisor must restart them. The reload request is best effort: a cache permission error produces an administrator warning and never turns a successful plugin install into NotInstalled. Repair the Panel cache permissions and reload workers after active operations finish when this warning appears. For a manual file-copy upgrade, request `php artisan queue:restart` through your usual deployment procedure. No server backend reselection is needed.

## Setup and first isolated test

1. Install/update the plugin through Pelican, run its normal plugin migrations and let the graceful reload finish through the ordinary Pelican queue supervisor. Verify that workers are running afterward.
2. Use a **disposable clone**, not a production Minecraft server. Configure a working native Wings backup host with a free backup slot. Keep its existing startup and image and boot the original installation successfully.
3. Adopt it with **Native Pelican / Wings**. For an existing adopted record, Overview → Filesystem backend → Native Pelican / Wings retains the verified upstream baseline and protection policies. Prepare the target again. No Linux volume permissions, per-server SSH commands or extra API token are needed.
4. Prepare the exact installed upstream baseline if it is not already verified. Finish adoption, select the exact target and prepare it. Review newly discovered directories, protected custom files, unresolved conflicts and runtime blockers. The comparison reads only manifest-delta candidates and necessary launcher/world-path metadata.
5. Review the runtime transition, startup/image changes and backup exclusions. The original installation must be running with matching Panel/Wings settings so its boot log can prove Minecraft, loader and actual Java. Click **Update with backup** only when this exact plan is ready.
6. Check History for the exact backup UUID and terminal operation outcome. Independently inspect the disposable server's real console, worlds/dimensions, custom mods and networking. A successful fixture test does not replace this rehearsal.
7. After successful rehearsals, explicitly enable per-server automatic deployment and create **Schedules → Create Task → Update Modpack**. Use the optional normal restart when no new release exists. Following tasks wait for the update result. A failed update stops the chain even if its task has Continue on failure selected. Failures before deployment use the existing Discord failure outbox and its configured opt-in; deployment failures retain their own alert without an extra duplicate schedule alert.

Official artifact hosts and approved CDN redirects are built in. Retained artifacts are reused only for the exact recipe and after checksum validation. Corrupt cached bytes are quarantined without deleting their evidence, then downloaded and checked again; malformed unrelated cache receipts are ignored. Protection-policy comparisons reuse the prepared release. Custom allowed hosts extend that list; native preparation does not require a manual whitelist for official downloads.

## What is supported

* File-only updates which reuse a runtime identified from current launcher files and Pelican settings. A running original server, uptime and fresh log are not prerequisites for manually approved Compare or Deploy. This is not tied to Forge 1.20.1 or Java 17.
* Modern Forge/NeoForge installer runtimes can now be prepared before approval using the exact checksummed official Maven installer in an unprivileged bubblewrap filesystem/PID sandbox. Only the private workspace is writable on the host; Panel code, secrets and live volumes are not mounted. The generated arguments and server JAR must match the installer profile. Required libraries receive a precise SHA-256 manifest and enter the existing three-way comparison and native backup/deploy/rollback transaction. Cached complete builds are verified and reused; interrupted downloads retain their private workspace for checksum-checked retry. Obsolete generated libraries become untouched local extras, following the additive behavior of official installers.
* Published, complete Forge/NeoForge runtime migrations **within the same loader family**, using an existing direct `java ... @libraries/.../unix_args.txt` or `java ... @unix_args.txt` startup. Root argument aliases are switched to the verified target coordinate; the original root file/link remains intact for recovery. Shell commands, scripts and ambiguous argument references remain blocked. The target argument file must explicitly prove the target game/loader version, all referenced library JARs must be present in the verified upstream manifest and private stage, and their installed hashes are verified before startup.
* Java image changes only when exactly one matching Java image is supported by the server's egg. Existing JVM flags and variables are retained. Startup/image/egg/variable snapshots detect outside changes; recovery never blindly overwrites an administrator's new settings.
* Existing customized scripts and disabled mods are preserved through the ordinary three-way policies. Existing launchers still need identifiable runtime files and Java configuration. Configured Java from an explicit image tag remains `image_hint`, and loader inspection remains `file_evidence`; neither is a claim that the old pack booted. Fresh target startup is always verified after deployment.
* For an official GTNH pack update on Crucible, choose **Updates → Keep existing Crucible runtime**. It refreshes the comparison from the existing prepared pack, preserving file decisions and invalidating any earlier approval. Both verified upstream packs must use Minecraft 1.7.10 / Forge 10.13.4.1614, and the JAR selected by the current Pelican Startup, its JVM argument files and configured Java image must qualify separately. The server may already be stopped, including after an operator replaced that JAR. Compare reads its actual bytes and records their SHA-256; it does not replace the JAR or require starting the old pack. On a stopped server the old `latest.log` is not used as boot proof. The startup can use `java`, `exec java`, or `mkdir -p logs; exec java`, including GC log rotation under `logs/`. Other shell wrappers are not supported. The approved file plan cannot modify the active JAR, its root JVM argument files, or runtime libraries and stock launcher JARs. If a launcher path is flagged, choose **Keep local** for that path and refresh the plan. The plugin retains the startup and image and checks the active JAR and arguments again before starting the target and after rollback.
* Candidate files behind symlinks are recorded as protected without reading their targets; they are never treated as missing files or removed by the update. Other transfer or permission failures still stop comparison.
* Existing root `unix_args.txt` symlinks are retained by native leaf rename during rollback, without following or recreating them. The retained entry's metadata is checked and the restored original runtime must boot successfully. Other root symlinks remain explicit blockers.

## Explicit remaining blockers

* Installer preparation is conditional on a root-owned system `bubblewrap`, working unprivileged user/PID namespaces and a root-owned compatible JDK under `/usr/lib/jvm` on the **Panel host**. These are optional runtime-build capabilities; ordinary native file updates require neither. No unsandboxed fallback or host permission changes are performed. Java Docker images do not supply the Panel JDK. Missing capabilities produce a visible **Runtime blocked** result while retaining verified pack files.
* Preparation requires 2 GiB free private storage and 3 GiB memory headroom, with bounded process time, address space, file size and process count. Unsafe, unresolved or unsupported installer profiles fail closed. Only modern spec-1 Forge/NeoForge profiles with explicit server outputs and argument identity are accepted.
* Fabric/Quilt version migrations, changing loader families, custom script-driven loader migrations and unverified legacy runtime changes are blocked. Current runtime reuse is conditional on independent boot evidence.
* Native disabled-mod policies rename only approved active JARs under `mods/` to their exact `.jar.disabled` path, after a completed locked backup. Wings verifies source/destination bytes and permissions. An existing destination blocks qualification rather than being overwritten; failed or interrupted deployment uses the same complete native recovery transaction.
* Extra container mounts, egg filesystem denylists, other root symlinks, unsafe excluded folders, unavailable backup capacity/adapters and ambiguous startup evidence block deployment.
* Native backups currently qualify the Wings adapter; other backup adapters remain blocked.
* Native text/boot reads are bounded to 4 MiB. An oversized or unsupported startup log blocks qualification/verification. No entire world or installation is scanned to work around that.

## Transaction and recovery

The Panel owns the durable operation receipt, exact approved plan, policies, runtime snapshots and checkpoints. Wings performs live file operations using existing node authentication.

After an acknowledged maintenance guard and stop, the plugin rechecks approved candidate hashes and world-path metadata, creates a locked native backup, and waits for its exact successful callback. It uploads **all** new candidate bytes to an operation-specific Wings stage and verifies them before retiring any live file. Transfer progress refreshes the operation heartbeat and checks execution fencing, so a slow upload is not mistaken for an abandoned job and a superseded worker stops transferring. Replacements precede removals; every installed file and removed path is verified. This is a **recoverable ordered transaction, not atomic deployment**.

Runtime migration requires the exact patched Forge/NeoForge server JAR as well as libraries named in the argument file; a complete-looking bootstrap classpath alone is insufficient. The plugin verifies these target dependencies, applies startup/image settings through Pelican's native service, retires the old startup log, and waits for a fresh `Done` marker plus exact Minecraft/loader/Java evidence. If the live log changes during a read, boot verification discards that read and polls again within the same timeout. A process exit or a truncated response from an unchanged file still fails verification. Only then does it commit the installed release. Ordinary game-generated configuration changes after boot are not mistaken for a failed transfer.

On failure, Minecraft is stopped and an inert recovery startup is synchronized to Wings. This matters because Wings temporarily changes suspension during restore, including failed restores. Native Panel starts are also fenced while a deployment owns the server. Recovery preserves excluded `backup/` and `backups/` directories and a retained launcher-link directory, deletes other **root entries through Wings**, and confirms the root was completely cleared. It then requests the exact backup with **`truncate=false`**, waits for the matching native callback, returns the retained launcher link, verifies the small original file receipt, restores original launch settings and checks a fresh original boot. Plain overlay restore without the verified clear is unsafe and is never accepted.

If deletion, callback, hashes, launch settings or original boot cannot be confirmed, the server stays stopped with a **Manual recovery required** checkpoint and locked backup. Use **Recover original installation** after all pending callbacks have finished. This reuses the exact backup and original receipts; it never replays apply. A worker interruption is retained in this state. Recovery must acquire the same deployment lease, and an old execution is fenced when recovery is approved. If a killed worker's cache lease remains, wait for its expiry (up to four hours); do not force-unlock an uncertain in-flight write.

Locked recovery backups and unrelated backups are never pruned by this workflow. Backup quotas are respected.

For a failed native deployment boot, the worker asks Wings for the failed container's console tail **before** the rollback can replace the container. It keeps a short private sample in the encrypted operation receipt, separately for the target, automatic rollback and latest failed manual recovery. If the console is unavailable or matches the pre-start console, it uses the last stable sample of the fresh `logs/latest.log`, if one was read. If the process exited before the first running sample, it also attempts a bounded read of the fresh log created after log retirement; an old log cannot become failure evidence. Failure to collect diagnostics never blocks recovery. The console tail is not sent to Discord, written to the application log, or included in Copy diagnostics.

From the Panel host, run `sudo -u www-data php artisan arcturus-modpack:boot-diagnostics <operation-uuid>` in the Panel directory. This is read-only and does not contact Wings or replay the update. Console output can contain sensitive values. Review it before sharing. The command cannot retrieve output for failures before this feature was installed.

## Validation boundary

A real checksummed NeoForge 21.1.172 installer was executed successfully under Java 21 in a disposable filesystem/PID sandbox, generating its patched server JAR and runtime libraries. The executor maps system-package ownership to UID 65534, so the scratch smoke harness adapts that identity only; production still requires root-owned tools. This exercised actual installer output, not a Minecraft boot.

File-only updates and published NeoForge migrations, including partial-write and failed-boot recovery, now begin with provider metadata, the real database preparation job, the production Native configuration and actual pinned HTTPS/TLS archive transfer, extraction and manifest generation. Only DNS/socket routing and provider metadata are controlled by the test; transport verification is not disabled. The Wings control plane remains an HTTP fixture, not a live daemon. Policy-only comparisons make no further artifact requests.

Small deterministic fixtures execute real rc.2 approval, database queue, daemon repositories, startup service and native backup callback controllers, with a disposable HTTP Wings model. They cover a complete file-only update, published and installer-produced NeoForge/Java/startup migrations, partial write, failed boot, image/startup rollback, failed restore callback, retained launcher symlink, excluded backups, custom/disabled mods, duplicate delivery and interrupted-worker recovery. Separate HTTP tests cover binary streams, truncated transfers, symlink rejection and no-op rename responses.

**No live Wings daemon, Docker container or real Minecraft server was exercised in this environment. Production qualification remains the operator's disposable-instance test.**

## Source audit

Audited Panel tag `v1.0.0-rc.2` and Wings commit `1c2070eef5888911a8a7e378fcb9e355b4d7b17b`:

* Panel `DaemonFileRepository`, `DaemonServerRepository`, `DaemonBackupRepository`, `StartupModificationService` and native backup callback controllers.
* Wings `router/router_server_files.go`: authenticated binary contents and raw writes with Content-Length. Rename can acknowledge a missing entry, so response status alone is insufficient.
* Wings `server/filesystem/filesystem.go`: leaf rename, lstat-based recursive safe deletion, preserved denylisted entries and destructive root truncation.
* Wings `server/backup.go` and `router/router_server_backup.go`: nontruncating archive restore, callback reporting and temporary suspension changes.
* Valhalla `modules/pterodactyl.js`, `downloader.js`, `comparator.js`, `merger.js`, `modloader.js` and `managers/updateManager.js`: API file management, manifest deltas and exact loader coordinates. Delete-before-transfer and incomplete overlay rollback were not adopted.

## First isolated end-to-end test

1. Update the plugin on Panel rc.2 and let its queue workers finish active jobs and reload so the new native guard bindings are loaded. Use a disposable Minecraft server with its own volume and port; clone the pack and representative custom files there, never use the production server for failure injection.
2. Keep the original configuration, current startup and approved egg image. A manual test can begin with the original stopped, without uptime or a fresh log, provided its launcher/runtime files and explicit Java image can be qualified. Use the Wings backup adapter, available backup quota, no extra mounts and no egg filesystem denylist entries.
3. Adopt that test server with **Native Pelican / Wings**, select the exact installed provider/version and prepare its baseline. Review custom mods/scripts, world location and protection rules. No volume ACL setup or API token is required.
4. Select a target with an unchanged runtime for the first pass, choose **Prepare update**, resolve conflicts and directory review, then approve **Update with backup**. Wait for **Completed**, inspect Minecraft's new boot log, the exact locked backup UUID and your custom/disabled files. Preparation completion alone is not a deployment result.
5. On a separate disposable clone, select a supported same-loader Forge/NeoForge migration. A pack that publishes complete verified runtime libraries needs no installer sandbox. Otherwise the Panel host must already provide a trusted compatible JDK and bubblewrap with usable unprivileged user namespaces; preparation reports a specific blocker if unavailable. Minecraft 26 requires Java 25 for the build and an approved Java 25 egg image for the server. Review the generated dependency plan and test **Update with backup** again.
6. Keep automatic schedules disabled until those manual passes work. Then add **Schedules → Create Task → Update Modpack** on the disposable instance. Check both update and no-update restart behavior. Do not delete locked recovery backups. For a failed update, verify **Rolled back** and original startup/image/files; if recovery is unverified, leave the server stopped and use **Recover original installation** with the retained operation and backup.

The automated fixtures inject write, startup, callback and interrupted-worker failures. Live restore and Minecraft startup still need operator validation on the disposable instance before enabling production automation.


## If the disposable test fails

Use **Updates → Copy diagnostics** and include the plugin version, operation/worker IDs, provider, current backend, phase, HTTP status and sanitized `host`/`reason` when present. This export omits recipes, signed URL queries and credentials. Old failures will not acquire a hostname retrospectively; retry preparation once after upgrading to obtain the new diagnostic. Do not repeatedly switch a backend that is already saved as Native.

For an unclassified Panel failure, include only the Laravel stack trace matching that operation ID, with tokens and signed query strings removed. For a queue that stays waiting, verify the ordinary Pelican supervisor has a running worker after the graceful reload. For an uncertain deployment/recovery outcome, leave the instance stopped and retain its exact locked backup and journal; do not replay deployment or manually truncate the volume.


## Encrypted operation storage

Operation contexts use LONGTEXT on MySQL/MariaDB. Encrypted runtime and file
receipts can exceed the 65,535-byte TEXT limit. The upgrade migration widens
existing columns without rewriting their contents; rollback never shrinks them.
An oversized model write on an old TEXT schema is rejected before SQL, including
on non-strict MySQL installations.

A previously truncated encrypted context cannot be reconstructed by widening
the column or changing APP_KEY. Retain that row and its operation lock. Inspect
the operation-specific logs, actual Wings state, recorded backup and startup
settings before any recovery decision. Never clear the lock or replay deployment
merely because the schema migration succeeded.

See [bounded native verification](native-batch-verification.md) for directory-sharing batches, verification phases, measured fixture request counts and deferred staging/batch-mutation work.

See [Applying progress and Wings mutation batches](native-applying.md) for step-specific counts, bounded chmod/rename, partial-batch recovery and remaining per-file upload work.

For a retained recovery whose original Minecraft is already running, use [read-only running-original verification](verify-running-original.md) before considering another restore.

See [native performance measurements](native-performance.md) for bounded staging uploads, Comparison batching, Applying budgets, recorded timings and the GTNH copy rehearsal.

## Manual updates from a stopped server

Native qualification separates the file/configuration plan from optional live boot observations. An absent log or uptime cannot itself block a manual comparison. Live observations are excluded from the approval digest: stopping a server after comparison does not invalidate an otherwise identical approved plan. File hashes, retained launcher permissions, JVM args, image/startup settings and policies remain bound to approval and are checked again before mutation.

Wings `offline` and `exited` both represent an already stopped container. Deployment installs the usual guard and confirms that state, without a helper Start or redundant Stop, then verifies drift and requests its exact locked backup. A running server is stopped through the existing flow. ZIP staging and live-file changes still wait for the exact successful locked backup callback.

After a target failure, recovery restores the backup and original settings and records `restore_files_verified` separately from `recovery_startup_verified`. The operation becomes `restored` only after a fresh original boot is actually verified. If the pre-update configuration was already broken (for example Pack200 with an operator-replaced Crucible), a failed original boot leaves `manual_intervention`, the server stopped, the backup retained, and both crash diagnostics retained. Restored bytes alone never prove a functional rollback.

Automatic Schedules keep their administrator opt-in, current live boot requirement and sequencing rules. The offline allowance applies to explicit manual approval; it does not make a previously offline or unverified installation eligible for automatic updates. No new runtime mode, JAR replacement option or UI has been added.
