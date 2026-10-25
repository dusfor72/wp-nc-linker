#!/usr/bin/env bash
# Runs the Playwright E2E tests against the running environment (requires `npm run build`).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_env

[[ -f "$DEV_DIR/../build/media-tab.js" ]] || die "build/ missing, run npm run build"

cd "$DEV_DIR/.."
WP_BASE_URL="http://localhost:8080" \
WP_USERNAME="admin" \
WP_PASSWORD="$WP_ADMIN_PASSWORD" \
WPNCL_E2E_USER_PASSWORD="$WP_USER_PASSWORD" \
	npx playwright test "$@"
