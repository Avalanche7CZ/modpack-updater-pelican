# One-time local Wings node setup (Ubuntu / Pelican)

Target flow: **One-time host setup → Adopt a local server → Prepare installed baseline**.

Use this backend when Panel PHP-FPM, `pelican-queue`, Wings and the server volumes share the same Ubuntu host/filesystem namespace. The existing Python staging implementation runs as a fixed, unprivileged subprocess of the Laravel job. There is no Python daemon, HTTPS proxy, API token, per-server SSH configuration or privileged web request. Remote nodes retain their existing remote backend.

## 1. Approve the local node once

Import the current plugin ZIP and open **Arcturus Modpack Manager → Local filesystem setup**. The page shows readiness checks, the Pelican node IDs and the setup command for each node. Choose the node physically running on this host and verify its volume root against Wings. Its database `daemon_base` must exactly match the explicitly approved canonical path.

Requirements: Python **3.11+** at `/usr/bin/python3`, Ubuntu's `acl` and `util-linux` tools, PHP process execution (`proc_open`), and an existing asynchronous database/Redis queue. PHP-FPM and `pelican-queue` must use the **same unprivileged account**, commonly `www-data`. The Panel account must be separate from the writable Wings volume/file owner. No Docker socket, sudo permission or setuid helper is granted to the Panel.

Install dependencies if needed:

```sh
sudo apt-get install acl python3 util-linux
```

Copy the setup script from your verified plugin release into a root-owned location, then run it once. The status page supplies commands using your actual Panel path and database node/root. Example only:

```sh
sudo install -o root -g root -m 0755 \
  /var/www/pelican/plugins/arcturus-modpack-manager/node-worker/setup-local-node.py \
  /usr/local/sbin/arcturus-local-node-setup

sudo /usr/bin/python3 /usr/local/sbin/arcturus-local-node-setup \
  --panel /var/www/pelican \
  --user www-data \
  --node 1 \
  --volume-root /var/lib/pelican/volumes
```

Use your actual PHP-FPM/queue account instead of `www-data`; use `--php /usr/bin/php8.4` if necessary. Append `--dry-run` to preview node identity and scope without changing ACLs, files, approval or queue state. The command is **administrator-only**; never give the Panel permission to invoke it through sudo. It refuses a root service account and always drops privileges through a fixed `runuser` invocation **before** executing any Panel PHP.

The administrator explicitly approves one local node and volume root. The script then:

- Reads node ID, node UUID, `daemon_base`, server UUIDs, queue driver and active-operation count from Pelican through an unprivileged, read-only Artisan command. Node credentials are not exported. Active operations must be resolved first.
- Rejects noncanonical paths, symlinks, overlapping staging, mutable volume ancestors and nested mounts beneath the approved root. The volume parent and its ancestors must be root-owned and not group/world-writable.
- Grants the Panel account **read/traverse only**, using descriptor-pinned POSIX ACL operations. Existing database servers receive access to regular top-level pack files and managed pack directories. Actual/custom worlds, world markers, logs, backups, unknown directories, symlinks and special files are excluded from recursive provisioning. Hard links are rejected so granting an ACL cannot expose a shared inode outside the approved volume.
- Preserves other ACL subjects' effective permissions when expanding an ACL mask. It does not change live file contents, ownership, startup commands, Java settings, server configuration or saved protection policies. It never grants new write permission to live files, runs pack scripts or uses `chmod 777`.
- Sets default read/traverse ACLs at the approved volume parent and managed directories so normally created future UUID volumes and pack files inherit access. Existing worlds are not traversed or recursively ACL-modified. **The OS permission boundary is the approved node volume tree; newly created descendants, including worlds, can inherit read ACLs.** Application-level UUID eligibility and world exclusions remain stricter: the plugin never inventories worlds or scans non-database servers. File creators explicitly removing ACLs or creating owner-only files can still block later access; the plugin reports that instead of fixing permissions from the web.
- Creates one shared staging parent, default `/var/lib/arcturus-modpack-manager/staging`, owned by the service account with mode `0700`. It must be outside the entire live volume root. Individual UUID staging directories are not created by the privileged command.
- Writes a protected root-owned approval file at `/etc/arcturus-modpack-manager/local-node.json`. It is generated automatically, contains node-wide identity/paths and official download hosts, and needs **no per-server entries or environment variable**. The application reads it afresh, including when Laravel config is cached.
- Requests a graceful `queue:restart` as the unprivileged Panel account and verifies host readiness. A normal queue service manager must already be keeping `pelican-queue` alive. No service/agent is installed automatically.

A failed setup can leave partial **read-only ACL grants** or the shared staging parent. It does not roll them back blindly or delete stages. Correct the reported issue and rerun the same command; it is idempotent. Changing the approved node/root/account requires explicit `--replace`; old ACLs and stages are retained for operator review. Replacing an approval is not a migration of existing managed-server evidence.

## 2. Enable the native default

Return to **Local filesystem setup → Refresh readiness**. Checks cover:

- protected host approval and its node identity/root matching current Pelican database metadata;
- unprivileged Python/process execution, root access and private writable staging parent;
- the asynchronous queue and safe retry visibility;
- discovered database servers on that node, with canonical UUID volumes and per-server staging state.

Choose **Use local for new eligible servers**. This saves a native plugin setting. Existing managed-server backends, verified baselines, policies and installed versions are unchanged. **Use remote default** reverses only the new-server preference.

## 3. Adopt and prepare

In **Adopt Existing Server**, select any server on the approved node and leave **Preparation backend → Use node default**. The plugin derives `<approved-volume-root>/<database-server-UUID>`; neither the form nor HTTP payload supplies a host path. It verifies node membership, node UUID/root, the real UUID directory and permissions, then creates `<shared-staging-parent>/<UUID>` unprivileged with mode `0700`. Existing unsafe stages are rejected rather than chmodded or replaced.

Remote-node servers use the remote backend even with the local default. A missing local volume or failed local permission check is shown as a form error; it is not silently treated as a remote server. You can also explicitly choose local or remote in the wizard. Existing managed servers can select **Filesystem backend → Same-host local filesystem** on their detail page; selection validates/creates staging and invalidates previous baseline/plan verification without rewriting policies or live files.

For the existing TFG-Modern server, preserve the exact installed release **0.13.5**, source and server ZIP pattern. Choose **Prepare installed baseline**. Downloads, upstream checksums, archive/path limits, world exclusion, manifests, inventory, planner and protection rules use the existing implementation. A customized live installation is never substituted for the upstream baseline. Unsupported/missing checksums still block preparation. Review File Changes and finish verified adoption separately.


## Discover and review upstream directories

Preparation verifies the upstream artifact and safely extracts it into private staging before discovering its structure. Unknown top-level directories do not fail manifest generation and do not grant live filesystem access. The immutable manifest records `directory_discovery` with standard, review and protected classifications. Standard pack directories default to Manage. Unfamiliar directories default to pending review; protected roots and nested runtime/secret paths never enter the managed manifest.

In the managed server adoption page, choose **Review upstream directories**. Each safe directory offers **Manage**, **Keep local**, or **Exclude**. Manage includes it in live inventory and normal three-way planning. Keep local preserves the server's directory without reading or updating its files. Exclude leaves it outside manager operations. Both retain the upstream evidence, so either decision can be changed without another download. Unknown directories in a later target require review again before update approval. Adoption requires all pending choices to be resolved. World/runtime protection always wins over directory choices and file rules.

Decisions are stored on the managed server, audited, revision guarded, and sent as request-scoped policy to the local or remote worker. They never edit the node approval file. Saving invalidates old previews and approvals and recomputes comparison from retained manifests. Update remote workers together with the plugin; their capabilities must include directory policy support.

The reported TFG-Modern structure (`tacz/`, `DiscordIntegration-Data/`) follows this same workflow as any other pack; there are no TFG additions to the global directory list. GTNH and FTB archives with a sole enclosing directory are automatically unwrapped only when their immediate contents include `mods/` and an independent server-layout marker (`config/`, `libraries/`, or `server.properties`). An unfamiliar content directory by itself is preserved for review. An operator's existing `archive_roots` setting remains an explicit fallback.

### OS access boundary

One-time setup remains unprivileged from the Panel's perspective: no sudoers entry, chmod endpoint, arbitrary host paths or privileged PHP. Existing node-wide additions remain legacy ACL provisioning inputs; they are no longer the modpack approval mechanism. Default read/traverse ACL inheritance supports normally created future pack directories. The helper also skips nested runtime/secret paths when provisioning existing files.

**POSIX default ACLs cannot express filename-based inheritance exceptions.** Existing owner-only directories, files created with restrictive modes, ACL removal, nested mounts and a custom world path cannot be safely fixed automatically by an unprivileged Panel. We deliberately do not widen recursive permissions on unknown existing directories. If Manage encounters missing access, the comparison fails explicitly with `PERMISSION_DENIED`; verified baseline manifests and stages remain usable. Select Keep local/Exclude, or have the host operator provision read/traverse rights for that server's reviewed content. This is an OS permission exception, not a required approval/download step for each modpack. Sites needing strict OS isolation from all world data should use a separately administered remote worker with an independent account and reviewed read access, rather than grant the Panel broader volume permissions. No new privileged service is installed by this change.

Preparation retries reuse an available archive from a terminal operation for the same server and exact recipe, rechecking every declared upstream checksum and size before extraction. This includes retained ZIPs from the earlier unknown-directory failure. Different recipes or absent retained artifacts use the normal verified download. Tampered cached artifacts fail checksum verification; they never become a baseline. Failed operations remain immutable evidence; no active operation is replayed. Completed manifests are reused for policy comparisons without extraction or download.

Setup errors include a fixed phase (`read_panel_node_metadata`, `provision_read_acls`, `persist_node_configuration`, `restart_queue`, or `verify_node_access`), subprocess exit status/timeout or OS errno where applicable. Subprocess output remains private because it can contain credentials. Partial ACL/configuration changes and staging are retained for operator review.

## Queue behavior and restarts

Local preparation remains an existing Laravel job with a bounded Python subprocess. Each local step allows 14400 seconds; job timeout is 14430 seconds, operation lock 14460 seconds, and heartbeats update every 30 seconds. Once a valid host approval exists, the plugin sets the current database/Redis connection's effective `retry_after` to **at least 14520 seconds at application boot**. No `.env` or Panel-core edits are made; credentials and queue connection remain unchanged. This increases failed-worker retry visibility for other jobs on the same connection too.

Synchronous or unsupported queue drivers fail readiness/preflight; the plugin does not switch production queue connections automatically. A configured database/Redis queue is a normal Pelican prerequisite. Ensure your service manager allows a running preparation to finish before forcibly stopping it (`TimeoutStopSec` / Supervisor `stopwaitsecs`).

Long-lived workers must restart after plugin import/upgrade so they can autoload new classes. The one-time command requests a graceful restart; restart `pelican-queue` through your normal service manager if it was not already supervising workers. Later plugin imports still need the usual queue restart. A fresh web request seeing a plugin does not prove an old queue process loaded it. See [Laravel queue deployment](https://laravel.com/docs/12.x/queues#queue-workers-and-deployment).

## Diagnosis, scope and compatibility

Use **Refresh readiness** on the setup page and **Test filesystem access** on a managed server. These checks do not download a pack, create stages, scan pack/world files or grant permissions. Readiness covers roots/parents and stage metadata; a later inventory can still encounter a child whose ACL was removed. Re-run the same node-wide administrator command after correcting destructive ACL/chmod changes, rather than adding per-server configuration. Unsupported nested mounts, hard-linked pack files and separately isolated service accounts need independent operator review; no unsafe fallback is offered.

Missing/foreign node metadata, changed node roots, unknown UUIDs, symlinked or missing volumes, unsafe stage ownership/modes and unavailable Python/process permissions block local use. After node transfers or volume-root changes, reapprove the correct host scope and explicitly reselect affected backends; retained old stages/baselines are not silently rebound.

Keep retained stages referenced by active operations, baselines and text-merge reviews. Existing cancellation and reconciliation actions use the selected backend. Concurrent subprocesses are excluded by the existing Panel operation lock and staging lock; interrupted stages are not silently replayed. No stage/world deletion or production repair is performed by setup/status actions.

Existing manually configured `ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG` mappings remain supported for already configured installations. They take precedence for their UUIDs and retain pinned paths/evidence; they are not required for node-wide discovery. Do not delete old mappings/stages while operations or merge reviews reference them. The optional node-wide approval `artifacts` map retains the existing operator-reviewed custom-artifact capability; no recipe may supply arbitrary local source paths.

Local preparation and remote preparation both retain their deployment restrictions. This change enables neither live application nor automatic restore/rollback, and does not prove a full TFG runtime/world migration.

## Preparation diagnostics and progress

After importing this update, run the plugin migrations through Pelican's normal plugin upgrade, then restart long-lived queue workers (`sudo systemctl restart pelican-queue` for the standard Ubuntu service). Web requests and already-running queue processes must use the same plugin version. No setup daemon or new service is needed.

Background Operation polls small persisted state every three seconds while visible. It shows actual current-artifact bytes, known totals, sampled MiB/s and ETA, plus separate download, streamed-checksum comparison, archive extraction, staged hashing and live managed-inventory comparison snapshots. Checksums are calculated during transfer and compared before extraction; there is no invented second hash pass. Each recorded phase has its own status and measured counters. Unknown totals use a working/waiting indicator without a filled bar or percentage; failed, cancelled and interrupted operations show a terminal status without an active bar. Hashing does not reuse extraction totals. Phase percentages are not a weighted overall percentage. Overall downloaded bytes are displayed separately; an overall total is shown only when all artifact sizes are known. Fast phases can complete between polls and remain visible in their phase rows. Download speed and ETA remain visible as explicitly labeled last-recorded samples after transfer ends. IDs, raw byte counters and diagnostic context are collapsed under Technical details. Checkpoints are throttled to two seconds, with immediate terminal checkpoints, and survive refreshes/restarts. A stale sample is identified as stale, not ongoing work.

Failures show a fixed error code, phase, safe file/entry/artifact identifier and corrective action in Background Operation and History. Codes distinguish HTTP status, DNS/timeout/TLS, missing/invalid/mismatched checksums, transfer size, corrupt/unsafe archives, permissions, storage and managed-path validation. Signed URLs, original exception messages, tokens and absolute host paths are never displayed. A filename that cannot safely be displayed gets a stable file ID. Keep the operation IDs and administrator-only stage evidence: `operation.json`, downloaded ZIP, extracted/partial files and, during comparison, `comparison.json`. Comparison does not overwrite completed preparation evidence. Older failures cannot recover details that an older helper already discarded; start a new operation after installing the fix. Do not delete an active stage or blindly replay uncertain work.

Cancellation is a persisted request until the worker confirms it, including while comparing inventory. The queue checks requests periodically (up to approximately 30 seconds for local work); blocking network I/O can delay confirmation until its timeout. Partial staging remains. An interrupted process is not reported as successful or automatically replayed. Baseline preparation remains read-only against live files, and deployment stays disabled.

### Archives with an enclosing directory

Unknown sole content directories are retained for review; obvious serverpack wrappers are detected as described above. Legacy operator-reviewed `archive_roots` remain supported without granting live access.
