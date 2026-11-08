# Native deployment implementation status

Target: Pelican Panel **1.0.0-rc.2**. This is an implementation checkpoint, not a claim of production qualification.

## Implemented primitives

* `Native\Wings` uses `DaemonFileRepository` and the existing node authentication. It streams binary contents and uploads with an explicit Content-Length; no client API token or host-volume permissions are needed.
* Candidate hashes inspect ancestors and reject symlinks, special entries, oversized files, incomplete listings, truncated responses and files that change during reads.
* `Native\Transaction` verifies private staged files, uploads and verifies all new bytes before retiring any live file, orders replacements before removals, and verifies every mutation. Its callback must persist each intent before the request. It refuses an existing staging identity rather than replaying interrupted apply.
* `Native\Recovery` clears root entries except approved excluded backup directories, verifies that the root was completely cleared, and checkpoints every action. A caller must authorize the exact completed locked backup and offline guard before each deletion, then request native restore with `truncate=false`, await its exact callback and verify the original installation. An ordinary overlay restore is unsafe and is not sufficient.
* `Runtime\RuntimePlan` builds exact loader/version/Java plans and boot probes. It does not itself download, execute or qualify an installer.

These primitives are intentionally not selectable as a production backend yet. The existing deployment path remains unchanged until orchestration, preparation, startup verification, recovery receipts and schedule integration are complete. Do not run them manually on a live volume.

## Upstream API audit

Audited Panel tag `v1.0.0-rc.2` and Wings source commit `1c2070eef5888911a8a7e378fcb9e355b4d7b17b`:

* Panel `app/Repositories/Daemon/DaemonFileRepository.php`: directory listing, raw write, rename, delete, synchronous extraction and compression.
* Wings `router/router_server_files.go`: authenticated contents streams binary files; raw write requires Content-Length. Rename can return success when an entry is missing, so HTTP success is not evidence of a completed mutation.
* Wings `server/filesystem/filesystem.go`: recursive safe deletion preserves denylisted children. Recovery must verify the root is clear; a no-op deletion must never be treated as a successful restore prerequisite. Truncating the root removes excluded backup folders too.
* Wings `server/backup.go` and `router/router_server_backup.go`: nontruncating restore writes archive entries into the existing root and reports completion to Panel. Restore temporarily manages suspension itself; orchestration must re-establish and verify the deployment guard before recovery verification or start.
* Valhalla `modules/pterodactyl.js`, `downloader.js`, `comparator.js`, `merger.js`, `modloader.js`, and `managers/updateManager.js`: API-driven file operations, manifest comparison and exact NeoForge installer coordinates are useful references. Delete-before-transfer and partial overlay recovery are not adopted.

## Validation and remaining work

Small deterministic in-memory transaction/recovery fixtures and native rc.2 HTTP integration fixtures cover binary transfer, partial writes, changed/missing files, truncated streams, symlink rejection, no-op rename and incomplete recovery deletion. They do **not** run a real Wings daemon, Docker container, loader installer or Minecraft server.

Remaining before activation: Panel-private preparation backend, native candidate inventory, deployment job/approval integration, durable interruption recovery, exclusion-preserving native backup orchestration, startup/image rollback and fresh native boot verification, verified runtime artifact preparation, UI and Schedules wiring. Existing host ACL setup is legacy; this checkpoint does not remove that requirement from the old backend.
