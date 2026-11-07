# Capability and qualification status

This release provides remote inspection, adoption, upstream staging and review. **It is not a qualified remote deployment release.** Manual Apply Update is available only for the [qualified same-host workflow](manual-deployment.md): unchanged installed Forge 1.20.1 / Java 17 runtime reuse and native Wings backups. Automatic deployment stays disabled. Setting an environment flag does not enable missing node transactions.

| Capability | Implemented behavior | Qualification |
| --- | --- | --- |
| File operations | Native Pelican `DaemonFileRepository`: list/stat, bounded text reads, text writes, mkdir, rename/move, delete, copy-with-daemon-selected-name | Actual rc.1 classes with HTTP fixtures; no live Wings write test |
| SHA-256 inventory | Server-scoped worker streams every managed file; accounts for missing requested files; rejects partial/oversized responses | Real disposable filesystem and worker HTTP tests |
| Worlds | Comparison never hashes worlds; offline manual-deployment recovery fingerprints the whole recoverable volume read-only | Custom `level-name` is excluded from mutations; unsupported property keys fail closed |
| Provider metadata | Independent FTB, GitHub, Modrinth, CurseForge, ATLauncher and custom adapters | Fixtures; live FTB/Modrinth/GitHub metadata smoke checks |
| Preparation | Node-side downloads with upstream checksums, bounded ZIP extraction, normalized manifest, durable progress | Full small local-artifact staging test; no full modpack installation |
| Adoption | Native read-only inspection, exact upstream baseline, customized-file report, explicit finish or unverified monitoring | Planner/provenance/framework tests; live Wings inspection not exercised |
| Modrinth | `.mrpack` index, server environment, overrides then server-overrides, dependency metadata | Fixtures; no automatic loader installation; optional files require an explicit custom recipe |
| CurseForge | Exact file/server-pack resolution and checkpointed client-manifest preparation | Two-stage fixture preparation and queue reconciliation tested; missing keys/downloads/hashes/dependencies fail closed; optional selections still require explicit normalization |
| FTB | Current official manifest endpoint, newest stable IDs, targets, client-only filtering, checksums, CurseForge reference resolution | Files can be staged; generated loader/Java runtime is not installed by this worker |
| GitHub / GTNH | Exact tags, explicit server-asset glob, ambiguity rejection, GitHub SHA-256 asset digest | Missing digest requires an independently pinned custom recipe; no launcher or Java change |
| ATLauncher | Official project/version/config metadata and checksummed permitted downloads | API bot protection, browser-only mods, MD5-only files and unsigned config ZIPs block preparation; no restriction bypass |
| Custom | Allowlisted HTTPS schema-2 recipes or operator-owned local recipe files; exact node-local artifact IDs | Every artifact needs an upstream checksum; no user-provided filesystem download paths |
| Three-way comparison | Preserves local files, missing mods, protected data, stable mod IDs and explicit mappings | Existing suite plus rename/deletion/identity regression tests |
| Per-server policies | Exact/mod-ID/filename/directory/glob/loader/extension selectors, editable templates, bulk file rules, native rule testing, audit and approval invalidation | Native Livewire wizard/modal submission, server isolation/reload, race and exact preview/deploy fixture tests; see [policy limits](server-policies.md) |
| Text merging | Strict single-line `.properties` three-way merge output for administrator review | Escapes, continuations, duplicate keys and conflicts refuse success; no automatic write-back |
| Runtime preflight | Forge/NeoForge args paths, legacy Forge JARs, Fabric/Quilt launchers, Crucible, image hints | Unknown actual Java or incompatible runtime blocks deployment; no egg/image/startup mutation |
| Native backups | Locked Wings backups, quota checks, exact backup and restore callbacks, full offline recovery verification | Native callback controllers and archive/restore failure fixtures on rc.1/rc.2; no live Wings test; other adapters blocked for manual deployment |
| Remote rollback exclusions | Standard Wings cannot move excluded data outside its volume | Automatic truncating restore is blocked; operator preservation/recovery required |
| Deployment / rollback | Exact manual approval, acknowledged per-UUID maintenance guard, stopped-server transaction, fresh startup and verified full rollback or stopped checkpoint | Native Filament/database queue/Wings HTTP fixtures on rc.1/rc.2; no actual TFG/Minecraft or live Wings qualification; remote and automatic deployment disabled |
| Native UI | Admin dashboard, adoption wizard, detail tabs, conflict decisions, encrypted plugin settings; owner/subuser read-only page | Filament 5 component construction, Livewire tab rendering, Blade compilation and 36 Chromium viewport/theme fixture checks |
| Same-host filesystem | One-time root-owned node approval, trusted UUID discovery, inherited read ACLs and private staging on adoption; existing unprivileged stdio preparation/inventory | Local archive, checksum, permission/path, lock/recovery, Laravel/Filament and remote compatibility fixtures; qualified manual updates require additional scoped deployment/read-only recovery ACLs; no live TFG runtime proof |
| Database | Additive Arcturus-only migration; previous records become unverified, default transport Wings | SQLite install/upgrade/down tests and row-size guard; no MariaDB runtime test |

Preparation completion means **the published files were staged and checksummed**. It does not mean a loader is installed, a launch script is trusted, the pack is deployable, or a world migration has been tested. Generated runtime files remain outside a published-file baseline.

## Destructive gates

A verified baseline must match the exact provider, project and version. It is obtained from independently checksummed upstream artifacts in isolated staging, never from customized live files. The Panel stores an authenticated snapshot and provenance separately from server files. Older schema-1 inventories stay usable by the offline CLI but do not become trusted deployment baselines.

The node worker deliberately exposes no `exec`, shell, power, deploy, restore, arbitrary copy source or arbitrary extraction-destination operation. It advertises `deploy=false` and `preserve_exclusions=false`. A production deployment implementation needs a qualified, durable server-scoped transaction with conditional writes and recovery, confirmed stopped-server ownership, native backup completion, fresh startup evidence and exact restore verification. Those are not approximated with sequential unchecked Wings requests.

Full restore with truncation deletes entries absent from a backup, including excluded backup folders. Standard Wings rename/copy paths are constrained to the server volume. Moving excluded data into another folder in that same volume is not preservation. Until an outside-volume transaction is implemented and validated on disposable Wings, remote automatic rollback is refused. Locked pre-update backups are never automatically deleted.

## Source audit

APIs were inspected against official sources, without copying cosmii02 implementation code:

- [Pelican rc.1](https://github.com/pelican-dev/panel/tree/46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00): PHP 8.4/8.5, Filament 5, Laravel 13; `HasPluginSettings`, plugin discovery, daemon repositories, native backup initiator and restore callback.
- [Pelican current source inspected](https://github.com/pelican-dev/panel/tree/35fdb2d6ab65e80bed21f87724da21ca532d80a5). CI also checks rc.2 using its own lockfile.
- [Wings source inspected](https://github.com/pelican/wings/tree/86adbf43ff789ac11114770f388cae81c79a8ad2): file stat fields are `file`, `directory`, `symlink`, `mode_bits`; pulls have progress/cancellation endpoints but no general SHA-256 inventory or multi-file transaction.
- [Reference plugin](https://github.com/cosmii02/pelican-modpackmanager/tree/b87a267d0cf722799db5e4e56ed51179b4aab4c1): provider architecture, loader detection, server tenancy, native plugin settings and installation flow. Its reinstall/egg-switch behavior is deliberately not adopted for customized servers.
- [FTB official installer](https://github.com/FTBTeam/FTB-Server-Installer/blob/main/repos/ftb.go): current `/v1/modpacks/modpack/{pack}/{version}` endpoint.
- [CurseForge API](https://docs.curseforge.com/rest-api/): server-pack IDs, hashes, required files and download permissions.

Framework tests are evidence of API compatibility. They are not evidence of live Wings, Minecraft startup, successful world migration or production backup restoration.

## Private GitHub distribution

This repository is currently private. The automatic workflow can publish authenticated GitHub Releases, but Pelican's ordinary unauthenticated `releases/latest/download/update.json` request cannot read private assets. Use an authenticated GitHub download and manual plugin import, or an operator-controlled publicly readable mirror of the ZIP/feed/checksums. A consistent feed is not proof that an unauthenticated Panel can reach it. This change does not alter repository visibility or expose release contents publicly.
