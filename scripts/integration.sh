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
