#!/usr/bin/env bash
# Runs the integration tests inside the WordPress container (environment must be running).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_env

[[ -f "$DEV_DIR/../vendor/autoload.php" ]] || die "vendor/ missing, run composer install"
[[ -f "$SECRETS_DIR/linker-app-password" ]] || die "App password missing, run dev/bin/setup.sh"

compose exec -T -u www-data -w /var/www/html/wp-content/plugins/wp-nc-linker \
	-e "WPNCL_TEST_APP_PASSWORD=$(cat "$SECRETS_DIR/linker-app-password")" \
	-e "WPNCL_TEST_CONTENT_PASSWORD=$NC_CONTENT_PASSWORD" \
	wordpress \
	php vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
