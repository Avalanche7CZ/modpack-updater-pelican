# Validation and disposable integration

Run from the plugin checkout with PHP 8.4/8.5, ZIP/mbstring, Python 3.11+ and Ubuntu POSIX ACL tools (`apt-get install acl`):

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
php tests/integration/local.php
php tests/integration/local_node.php
php tests/integration/curseforge.php
php tests/integration/runtime.php
php tests/integration/policies.php
php tests/integration/discord.php
php tests/integration/discord_deployment.php
```

The bootstrap refuses a checkout with an existing `.env`, uses in-memory SQLite and test-only cache/session/mail configuration, and requires an explicit completion marker to prevent Laravel's console exception renderer producing false success. It loads actual Pelican/Laravel/Filament classes, runs Arcturus migrations up/down, verifies the reference plugin's sentinel data is untouched, tests encrypted settings, checks durable queue exclusion/recovery locks and exercises actual daemon repositories through mocked HTTP responses. The Filament test discovers both panels, constructs native wizard/forms/tables/settings and compiles every Blade template. The Livewire test renders and switches all seven detail tabs and verifies guest denial and the disabled destructive control.

Runtime inspection tests use the native Wings file repository with a remote FTB-like NeoForge tree, including obsolete versions and a symlink. They verify active launcher selection and Minecraft arguments without reading worlds, executing scripts or sending power requests.

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

The original six detail tabs were rendered through actual Livewire with a small customized-server fixture and Pelican's built CSS. Chromium checked widths 390, 768 and 1440 in light/dark mode (36 combinations), with no page-level horizontal overflow and a disabled Apply Update control. Long file paths were visually reviewed and the change table uses a bounded horizontal scroll region on narrow screens. These are fixture layout checks, not end-to-end authenticated Panel/Wings user-flow tests. Loading indicators were hidden to reflect their normal idle state in static snapshots.

To export the same fixture HTML after building Pelican's frontend assets, set `ARCTURUS_UI_SNAPSHOTS=/tmp/arcturus-ui` when running `tests/integration/livewire.php`; serve the exported files and that disposable Panel's `public/build` directory together. No browser libraries or frontend framework are shipped with the plugin.

## Per-server policy validation

The policy suite runs actual native Livewire wizard steps and submits its rendered final action, covering draft creation, monitoring-only creation, full validation and unauthorized users. Native bulk-policy and advanced-rule modals are mounted; Test rule executes against a discovered fixture mod. Tests cover SQLite reload/isolation, unchanged upgrade rule JSON, policy audit before/after, stale editor rejection, active-operation locks and concurrent policy/preview approval invalidation. A real disposable filesystem fixture compares the actual PreviewService decisions with LocalDeployment and rejects mismatched decisions before mutation. Python fixtures validate legacy/modern mod names/versions and custom inventory/world boundaries.

Set `ARCTURUS_UI_SNAPSHOTS=/tmp/arcturus-policy-ui` while running `tests/integration/policies.php` to export adoption review, Mods & Files and the native rule modal for responsive layout checks using a disposable Panel's built CSS. These are layout fixtures; native stateful submissions are exercised by Livewire, not represented as live Wings or full browser end-to-end qualification.

The three new policy screens passed 18 Chromium viewport/theme fixture checks (390, 768, 1440 pixels in light/dark). The final adoption action was explicitly checked for visibility on the final step. Snapshots reflect the native last-step/modal and idle loading state; they do not replace the actual Livewire submission and rule-action tests.

The local suite invokes the real Python stdio helper against private temporary directories. It covers verified baseline/target manifests, complete local-only inventory, world exclusion, queue preflight, policy/runtime preservation, path/permission rejection, and unchanged remote selection. Native Livewire tests exercise backend selection, capability testing, and refusing a missing mapping before dispatch. Python tests additionally cover extraction/checksum failure, cross-process locks and interrupted state without replay. These are fixture tests; no production TFG files or server runtime are used.

Node setup fixtures exercise actual POSIX ACL inheritance, preservation of other subjects' effective permissions, world/symlink/special-file exclusion, hard-link and nested-mount rejection, and privilege dropping before Panel PHP. The native node suite tests read-only discovery/status, forged/writable approval rejection, node membership and root changes, future UUIDs, private staging on wizard adoption, persisted defaults, remote isolation and a real fixture baseline through the existing local helper. Root host installation is not performed during CI or development; the privileged tool itself is never invoked by a web request.

The TFG managed-path regression uses the reported 0.13.5 directory and top-level-file layout with small fixture contents (not the full 520,761,531-byte release artifact). It proves the pre-manifest failure after successful checksum/extraction, then successful manifests/inventory under explicit approval, without changing the global whitelist or live files. Tests also cover single-root archive normalization, reserved/custom worlds, nested backups, permission failures, ACL provisioning, file recipes, planning, persisted failure diagnostics and the native failure banner.

## Manual deployment transaction fixtures

Run `PELICAN_SOURCE=/tmp/arcturus-panel php tests/integration/deployment.php` with disposable official Pelican rc.1/rc.2 dependencies and an unprivileged account. CI runs this alongside the existing native suites. It uses actual Filament actions, database-queue serialization/consumption, native server-configuration generation, backup services and backup/restore callback controllers. Wings HTTP is faked; verified synthetic serverpack/runtime files and real archive extraction live only under a temporary directory.

Success, failed startup, partial writes, post-backup stage/settings drift, quota/missing runtime/read-access blockers, bad/unlocked/pending/failed backups, corrupt/pending/failed restores, atomic completion failure, guard refusal, scheduled receipt redispatch, interrupted-worker checkpoints and explicit operator recovery verification are covered. The full original offline inventory includes worlds, player data, custom mods/configuration and preserved excluded backup folders. Duplicate approval/job delivery and rejected post-approval drift are checked before destructive effects.

See [manual deployment](manual-deployment.md) for the supported same-runtime Forge/Java and native Wings adapter scope, host ACL requirements and recovery procedure. This fixture qualification does not claim actual production TFG startup, live Wings backup storage, world migration or hosted CI execution.

## Rendered File Changes browser regression

Run `bash scripts/test-browser.sh` with `PELICAN_SOURCE` set to a disposable official Panel checkout (without `.env`), PHP 8.4+, Playwright and Chromium installed. Set `ARCTURUS_PLAYWRIGHT_MODULE` to its installed module path and optionally `ARCTURUS_CHROME_BIN`. CI runs it once on rc.1 in addition to the native integration suites. The loopback-only fixture seeds 22,000 changes in private SQLite storage; its injected test administrator and worker HTTP fixtures must never be used with production data or routes.

Chromium loads actual Blade, Filament, Alpine and Livewire assets, clicks Keep local / Use upstream, accepts native confirmation, records real HTTP responses/JavaScript errors, and asserts loading and renderless feedback. It checks repeated decisions, exact HTTP replay, a stale browser revision, queue rejection/transaction rollback, an indexed decision at the end of the inventory and the actual 10-second polling interval. A separate native database worker executes comparisons; HTTP runs at 128 MiB, while full background comparisons use 512 MiB. Evidence, screenshots and query/request diagnostics remain in the printed temporary directory.

The confirmed markup regression was an uncompiled `@js($change['path'])` inside a Blade component attribute. Browser evaluation failed before any decision POST; direct PHP method tests could not detect it. Bound component attributes now serialize arguments safely, including quotes, ampersands and Unicode. Decisions commit their policy and queued comparison together under a replayable receipt. They do not synchronously rerender the page. Polls check compact state and rerender only changed terminal evidence, rather than repeatedly streaming every change during work.

Version-renamed JARs are linked only by unique metadata identities from verified manifests/current inventory. An unchanged old JAR and its new upstream filename are classified as an update pair, so generic deletion-review rules do not treat it as an unrelated removal. Physical conditional remove/add mutations remain explicit. Exact/mod-ID/filename protections, explicit review, genuine local edits, disabled/deleted mods and ambiguous/missing identities retain their safeguards. Production's specific nine JARs still require their actual paths, hashes and identity metadata to distinguish ordinary replacements from genuine conflicts.

Verification recorded for this change: Chromium completed four repeated decision/comparison cycles with loading, renderless feedback, exact HTTP replay, terminal polling and visible stale-revision rejection over 22,000 files. The run was stopped at the user's request during the queue-rejection case; its remaining browser assertions and the full CI matrix were not completed. The queue-case harness was corrected to wait for the refreshed DOM revision before clicking. Focused planner/protection/KubeJS, 128 MiB detail workflow (110.2 MiB peak), and native deployment/restore tests with real fixture JAR metadata passed. No production browser session or production JAR evidence was supplied.
