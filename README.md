# Pelican Modpack Manager

Pelican plugin for Minecraft modpack release checks, protected config changes, file-diff previews, staged updates and Discord notifications.

**Status: 0.2.0 development / experimental.** Do not deploy to production until tested on the exact Panel and Wings version. The plugin does not modify Pelican core or require new eggs.

## Features

- Native Filament admin dashboard with server status, release checks, responsive tables and preview details.
- Managed servers form with actual Pelican server selection, providers, policy, protection rules and worker paths.
- Searchable and filterable update history, queued background jobs and Discord webhook notifications.
- Release discovery: FTB, GitHub Releases, Modrinth, CurseForge (notification only), local custom manifests.
- Versioned SHA-256 manifests and three-way comparison: verified original upstream / local server / new upstream.
- Staging via official FTB Server Installer or unique server ZIP on GitHub/Modrinth.
- Offline deployment with full backup, checksums and file-level rollback on failure.
- Optional Pelican/Wings stop/start integration and Minecraft "Done (seconds)!" startup verification.
- Full snapshot restore after failed startup, if the live root can be atomically renamed.

**Not implemented:** semantic configuration merging, mod-ID-aware disabled-mod handling, deployment to a remote Wings node without a shared/local filesystem. CurseForge does not automatically supply server ZIPs. GitHub/Modrinth need a unique server ZIP to stage.

## Requirements

- Pelican Panel with plugin API v1 and Filament v4. Compatibility with the owner's 1.0.0-rc.1 build still needs a staging-panel test.
- PHP 8.3+, Pelican scheduler, and a configured Laravel queue worker.
- PHP ZIP extension for ZIP sources and a validated official FTB installer for FTB sources.
- Managed updates only where the Panel's queue worker can access the Wings volume filesystem.
- Enough disk space for a full server backup outside the live tree.

## Install

GitHub Actions uploads **pelican-modpack-manager-plugin** as a ZIP artifact. The ZIP has a root folder named **modpack-manager**, matching the plugin ID.

Import that ZIP in Pelican Admin → Plugins → Import, then Install. For manual installation, copy plugin.json and the src, config, database, resources and bin folders to:

~~~text
/var/www/pelican/plugins/modpack-manager/
~~~

From the Pelican installation directory:

~~~bash
php artisan p:plugin:install modpack-manager
~~~

Pelican automatically registers plugin migrations, providers, commands and views. The plugin is limited to the admin panel and its resources are limited to root administrators.

## Environment

Set these values on the Pelican **panel/queue worker host**, never in GitHub. Restart the queue workers after changes.

~~~dotenv
MODPACK_MANAGER_CHECK_INTERVAL_HOURS=6
MODPACK_MANAGER_DISCORD_WEBHOOK=
MODPACK_MANAGER_CURSEFORGE_API_KEY=
MODPACK_MANAGER_CUSTOM_MANIFEST_DIRECTORY=/srv/modpack-manifests
MODPACK_MANAGER_ALLOWED_LIVE_ROOTS=/var/lib/pelican/volumes
MODPACK_MANAGER_FTB_INSTALLER_BINARY=/usr/local/bin/ftb-server

# Both are intentionally disabled by default.
MODPACK_MANAGER_ENABLE_DEPLOY=false
MODPACK_MANAGER_AUTOMATIC_DEPLOY=false
~~~

The standard Laravel queue worker needs to run. Long-running FTB staging and Minecraft startup may need a 3600-second worker timeout. Ensure the Laravel scheduler is active; automatic checks use the modpack:check Artisan command.

For deployment, give the **dedicated queue worker** narrowly scoped access to the exact live server directory, staging parent and external backup parent. Do not run the web server as root.

## Connect a server

Open **Modpack Manager → Managed servers → Connect server**. Select an existing Pelican server, release provider, source ID, the verified installed pack version and protection rules.

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

- Live root: existing local Wings volume path within MODPACK_MANAGER_ALLOWED_LIVE_ROOTS.
- Baseline manifest path: trusted JSON file for installed upstream release.
- Target manifest path: versioned JSON manifest of the new server pack.

The panel only reads hashes during preview. Remote nodes need a separate worker or manual CLI; this plugin does not silently mount volumes or connect by SSH.

## Update flow

1. Scheduled release check records latest stable version and optionally notifies Discord.
2. Prepare downloads a server ZIP to isolated staging or runs the official FTB installer there.
3. The worker hashes the staged files and builds a target manifest.
4. Dry-run compares baseline, current server and target, keeping local extras and protected configs.
5. A confirmed/approved deployment stops the Pelican server, copies a full snapshot outside the live tree, applies updates and starts Minecraft.
6. Success requires the new Minecraft startup marker (not merely a running container).
7. On failure it attempts to restore the full snapshot, then restart the original version.

**Manual deployment** requires MODPACK_MANAGER_ENABLE_DEPLOY=true and the per-server deploy toggle. **Automatic deployment** additionally requires MODPACK_MANAGER_AUTOMATIC_DEPLOY=true, policy Auto, no unresolved conflicts, and an upstream artifact digest (or validated FTB installer). Both flags are OFF by default.

For major GTNH upgrades, use Approval policy. A successful boot cannot guarantee world/machine migration correctness. Backups are not pruned automatically.

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

GitHub Actions runs these tests, a CLI smoke test and builds the plugin ZIP. **CI does not test an actual Pelican installation, Wings daemon or Minecraft boot.** Live integration remains required before enabling deployment.

## Safety limitations

- Deploy is globally and individually opt-in; no panel installation triggers a server restart.
- Symlinks, traversal paths and ZIP entries with unsafe file types are rejected.
- Changed local configs or untrusted baseline metadata block updates.
- Full local backups happen before writing files; on a failed boot, full restore is attempted.
- Modpacks may require manual migrations, Java/runtime changes or config review.
- No arbitrary URL downloads for custom providers; local JSON manifests only.
- This plugin relies on internal Pelican daemon APIs, which may change across panel releases.

Development continues in the draft PR. Use a disposable staging server first.
