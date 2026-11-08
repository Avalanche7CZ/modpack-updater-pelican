# Capability and qualification status

Normal deployment uses **Native Pelican / Wings**, without host-volume ACL provisioning or an additional API token. Read the [native deployment guide](native-deployment.md) for the exact supported runtime and recovery boundary. Legacy local/remote backends retain their previous restrictions. Tests use real rc.2 services with disposable filesystem/HTTP fixtures; no live Wings/Docker/Minecraft qualification is claimed.

| Capability | Implemented behavior | Qualification |
| --- | --- | --- |
| File operations | Native authenticated binary streams, verified uploads, mkdir, checked rename/delete and ordered journaled deployment | Real rc.2 repositories with HTTP fixtures; no live Wings test |
| SHA-256 inventory | Native manifest-delta candidate streams, ancestor/symlink checks and bounded reads; no recursive server inventory | Native HTTP fixtures and existing worker regressions |
| Worlds | Plugin verification never hashes/scans worlds; native locked Wings backups and restore callbacks cover complete server data | Custom `level-name` is excluded from mutations; unsupported property keys fail closed |
| Provider metadata | Independent FTB, GitHub, Modrinth, CurseForge, ATLauncher and custom adapters | Fixtures; live FTB/Modrinth/GitHub metadata smoke checks |
| Preparation | Fixed unprivileged Python archive/checksum worker in Panel-private storage; legacy node staging optional | Real worker fixtures; real NeoForge 21.1.172 installer smoke-tested in a private sandbox; no real Minecraft boot |
| Adoption | Native read-only inspection, exact upstream baseline, customized-file report, explicit finish or unverified monitoring | Planner/provenance/framework tests; live Wings inspection not exercised |
| Modrinth | `.mrpack` index, server environment, overrides then server-overrides, dependency metadata | Fixtures; no automatic loader installation; optional files require an explicit custom recipe |
| CurseForge | Exact file/server-pack resolution and checkpointed client-manifest preparation | Two-stage fixture preparation and queue reconciliation tested; missing keys/downloads/hashes/dependencies fail closed; optional selections still require explicit normalization |
| FTB | Current official manifest endpoint, newest stable IDs, targets, client-only filtering, checksums, CurseForge reference resolution | Files can be staged; generated loader/Java runtime is not installed by this worker |
| GitHub / GTNH | Exact tags, explicit server-asset glob, ambiguity rejection, GitHub SHA-256 asset digest | Missing digest requires an independently pinned custom recipe; no launcher or Java change |
| ATLauncher | Official project/version/config metadata and checksummed permitted downloads | API bot protection, browser-only mods, MD5-only files and unsigned config ZIPs block preparation; no restriction bypass |
| Custom | Allowlisted HTTPS schema-2 recipes or operator-owned local recipe files; exact node-local artifact IDs | Every artifact needs an upstream checksum; no user-provided filesystem download paths |
| Three-way comparison | Preserves local files, missing mods, protected data and explicit mappings; one logical row per checksum-bound Forge/NeoForge mod upgrade | Native queued mod deployment and rollback fixtures; optional embedded identity grouping; [scope](mod-upgrades.md) |
| Per-server policies | Exact/mod-ID/filename/directory/glob/loader/extension selectors, editable templates, bulk file rules, native rule testing, audit and approval invalidation | Native Livewire wizard/modal submission, server isolation/reload, race and exact preview/deploy fixture tests; see [policy limits](server-policies.md) |
| Text merging | Strict single-line `.properties` three-way merge output for administrator review | Escapes, continuations, duplicate keys and conflicts refuse success; no automatic write-back |
| Runtime preflight | Independently verified boot evidence; published complete Forge/NeoForge migrations, approved egg Java images and controlled startup rollback | Complete native service/queue fixtures for runtime migration and image/startup recovery; installer-produced dependencies enter the same verified plan when isolated-build capabilities are available |
| Native backups | Locked Wings backups, quota checks, exact backup and restore callbacks, full offline recovery verification | Native callback controllers and archive/restore failure fixtures on rc.2; no live Wings test; other adapters blocked for manual deployment |
| Native rollback exclusions | Preserve approved backup folders and existing root unix_args launcher link; clear other root entries and verify; restore exact backup without truncation | Partial-write, failed-callback and interrupted-worker recovery fixtures; incomplete clear blocks start |
| Deployment / rollback | Exact approval, acknowledged Wings guard, confirmed native backup, verified staged transfer, ordered changes, fresh target boot, rollback or stopped checkpoint | Full file-only and published NeoForge migration fixtures through real rc.2 services; no live Minecraft qualification |
| Native UI | Admin dashboard, adoption wizard, detail tabs, conflict decisions, encrypted plugin settings; owner/subuser read-only page | Filament 5 component construction, Livewire tab rendering and Blade compilation |
| Same-host filesystem | One-time root-owned node approval, trusted UUID discovery, inherited read ACLs and private staging on adoption; existing unprivileged stdio preparation/inventory | Local archive, checksum, permission/path, lock/recovery, Laravel/Filament and remote compatibility fixtures; qualified manual updates require additional scoped deployment/read-only recovery ACLs; no live TFG runtime proof |
| Database | Additive Arcturus-only tables and durable encrypted operation receipts; Native backend is the new-adoption default | SQLite install/upgrade/down and receipt tests; no MariaDB runtime test |

Preparation completion means **the published files were staged and checksummed**. It does not mean a loader is installed, a launch script is trusted, the pack is deployable, or a world migration has been tested. Generated runtime files remain outside a published-file baseline.

## Destructive gates

A verified baseline must match the exact provider, project and version. It is obtained from independently checksummed upstream artifacts in isolated staging, never from customized live files. The Panel stores an authenticated snapshot and provenance separately from server files. Older schema-1 inventories stay usable by the offline CLI but do not become trusted deployment baselines.

The fixed preparation worker exposes no shell, deploy or power endpoint. Live deployment uses authenticated Pelican daemon repositories and a durable Panel transaction. Every native mutation is checked; Wings HTTP success alone is insufficient. The original runtime settings and exact backup UUID remain in the operation receipt. Unsupported mounts, denylists, unsafe paths, missing runtime dependencies, unverified Java or backup capacity are actionable blockers.

Recovery does not use truncation with excluded folders. It retains approved backup directories, preserves the known root launcher link by native rename, verifies root clearing, invokes the native nontruncating restore and waits for its exact callback. Unverified recovery stays stopped. See the [transaction and recovery details](native-deployment.md#transaction-and-recovery).

## Source audit

APIs were inspected against official sources, without copying cosmii02 implementation code:

- [Pelican rc.1](https://github.com/pelican-dev/panel/tree/46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00): PHP 8.4/8.5, Filament 5, Laravel 13; `HasPluginSettings`, plugin discovery, daemon repositories, native backup initiator and restore callback.
- [Pelican current source inspected](https://github.com/pelican-dev/panel/tree/35fdb2d6ab65e80bed21f87724da21ca532d80a5). Only rc.2 is targeted by CI; it uses the official rc.2 lockfile.
- [Wings source inspected](https://github.com/pelican/wings/tree/86adbf43ff789ac11114770f388cae81c79a8ad2): file stat fields are `file`, `directory`, `symlink`, `mode_bits`; pulls have progress/cancellation endpoints but no general SHA-256 inventory or multi-file transaction.
- [Reference plugin](https://github.com/cosmii02/pelican-modpackmanager/tree/b87a267d0cf722799db5e4e56ed51179b4aab4c1): provider architecture, loader detection, server tenancy, native plugin settings and installation flow. Its reinstall/egg-switch behavior is deliberately not adopted for customized servers.
- [FTB official installer](https://github.com/FTBTeam/FTB-Server-Installer/blob/main/repos/ftb.go): current `/v1/modpacks/modpack/{pack}/{version}` endpoint.
- [CurseForge API](https://docs.curseforge.com/rest-api/): server-pack IDs, hashes, required files and download permissions.

Framework tests are evidence of API compatibility. They are not evidence of live Wings, Minecraft startup, successful world migration or production backup restoration.

## Private GitHub distribution

This repository is currently private. The automatic workflow can publish authenticated GitHub Releases, but Pelican's ordinary unauthenticated `releases/latest/download/update.json` request cannot read private assets. Use an authenticated GitHub download and manual plugin import, or an operator-controlled publicly readable mirror of the ZIP/feed/checksums. A consistent feed is not proof that an unauthenticated Panel can reach it. This change does not alter repository visibility or expose release contents publicly.
