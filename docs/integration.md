# Validation and disposable integration

Run from the plugin checkout with PHP 8.4/8.5, ZIP/mbstring and Python 3.11+:

```sh
bash scripts/test.sh
```

This preserves the original planner, inventory, local deployment, backup safety, exclusions, folder-preservation, schema and identity suites. Added tests exercise provider exact versions, required downloads, `.mrpack` environments, renamed/disabled/deleted mods, explicit protection, safe text merges, provenance rejection, runtime incompatibility, node hash completeness, archive failures, interrupted/checksummed transfers, SSRF/redirect policy and HTTP server-scoped worker authentication.

The Python tests perform real filesystem hashing/extraction and a complete small trusted-local-artifact preparation, including durable operation state and live-file preservation. Download transport fixtures exercise the implemented streaming downloader; they do not constitute an Internet download or Wings qualification. No Minecraft installation runs in these tests.

## Framework and database integration

```sh
bash scripts/integration.sh v1.0.0-rc.1
bash scripts/integration.sh v1.0.0-rc.2
```

Or set `PELICAN_SOURCE` to a disposable source checkout with that tag's Composer lockfile installed, then run:

```sh
php tests/integration/pelican.php
php tests/integration/filament.php
php tests/integration/livewire.php
php tests/integration/adoption.php
php tests/integration/curseforge.php
```

The bootstrap refuses a checkout with an existing `.env`, uses in-memory SQLite and test-only cache/session/mail configuration, and requires an explicit completion marker to prevent Laravel's console exception renderer producing false success. It loads actual Pelican/Laravel/Filament classes, runs Arcturus migrations up/down, verifies the reference plugin's sentinel data is untouched, tests encrypted settings, checks durable queue exclusion/recovery locks and exercises actual daemon repositories through mocked HTTP responses. The Filament test discovers both panels, constructs native wizard/forms/tables/settings and compiles every Blade template. The Livewire test renders and switches all six detail tabs and verifies guest denial and the disabled destructive control.

Do not substitute a production Panel checkout, database, Wings node or server. Framework tests deliberately do not execute actual power, native backup, restore or provider installation requests.

## Full disposable Wings qualification still required

Provision separate Panel/Wings containers/VMs, test-only keys and a small fixture server volume. Keep this completely isolated from production. The host used for development did not offer Docker access, so a live Wings environment was not available.

Before implementing/enabling remote destructive transactions, cover:

1. Real file API metadata, bounded reads, remote stages/hashes and per-server authorization.
2. Node/queue/process restarts during every operation; duplicate delivery and ambiguous HTTP responses.
3. Native backup quota, failed/partial callback, exact UUID/lock/checksum metadata and delayed callbacks.
4. Excluded data preserved outside the truncated volume; restore success/failure callback, post-restore hashes and stopped-server behavior on uncertainty.
5. Conditional transaction writes against the reviewed plan, rollback after partial apply, launcher permissions and retained diagnostics.
6. Fresh startup evidence across log rotation, loader/Java/image/egg compatibility, then operator checks of real worlds and dimensions. A `Done` line alone is insufficient.
7. Browser interaction at desktop, tablet and mobile widths in both themes, including long paths, conflict decisions, cancel/reconcile and revoked permissions.

Remote destructive operations stay disabled until these checks are implemented and pass. MariaDB runtime migrations, graphical browser behavior, real Wings power/backup/restore, full pack/loader installation and Minecraft/world migration are separate from the validated unit/framework boundaries. See [capabilities](capabilities.md) for the shipped limits.

## Browser layout check performed

The six detail tabs were rendered through actual Livewire with a small customized-server fixture and Pelican's built CSS. Chromium checked widths 390, 768 and 1440 in light/dark mode (36 combinations), with no page-level horizontal overflow and a disabled Apply Update control. Long file paths were visually reviewed and the change table uses a bounded horizontal scroll region on narrow screens. These are fixture layout checks, not end-to-end authenticated Panel/Wings user-flow tests. Loading indicators were hidden to reflect their normal idle state in static snapshots.

To export the same fixture HTML after building Pelican's frontend assets, set `ARCTURUS_UI_SNAPSHOTS=/tmp/arcturus-ui` when running `tests/integration/livewire.php`; serve the exported files and that disposable Panel's `public/build` directory together. No browser libraries or frontend framework are shipped with the plugin.
