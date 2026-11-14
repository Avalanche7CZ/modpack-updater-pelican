#!/usr/bin/env bash
# Creates a fresh source-only Pelican checkout; never uses a configured Panel.
set -euo pipefail
PANEL_REF="${1:-v1.0.0-rc.2}"
[[ "$PANEL_REF" =~ ^v1\.0\.0-rc\.2$ ]] || { echo 'Use the qualified rc.2 source tag' >&2; exit 2; }
TEST_PANEL=$(mktemp -d /tmp/arcturus-panel-test-XXXXXXXX)
echo "Disposable Pelican source: $TEST_PANEL (retained for diagnostics)"
git clone --depth=1 --branch "$PANEL_REF" https://github.com/pelican-dev/panel.git "$TEST_PANEL"
composer install --working-dir="$TEST_PANEL" --no-dev --no-scripts --no-interaction --prefer-dist
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/pelican.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/console.php
PELICAN_SOURCE="$TEST_PANEL" node tests/console_output.mjs
git -C "$TEST_PANEL" apply --check "$PWD/patches/pelican/rc2-console-append.patch"
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/filament.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/livewire.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/adoption.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/local.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/local_node.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/curseforge.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/runtime.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/policies.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/discord.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/discord_deployment.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/native_wings.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/native_batch.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/native_bulk.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/apply_batches.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/manual_lease.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/native_restore_policy.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/native_deployment.php
PELICAN_SOURCE="$TEST_PANEL" php tests/integration/boot_race.php
