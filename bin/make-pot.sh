#!/usr/bin/env bash
#
# Regenerates languages/universal-geo-context.pot from every translatable
# string in the plugin (M5 — translation readiness).
#
# Usage:
#   bin/make-pot.sh          Regenerates the file in place.
#   bin/make-pot.sh --check  Regenerates to a scratch file and diffs it
#                            against the committed .pot, ignoring the
#                            POT-Creation-Date line (which always changes).
#                            Exits non-zero on drift; leaves the committed
#                            file untouched. This is the CI gate.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
POT="$ROOT/languages/universal-geo-context.pot"

if [ ! -x "$ROOT/vendor/bin/wp" ]; then
    echo "vendor/bin/wp not found — run 'composer install' first (wp-cli/wp-cli and wp-cli/i18n-command are dev dependencies)." >&2
    exit 1
fi

mkdir -p "$ROOT/languages"

generate() {
    local target="$1"
    bash "$ROOT/vendor/bin/wp" i18n make-pot "$ROOT" "$target" \
        --domain=universal-geo-context \
        --exclude=vendor,tests,dist,.github,bin \
        --headers='{"Report-Msgid-Bugs-To":"https://github.com/magpern/universal-geo-context/issues"}' \
        --allow-root
}

if [ "${1:-}" = "--check" ]; then
    SCRATCH="$(mktemp /tmp/universal-geo-context-XXXXXX.pot)"
    trap 'rm -f "$SCRATCH"' EXIT

    generate "$SCRATCH" >/dev/null

    if ! diff -q <(grep -v '^"POT-Creation-Date' "$POT") <(grep -v '^"POT-Creation-Date' "$SCRATCH") >/dev/null; then
        echo "languages/universal-geo-context.pot is out of date. Run 'bin/make-pot.sh' and commit the result." >&2
        diff <(grep -v '^"POT-Creation-Date' "$POT") <(grep -v '^"POT-Creation-Date' "$SCRATCH") || true
        exit 1
    fi

    echo "languages/universal-geo-context.pot is up to date."
    exit 0
fi

generate "$POT"
echo "$POT"
