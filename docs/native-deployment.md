# Native Pelican/Wings updates

Target: **Pelican Panel 1.0.0-rc.2**, PHP 8.4/8.5, existing Panel scheduler and asynchronous database/Redis queue. Python 3.11+ performs fixed, unprivileged upstream archive preparation inside Panel-private storage. It has no live-volume mapping, shell execution capability, listening service or node token. The plugin sets the queue visibility window to at least 14,520 seconds; restart existing queue workers after upgrading code.

## Setup and first isolated test

1. Install/update the plugin through Pelican, run its normal plugin migrations and restart `pelican-queue` using your ordinary deployment procedure.
2. Use a **disposable clone**, not a production Minecraft server. Configure a working native Wings backup host with a free backup slot. Keep its existing startup and image and boot the original installation successfully.
3. Adopt it with **Native Pelican / Wings**. For an existing adopted record, Overview → Filesystem backend → Native Pelican / Wings retains the verified upstream baseline and protection policies. Prepare the target again. No Linux volume permissions, per-server SSH commands or extra API token are needed.
4. Prepare the exact installed upstream baseline if it is not already verified. Finish adoption, select the exact target and prepare it. Review newly discovered directories, protected custom files, unresolved conflicts and runtime blockers. The comparison reads only manifest-delta candidates and necessary launcher/world-path metadata.
5. Review the runtime transition, startup/image changes and backup exclusions. The original installation must be running with matching Panel/Wings settings so its boot log can prove Minecraft, loader and actual Java. Click **Update with backup** only when this exact plan is ready.
6. Check History for the exact backup UUID and terminal operation outcome. Independently inspect the disposable server's real console, worlds/dimensions, custom mods and networking. A successful fixture test does not replace this rehearsal.
7. After successful rehearsals, explicitly enable per-server automatic deployment and create **Schedules → Create Task → Update Modpack**. Use the optional normal restart when no new release exists. Following tasks wait for the update result. A failed update stops the chain even if its task has Continue on failure selected. Failures before deployment use the existing Discord failure outbox and its configured opt-in; deployment failures retain their own alert without an extra duplicate schedule alert.

## What is supported

* File-only updates which reuse a runtime independently proven by the current boot log. This is not tied to Forge 1.20.1 or Java 17.
* Modern Forge/NeoForge installer runtimes can now be prepared before approval using the exact checksummed official Maven installer in an unprivileged bubblewrap filesystem/PID sandbox. Only the private workspace is writable on the host; Panel code, secrets and live volumes are not mounted. The generated arguments and server JAR must match the installer profile. Required libraries receive a precise SHA-256 manifest and enter the existing three-way comparison and native backup/deploy/rollback transaction. Cached complete builds are verified and reused; interrupted downloads retain their private workspace for checksum-checked retry. Obsolete generated libraries become untouched local extras, following the additive behavior of official installers.
* Published, complete Forge/NeoForge runtime migrations **within the same loader family**, using an existing direct `java ... @libraries/.../unix_args.txt` or `java ... @unix_args.txt` startup. Root argument aliases are switched to the verified target coordinate; the original root file/link remains intact for recovery. Shell commands, scripts and ambiguous argument references remain blocked. The target argument file must explicitly prove the target game/loader version, all referenced library JARs must be present in the verified upstream manifest and private stage, and their installed hashes are verified before startup.
* Java image changes only when exactly one matching Java image is supported by the server's egg. Existing JVM flags and variables are retained. Startup/image/egg/variable snapshots detect outside changes; recovery never blindly overwrites an administrator's new settings.
* Existing customized scripts and disabled mods are preserved through the ordinary three-way policies. Existing Fabric/Quilt and legacy Forge/Crucible launchers can be reused only when exact runtime and Java boot evidence is available. Unsupported log formats remain blocked rather than trusting image names.
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

Runtime migration requires the exact patched Forge/NeoForge server JAR as well as libraries named in the argument file; a complete-looking bootstrap classpath alone is insufficient. The plugin verifies these target dependencies, applies startup/image settings through Pelican's native service, retires the old startup log, and waits for a fresh `Done` marker plus exact Minecraft/loader/Java evidence. Only then does it commit the installed release. Ordinary game-generated configuration changes after boot are not mistaken for a failed transfer.

On failure, Minecraft is stopped and an inert recovery startup is synchronized to Wings. This matters because Wings temporarily changes suspension during restore, including failed restores. Native Panel starts are also fenced while a deployment owns the server. Recovery preserves excluded `backup/` and `backups/` directories and a retained launcher-link directory, deletes other **root entries through Wings**, and confirms the root was completely cleared. It then requests the exact backup with **`truncate=false`**, waits for the matching native callback, returns the retained launcher link, verifies the small original file receipt, restores original launch settings and checks a fresh original boot. Plain overlay restore without the verified clear is unsafe and is never accepted.

If deletion, callback, hashes, launch settings or original boot cannot be confirmed, the server stays stopped with a **Manual recovery required** checkpoint and locked backup. Use **Recover original installation** after all pending callbacks have finished. This reuses the exact backup and original receipts; it never replays apply. A worker interruption is retained in this state. Recovery must acquire the same deployment lease, and an old execution is fenced when recovery is approved. If a killed worker's cache lease remains, wait for its expiry (up to four hours); do not force-unlock an uncertain in-flight write.

Locked recovery backups and unrelated backups are never pruned by this workflow. Backup quotas are respected.

## Validation boundary

A real checksummed NeoForge 21.1.172 installer was executed successfully under Java 21 in a disposable filesystem/PID sandbox, generating its patched server JAR and runtime libraries. The executor maps system-package ownership to UID 65534, so the scratch smoke harness adapts that identity only; production still requires root-owned tools. This exercised actual installer output, not a Minecraft boot.

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

1. Update the plugin on Panel rc.2 and restart its queue worker so the new native guard bindings are loaded. Use a disposable Minecraft server with its own volume and port; clone the pack and representative custom files there, never use the production server for failure injection.
2. Boot the original version successfully. Its current log must identify Minecraft, loader and actual Java; keep the original startup and approved egg image. Use the Wings backup adapter, available backup quota, no extra mounts and no egg filesystem denylist entries.
3. Adopt that test server with **Native Pelican / Wings**, select the exact installed provider/version and prepare its baseline. Review custom mods/scripts, world location and protection rules. No volume ACL setup or API token is required.
4. Select a target with an unchanged runtime for the first pass, choose **Prepare update**, resolve conflicts and directory review, then approve **Update with backup**. Wait for **Completed**, inspect Minecraft's new boot log, the exact locked backup UUID and your custom/disabled files. Preparation completion alone is not a deployment result.
5. On a separate disposable clone, select a supported same-loader Forge/NeoForge migration. A pack that publishes complete verified runtime libraries needs no installer sandbox. Otherwise the Panel host must already provide a trusted compatible JDK and bubblewrap with usable unprivileged user namespaces; preparation reports a specific blocker if unavailable. Minecraft 26 requires Java 25 for the build and an approved Java 25 egg image for the server. Review the generated dependency plan and test **Update with backup** again.
6. Keep automatic schedules disabled until those manual passes work. Then add **Schedules → Create Task → Update Modpack** on the disposable instance. Check both update and no-update restart behavior. Do not delete locked recovery backups. For a failed update, verify **Rolled back** and original startup/image/files; if recovery is unverified, leave the server stopped and use **Recover original installation** with the retained operation and backup.

The automated fixtures inject write, startup, callback and interrupted-worker failures. Live restore and Minecraft startup still need operator validation on the disposable instance before enabling production automation.
