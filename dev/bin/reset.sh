#!/usr/bin/env bash
# Removes containers and volumes (all seed data). Certificates, dev/.env and downloads are kept.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

compose down --volumes --remove-orphans
rm -f "$SECRETS_DIR/linker-app-password"
log "Environment removed; run dev/bin/setup.sh to recreate it"
