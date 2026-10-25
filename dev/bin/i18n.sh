#!/usr/bin/env bash
# Regenerates the translation files from the sources: POT, de_DE PO (new strings stay untranslated),
# MO and the JSON file of the media tab script. Needs WP-CLI (WP_CLI="php wp-cli.phar" or `wp`)
# and a current build/ (npm run build), because the script strings are taken from build/media-tab.js.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

read -r -a wp_cli <<< "${WP_CLI:-wp}"
[[ -f build/media-tab.js ]] || { echo "build/ missing, run npm run build" >&2; exit 1; }

"${wp_cli[@]}" i18n make-pot . languages/wp-nc-linker.pot \
	--exclude=assets/src,node_modules,vendor,dev,tests,docs,dist \
	--headers='{"Report-Msgid-Bugs-To":"","Last-Translator":"","Language-Team":""}'
"${wp_cli[@]}" i18n update-po languages/wp-nc-linker.pot languages/wp-nc-linker-de_DE.po
"${wp_cli[@]}" i18n make-mo languages
"${wp_cli[@]}" i18n make-json languages --no-purge
