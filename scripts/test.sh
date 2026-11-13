#!/usr/bin/env bash
set -euo pipefail
find src config database bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*.php; do
  [[ "$test" == tests/bootstrap.php ]] && continue
  php "$test"
done
node tests/console_output.mjs
python3 tests/node_worker.py
python3 tests/local_worker.py
python3 tests/setup_local_node.py
python3 tests/tfg_managed_paths.py
python3 tests/preparation_progress.py
TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT
mkdir -p "$TEMP_DIR/base/config" "$TEMP_DIR/target/config" "$TEMP_DIR/live/config"
printf old > "$TEMP_DIR/base/config/A.cfg"
printf new > "$TEMP_DIR/target/config/A.cfg"
printf old > "$TEMP_DIR/live/config/A.cfg"
php bin/modpack.php manifest "$TEMP_DIR/base" 1.0 > "$TEMP_DIR/base.json"
php bin/modpack.php manifest "$TEMP_DIR/target" 2.0 > "$TEMP_DIR/target.json"
php bin/modpack.php preview "$TEMP_DIR/base.json" "$TEMP_DIR/target.json" "$TEMP_DIR/live" > "$TEMP_DIR/preview.json"
php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($p["counts"]["update"] ?? 0) !== 1 || $p["blocked"] !== 0) exit(1);' "$TEMP_DIR/preview.json"
