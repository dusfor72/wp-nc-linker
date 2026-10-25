#!/usr/bin/env bash
# Runs the integration tests inside the WordPress container (environment must be running).
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ -f "$DEV_DIR/../vendor/autoload.php" ]] || die "vendor/ missing, run composer install"

compose exec -T -u www-data -w /var/www/html/wp-content/plugins/wp-nc-linker wordpress \
	php vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
