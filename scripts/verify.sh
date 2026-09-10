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

expect_status() { # url  expected_code  label   (does not follow redirects)
  code=$(curl -s -o /dev/null -w "%{http_code}" "$1")
  if [ "$code" = "$2" ]; then echo "  ok   [$code] $3"; else echo "  FAIL [$code, wanted $2] $3"; fail=1; fi
}

expect_login() { # url  label   (a redirect to the login, or a hard refusal)
  code=$(curl -s -o /dev/null -w "%{http_code}" "$1")
  case "$code" in
    301|302|303|307|401|403) echo "  ok   [$code] $2 asks for a login" ;;
    *) echo "  FAIL [$code] $2 should ask for a login"; fail=1 ;;
  esac
}

expect_private() { # url  label   (login/refusal, with 404 allowed to avoid existence disclosure)
  code=$(curl -s -o /dev/null -w "%{http_code}" "$1")
  case "$code" in
    301|302|303|307|401|403|404) echo "  ok   [$code] $2 fails closed" ;;
    *) echo "  FAIL [$code] $2 should fail closed"; fail=1 ;;
  esac
}

expect "$BASE/"               "200" "storefront home"
expect "$BASE/admin/login.php" "200" "admin login page"

# Brand chrome must actually serve after a deploy.
expect "$BASE/favicon.ico"                                  "200" "favicon.ico"
expect "$BASE/site.webmanifest"                             "200" "web manifest"
expect "$BASE/assets/img/brand/lockup.svg"                  "200" "logo lockup"
expect "$BASE/assets/img/brand/icons/apple-touch-icon.png" "200" "apple touch icon"
expect "$BASE/assets/fonts/hanken-grotesk-latin.woff2"      "200" "brand font (Hanken Grotesk)"
expect "$BASE/assets/img/payments/paystack.svg"             "200" "Paystack checkout mark"
expect "$BASE/page.php?slug=delivery-policy#make-it-right" "200" "Delivery Policy and Make It Right guidance"
# M6 routes. A staff screen must send a signed-out visitor to the login rather
# than answering, and a trail token that does not exist must be a clean 404
# rather than a 500. expect_login is separate from expect_deny on purpose: an
# admin screen redirects, a file that should never be served does not.
expect "$BASE/public/order.php?token=not-a-real-token" "404" "public Order Trail refuses a bad token"
expect_login "$BASE/admin/delivery-manifest.php" "the day manifest"
expect_login "$BASE/admin/orders.php"            "the orders screen"

# The scheduled pass runs the payment sweep and sends due reminders, so it has
# to be reachable by the cron job and refuse everybody else. No token, 404.
expect "$BASE/public/cron.php"                   "404" "the cron endpoint fails closed without a token"
expect "$BASE/public/cron.php?token=not-a-real-token" "404" "and refuses a wrong token without admitting it exists"

# M9 contact. The contact page must serve. The one public write on the platform
# never answers a GET: a person or a crawler that follows a link to it is sent
# to the form, and only a POST can put a row in the table.
expect "$BASE/contact.php" "200" "the contact page"
expect_status "$BASE/api/v1/contact.php" "303" "the contact endpoint sends a GET to the form"
expect_login "$BASE/admin/content.php" "the messages screen"

# M10 customer reporting. Writes require an authenticated POST, and a GET must
# fail without touching a report or disclosing an order.
expect_status "$BASE/api/v1/make_it_right.php" "405" "the Make It Right endpoint refuses a GET"
expect_private "$BASE/public/order.php?order=1" "the private customer order view"
expect_login "$BASE/admin/make_it_right.php" "the Make It Right staff queue"
expect "$BASE/public/issue_photo.php?photo=0" "404" "a missing private issue photo"

expect_deny "$BASE/.env"                 ".env"
expect_deny "$BASE/includes/config/db.php" "includes/"
expect_deny "$BASE/migrations/001_core_schema.sql" "migrations/"
expect_deny "$BASE/docs/PRD.md"          "docs/"

[ $fail -eq 0 ] && echo "[verify] all green." || { echo "[verify] failures above."; exit 1; }
