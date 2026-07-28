#!/usr/bin/env bash
#
# Builds an installable plugin zip into dist/.
# Run `composer install --no-dev` first so vendor/ contains only the autoloader.
#
# Usage: bin/build-zip.sh [version]   (defaults to the plugin header version)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(sed -n 's/^ \* Version: //p' "$ROOT/universal-geo-context.php" | tr -d '[:space:]')}"

if [ -z "$VERSION" ]; then
    echo "Could not determine plugin version." >&2
    exit 1
fi

if [ -d "$ROOT/vendor/phpunit" ]; then
    echo "vendor/ contains dev dependencies — run 'composer install --no-dev' before building." >&2
    exit 1
fi

DIST="$ROOT/dist"
BUILD="$DIST/universal-geo-context"

rm -rf "$DIST"
mkdir -p "$BUILD"

cp "$ROOT/universal-geo-context.php" "$ROOT/uninstall.php" "$BUILD/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$BUILD/"
[ -f "$ROOT/LICENSE" ] && cp "$ROOT/LICENSE" "$BUILD/"
cp -R "$ROOT/src" "$BUILD/src"
cp -R "$ROOT/vendor" "$BUILD/vendor"
[ -d "$ROOT/languages" ] && cp -R "$ROOT/languages" "$BUILD/languages"

( cd "$DIST" && zip -rq "universal-geo-context-${VERSION}.zip" universal-geo-context )

echo "dist/universal-geo-context-${VERSION}.zip"
