#!/usr/bin/env bash
#
# Audits an already-built release directory (bin/build-zip.sh's output,
# before zipping) for release-blocking problems: missing production files,
# stray development files, or bundled dev-only Composer packages (M5).
#
# Usage: bin/release-audit.sh [path-to-build-dir]
#   Defaults to dist/universal-geo-context — bin/build-zip.sh's own output
#   directory. Run `bin/build-zip.sh` first; this script never builds one
#   itself, so a run is always auditing exactly what was actually built.
#
# Every check runs and is reported; failures are aggregated and the script
# exits non-zero at the end if any check failed, rather than stopping at
# the first problem.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${1:-$ROOT/dist/universal-geo-context}"

if [ ! -d "$BUILD" ]; then
    echo "Build directory not found: $BUILD" >&2
    echo "Run 'bin/build-zip.sh' first, then re-run this script." >&2
    exit 1
fi

FAILURES=()

pass() {
    echo "PASS: $1"
}

fail() {
    echo "FAIL: $1" >&2
    FAILURES+=("$1")
}

check_exists() {
    local description="$1"
    local path="$2"

    if [ -e "$BUILD/$path" ]; then
        pass "$description ($path present)"
    else
        fail "$description — missing: $path"
    fi
}

check_absent() {
    local description="$1"
    local path="$2"

    if [ -e "$BUILD/$path" ]; then
        fail "$description — must not be in the built zip: $path"
    else
        pass "$description ($path absent)"
    fi
}

# ---- required production files ---------------------------------------------

check_exists "Main plugin file"          "universal-geo-context.php"
check_exists "Uninstall script"          "uninstall.php"
check_exists "readme.txt"                "readme.txt"
check_exists "LICENSE"                   "LICENSE"
check_exists "Source directory"          "src"
check_exists "Composer autoloader"       "vendor/autoload.php"
check_exists "Public API bootstrap file" "src/api.php"

# ---- dev-only paths that must never ship -----------------------------------

check_absent "Test suite"                     "tests"
check_absent "PHPUnit config (unit)"          "phpunit.xml.dist"
check_absent "PHPUnit config (integration)"   "phpunit-integration.xml.dist"
check_absent "PHPCS config"                   "phpcs.xml.dist"
check_absent "GitHub workflows"               ".github"
check_absent "Git metadata"                   ".git"
check_absent ".gitignore"                     ".gitignore"
check_absent "composer.json"                  "composer.json"
check_absent "composer.lock"                  "composer.lock"
check_absent "Dev-only scripts directory"     "bin"
check_absent "Working agreement (dev-only)"   "CLAUDE.md"
check_absent "Machine-local notes (dev-only)" "CLAUDE.local.md"
check_absent "Nested dist/ directory"         "dist"

# ---- temp / local-environment / credential-shaped files --------------------

for pattern in ".env" "*.log" ".DS_Store" "Thumbs.db" "wp-tests-config.php" "*.pem" "*.key" "*.sql"; do
    matches="$(find "$BUILD" -iname "$pattern" 2>/dev/null || true)"

    if [ -n "$matches" ]; then
        fail "Temp/local/credential-shaped file(s) matching '$pattern' found:"$'\n'"$matches"
    else
        pass "No files matching '$pattern'"
    fi
done

# ---- dev-only Composer packages must not be bundled -------------------------

DEV_PACKAGE_DIRS=(
    "vendor/phpunit"
    "vendor/wp-coding-standards"
    "vendor/woocommerce/woocommerce-sniffs"
    "vendor/yoast/phpunit-polyfills"
    "vendor/wp-phpunit"
    "vendor/dealerdirect"
    "vendor/wp-cli"
)

for dev_dir in "${DEV_PACKAGE_DIRS[@]}"; do
    if [ -d "$BUILD/$dev_dir" ]; then
        fail "Dev-only Composer package bundled: $dev_dir"
    else
        pass "Dev-only Composer package absent: $dev_dir"
    fi
done

# ---- summary ------------------------------------------------------------

echo
if [ "${#FAILURES[@]}" -eq 0 ]; then
    echo "Release audit: PASS — $BUILD is clean."
    exit 0
fi

echo "Release audit: FAIL — ${#FAILURES[@]} check(s) failed:" >&2
for failure in "${FAILURES[@]}"; do
    echo "  - $failure" >&2
done
exit 1
