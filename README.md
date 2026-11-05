# Pelican Arcturus Modpack Manager

Pelican plugin for Minecraft modpack release checks, protected config changes, file-diff previews, staged updates, native Pelican Backups and Discord notifications.

**Status: 0.2.0 development / experimental.** Do not deploy to production until tested on the exact Panel and Wings version. The plugin does not modify Pelican core or require new eggs.

## Features

- Native Filament admin dashboard with server status, release checks, responsive tables and preview details.
- Managed servers form with actual Pelican server selection, providers, policy, protection rules and worker paths.
- Searchable and filterable update history, queued background jobs and Discord webhook notifications.
- Release discovery: FTB, GitHub Releases, Modrinth, CurseForge (notification only), local custom manifests.
- Versioned SHA-256 manifests and three-way comparison: verified original upstream / local server / new upstream.
- Staging via official FTB Server Installer or unique server ZIP on GitHub/Modrinth.
- **Pelican Backups by default**: Wings/S3 storage, existing quota/retention, locked pre-update backup, selectable directory exclusions and callback-confirmed restore.
- Optional legacy filesystem snapshot mode (independent CLI always uses filesystem snapshots).
- Optional Pelican/Wings stop/start integration and Minecraft "Done (seconds)!" startup verification.
- Native Pelican restore with full truncate and confirmed Wings callback on failed startup; legacy filesystem mode can still swap local snapshots.

**Not implemented:** semantic configuration merging, mod-ID-aware disabled-mod handling, deployment to a remote Wings node without a shared/local filesystem. CurseForge does not automatically supply server ZIPs. GitHub/Modrinth need a unique server ZIP to stage.

## Requirements

- Pelican Panel with plugin API v1 and Filament v4. Compatibility with the owner's 1.0.0-rc.1 build still needs a staging-panel test.
- PHP 8.3+, Pelican scheduler, and a configured Laravel queue worker.
- PHP ZIP extension for ZIP sources and a validated official FTB installer for FTB sources.
- Managed updates only where the Panel's queue worker can access the Wings volume filesystem.
- Enough space in the configured Pelican Backup Host (Wings local storage or S3); filesystem mode additionally needs a backup directory outside the live tree. For native Pelican restore with exclusions, the staging parent must be on the **same filesystem** as the Wings server volume.

## Install

Every successful commit to **main** automatically publishes a GitHub Release with the verified **arcturus-modpack-manager.zip** installer. Download it from [GitHub Releases](https://github.com/Avalanche7CZ/modpack-updater-pelican/releases/latest), not the source-code ZIP GitHub generates automatically.

The plugin ZIP has one top-level folder named **arcturus-modpack-manager**, matching the `plugin.json` ID. The same release also includes `SHA256SUMS.txt` and `update.json`. The archive's embedded `plugin.json` gets a distinct semantic version for each release; the published checksum matches that exact ZIP. GitHub Actions also builds a preview artifact for PRs, but the release ZIP is the supported installable distribution.

Import **arcturus-modpack-manager.zip** in Pelican Admin → Plugins → Import, then Install. For manual installation, copy plugin.json and the src, config, database, resources and bin folders to:

~~~text
/var/www/pelican/plugins/arcturus-modpack-manager/
~~~

From the Pelican installation directory:

~~~bash
php artisan p:plugin:install arcturus-modpack-manager
~~~

Pelican automatically registers plugin migrations, providers, commands and views. The plugin is limited to the admin panel and its resources are limited to root administrators.

## Automatic GitHub Releases and Pelican updates

- On every push/merge commit to `main`, **Auto Release — Pelican plugin** runs PHP lint, planner, inventory, backup-safety and deployment regression tests plus a CLI smoke test.
- Only after tests pass, the job packages the correct ZIP and publishes a numbered GitHub Release with `arcturus-modpack-manager.zip`, `update.json` and `SHA256SUMS.txt`.
- Versions are generated from the major/minor in the repository's `plugin.json` and a monotonically increasing workflow run number (for example, `0.2.1`, `0.2.2`). The ZIP's `plugin.json` uses the actual release version. Tags are `vX.Y.Z`.
- The repository's `plugin.json` has `update_url` set to the stable URL:
  `https://github.com/Avalanche7CZ/modpack-updater-pelican/releases/latest/download/update.json`.
- The feed tells Pelican which exact tagged ZIP to download and supplies its SHA-256 hash. Pelican's plugin page can then offer **Update** after a new release is published (the panel may cache update checks for a few minutes).
- **Important:** This update feed requires an anonymously accessible GitHub Release. For private repositories, Pelican cannot fetch the public `releases/latest/download` URL without separate authentication. Source-code ZIP archives are **not** Pelican installation packages.
- No commit or GitHub Release automatically changes a running Pelican installation or Minecraft server. Installing/updating the plugin remains an admin decision; Minecraft auto-deploy is separately disabled by default.

Latest install ZIP: [arcturus-modpack-manager.zip](https://github.com/Avalanche7CZ/modpack-updater-pelican/releases/latest/download/arcturus-modpack-manager.zip). This URL becomes available once the first automatic Release has been published.

## Installation troubleshooting (MySQL / MariaDB)

If Pelican reports an SQL migration error containing `Row size too large` (MySQL/MariaDB error 1118) while creating `arcturus_modpack_servers`, you have an old plugin package: its initial migration used eight `VARCHAR(2048)` fields. With `utf8mb4`, those fields alone can exceed MySQL's 65,535-byte logical row limit.

**Fix:** Download the newest `arcturus-modpack-manager.zip` from [Releases](https://github.com/Avalanche7CZ/modpack-updater-pelican/releases/latest), re-import it in Pelican and retry installation. The corrected migration uses `TEXT` for long URLs and filesystem paths. A failed installation normally remains in the `NotInstalled` state, and can be retried after replacing the plugin's files.

Do **not** run `migrate:fresh` or drop Pelican tables to solve this. If a retry reports `table already exists`, inspect the full error and migration state first: a previous partial installation could have created some tables before failing.

This schema change has an offline regression check, but the exact Pelican/Wings integration should still be verified on the operator's installation.

## Environment

Set these values on the Pelican **panel/queue worker host**, never in GitHub. Restart the queue workers after changes.

~~~dotenv
ARCTURUS_MODPACK_MANAGER_CHECK_INTERVAL_HOURS=6
ARCTURUS_MODPACK_MANAGER_DISCORD_WEBHOOK=
ARCTURUS_MODPACK_MANAGER_CURSEFORGE_API_KEY=
ARCTURUS_MODPACK_MANAGER_CUSTOM_MANIFEST_DIRECTORY=/srv/modpack-manifests
ARCTURUS_MODPACK_MANAGER_ALLOWED_LIVE_ROOTS=/var/lib/pelican/volumes
ARCTURUS_MODPACK_MANAGER_FTB_INSTALLER_BINARY=/usr/local/bin/ftb-server

# Both are intentionally disabled by default.
ARCTURUS_MODPACK_MANAGER_ENABLE_DEPLOY=false
ARCTURUS_MODPACK_MANAGER_AUTOMATIC_DEPLOY=false
~~~

The standard Laravel queue worker needs to run. Backup, restore and startup checks can require a worker timeout longer than **10800 seconds**; ensure queue supervisor retry_after / timeout settings accommodate the job without duplicate execution. Ensure the Laravel scheduler is active; automatic checks use the modpack:check Artisan command.

For deployment, give the **dedicated queue worker** narrowly scoped access to the exact live server directory, staging parent and external backup parent. Do not run the web server as root.

## Connect a server

Open **Arcturus Modpack Manager → Managed servers → Connect server**. Select an existing Pelican server, release provider, source ID, the verified installed pack version and protection rules.

Provider source formats:

| Provider | Source | Stage support |
| --- | --- | --- |
| FTB | Numeric pack ID | Official FTB Server Installer |
| GitHub | owner/repository | One server ZIP matched by optional asset glob |
| Modrinth | Project slug / ID | One recognizable server ZIP |
| CurseForge | Numeric mod ID | Release checks only, API key required |
| Custom | Absolute path to local manifest JSON in approved directory | Manual |

**File rules** are ordered; the last matching rule wins. Examples:

~~~json
{
  "rules": [
    {"pattern": "config/**/*.cfg", "mode": "merge"},
    {"pattern": "config/Arcturus/**", "mode": "protect"},
    {"pattern": "mods/Angelica-*.jar", "mode": "disable"},
    {"pattern": "mods/ArchaicFix-*.jar", "mode": "disable"}
  ]
}
~~~

Confirm the actual mods before using these example rules. Disabled mods are matched by file path patterns, not by JAR metadata. A "merge" rule marks conflicts for manual review; it does not silently merge configuration files.

World folders, runtime identity and server.properties are treated as immutable, even with a replace rule.

## Adopt a trusted baseline

You must have the **unmodified original upstream server pack** corresponding to the installed version. Do not infer a baseline from the customized live server.

~~~bash
php bin/modpack.php manifest /srv/upstream/GTNH-2.8.1 2.8.1 > /srv/manifests/gtnh-base.json
~~~

To use the UI's read-only preview, set:

- Live root: existing local Wings volume path within ARCTURUS_MODPACK_MANAGER_ALLOWED_LIVE_ROOTS.
- Baseline manifest path: trusted JSON file for installed upstream release.
- Target manifest path: versioned JSON manifest of the new server pack.

The panel only reads hashes during preview. Remote nodes need a separate worker or manual CLI; this plugin does not silently mount volumes or connect by SSH.

## Update flow

1. Scheduled release check records latest stable version and optionally notifies Discord.
2. Prepare downloads a server ZIP to isolated staging or runs the official FTB installer there.
3. The worker hashes the staged files and builds a target manifest.
4. Dry-run compares baseline, current server and target, keeping local extras and protected configs.
5. A confirmed/approved deployment stops the server and requests a **locked full backup via Pelican**, respecting its quota (no overriding/deleting other backups). The update proceeds only when Pelican marks this exact backup successful, with a valid checksum and size.
6. The updater applies the staged files without creating a second copy of the whole server, and then verifies Minecraft's startup marker.
7. On failure it requests **Pelican Restore (truncate existing files)** for that backup UUID, waits for the Wings restore-success callback, checks managed file hashes, then attempts to restart the original version. A failed or timed-out restore leaves the server stopped for manual recovery.

**Manual deployment** requires ARCTURUS_MODPACK_MANAGER_ENABLE_DEPLOY=true and the per-server deploy toggle. **Automatic deployment** additionally requires ARCTURUS_MODPACK_MANAGER_AUTOMATIC_DEPLOY=true, policy Auto, no unresolved conflicts, and an upstream artifact digest (or validated FTB installer). Both flags are OFF by default.

For major GTNH upgrades, use Approval policy. A successful boot cannot guarantee world/machine migration correctness. The pre-update backup is **locked** and visible under the server's Pelican Backups. If the backup quota is full, updating stops rather than deleting another backup. Excluded directories are not stored in the archive.

## Backup backend configuration

Use **Arcturus Modpack Manager → Managed servers → Backup backend**:

- **Pelican Backups (default):** creates a locked pre-update backup under the server's existing Pelican Backup Host (Wings or S3), records its UUID, and waits for the completed callback. No extra `backup_root` path is required. By default the top-level `backup/` and `backups/` directories are excluded.
- **Legacy filesystem snapshots:** uses the old local directory copy and atomic-swap rollback. Requires `backup_root` outside the server. Intended only as a fallback when Pelican-native backups cannot be used.

If the server has reached its Pelican backup limit, increase the limit or free a backup through Pelican before updating. The updater never silently prunes existing backups. Do not manually unlock or delete a pre-update backup while an update is in progress.

### Exclude large in-server backup directories

In **Arcturus Modpack Manager → Managed servers → Backup backend → Exclude folders from Pelican backup** you can edit the list (default: `backup`, `backups`). Enter **folder names from the root of the Minecraft server**, not glob patterns. Wings receives anchored gitignore rules:

~~~text
/backup/
/backups/
~~~

Example: `backup/world-2026.zip` and `backups/old.tar.gz` are left out, but `world/`, `mods/`, `config/` and other pack data still go into the Pelican backup. The plugin refuses traversal strings, glob expressions, or critical Minecraft directories. Exclusions are per server and visible in the backup event history. This reduces the archive size considerably if these folders already contain 30–40 GB of older archives.

**Important when rolling back:** Pelican's full `truncate=true` restore would erase excluded live folders even though they aren't in the archive. Before restore, the worker **renames** existing excluded top-level folders to a durable stash under your configured `staging_parent`, outside the live server directory. After Wings confirms successful restore, it moves them back. This normally moves directory entries without copying 30–40 GB, but requires the same filesystem and sufficient permissions. If preservation or restore fails, the worker stops instead of risking the folder contents; the stash path is reported in the failure history for manual recovery. Never clean an `mpm-preserved-*` directory manually while recovery is pending.

A pre-update backup with exclusions is intentionally **not** a complete archive of the server: the ignored backup folders are preserved separately during managed rollback, but are **not available if you restore the Pelican archive manually to an empty server**. Manual Pelican restores will not recover these folders. Keep separate copies if they matter.

When you configure no exclusions, the updater sends an explicit comment-only ignore list so Wings does not silently inherit a different `.pelicanignore` that could omit important data.

**Restore caveat:** Pelican reports both successful and failed restore completion by clearing the server's restoring state. The plugin therefore also verifies the restore callback tied to the exact backup and verifies manifest-managed hashes before considering recovery successful. It cannot prove the world is semantically healthy; major modpack upgrades still warrant an operator review.

## Offline node CLI

The independent PHP CLI can be run on a Wings host without the Panel. It **does not stop Minecraft**. You must stop the server yourself before deploying or rolling back.

~~~bash
php bin/modpack.php manifest /srv/upstream/old 2.8.1 > base.json
php bin/modpack.php manifest /srv/upstream/new 2.9.0 > target.json

php bin/modpack.php preview base.json target.json /srv/minecraft/gtnh examples/rules.example.json > preview.json

# Only after stopping Minecraft:
php bin/modpack.php deploy base.json target.json /srv/minecraft/gtnh /srv/upstream/new /srv/backups examples/rules.example.json --offline-confirmed

# Full backup rollback, also offline:
php bin/modpack.php rollback /srv/minecraft/gtnh /srv/backups/SNAPSHOT-DIRECTORY --offline-confirmed
~~~

The "--offline-confirmed" CLI flag is an explicit acknowledgement, not independent proof that the server has stopped. The integrated Pelican queue worker separately verifies the Wings state.

Rollback restores all files including world data from the snapshot, and preserves the failed installation in a sibling directory. Mounted volume roots that cannot be renamed may need a manual restore.

## Validation

~~~bash
find src bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/planner.php
php tests/inventory.php
php tests/deployment.php
~~~

GitHub Actions runs these tests, a CLI smoke test and validates the plugin ZIP before each release. **CI does not test an actual Pelican installation, Wings daemon or Minecraft boot.** Live integration remains required before enabling deployment.

## Safety limitations

- Deploy is globally and individually opt-in; no panel installation triggers a server restart.
- Symlinks, traversal paths and ZIP entries with unsafe file types are rejected.
- Changed local configs or untrusted baseline metadata block updates.
- A verified locked Pelican backup is required before writing files in default mode; the saved Pelican backup UUID is included in update history.
- The plugin never treats a backup creation request as success and never infers restore success from the server status alone.
- Modpacks may require manual migrations, Java/runtime changes or config review.
- No arbitrary URL downloads for custom providers; local JSON manifests only.
- Backup creation and restore use Pelican's internal InitiateBackupService, Backup model, Wings restore endpoint and callback status. The exact Pelican build must be integration-tested.
- The legacy filesystem mode remains available as an operator-selected fallback. Full snapshots can be large and may not work if the live volume cannot be renamed.

Development continues in the draft PR. Use a disposable staging server first.

## Compatibility with the cosmii02 Modpack Manager plugin

This is a **separate** plugin with ID `arcturus-modpack-manager`, distinct from [`cosmii02/pelican-modpackmanager`](https://github.com/cosmii02/pelican-modpackmanager), whose ID is `modpack-manager`. Both may be installed together because their plugin IDs, configuration namespaces, admin routes, commands and database tables are different.

**Never import older Arcturus ZIPs named `modpack-manager.zip`:** those were published under the conflicting ID and can overwrite the existing cosmii02 plugin. Install only `arcturus-modpack-manager.zip` from a release created **after** this change. The corrected SQL migration uses `TEXT` columns for long URLs and paths to avoid MariaDB error 1118. If a prior install failed, verify the original cosmii02 plugin's files still show author `cosmii02`, and remove the incorrect Arcturus package through Pelican's normal plugin interface if necessary. Do not delete SQL tables without inspecting migration state.
