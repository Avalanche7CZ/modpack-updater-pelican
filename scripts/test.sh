#!/usr/bin/env bash
set -euo pipefail
find src config database bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*.php; do
  [[ "$test" == tests/bootstrap.php ]] && continue
  php "$test"
done
python3 tests/node_worker.py
python3 tests/local_worker.py
python3 tests/setup_local_node.py
python3 tests/tfg_managed_paths.py
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT
mkdir -p "$TEMP_DIR/base/mods" "$TEMP_DIR/target/mods" "$TEMP_DIR/live/mods"
printf old > "$TEMP_DIR/base/mods/A.jar"
printf new > "$TEMP_DIR/target/mods/A.jar"
printf old > "$TEMP_DIR/live/mods/A.jar"
php bin/modpack.php manifest "$TEMP_DIR/base" 1.0 > "$TEMP_DIR/base.json"
php bin/modpack.php manifest "$TEMP_DIR/target" 2.0 > "$TEMP_DIR/target.json"
php bin/modpack.php preview "$TEMP_DIR/base.json" "$TEMP_DIR/target.json" "$TEMP_DIR/live" > "$TEMP_DIR/preview.json"
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($p["counts"]["update"] ?? 0) !== 1 || $p["blocked"] !== 0) exit(1);' "$TEMP_DIR/preview.json"
