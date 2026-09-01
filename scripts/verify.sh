#!/usr/bin/env bash
# =============================================================================
# scripts/verify.sh
# OK Veggies. Post-deploy smoke tests. Checks that the site answers and that the
# server-only paths are denied. Pass the base URL as the first argument or set
# VERIFY_BASE_URL; defaults to the APP_URL in .env.
#   bash scripts/verify.sh https://okveggies.com.ng
# =============================================================================
set -uo pipefail

APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="${1:-${VERIFY_BASE_URL:-}}"
if [ -z "$BASE" ] && [ -f "$APP_ROOT/.env" ]; then
  BASE="$(grep -E '^APP_URL=' "$APP_ROOT/.env" | head -1 | cut -d= -f2-)"
fi
BASE="${BASE%/}"
if [ -z "$BASE" ]; then echo "[verify] no base URL"; exit 2; fi
echo "[verify] base: $BASE"

fail=0
expect() { # url  expected_code  label
  code=$(curl -s -o /dev/null -w "%{http_code}" -L "$1")
  if [ "$code" = "$2" ]; then echo "  ok   [$code] $3"; else echo "  FAIL [$code, wanted $2] $3"; fail=1; fi
}
expect_deny() { # url  label   (403 or 404 both acceptable)
  code=$(curl -s -o /dev/null -w "%{http_code}" "$1")
  if [ "$code" = "403" ] || [ "$code" = "404" ]; then echo "  ok   [$code] $2 is denied"; else echo "  FAIL [$code] $2 should be denied"; fail=1; fi
}

expect "$BASE/"               "200" "storefront home"
expect "$BASE/admin/login.php" "200" "admin login page"

# Brand chrome must actually serve after a deploy.
expect "$BASE/favicon.ico"                                  "200" "favicon.ico"
expect "$BASE/site.webmanifest"                             "200" "web manifest"
expect "$BASE/assets/img/brand/lockup.svg"                  "200" "logo lockup"
expect "$BASE/assets/img/brand/icons/apple-touch-icon.png" "200" "apple touch icon"
expect "$BASE/assets/fonts/hanken-grotesk-latin.woff2"      "200" "brand font (Hanken Grotesk)"
expect_deny "$BASE/.env"                 ".env"
expect_deny "$BASE/includes/config/db.php" "includes/"
expect_deny "$BASE/migrations/001_core_schema.sql" "migrations/"
expect_deny "$BASE/docs/PRD.md"          "docs/"

[ $fail -eq 0 ] && echo "[verify] all green." || { echo "[verify] failures above."; exit 1; }
