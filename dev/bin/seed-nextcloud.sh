#!/usr/bin/env bash
# Seeds Nextcloud: accounts, group, test files, shares, albums and the linker app password.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_env

CONTENT_USER=content
LINKER_USER=linker
EDITORS_GROUP=wpncl-editors

nc_installed() {
	occ status --output=json | grep -q '"installed":true'
}

# HTTP request inside the Nextcloud container (TLS verified against the development CA).
nc_curl() {
	compose exec -T nextcloud curl -fsS --cacert /etc/wpncl-certs/ca.crt "$@"
}

dav() {
	local user="$1" password="$2" method="$3" path="$4"
	shift 4
	nc_curl -u "$user:$password" -X "$method" "https://localhost/remote.php/dav/$path" "$@"
}

ocs_share() {
	nc_curl -u "$CONTENT_USER:$NC_CONTENT_PASSWORD" -H 'OCS-APIRequest: true' \
		-X POST 'https://localhost/ocs/v2.php/apps/files_sharing/api/v1/shares' \
		--data-urlencode "path=$1" -d shareType=0 --data-urlencode "shareWith=$LINKER_USER" \
		-d permissions=1 "${@:2}" -o /dev/null
}

collaborators() {
	local album="$1" json="$2"
	dav "$CONTENT_USER" "$NC_CONTENT_PASSWORD" PROPPATCH "photos/$CONTENT_USER/albums/$album" \
		-H 'Content-Type: application/xml' -o /dev/null --data-binary @- <<XML
<?xml version="1.0"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
	<d:set><d:prop><nc:collaborators>$json</nc:collaborators></d:prop></d:set>
</d:propertyupdate>
XML
}

# Creates an album of $user and adds files (paths relative to the user's root, URL-encoded).
album() {
	local user="$1" password="$2" album="$3"
	shift 3
	dav "$user" "$password" MKCOL "photos/$user/albums/$album" -o /dev/null
	local file
	for file in "$@"; do
		dav "$user" "$password" COPY "files/$user/$file" -o /dev/null \
			-H "Destination: https://localhost/remote.php/dav/photos/$user/albums/$album/${file##*/}"
	done
}

log "Waiting for the Nextcloud installation"
wait_for 600 "Nextcloud installation" nc_installed
wait_for 120 "Nextcloud HTTPS" nc_curl -o /dev/null https://localhost/status.php

if occ user:info "$LINKER_USER" >/dev/null 2>&1; then
	log "Nextcloud already seeded"
	exit 0
fi

log "Installing Memories $MEMORIES_VERSION"
compose cp "$DATA_DIR/memories-$MEMORIES_VERSION.tar.gz" nextcloud:/tmp/memories.tar.gz
compose exec -T -u www-data nextcloud sh -c 'tar -xzf /tmp/memories.tar.gz -C /var/www/html/custom_apps'
compose exec -T nextcloud rm -f /tmp/memories.tar.gz
occ app:enable photos
occ app:enable memories
occ app:list --output=json | grep -oE '"(photos|memories)":"[^"]+"'

log "Creating accounts and group"
# No skeleton/template files: the test accounts contain only the seed files.
occ config:system:set skeletondirectory --value=''
occ config:system:set templatedirectory --value=''
occ_env "OC_PASS=$NC_CONTENT_PASSWORD" -- user:add --password-from-env --display-name "Content" "$CONTENT_USER"
occ_env "OC_PASS=$NC_LINKER_PASSWORD" -- user:add --password-from-env --display-name "Linker" "$LINKER_USER"
occ group:add "$EDITORS_GROUP"
occ group:adduser "$EDITORS_GROUP" "$LINKER_USER"

log "Generating and uploading test files (WebDAV, like a regular upload)"
compose cp "$DEV_DIR/nextcloud/make-fixtures.php" nextcloud:/tmp/make-fixtures.php
compose exec -T \
	-e "CONTENT_AUTH=$CONTENT_USER:$NC_CONTENT_PASSWORD" \
	-e "LINKER_AUTH=$LINKER_USER:$NC_LINKER_PASSWORD" \
	nextcloud bash -c '
	set -euo pipefail
	tmp="$(mktemp -d)"
	trap "rm -rf \"$tmp\" /tmp/make-fixtures.php" EXIT
	php -d memory_limit=1G /tmp/make-fixtures.php "$tmp"
	cd "$tmp"
	encode() { php -r "echo implode(\"/\", array_map(\"rawurlencode\", explode(\"/\", \$argv[1])));" "$1"; }
	for user in content linker; do
		auth_var="${user^^}_AUTH"
		base="https://localhost/remote.php/dav/files/$user"
		while IFS= read -r -d "" dir; do
			curl -fsS --cacert /etc/wpncl-certs/ca.crt -u "${!auth_var}" -X MKCOL -o /dev/null \
				"$base/$(encode "${dir#"$user"/}")"
		done < <(find "$user" -mindepth 1 -type d -print0 | sort -z)
		while IFS= read -r -d "" file; do
			curl -fsS --cacert /etc/wpncl-certs/ca.crt -u "${!auth_var}" -T "$file" -o /dev/null \
				"$base/$(encode "${file#"$user"/}")"
		done < <(find "$user" -type f -print0)
	done
'

log "Sharing folders with $LINKER_USER"
ocs_share '/Shared With Linker'
ocs_share '/Hidden Download' --data-urlencode 'attributes=[{"scope":"permissions","key":"download","value":false}]'

log "Creating albums"
album "$CONTENT_USER" "$NC_CONTENT_PASSWORD" 'Shared%20Album' \
	'Photos/landscape.jpg' 'Photos/portrait.jpg' 'Photos/large-original.jpg'
collaborators 'Shared%20Album' "[{\"id\":\"$LINKER_USER\",\"type\":0}]"
album "$CONTENT_USER" "$NC_CONTENT_PASSWORD" 'Group%20Album' 'Photos/square.png'
collaborators 'Group%20Album' "[{\"id\":\"$EDITORS_GROUP\",\"type\":1}]"
album "$LINKER_USER" "$NC_LINKER_PASSWORD" 'Linker%20Album' 'Own/linker-photo.jpg'

log "Creating app password for $LINKER_USER"
app_password="$(occ_env "NC_PASS=$NC_LINKER_PASSWORD" -- user:auth-tokens:add --password-from-env --name wp-nc-linker-dev "$LINKER_USER" | tail -n 1)"
[[ "$app_password" =~ ^[A-Za-z0-9]{72}$ ]] || die "Unexpected app password output"
umask 077
printf '%s' "$app_password" > "$SECRETS_DIR/linker-app-password"
umask 022

log "Checking the seed as $LINKER_USER (app password)"
propfind_names() {
	dav "$LINKER_USER" "$app_password" PROPFIND "$1" -H 'Depth: 1' \
		| grep -oE '<d:href>[^<]+</d:href>' | sed -E 's#</?d:href>##g' | tail -n +2
}
echo "  files:";        propfind_names "files/$LINKER_USER/"           | sed 's/^/    /'
echo "  albums:";       propfind_names "photos/$LINKER_USER/albums/"       | sed 's/^/    /'
echo "  sharedalbums:"; propfind_names "photos/$LINKER_USER/sharedalbums/" | sed 's/^/    /'

dav "$LINKER_USER" "$app_password" PROPFIND "files/$LINKER_USER/Shared%20With%20Linker/forest.jpg" -H 'Depth: 0' \
	--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:metadata-photos-size/></d:prop></d:propfind>' \
	| grep -q '<width>2400</width>' || die "photos-size metadata missing"
dav "$LINKER_USER" "$app_password" PROPFIND "files/$LINKER_USER/Hidden%20Download/no-download.jpg" -H 'Depth: 0' \
	--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:share-attributes/></d:prop></d:propfind>' \
	| grep -q '&quot;download&quot;,&quot;value&quot;:false' || die "download restriction missing on Hidden Download"
log "Seed checks passed"
