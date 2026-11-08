# Manifest updates and scheduled maintenance (0.5)

## Upgrade

Install the plugin update including `node-worker/worker.py` and the new migration through Pelican's normal plugin update/migration flow, then restart Panel queue workers. Remote workers must be updated too: `scoped_inventory=1` is required. There is no fallback to the old broad scan if a worker is outdated. Run Compare files again: old plans/approvals cannot pass the new `manifest-delta-v1` deployment gate. Existing adopted servers and rules are retained. No automation is enabled by the migration.

## Manual update

Open Updates, check releases and select the target. **Prepare update** downloads, verifies and compares in one background operation. Review the resulting counts and any conflicts in File Changes, then choose **Update with backup**. **Refresh comparison** in File Changes reuses the prepared target when policies change or readiness expires. Filesystem setup and baseline recovery actions stay in Overview.

## Comparison

The plugin reads the saved installed-release manifest and the target manifest first. It selects paths added, changed or removed upstream, plus explicit remove/disable/replace rules requiring enforcement. The worker checks only those paths and their ancestors, without recursively enumerating live directories or opening unrelated JARs. Missing paths are explicitly accounted for. World data, runtime data, excluded directories, inaccessible paths and symlinks are protected. An update involving three candidate paths does not scan another 22,000 unchanged files.

Adoption records the exact upstream association and runtime evidence; it does not audit all live files. Customizations are detected when an update touches their paths. Local-only files are preserved by leaving them alone, so Mods & Files is a view of checked candidate evidence rather than a complete server audit.

Preparing a previously unseen upstream archive still downloads, extracts and creates a verified manifest for that archive. This is upstream preparation, separate from checking the live server. Stored manifests and retained verified stages avoid doing another live-tree scan. Before apply, only files to be installed and critical launcher/runtime dependencies are hashed; full stage and full mod-directory rehashes are removed.

## Pelican Schedules

In managed modpack settings, enable **Allow Update Modpack schedule tasks** and **Allow scheduled deployment** as a root administrator. Then open the server's native **Schedules**, create or edit its cron schedule, and choose **Create Task → Update Modpack**. Choose whether the task should do a normal restart when no release is available. For a 07:00 maintenance window, use the time configuration of Pelican's native scheduler. Do not add another power restart for the same purpose.

The plugin registers a native task schema and a bus handler adapter; built-in power, command, backup and delete tasks retain their original handler. Only a root administrator can create or change this update action. It requires an adopted, verified server on Native Pelican / Wings (or the qualified legacy local backend), Pelican backups and administrator opt-in. The runtime rechecks the approving administrator's authority and the saved policy revision.

The task checks the latest provider release. Same version: optionally restart normally, with no comparison or update backup. New version: prepare and compare the manifest delta, then run the existing deployment/backup/recovery job. Preparation and deployment happen in asynchronous jobs. The native schedule remains processing, and later tasks wait until a successful terminal result. A conflict, rejected runtime, failed update, restored original installation or manual-recovery checkpoint ends this schedule without running later tasks. The result is recorded in modpack History.

The native receipt survives worker restarts. Recovery reconciles the same operation and does not replay an applied deployment. Duplicate delivery of a queued native task shares a receipt. Deleting/changing the task while work is underway cannot skip recovery or release its following tasks.

**Upgrade from the old daily timer:** its Laravel schedule registration is removed, the legacy maintenance command no longer queues work, and old queued timer jobs do nothing. Existing daily-time columns are retained for reference. Recreate the desired timing in Pelican Schedules; no native schedule is silently enabled by migration. The existing Panel cron and queue workers are still required. Restart queue workers after the plugin update.

Native deployment supports proven runtime reuse and complete published Forge/NeoForge runtime migrations, with qualified startup/image changes. Installer-generated dependencies and other unsupported combinations remain explicit blockers. See [native deployment](native-deployment.md) for the exact support boundary.

The standard root `unix_args.txt` symlink is accepted when it resolves to the already verified Forge argument file. Its original link target is recorded and verified during recovery; other symlink mutation paths stay protected.

## Backup and recovery

Stop → completed locked native Pelican backup → conditional file mutations → fresh Minecraft startup marker. Failure → stop → native restore of the exact backup → verify touched files and original launcher → start original installation. Wings reads the full backup data; the plugin does not independently hash the world or implement a second backup engine. If native restore or original startup cannot be confirmed, retain the stopped recovery checkpoint and notify the operator.

## Verification

Regressions cover 22,000 untouched paths, a sparse 8 GiB world, ordinary JAR updates without embedded metadata, custom mods, symlink/world exclusions, real queued Pelican manual/automatic mod replacement, one daily no-update restart, revoked administrator authority and stale preparation policy. Wings responses are fixture-controlled; this is not a claim of production runtime verification.

## Plugin update feed

Plugin metadata uses `https://raw.githubusercontent.com/Avalanche7CZ/modpack-updater-pelican/update-feed/update.json`.
The tiny metadata response bypasses GitHub release redirects and asset downloads, which can exceed Pelican's 5-second feed timeout or return an upstream 504. The ZIP remains a version-pinned release asset; Pelican has a separate 60-second archive download timeout and verifies the advertised SHA-256.

After publishing a release, the release workflow downloads that release's actual `update.json` and copies it to the dedicated `update-feed` branch. It does not modify main or trigger a release loop. Rerunning a release copies the existing published metadata, never a checksum from a newly rebuilt but unpublished ZIP. Preserve the `update-feed` branch.

Existing installations that cannot read their old release-based feed need a one-time change of only `update_url` in their installed plugin.json to the raw URL above, preserving all other fields including `meta`. Clear only `plugins.arcturus-modpack-manager.update` afterward. Future plugin packages already contain the new URL.
