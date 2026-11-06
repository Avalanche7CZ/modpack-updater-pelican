# Existing offline node CLI

The dependency-free CLI remains available for explicitly controlled local node operations. It never stops/restarts Minecraft or retrieves provider releases. The operator supplies separately verified upstream manifests and staging. Its schema-1 manifests are inventory interchange, not independently authenticated upstream provenance for the Panel.

```sh
php bin/modpack.php manifest /srv/trusted-upstream/installed 1.0 > installed.json
php bin/modpack.php manifest /srv/trusted-upstream/target 1.1 > target.json
php bin/modpack.php preview installed.json target.json /srv/disposable-server rules.json
```

Use a trusted unpacked upstream release for each manifest. Never manufacture the original upstream baseline from live customized files. The planner preserves world and path safety invariants and blocks conflicts.

The destructive CLI requires explicit offline confirmation and creates a full local snapshot before applying file changes:

```sh
php bin/modpack.php deploy installed.json target.json /srv/server /srv/trusted-upstream/target /srv/snapshots rules.json --offline-confirmed
php bin/modpack.php rollback /srv/server /srv/snapshots/EXACT-SNAPSHOT --offline-confirmed
```

Stop and independently verify the server is stopped first. Keep staging/snapshots outside the server directory. Test on disposable copies before operational use. The CLI's filesystem backup is an optional local fallback and does not replace Pelican-native backups as the Panel default. It cannot rename mounted roots during rollback; such failures retain recovery evidence and require manual action. The CLI uses the same policy engine and reads the configured world name. Unlike the Panel inventory, it does not discover local-only files outside the supplied manifests, and it does not provide metadata-based cross-version mod identity resolution or runtime/Java approval; use explicit protection rules and review the full plan. Native remote deployment remains disabled.
