#!/usr/bin/env bash
# Builds and starts the development environment and seeds it (idempotent).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

command -v docker >/dev/null || die "docker is required"
command -v openssl >/dev/null || die "openssl is required"
command -v curl >/dev/null || die "curl is required"

mkdir -p "$SECRETS_DIR" "$CERTS_DIR" "$DATA_DIR"
chmod 700 "$SECRETS_DIR" "$CERTS_DIR"

if [[ ! -f "$DEV_DIR/.env" ]]; then
	log "Generating dev/.env with random passwords"
	umask 077
	cat > "$DEV_DIR/.env" <<ENV
DB_ROOT_PASSWORD=$(random_secret)
WP_DB_PASSWORD=$(random_secret)
NC_DB_PASSWORD=$(random_secret)
NC_ADMIN_PASSWORD=$(random_secret)
NC_CONTENT_PASSWORD=$(random_secret)
NC_LINKER_PASSWORD=$(random_secret)
WP_ADMIN_PASSWORD=$(random_secret)
WP_USER_PASSWORD=$(random_secret)
ENV
	umask 022
fi
load_env

if [[ ! -f "$CERTS_DIR/nextcloud.crt" ]]; then
	log "Generating development CA and Nextcloud certificate"
	openssl req -x509 -newkey rsa:3072 -nodes -days 825 \
		-subj "/CN=WP NC Linker Dev CA" \
		-keyout "$CERTS_DIR/ca.key" -out "$CERTS_DIR/ca.crt" 2>/dev/null
	openssl req -newkey rsa:2048 -nodes \
		-subj "/CN=nextcloud.test" \
		-keyout "$CERTS_DIR/nextcloud.key" -out "$CERTS_DIR/nextcloud.csr" 2>/dev/null
	printf 'subjectAltName=DNS:nextcloud.test,DNS:localhost\nextendedKeyUsage=serverAuth\n' > "$CERTS_DIR/nextcloud.ext"
	openssl x509 -req -days 825 -in "$CERTS_DIR/nextcloud.csr" \
		-CA "$CERTS_DIR/ca.crt" -CAkey "$CERTS_DIR/ca.key" -CAcreateserial \
		-extfile "$CERTS_DIR/nextcloud.ext" -out "$CERTS_DIR/nextcloud.crt" 2>/dev/null
	rm -f "$CERTS_DIR/nextcloud.csr" "$CERTS_DIR/nextcloud.ext"
	# Read by Apache in the container (root at start-up); the CA key stays private.
	chmod 644 "$CERTS_DIR/ca.crt" "$CERTS_DIR/nextcloud.crt" "$CERTS_DIR/nextcloud.key"
	chmod 600 "$CERTS_DIR/ca.key"
	chmod 755 "$CERTS_DIR"
fi

MEMORIES_TARBALL="$DATA_DIR/memories-$MEMORIES_VERSION.tar.gz"
if [[ ! -f "$MEMORIES_TARBALL" ]]; then
	log "Downloading Memories $MEMORIES_VERSION"
	curl -fsSL -o "$MEMORIES_TARBALL.part" \
		"https://github.com/pulsejet/memories/releases/download/v$MEMORIES_VERSION/memories.tar.gz"
	mv "$MEMORIES_TARBALL.part" "$MEMORIES_TARBALL"
fi

log "Building and starting containers"
compose build --quiet
compose up -d

"$DEV_DIR/bin/seed-nextcloud.sh"
"$DEV_DIR/bin/seed-wordpress.sh"

log "Ready"
cat <<INFO
  WordPress:  http://localhost:8080/wp-admin/  (admin / see dev/.env WP_ADMIN_PASSWORD)
  Nextcloud:  https://localhost:8443/          (admin / see dev/.env NC_ADMIN_PASSWORD)
  Plugin connection (inside the network):
    URL:          https://nextcloud.test
    User:         linker
    App password: dev/.secrets/linker-app-password
INFO
