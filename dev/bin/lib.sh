#!/usr/bin/env bash
# Shared helpers for the development environment scripts.

DEV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS_DIR="$DEV_DIR/.secrets"
CERTS_DIR="$DEV_DIR/.certs"
DATA_DIR="$DEV_DIR/.data"

MEMORIES_VERSION="10.2.0"

compose() {
	docker compose --project-directory "$DEV_DIR" -f "$DEV_DIR/docker-compose.yml" "$@"
}

# occ as the web server user. Extra environment variables: occ_env VAR=value ... -- args.
occ() {
	compose exec -T -u www-data nextcloud php occ "$@"
}

occ_env() {
	local env_args=()
	while [[ $# -gt 0 && "$1" != "--" ]]; do
		env_args+=(-e "$1")
		shift
	done
	shift
	compose exec -T "${env_args[@]}" -u www-data nextcloud php occ "$@"
}

wp() {
	compose exec -T -u www-data wordpress wp "$@"
}

log() {
	printf '\033[1;34m==>\033[0m %s\n' "$*"
}

die() {
	printf '\033[1;31mError:\033[0m %s\n' "$*" >&2
	exit 1
}

random_secret() {
	openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-32
}

# Loads dev/.env into the environment.
load_env() {
	[[ -f "$DEV_DIR/.env" ]] || die "dev/.env missing, run dev/bin/setup.sh"
	set -a
	# shellcheck source=/dev/null
	source "$DEV_DIR/.env"
	set +a
}

# Waits until a command succeeds (max. $1 seconds).
wait_for() {
	local timeout="$1" description="$2"
	shift 2
	local start=$SECONDS
	until "$@" >/dev/null 2>&1; do
		if (( SECONDS - start > timeout )); then
			die "Timeout waiting for $description"
		fi
		sleep 3
	done
}
