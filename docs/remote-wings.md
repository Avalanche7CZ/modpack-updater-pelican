# Remote Wings setup

> Legacy backend documentation. Normal updates now use [Native Pelican / Wings](native-deployment.md), without server-volume ACL provisioning or a separate node worker. The restrictions and setup commands below apply only to the explicitly selected legacy backend.


If Panel, Wings and volumes share one host, you can instead explicitly select the [local filesystem backend](local-filesystem.md), which reuses this worker's safe staging code without a service, proxy or tokens.

No Panel volume mount is required for inspection, adoption, staging or comparison. Native file operations use Pelican's existing node credentials. Efficient SHA-256 inventory and safe archive staging use the separate worker shipped in `node-worker/worker.py`.

The worker is deliberately not a general remote administration agent. It cannot run shell commands, stop/start Minecraft, execute pack scripts, reinstall servers, change eggs/images/startup, apply live updates or restore backups.

## Node installation

Requirements: Linux, Python **3.11+** (stdlib only, including `tomllib`), a dedicated service account, TLS reverse proxy, sufficient staging disk and read access to the explicitly managed server volumes.

1. Copy `worker.py` to an operator-controlled directory such as `/opt/arcturus-modpack-manager/worker.py`. The service account must not be able to replace this code or its configuration.
2. Create a dedicated staging directory **outside** every Wings volume, mode `0700`, writable only by the worker account. Never use a shared world directory, `/tmp`, or a directory writable by a server process as persistent staging.
3. Give the account read/traverse access only to the configured server volumes using narrow ACLs/groups or read-only bind mounts. Keep volume mappings fixed by UUID. Do not grant Docker socket access, sudo or root privileges.
4. Write a mode-`0600` configuration file. Generate a different random secret for each server, e.g. `openssl rand -hex 32`.

```json
{
  "servers": {
    "11111111-1111-4111-8111-111111111111": {
      "root": "/var/lib/pelican/volumes/11111111-1111-4111-8111-111111111111",
      "staging": "/var/lib/arcturus-modpack-manager/server-one",
      "token": "REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS",
      "max_archive_bytes": 8589934592,
      "max_inventory_bytes": 34359738368,
      "allowed_hosts": [
        "github.com", "release-assets.githubusercontent.com", "objects.githubusercontent.com",
        "cdn.modrinth.com", "files.feed-the-beast.com",
        "edge.forgecdn.net", "mediafilez.forgecdn.net", "download.nodecdn.net"
      ],
      "artifacts": {
        "reviewed-server-pack": "/srv/arcturus-trusted-artifacts/server-pack.zip"
      }
    }
  }
}
```

All roots must be existing canonical paths without symlink components. Staging must not overlap the live root. Do not share staging roots between configured servers. Artifact IDs resolve only to operator-configured regular files outside live volumes; requests cannot supply local source paths.

Run under your service manager:

```sh
python3 /opt/arcturus-modpack-manager/worker.py --config /etc/arcturus-modpack-manager/node.json --port 9876
```

A [sample systemd unit](../node-worker/arcturus-modpack-worker.service) is included. Adjust its `ReadOnlyPaths`/`ReadWritePaths` and service account to the exact configured paths. The worker binds only to `127.0.0.1`. Put a verified HTTPS reverse proxy in front, restrict incoming access to Panel workers, cap request bodies at 16 MiB, set appropriate read timeouts, and avoid logging Authorization headers or request bodies. Do not publicly proxy unrelated node endpoints. Node staging must survive worker/container restarts.

## Panel installation

Create a separate operator-owned mode-`0600` JSON file on the Panel host:

```json
{
  "11111111-1111-4111-8111-111111111111": {
    "url": "https://node-worker.example.net",
    "token": "THE_SAME_PER_SERVER_SECRET"
  }
}
```

Set `ARCTURUS_MODPACK_MANAGER_NODE_CONFIG=/etc/pelican/arcturus-node-workers.json` in the Panel environment. Clear/rebuild Pelican's normal config cache as appropriate and restart its queue workers. TLS verification is required; no insecure-TLS toggle is offered. Worker endpoints are operator-controlled and may be private addresses; modpack download URLs must resolve to public addresses and match an exact allowlist.

For CurseForge client manifests, update the worker as well as the plugin: its capabilities must advertise `curseforge_resolution=true`. The worker can retain `awaiting_resolution` across restarts without treating a manifest-only stage as complete.

The Panel and node each enforce their own limits. Use the stricter effective limit. The node performs one preparation at a time per server; concurrency settings do not invent parallel progress.

## Integrity and scope

Managed recursive roots: `mods`, `config`, `configs`, `scripts`, `kubejs`, `libraries`, `defaultconfigs`, `configureddefaults`, `plugins`, `resources`, `patches`, `datapacks`, `ftbteambases`, `resourcepacks`, `shaderpacks`, `global_packs`, `paxi`, `openloader`, `schematics`, `assets`, `structures`, plus regular top-level files. Worlds/dimensions/player data, logs, backup folders and unknown directories are excluded. `server.properties` is read only to identify the configured world root. Upstream content outside this scope blocks normalization rather than expanding silently into world data.

Hashes use streaming reads and descriptor-relative `O_NOFOLLOW` traversal. Inode/size/high-resolution timestamps are checked across each hash. A running-server inventory is a point-in-time observation, not an atomic deployment snapshot. Limits, changed files, symlinks, special nodes and incomplete requested-file responses fail closed. No partial success is returned.

Downloads validate every HTTPS redirect, resolve only public addresses, pin the checked address to the TLS connection, verify upstream hashes and size, and enforce byte limits while streaming. ZIP validation rejects traversal, duplicate paths, links/special files, encryption, excessive expansion and file counts before extraction. Files are never executed. Sources that need a loader installer or browser-only download remain restricted.

## Recovery and disk retention

Each operation has an immutable ID and payload fingerprint, `operation.json`, isolated files and a completed upstream manifest. Partial work is retained for diagnosis. Worker restarts mark incomplete work interrupted and never replay it automatically. A completed operation can be queried again using the same ID; a different recipe cannot reuse it.

`php artisan arcturus-modpack:recover` reconciles stalled read-only preparations. Cancel requests are confirmed by a terminal worker result. A lost request response does not imply success or failure. Inspect both Panel history and retained node state before retrying. Keep baseline stages needed for text-merge review; remove obsolete stages only after confirming no active operation, adoption provenance or pending review references them. No automatic stage/backup deletion is performed.

Do not restore a native backup with truncation while excluded directories remain only inside the volume. This worker advertises no outside-volume preservation or deployment transaction capability. Follow an operator-led recovery procedure: confirm the server is stopped, preserve excluded data independently on the node, restore the exact backup using Pelican, confirm its callback and file hashes, restore preserved exclusions, then validate the original startup. If any step is uncertain, leave the server stopped.

## Per-server inventory permissions

The optional worker `managed_directories` array replaces its default pack-directory allowlist for that server (include `mods`, `config`, `libraries`, etc. if needed). `excluded_directories` replaces the default read exclusions (`logs`, `crash-reports`, `backups`, `backup`, `data`). Both accept at most 128 top-level relative directory names. These settings do not override actual world/dimension detection or path safety. Unknown/unscanned directories are returned in `excluded_roots` and remain protected in the plan. Use this to include custom server utilities without scanning every directory or world. These permissions never grant writes or shell execution.

Update the worker from the same plugin release and restart its isolated service to enable mod names/versions/loaders and these inventory settings. Existing workers remain compatible for hash/ID-based review; absent metadata is displayed as unavailable. Update preferences themselves remain only in the Panel's per-server policies, described in [server policies](server-policies.md).

### Reviewed additional pack directories

`additional_managed_directories` extends the selected/default managed root list with explicitly reviewed top-level pack directories. It is never populated from an archive. Names must be safe single directory names; world/player/dimension, backup/log and other reserved runtime roots cannot be added. A custom world name still overrides approval. For the reported TFG-Modern 0.13.5 layout, an operator can review and configure `["DiscordIntegration-Data", "tacz"]`. Same-host operators should use the administrator setup flags in [the local guide](local-filesystem.md), which also provision restricted read permissions. Unknown roots now produce a sanitized phase/code/root diagnostic and retain their failed stage.

## Diagnostic/progress upgrade

Install `worker.py` from the matching plugin release and restart the isolated worker service and long-lived Panel queue workers. Updated workers persist throttled progress/checkpoints and sanitized phase/code/context diagnostics; older workers remain compatible but cannot supply details they never recorded. The Panel polls persisted progress without a WebSocket or monitoring service. See [progress and evidence handling](local-filesystem.md#preparation-diagnostics-and-progress) for counters, unknown totals, cancellation and retained stages. For reviewed wrapped ZIPs, the operator-owned server entry can explicitly set `"archive_roots": ["ReviewedPackRoot"]`; this strips only that enclosing name and never approves inner directories or world/runtime data. No automatic archive-root discovery is used.
