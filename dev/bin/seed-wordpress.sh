#!/usr/bin/env bash
# Seeds WordPress: installation, test users with different roles, plugin activation.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_env

log "Waiting for WordPress"
wait_for 300 "wp-config.php" compose exec -T wordpress test -f /var/www/html/wp-config.php

if ! wp core is-installed 2>/dev/null; then
	log "Installing WordPress"
	wp core install --url=http://localhost:8080 --title='WP NC Linker Dev' \
		--admin_user=admin --admin_password="$WP_ADMIN_PASSWORD" \
		--admin_email=admin@example.org --skip-email
fi

log "Creating test users"
for role in editor author subscriber; do
	if ! wp user get "$role" --field=ID >/dev/null 2>&1; then
		wp user create "$role" "$role@example.org" --role="$role" --user_pass="$WP_USER_PASSWORD" --porcelain >/dev/null
	fi
done

wp rewrite structure '/%postname%/' --hard --quiet
wp plugin activate wp-nc-linker

log "Configuring the plugin connection (linker account)"
[[ -f "$SECRETS_DIR/linker-app-password" ]] || die "App password missing; seed Nextcloud first"
# Saved through the plugin's sanitize callback, so the app password is stored encrypted.
compose exec -T -u www-data \
	-e "WPNCL_SEED_APP_PASSWORD=$(cat "$SECRETS_DIR/linker-app-password")" \
	wordpress wp eval '
		update_option(
			"wpncl_settings",
			array_merge(
				(array) get_option( "wpncl_settings", array() ),
				array(
					"url"              => "https://nextcloud.test",
					"user"             => "linker",
					"new_app_password" => getenv( "WPNCL_SEED_APP_PASSWORD" ),
				)
			)
		);
	'
wp nc-linker test >/dev/null || die "Connection test failed after seeding"
