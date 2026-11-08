# Native Pelican/Wings updates

Target: **Pelican Panel 1.0.0-rc.2**, PHP 8.4/8.5, existing Panel scheduler and asynchronous database/Redis queue. Python 3.11+ performs fixed, unprivileged upstream archive preparation inside Panel-private storage. It has no live-volume mapping, shell execution capability, listening service or node token. The plugin sets the queue visibility window to at least 14,520 seconds; restart existing queue workers after upgrading code.

## Setup and first isolated test

1. Install/update the plugin through Pelican, run its normal plugin migrations and restart `pelican-queue` using your ordinary deployment procedure.
2. Use a **disposable clone**, not a production Minecraft server. Configure a working native Wings backup host with a free backup slot. Keep its existing startup and image and boot the original installation successfully.
3. Adopt it with **Native Pelican / Wings**. For an existing adopted record, Overview → Filesystem backend → Native Pelican / Wings retains the verified upstream baseline and protection policies. Prepare the target again. No Linux volume permissions, per-server SSH commands or extra API token are needed.
4. Prepare the exact installed upstream baseline if it is not already verified. Finish adoption, select the exact target and prepare it. Review newly discovered directories, protected custom files, unresolved conflicts and runtime blockers. The comparison reads only manifest-delta candidates and necessary launcher/world-path metadata.
5. Review the runtime transition, startup/image changes and backup exclusions. The original installation must be running with matching Panel/Wings settings so its boot log can prove Minecraft, loader and actual Java. Click **Update with backup** only when this exact plan is ready.
6. Check History for the exact backup UUID and terminal operation outcome. Independently inspect the disposable server's real console, worlds/dimensions, custom mods and networking. A successful fixture test does not replace this rehearsal.
7. After successful rehearsals, explicitly enable per-server automatic deployment and create **Schedules → Create Task → Update Modpack**. Use the optional normal restart when no new release exists. Following tasks wait for the update result.

## What is supported

* File-only updates which reuse a runtime independently proven by the current boot log. This is not tied to Forge 1.20.1 or Java 17.
* Published, complete Forge/NeoForge runtime migrations **within the same loader family**, using an existing direct `java ... @libraries/.../unix_args.txt` startup. The target argument file must explicitly prove the target game/loader version, all referenced library JARs must be present in the verified upstream manifest and private stage, and their installed hashes are verified before startup.
* Java image changes only when exactly one matching Java image is supported by the server's egg. Existing JVM flags and variables are retained. Startup/image/egg/variable snapshots detect outside changes; recovery never blindly overwrites an administrator's new settings.
* Existing customized scripts and disabled mods are preserved through the ordinary three-way policies. Existing Fabric/Quilt and legacy Forge/Crucible launchers can be reused only when exact runtime and Java boot evidence is available. Unsupported log formats remain blocked rather than trusting image names.
* Existing root `unix_args.txt` symlinks are retained by native leaf rename during rollback, without following or recreating them. The retained entry's metadata is checked and the restored original runtime must boot successfully. Other root symlinks remain explicit blockers.

## Explicit remaining blockers

* **Installer-generated runtime dependencies are not yet qualified.** A pack which publishes only an installer or mod manifest, without the complete target runtime, cannot migrate merely by switching its startup string. This commonly affects FTB NeoForge upgrades. `RuntimePlan` preserves exact official installer profiles, but those profiles are not activated as a production installer workflow.
* Fabric/Quilt version migrations, changing loader families, custom script-driven loader migrations and unverified legacy runtime changes are blocked. Current runtime reuse is conditional on independent boot evidence.
* Native automatic enforcement of a new `.disabled` rename is not qualified. Existing disabled mods stay preserved; a plan requiring enforcement shows an actionable blocker.
* Extra container mounts, egg filesystem denylists, other root symlinks, unsafe excluded folders, unavailable backup capacity/adapters and ambiguous startup evidence block deployment.
* Native backups currently qualify the Wings adapter; other backup adapters remain blocked.
* Native text/boot reads are bounded to 4 MiB. An oversized or unsupported startup log blocks qualification/verification. No entire world or installation is scanned to work around that.

## Transaction and recovery

The Panel owns the durable operation receipt, exact approved plan, policies, runtime snapshots and checkpoints. Wings performs live file operations using existing node authentication.

After an acknowledged maintenance guard and stop, the plugin rechecks approved candidate hashes and world-path metadata, creates a locked native backup, and waits for its exact successful callback. It uploads **all** new candidate bytes to an operation-specific Wings stage and verifies them before retiring any live file. Replacements precede removals; every installed file and removed path is verified. This is a **recoverable ordered transaction, not atomic deployment**.

The plugin verifies the target dependencies, applies startup/image settings through Pelican's native service, retires the old startup log, and waits for a fresh `Done` marker plus exact Minecraft/loader/Java evidence. Only then does it commit the installed release. Ordinary game-generated configuration changes after boot are not mistaken for a failed transfer.

On failure, Minecraft is stopped and an inert recovery startup is synchronized to Wings. This matters because Wings temporarily changes suspension during restore, including failed restores. Native Panel starts are also fenced while a deployment owns the server. Recovery preserves excluded `backup/` and `backups/` directories and a retained launcher-link directory, deletes other **root entries through Wings**, and confirms the root was completely cleared. It then requests the exact backup with **`truncate=false`**, waits for the matching native callback, returns the retained launcher link, verifies the small original file receipt, restores original launch settings and checks a fresh original boot. Plain overlay restore without the verified clear is unsafe and is never accepted.

If deletion, callback, hashes, launch settings or original boot cannot be confirmed, the server stays stopped with a **Manual recovery required** checkpoint and locked backup. Use **Recover original installation** after all pending callbacks have finished. This reuses the exact backup and original receipts; it never replays apply. A worker interruption is retained in this state. Recovery must acquire the same deployment lease, and an old execution is fenced when recovery is approved. If a killed worker's cache lease remains, wait for its expiry (up to four hours); do not force-unlock an uncertain in-flight write.

Locked recovery backups and unrelated backups are never pruned by this workflow. Backup quotas are respected.

## Validation boundary

Small deterministic fixtures execute real rc.2 approval, database queue, daemon repositories, startup service and native backup callback controllers, with a disposable HTTP Wings model. They cover a complete file-only update, published NeoForge/Java/startup migration, partial write, failed boot, image/startup rollback, failed restore callback, retained launcher symlink, excluded backups, custom/disabled mods, duplicate delivery and interrupted-worker recovery. Separate HTTP tests cover binary streams, truncated transfers, symlink rejection and no-op rename responses.

**No live Wings daemon, Docker container, loader installer or real Minecraft server was exercised in this environment. Production qualification remains the operator's disposable-instance test.**

## Source audit

Audited Panel tag `v1.0.0-rc.2` and Wings commit `1c2070eef5888911a8a7e378fcb9e355b4d7b17b`:

* Panel `DaemonFileRepository`, `DaemonServerRepository`, `DaemonBackupRepository`, `StartupModificationService` and native backup callback controllers.
* Wings `router/router_server_files.go`: authenticated binary contents and raw writes with Content-Length. Rename can acknowledge a missing entry, so response status alone is insufficient.
* Wings `server/filesystem/filesystem.go`: leaf rename, lstat-based recursive safe deletion, preserved denylisted entries and destructive root truncation.
* Wings `server/backup.go` and `router/router_server_backup.go`: nontruncating archive restore, callback reporting and temporary suspension changes.
* Valhalla `modules/pterodactyl.js`, `downloader.js`, `comparator.js`, `merger.js`, `modloader.js` and `managers/updateManager.js`: API file management, manifest deltas and exact loader coordinates. Delete-before-transfer and incomplete overlay rollback were not adopted.
