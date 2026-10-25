#!/bin/bash
# Trusts the development CA (mounted by docker compose), then starts the official entrypoint.
set -euo pipefail

update-ca-certificates >/dev/null

exec docker-entrypoint.sh "$@"
