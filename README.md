# Pelican Modpack Manager

Universal, safety-first modpack update planning for [Pelican Panel](https://pelican.dev/).

> **Status: early development / read-only prototype.** This repository does not yet install or update running servers. Do not deploy it as an automatic updater.

## Goals

- A Pelican admin plugin (Filament) with per-server update policies.
- Provider adapters for FTB, GTNH/GitHub releases, CurseForge, Modrinth, and custom sources.
- A common manifest-based engine with SHA-256 hashes and a three-way comparison (installed upstream / local filesystem / target upstream).
- Explicit preservation rules for customized configs, extra files, and deliberately disabled mods.
- Dry-run previews before backup, stop, staging, deploy, verification, rollback and Discord notifications.

## Initial implementation

The current implementation contains a **read-only manifest planner and filesystem inventory scanner**. It reads package and live-server files to compute hashes but does not alter server files, invoke Pelican APIs or download modpacks.

Repository layout:

- `plugin.json` — Pelican plugin metadata (API v1).
- `src/ModpackManagerPlugin.php` — Filament plugin entry point.
- `src/Services/UpdatePlanner.php` — deterministic update planning.
- `src/Services/DirectoryInventory.php` — SHA-256 scans with symlink checks and bounded release inventory.
- `src/Services/ManifestJson.php` — strict versioned manifest format.
- `bin/modpack.php` — read-only command line preview.
- `examples/rules.example.json` — illustrative protected configuration and disabled mod rules.
- `tests/` — standalone PHP tests (run with `php tests/planner.php && php tests/inventory.php`).

## Planner inputs

Three relative-path => SHA-256 maps:

1. `base`: immutable manifest for the originally installed upstream release.
2. `local`: inventory of the live installation (hashes only).
3. `target`: immutable manifest of the proposed upstream release.

Rules are ordered by **last match wins**. Supported modes:

- `protect`: keep existing local content.
- `disable`: block installation of a matching upstream file (the worker must separately enforce the disabled-mod policy).
- `replace`: allow replacement of locally changed content explicitly.
- `merge`: mark text content for a later conflict-aware three-way merge; the planner never claims a merge succeeded.

Default classification deliberately **blocks** local conflicts, missing base provenance and unknown upstream removals from being applied automatically. Local-only files are never targeted for deletion. In the CLI preview, only paths owned by the old/new manifests are scanned; all other live files remain untouched and are not listed. Known runtime/world paths are immutable, even if a replacement rule is configured.

## Safety constraints

- Never infer a trusted baseline from the current live directory alone.
- No writes/deletions during planning; no auto-updates until staging and verified backup are implemented.
- Fail closed on ambiguous changes, unsafe paths, symlinks and archive extraction.
- All runtime/world/user data must remain outside modpack-managed ownership.
- A successful server startup is not sufficient proof that an update is safe.

## Roadmap

1. Read-only manifest engine + tests.
2. Provider adapters, package verification, release metadata and safe baseline adoption.
3. Admin UI: bind servers, protection rules, dry-run/history, permissions.
4. Out-of-process deployment worker with durable jobs, verified backups and recovery.
5. Maintenance windows, health checks, approvals, notifications and staged rollout.

**Compatibility note:** Pelican's plugin API evolves. The Filament entry point follows the documented API v1 structure; actual plugin installation and UI compatibility with panel 1.0.0-rc.1 have not yet been tested.

## Run a local dry-run (PHP 8.3+)

Use **trusted, already unpacked server packs**. A live server must never be substituted for an upstream release. Confirm the actual installed release version before creating the baseline.

1. Build the original and target upstream manifests:

~~~bash
php bin/modpack.php manifest /path/to/original-serverpack 2.8.1 > base.json
php bin/modpack.php manifest /path/to/new-serverpack 2.9.0 > target.json
~~~

2. Inspect the live installation without changing it:

~~~bash
php bin/modpack.php preview base.json target.json /path/to/live-server examples/rules.example.json > preview.json
~~~

Output includes per-file actions (`update`, `add`, `remove`, `preserve`, `conflict`, `merge_required`, `skip_disabled`, etc.) and `blocked`/`applicable` indicators. **`applicable=true` is not deployment approval**; it only means no unresolved planner conflicts. There is no deploy command.

Rule format: `{"rules": [{"pattern": "config/**/*.cfg", "mode": "merge"}, {"pattern": "config/Arcturus/**", "mode": "protect"}]}`. Last rule wins; `*` stays inside one directory, `**` matches nested paths. Example disabled-mod globs are illustrative and must be verified against real JAR filenames. There is no mod-ID-aware resolver yet.

**Security and limitations:**

- Scanning refuses symlinks, unsafe relative paths and oversized release inventories. It does not validate signatures or safely extract downloaded archives; release provenance must be established separately.
- Source manifests must describe the **exact upstream releases**. A manually modified or wrong baseline makes three-way comparisons unreliable.
- No automatic merging: `merge_required` is a stop condition, not a completed merge.
- No automatic enforcement of disabled mods or binary file replacement. Already-present disabled mods cause a stop condition.
- No Pelican dashboard or update execution yet. A successful local PHP test is not equivalent to integration testing with Pelican 1.0.0-rc.1.

## Plugin packaging

Pelican requires the plugin directory name to match `plugin.json`'s ID. This repo is named `modpack-updater-pelican`, but the installed plugin folder must be **`modpack-manager`**, with `plugin.json` at its root. The plugin entry point currently registers no admin pages or actions, so installing it is not useful yet. Keep it on a test instance until the UI is implemented and verified.

## Validation

~~~bash
find src bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/planner.php
php tests/inventory.php
~~~

GitHub Actions also runs these tests and an end-to-end CLI smoke test. It does not access a production Minecraft server.
