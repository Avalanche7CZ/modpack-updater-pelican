# Arcturus Modpack Manager

Native Pelican tooling for adopting customized Minecraft servers, checking releases, preparing verified upstream files on remote nodes and reviewing three-way file changes.

**Read the [capability matrix](docs/capabilities.md) before enabling this plugin. Remote destructive deployment remains disabled.** Adoption, checking, staging and preview do not stop, restart or reinstall the server.

Identity is permanently isolated from cosmii02's Modpack Manager:

- Name: `Arcturus Modpack Manager`
- Plugin ID / configuration / views: `arcturus-modpack-manager`
- PHP namespace: `Arcturus\ModpackManager`
- Tables: `arcturus_modpack_*`
- Commands: `arcturus-modpack:*`
- Package: `arcturus-modpack-manager.zip`, rooted at `arcturus-modpack-manager/`

Both plugins can coexist. Arcturus never reads, migrates or modifies cosmii02's tables, files or configuration.

For a same-host Ubuntu installation, use the [local filesystem setup](docs/local-filesystem.md): one-time administrator approval of the local Wings node, automatic UUID discovery and private staging on adoption. Open **Local filesystem setup**, run its host command once, then enable the local default. No separate node-worker service, HTTPS proxy, tokens, per-server JSON or environment edits are needed. Remote deployments retain the [remote worker setup](docs/remote-wings.md).

Modpack-specific roots require explicit operator review. For the reported TFG-Modern 0.13.5 `DiscordIntegration-Data` and `tacz` directories, see [reviewed directory setup](docs/local-filesystem.md#reviewed-modpack-specific-directories-tfg-modern-0135). Unknown archive folders are never automatically trusted.

## Requirements and installation

Target: Pelican Panel **1.0.0-rc.1**, API version 1, PHP 8.4 or 8.5, Filament 5. PHP cURL, ZIP, mbstring, intl and Pelican's normal database/queue dependencies are required. A shared persistent database/cache and an operational queue worker are needed. For remote preparation, keep queue `retry_after` above its 150-second tick timeout (180 seconds or more). Approved local-node setup configures a longer effective retry window for bounded local preparation; see the local setup guide. Manual deployment jobs have a 10,800-second timeout; the local queue requires a longer effective visibility window. See [manual deployment](docs/manual-deployment.md) for host permissions, supported runtimes and recovery requirements.

1. Download `arcturus-modpack-manager.zip` and `SHA256SUMS.txt` from a successful [GitHub Release](https://github.com/Avalanche7CZ/modpack-updater-pelican/releases).
2. Verify `sha256sum -c SHA256SUMS.txt`.
3. Import the ZIP through **Pelican Admin → Plugins**, then install/enable it. Do not unpack it over another plugin.
4. Open the plugin's native settings action. Set provider credentials, notification preferences, check frequency, backup exclusions and staging limits.
5. Configure the [node worker](docs/remote-wings.md) on each managed Wings node. Remote inventories fail explicitly when it is absent; the Panel does not need a volume mount.
6. Keep Pelican's normal scheduler and queue worker running. Restart queue workers after upgrading plugin code/settings. Settings are refreshed before each job; scheduler frequency changes take effect in a fresh scheduler process.

## Adopt an existing server

Open **Arcturus Modpack Manager → Managed servers → Adopt Existing Server**.

1. Select the existing Pelican server and inspect runtime/launch evidence.
2. Enter the actual provider/project and exact installed release ID. FTB uses a numeric version ID; CurseForge a file ID; Modrinth an immutable version ID; GitHub the exact tag. An image tag is a Java hint, not proof of the running runtime.
3. Set an explicit server asset pattern for GitHub, particularly GTNH's Java/Crucible variants. Choose an editable per-server policy template (or Empty), rules, disabled mod IDs, backup backend and safe exclusions.
4. Review the final summary and click **Create adoption draft** (or **Adopt for monitoring only**). Choose **Prepare installed baseline** on its detail page. Files are fetched into isolated node staging, independently of the live customized server.
5. Review **File Changes**: modified configs, local-only mods, missing files, renamed/disabled mods and protected paths. Retain baseline stages for later text-merge review.
6. Finish verified adoption. If the exact upstream release cannot be verified, use **Adopt for monitoring only**. Destructive operations stay restricted.

After an independently performed manual update, use **Record a manual update** on Overview to record the exact release now installed. It invalidates the previous baseline/approvals and requires a fresh adoption comparison; it never updates server files itself.

The wizard never fabricates provenance by hashing live files. Existing startup commands, eggs, Docker images and Java choices are preserved. Arcturus stores association/provenance in its own database, not the reference plugin's metadata. Back up the Panel database and APP_KEY as well as server backups.

## Arcturus protection presets

The **Adopt Existing Server** wizard now defaults to the editable **Arcturus Network — custom mods** preset. For TerraFirmaGreg Modern, select **TFG Modern + Arcturus Network** instead. Both include the conservative protections for `server.properties`, access-control files, changed configs and review of launcher changes.

The Arcturus presets add **Keep local** for the common `Paradigm`/`paradigm` and `ArcturusTokens` JAR filename variants, the `config/paradigm/`, `config/Paradigm/`, `config/paradigminsights/`, `config/paradigmvote/` and `config/arcturustokens/` directories, and the exact file `config/arcturustokens.properties`. TFG presets treat `kubejs/` as upstream-owned: unchanged baseline files update automatically in the plan, new files are added, and unchanged obsolete upstream files are removed. Local-only scripts and protected files are preserved; files changed both locally and upstream require Keep local / Use upstream, with a bounded read-only text diff where supported. No scripts or recipes are merged.

These are **starting templates**, not immutable rules in the update planner. The filename globs are case-sensitive; check exact installed JAR names after baseline comparison. Matching by verified mod ID is stronger when JARs are renamed between releases. New template defaults do **not** automatically change policies already saved on adopted servers. Selecting a template in the wizard replaces the *unsaved* rule list; inspect your edits first.

## Providers

| Provider | Source | Preparation behavior |
| --- | --- | --- |
| FTB | Numeric pack ID | Official exact server file manifest, supported hashes, targets and permitted dependency downloads; generated loader runtime requires separate qualification |
| GitHub / GTNH | `owner/repository` | Exact tag and one explicitly selected server ZIP with GitHub SHA-256 digest; otherwise use a pinned custom recipe |
| Modrinth | Project slug/ID | `.mrpack`, index checksums, server environments and server overrides; optional files require explicit normalization |
| CurseForge | Numeric project ID | API key required; official server packs and checkpointed client-manifest resolution with complete verified downloads; unknown server runtime remains blocked |
| ATLauncher | Pack safe name | Metadata and permitted checksummed mods; unsigned config archives, browser-only downloads and bot protection fail closed |
| Custom | Approved HTTPS recipe or operator-owned JSON path | Schema-2 recipes with exact URLs or configured node-local artifact IDs and independent checksums |

See [provider setup and custom recipes](docs/providers.md). A version being listed does not imply it can be prepared or deployed. No licensing restriction, API distribution opt-out or required-file error is bypassed.

## Review an update

Check releases, select an exact target, prepare its files and compare them with the installed upstream baseline and actual live file inventory:

`original upstream → locally installed → target upstream`

The planner distinguishes upstream additions/updates/removals, local modifications/extras, missing files, disabled mods, protected files, conflicts and unsafe identity changes. Review individual conflicts using **Keep local** or **Accept upstream**. These actions change the plan; they do not write live files. Editing rules invalidates previous review.

Use **Mods & Files** for searchable inventory and bulk file/mod policies, or **Protection Rules** for templates and the advanced rule editor with Test rule. Exact-path rules outrank mod ID, filename, directory, glob, loader and extension rules; priority then row order resolve ties. World/path invariants remain enforced. Named launchers and Arcturus/Paradigm configs are no longer immutable exceptions. See [per-server policy behavior and upgrade notes](docs/server-policies.md). File count or byte-limit failure never yields a partial-success inventory.

Mod identity comes from Fabric/Quilt metadata, Forge/NeoForge TOML or legacy `mcmod.info`. Use explicit path-to-identity mappings when metadata is absent. Missing upstream mods are kept absent across upstream renames where identity is known; unknown/ambiguous identities block new JARs. Additional local mods are preserved. Strict single-line `.properties` merges can be reviewed as text; unsupported syntax or conflicting keys requires manual work through Pelican's file editor. No merge writes live data automatically.

The Updates tab shows real file counts, known download bytes, runtime blockers and backup requirements. **Apply Update requires an administrator-approved current plan and qualified same-host Forge 1.20.1 / Java 17 runtime reuse with native Wings backups.** See [manual deployment and recovery](docs/manual-deployment.md). [Logical mod JAR upgrades](docs/mod-upgrades.md) are currently review-only pending transaction and browser qualification. Remote and automatic deployment remain disabled; no environment toggle bypasses missing safety evidence.

## Backups and recovery

**Pelican Backups remains the default backend.** The retained integration requires a stopped server, respects existing quota without deleting backups, creates a locked backup, waits for a successful completion callback and validates bytes/checksum/lock/exclusions. It records the exact UUID.

Top-level exclusions default to `backup` and `backups`. Worlds (including the configured `level-name`), mods, configs and runtime directories cannot be excluded. Empty exclusions override `.pelicanignore`, avoiding hidden critical-file omissions.

A truncating restore would destroy excluded folders if they remain inside the volume. Standard remote Wings cannot stash them outside that volume, so **automatic remote truncating restore is blocked**. Local preservation retains its recovery journal; it is an explicitly mapped filesystem fallback, not remote-node support. Never delete a retained stash or locked pre-update backup during investigation.

A cleared Pelican restore status alone is not success: the native exact-backup `restore-complete` callback and supplementary file checks are required. Failed or uncertain recovery leaves the server stopped and requires manual intervention. A Minecraft `Done` marker does not prove a world migrated correctly.

## Permissions and settings

Root administrators manage associations, credentials, protection rules and update review. The isolated **Arcturus Modpack** server page is read-only for owners and subusers with Pelican's `file.read` permission on that server. It exposes no provider credentials, worker configuration, host paths or destructive actions.

Settings use Pelican's `HasPluginSettings` API. Values are stored encrypted using Laravel's APP_KEY in the isolated settings table. Saved Discord webhooks are available only in root administrator forms, with Show/Hide and Copy controls. Other credentials stay blank. Blank edits keep saved credentials; explicit remove switches clear them. Node endpoint configuration remains operator-controlled. Keep APP_KEY stable during upgrades.

## Operations and troubleshooting

Background Operation shows persisted phase progress, actual downloaded bytes, known totals, MiB/s and ETA, with an indeterminate indicator when totals are unknown. Safe structured diagnostics and corrective actions remain in history; partial staging is retained. See [progress and diagnostic details](docs/local-filesystem.md#preparation-diagnostics-and-progress). Preparation uses durable operation IDs; queue redelivery reuses the same node ID. Worker restarts mark unfinished node work interrupted and retain partial artifacts. No destructive request is automatically replayed.

- **Node capability unavailable:** install/configure the worker and HTTPS proxy; check the exact server UUID/token mapping.
- **Unverified baseline:** prepare the exact original release, not a manifest derived from live customized files.
- **Missing checksum / restricted download:** select the correct official server pack or provide an independently pinned custom recipe. Do not invent digests or mirror prohibited files.
- **Unknown Java/loader or startup change:** review the real container/runtime and pack migration notes. Image names alone do not establish compatibility.
- **Stale operation:** use Reconcile preparation or `php artisan arcturus-modpack:recover`. This resumes non-destructive preparations and may redispatch the same untouched queued approval receipt. Interrupted destructive work remains locked for [manual recovery](docs/manual-deployment.md#failed-update-and-recovery).
- **Queue not running:** checks/staging remain queued. Configure Pelican's normal queue worker and scheduler.
- **Database/APP_KEY problems:** restore the Panel database/key; retained node stages can support operator investigation, but are not silently adopted as trusted server identity.

Use `php artisan arcturus-modpack:check` for checks. The existing [offline CLI](docs/offline-cli.md) remains available on an explicitly controlled local node. It is separate from remote Panel deployment and does not provide Minecraft runtime qualification.

## Upgrade, tests and releases

Upgrade only the `arcturus-modpack-manager` plugin. The additive migration preserves existing Arcturus fields/history. Existing associations default to unverified and Wings transport until reviewed; previously supplied local paths do not become trusted remote baselines. No reference-plugin migration is included. Retain a Panel database backup before upgrading.

Run `bash scripts/test.sh` for the existing and expanded offline regression suites. See [integration validation](docs/integration.md) for actual Pelican/Filament contracts, disposable node fixtures, API checks and limits. Do not point integration tests at production.

CI checks PHP 8.4/8.5, the Python worker, packaging and official Pelican rc.1/rc.2 lockfiles. Every successful main commit publishes a uniquely versioned install ZIP, native `update.json`, and SHA-256 sums after the required checks pass. Preview builds use the source version; release ZIP metadata and update feed share the computed release version. Local artifacts are not proof of publication.

The public release ZIP and `update.json` are available to Pelican's native updater. Cached update checks may take up to ten minutes to refresh.

## Discord notifications

Each managed server has a compact **Discord Notifications** section on Edit: enable toggle, encrypted webhook override (global fallback), optional role ID, event selection and a test action without pings. Successful updates send one Czech/English embed only after startup verification. Queued delivery is deduplicated and recorded in History; Discord errors never trigger rollback. See [setup, queue requirements and ambiguous-delivery handling](docs/discord-notifications.md).
