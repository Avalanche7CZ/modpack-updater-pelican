#!/usr/bin/env bash
# Requires a disposable official Panel checkout and Playwright/Chromium; never use a production checkout.
set -euo pipefail
: "${PELICAN_SOURCE:?Set PELICAN_SOURCE to a disposable Panel checkout without .env}"
[[ ! -e "$PELICAN_SOURCE/.env" ]] || { echo 'Refusing a Panel checkout with .env' >&2; exit 1; }
export ARCTURUS_BROWSER_FIXTURE
ARCTURUS_BROWSER_FIXTURE=$(mktemp -d /tmp/arcturus-browser.XXXXXX)
mkdir -p "$ARCTURUS_BROWSER_FIXTURE/sessions"
export ARCTURUS_PHP="${ARCTURUS_PHP:-$(command -v php)}"
PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
export ARCTURUS_BROWSER_URL="http://127.0.0.1:$PORT"
"$ARCTURUS_PHP" -r '$app=require "tests/integration/bootstrap.php"; Illuminate\Support\Facades\Artisan::call("filament:assets"); $GLOBALS["arcturus_integration_passed"]=true;' > "$ARCTURUS_BROWSER_FIXTURE/assets.log" 2>&1
"$ARCTURUS_PHP" -d memory_limit=128M -S "127.0.0.1:$PORT" tests/browser/router.php > "$ARCTURUS_BROWSER_FIXTURE/server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true; echo "Browser evidence retained: $ARCTURUS_BROWSER_FIXTURE"' EXIT
for attempt in {1..30}; do
  if curl -fsS "$ARCTURUS_BROWSER_URL/fixture" -o /dev/null; then break; fi
  sleep 1
done
node tests/browser/file-decisions.cjs
