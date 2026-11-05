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

The first implementation contains a **pure, read-only manifest planner**. It does not invoke Pelican APIs, spawn processes, contact release providers, or write to server directories.

Repository layout:

- `plugin.json` — Pelican plugin metadata (API v1).
- `src/ModpackManagerPlugin.php` — Filament plugin entry point.
- `src/Services/UpdatePlanner.php` — deterministic update planning.
- `tests/planner.php` — standalone PHP tests (run with `php tests/planner.php`).

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

Default classification deliberately **blocks** local conflicts, missing base provenance and unknown upstream removals from being applied automatically. New local-only files are preserved. Runtime/world paths are excluded from upstream package ownership by default.

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
