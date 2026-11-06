# Same-host local filesystem setup (Ubuntu / Pelican)

Use this backend when Panel PHP-FPM, `pelican-queue`, Wings and the managed volumes are on the **same machine and filesystem namespace**. Containerized Panels must explicitly expose the selected volumes read-only and staging read-write. There is no automatic path discovery or fallback from remote to local.

Local preparation runs the shipped Python archive/inventory implementation as an unprivileged, fixed stdio subprocess of the Laravel job. It needs **Python 3.11+** at `/usr/bin/python3` and PHP process execution (`proc_open`). No Python service, listener, HTTPS proxy, API token, sudo permission or Docker socket is needed. Archives, installers and pack scripts are never executed. Native Pelican/Wings APIs still supply runtime observations, image/egg/startup hints and power status; their existing credentials and settings are unchanged.

## 1. Provision explicit paths and permissions

These are **operator-run examples**, not an automatic installer. Substitute the actual UUID and Panel service account; check both PHP-FPM and `pelican-queue` run unprivileged as that account (commonly `www-data`). Root execution is refused. Keep plugin code and the mapping file owned by the operator and unwritable by the PHP account.

```sh
ARCTURUS_UUID='11111111-1111-4111-8111-111111111111'
ARCTURUS_VOLUME="/var/lib/pelican/volumes/$ARCTURUS_UUID"
sudo install -d -o root -g www-data -m 0750 /var/lib/arcturus-modpack-manager
sudo install -d -o www-data -g www-data -m 0700 "/var/lib/arcturus-modpack-manager/$ARCTURUS_UUID"
```

All configured root/staging directories must already exist at canonical absolute paths without symlinks or `..`. Each server needs a separate staging directory **outside every live volume**, with mode `0700` owned by the PHP/queue user. Neither live roots nor staging scopes may overlap other servers' corresponding roots. Provide enough staging disk for the downloaded archives and extracted files; nothing is deleted automatically.

Grant only read/traverse access to the live root and managed pack directories. For example, if using ACLs, inspect existing ACLs first, grant traverse access to otherwise inaccessible parent directories, and then grant access to the selected volume and existing pack directories:

```sh
sudo setfacl -m u:www-data:rx "$ARCTURUS_VOLUME"
sudo setfacl -R -m u:www-data:r-X "$ARCTURUS_VOLUME/mods" "$ARCTURUS_VOLUME/config"
sudo setfacl -m u:www-data:r-- "$ARCTURUS_VOLUME/server.properties"
```

Add only other existing managed directories/files actually used by the pack (for TFG these can include `libraries`, `kubejs`, `scripts`, `defaultconfigs` and launcher configuration). Grant directory traversal on inaccessible parents individually with `u:www-data:--x`. Do not apply recursive ACLs to the entire volume or worlds. Preparation never requires write permission to live files. Arrange the same read access for replacement pack files after manual updates; do not give the Panel broad write access or use `chmod 777`. Changing ownership of the live volume is unnecessary.

## 2. Configure the UUID mapping

Create `/etc/pelican/arcturus-local-filesystems.json`, owned by `root:www-data`, mode `0640`, with this shape. The file must be readable, regular, not a symlink, and not group/world-writable. Replace the example UUID **in both places**. Paths must match the real Wings volume, not a similarly named server.

```json
{
  "11111111-1111-4111-8111-111111111111": {
    "root": "/var/lib/pelican/volumes/11111111-1111-4111-8111-111111111111",
    "staging": "/var/lib/arcturus-modpack-manager/11111111-1111-4111-8111-111111111111",
    "max_archive_bytes": 8589934592,
    "max_inventory_bytes": 34359738368,
    "allowed_hosts": [
      "github.com",
      "release-assets.githubusercontent.com",
      "objects.githubusercontent.com"
    ]
  }
}
```

Only add exact upstream/CDN hostnames needed by the selected provider. Downloads retain HTTPS verification, public-IP checks, redirect allowlisting, streaming limits and upstream checksum verification. Do not add wildcard hosts or disable TLS. The optional `managed_directories`, `excluded_directories` and operator-reviewed `artifacts` map have the same semantics as [the existing worker](remote-wings.md). World detection always overrides these options. A local recipe cannot request arbitrary local artifact paths.

Set this in the Panel `.env`:

```dotenv
ARCTURUS_MODPACK_MANAGER_LOCAL_CONFIG=/etc/pelican/arcturus-local-filesystems.json
```

## 3. Configure the existing asynchronous queue

Large server ZIPs can take much longer than a normal short queue tick. A local preparation step can run for up to **14400 seconds**; its job timeout is **14430 seconds** and the operation lock lasts **14460 seconds**. Heartbeats refresh every 30 seconds. The local helper runs inside that job; no detached installation or daemon is started.

Use Pelican's existing database or Redis queue. The connection's `retry_after` must exceed 14460 seconds; **14520** is recommended. Set the variable for your actual Laravel queue connection, preserving the existing connection, database and credentials:

```dotenv
# For the database queue:
DB_QUEUE_RETRY_AFTER=14520
# For the Redis queue:
REDIS_QUEUE_RETRY_AFTER=14520
```

The framework defaults use these names. If your Panel overrides queue configuration, inspect the effective `config('queue.connections.'.config('queue.default'))` and set `retry_after` there. Do not switch to `sync`: the preflight refuses synchronous, unsupported or short-visibility queues rather than running downloads in a web request. Other jobs on this connection also inherit the longer retry visibility after a worker crash; account for that operational tradeoff. Normal execution is unaffected.

Ensure the queue service manager allows a running preparation to finish before forcing shutdown (for systemd, a suitable `TimeoutStopSec`; for Supervisor, `stopwaitsecs`). Restart/import while no preparation is running whenever possible. If a process is killed, retained stage state is interrupted on reconciliation rather than silently replayed.

## 4. Reload the Panel and select local mode

After importing/upgrading the plugin or changing the mapping/environment, rebuild the Panel configuration and **restart long-lived queue workers**. Example commands from your Panel directory, using its actual PHP binary/account:

```sh
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo systemctl restart pelican-queue
sudo systemctl status pelican-queue --no-pager
```

A newly imported plugin's classes are discovered when Laravel boots. A web request seeing the plugin does not prove an already-running queue process can autoload its jobs. Restarting `pelican-queue` resolves that separate autoloading issue; it does not grant filesystem permissions. This follows [Laravel's queue deployment requirement](https://laravel.com/docs/12.x/queues#queue-workers-and-deployment). Run your usual PHP-FPM reload if its OPcache/deployment configuration requires one. No queue service is restarted by this plugin.

In **Arcturus Modpack Manager → Managed servers → your server**:

1. Open **Filesystem backend**, choose **Same-host local filesystem**, and save. The plugin validates the UUID mapping, Python runtime, canonical paths, readable root and private writable staging, then pins those paths to this managed server. Changing backend/paths invalidates baseline/plan evidence while preserving installed version, saved policies, protected mods, server files and runtime settings.
2. Use **Test filesystem access**. This reads configuration/capability and directory-permission metadata; it does not scan worlds, download a pack or create a stage.
3. For the existing TFG-Modern server, confirm the saved installed upstream release is exactly **0.13.5** and the existing GitHub source/asset pattern resolves its verified server pack. Then choose **Prepare installed baseline**. Queue and filesystem preflight failures appear as actionable notifications before dispatch. An upstream release without supported checksums still blocks preparation.
4. Review **File Changes** and protected/local-only mods. Baseline evidence comes from the staged upstream archive, never from the customized live installation. Preparation does not finish adoption or deploy an update automatically.

## Diagnosis and recovery

- **Missing mapping:** check the exact Pelican server UUID, environment path, file ownership/mode, effective config cache and queue restart. Remote mode continues to require its separate worker; select local explicitly.
- **Root/staging permissions:** test as both actual service accounts. The quick capability check covers root/staging permissions; unreadable managed children can still fail a later inventory. Grant only their required read/traverse ACLs. Staging must be owned by the executing account and mode `0700`.
- **Changed mapping:** re-select local mode to validate/pin the new paths. Wait for active preparation to finish before changing paths. Retain old stages referenced by baselines or merge reviews.
- **Python/process unavailable:** install Python 3.11+ and allow `proc_open` for the Panel's trusted plugin process. Run the queue as an unprivileged account. Check AppArmor/systemd filesystem restrictions if Unix ACLs appear correct.
- **Archive/checksum/download/scope failure:** retain the stage and the Panel/node operation IDs. Correct the reviewed upstream recipe or permissions, then explicitly retry with a new operation. Do not manually replace a manifest with live files.
- **Queue interruption:** inspect the actual process and stage first, then use the existing preparation reconciliation/recovery actions. A live subprocess is protected by both the Panel operation lock and a staging file lock; a duplicate cannot prepare concurrently. Cancellation writes only the stage cancellation marker. Lock expiry after a hard worker crash is conservative; never clear an operation lock while its process is still running.

The local backend provides **read-only live inventory and isolated preparation only**. New local baselines cannot pass the deployment gate; no local apply/restore capability is enabled. Remote workers and existing legacy offline tests remain supported. This feature does not prove a full TFG runtime/world migration or modify production server files.
