#!/usr/bin/env bash
# Builds the installable plugin zip dist/wp-nc-linker-<version>.zip from the working tree
# (expects `npm run build`). Contains only the runtime files: no dev/, tests, sources of the
# assets, node_modules or vendor.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

version="$(sed -n 's/^ \* Version: *//p' wp-nc-linker.php)"
[[ -n "$version" ]] || { echo "Version not found in wp-nc-linker.php" >&2; exit 1; }

required=(
	build/media-tab.js
	build/media-tab.asset.php
	build/media-tab.css
	languages/wp-nc-linker.pot
	languages/wp-nc-linker-de_DE.mo
	"languages/wp-nc-linker-de_DE-$(printf '%s' build/media-tab.js | md5sum | cut -d' ' -f1).json"
)
for file in "${required[@]}"; do
	[[ -f "$file" ]] || { echo "Missing: $file" >&2; exit 1; }
done

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
target="$stage/wp-nc-linker"
mkdir -p "$target/languages"
cp -R wp-nc-linker.php uninstall.php LICENSE README.md src build "$target/"
cp languages/*.pot languages/*.po languages/*.mo languages/*.json "$target/languages/"

mkdir -p dist
zip_file="$PWD/dist/wp-nc-linker-$version.zip"
rm -f "$zip_file"
(cd "$stage" && zip -qrX "$zip_file" wp-nc-linker)
echo "$zip_file"
