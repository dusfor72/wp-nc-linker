#!/bin/bash
# Creates one database and account each for WordPress and Nextcloud.
set -euo pipefail

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY '${WP_DB_PASSWORD}';
CREATE USER IF NOT EXISTS 'nextcloud'@'%' IDENTIFIED BY '${NC_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'%';
GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'%';
FLUSH PRIVILEGES;
SQL
