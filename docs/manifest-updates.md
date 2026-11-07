# Manifest updates and scheduled maintenance (0.5)

## Upgrade

Install the plugin update including `node-worker/worker.py` and the new migration through Pelican's normal plugin update/migration flow, then restart Panel queue workers. Remote workers must be updated too: `scoped_inventory=1` is required. There is no fallback to the old broad scan if a worker is outdated. Run Compare files again: old plans/approvals cannot pass the new `manifest-delta-v1` deployment gate. Existing adopted servers and rules are retained. No automation is enabled by the migration.

## Comparison

The plugin reads the saved installed-release manifest and the target manifest first. It selects paths added, changed or removed upstream, plus explicit remove/disable/replace rules requiring enforcement. The worker checks only those paths and their ancestors, without recursively enumerating live directories or opening unrelated JARs. Missing paths are explicitly accounted for. World data, runtime data, excluded directories, inaccessible paths and symlinks are protected. An update involving three candidate paths does not scan another 22,000 unchanged files.

Adoption records the exact upstream association and runtime evidence; it does not audit all live files. Customizations are detected when an update touches their paths. Local-only files are preserved by leaving them alone, so Mods & Files is a view of checked candidate evidence rather than a complete server audit.

Preparing a previously unseen upstream archive still downloads, extracts and creates a verified manifest for that archive. This is upstream preparation, separate from checking the live server. Stored manifests and retained verified stages avoid doing another live-tree scan. Before apply, only files to be installed and critical launcher/runtime dependencies are hashed; full stage and full mod-directory rehashes are removed.

## Daily schedule

Edit the managed server and choose **Automatic at scheduled time**, **Allow scheduled deployment**, a daily `HH:MM` and an IANA timezone (for example `07:00`, `Europe/Prague`). Optionally enable **Restart when no update is available**. Enable automation after verifying a manual update on that server. Remove the old independent Pelican restart task to avoid two competing schedules. This is a plugin-managed schedule using Pelican's existing Laravel scheduler, not a new task type patched into Pelican core.

The existing Panel cron must run `schedule:run` every minute and the queue must be running. The plugin's `arcturus-modpack:maintenance` command queues due opted-in servers. Repeated delivery is claimed once per local calendar day. A busy server is not interrupted; delayed jobs outside the configured minute do not trigger a late update. Offline servers are not started solely for a no-update restart. Existing preflight also requires a running known-good runtime for updates.

At the configured time the plugin checks provider metadata. Same version: optionally do a normal restart, with no comparison or update backup. New version: prepare the target (or reuse its matching prepared stage), compare the manifest delta and queue the same deployment job used manually. Real conflicts/runtime blockers require administrator review, with no file writes. The configured administrator must still exist and be a root administrator. Changes to automation settings invalidate previous plans. Preparation cannot authorize an update after its policy revision changes.

The supported deployment runtime remains the existing qualified same-host Forge 1.20.1 / Java 17 runtime reuse. This change does not install arbitrary loader/Java migrations or enable remote deployment.

The standard root `unix_args.txt` symlink is accepted when it resolves to the already verified Forge argument file. Its original link target is recorded and verified during recovery; other symlink mutation paths stay protected.

## Backup and recovery

Stop → completed locked native Pelican backup → conditional file mutations → fresh Minecraft startup marker. Failure → stop → native restore of the exact backup → verify touched files and original launcher → start original installation. Wings reads the full backup data; the plugin does not independently hash the world or implement a second backup engine. If native restore or original startup cannot be confirmed, retain the stopped recovery checkpoint and notify the operator.

## Verification

Regressions cover 22,000 untouched paths, a sparse 8 GiB world, ordinary JAR updates without embedded metadata, custom mods, symlink/world exclusions, real queued Pelican manual/automatic mod replacement, one daily no-update restart, revoked administrator authority and stale preparation policy. Wings responses are fixture-controlled; this is not a claim of production runtime verification.
