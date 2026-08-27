#!/usr/bin/env bash
# =============================================================================
# scripts/smoke_roles.sh
# OK Veggies. Role smoke test for M1 staff auth. Starts a local PHP server and
# checks the whole flow over HTTP:
#   - the first-Owner setup endpoint (fail closed, create once, refuse twice),
#   - a guest hitting /admin/ is redirected to sign in,
#   - the Owner signs in and reaches the dashboard and Users and Roles,
#   - the Manager signs in, is refused Users and Roles (403), and never sees it
#     in the navigation,
#   - the login rate-limit locks an identifier after five failed tries.
#
# Needs the scratch database migrated and the app .env in place.
#   bash scripts/smoke_roles.sh
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${PORT:-8123}"
BASE="http://127.0.0.1:${PORT}"
SETUP="$(grep -E '^SETUP_TOKEN=' "$ROOT/.env" | head -1 | cut -d= -f2-)"

OWNER_EMAIL="owner@okveggies.com.ng"; OWNER_PW="owner-strong-777"; OWNER_PH="08030000001"
MGR_EMAIL="manager@okveggies.com.ng"; MGR_PW="manager-strong-777";  MGR_PH="08030000002"

fail=0
pass() { echo "  ok   $1"; }
bad()  { echo "  FAIL $1"; fail=1; }

jar() { mktemp; }
OJAR="$(jar)"; MJAR="$(jar)"; SJAR="$(jar)"; RJAR="$(jar)"

# GET a page (keeping cookies) and pull its CSRF token out of the form.
csrf_from() { # jar url
  curl -s -c "$1" -b "$1" "$2" \
    | grep -oE 'name="okv_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/'
}
code_of() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

# --- Clean the staff slate so the setup endpoint has work to do ---------------
mysql -h 127.0.0.1 -u okv -pokv_test_pw okveggies_test \
  -e "DELETE FROM users WHERE user_type='staff'; DELETE FROM rate_limits;" 2>/dev/null

# --- Start the local server --------------------------------------------------
php -S 127.0.0.1:"${PORT}" -t "$ROOT" >/tmp/okv_smoke_server.log 2>&1 &
SRV=$!
trap 'kill "$SRV" 2>/dev/null; rm -f "$OJAR" "$MJAR" "$SJAR" "$RJAR"' EXIT
for _ in $(seq 1 30); do
  [ "$(code_of "$BASE/admin/login.php")" != "000" ] && break
  sleep 0.3
done
if [ "$(code_of "$BASE/admin/login.php")" = "000" ]; then echo "[smoke] server did not start"; exit 2; fi
echo "[smoke] base: $BASE"

echo "[setup] first-Owner endpoint"
[ "$(code_of "$BASE/public/setup.php")" = "404" ] && pass "no token is a 404" || bad "no token should be 404"
[ "$(code_of "$BASE/public/setup.php?token=wrong")" = "404" ] && pass "a wrong token is a 404" || bad "wrong token should be 404"

setup_form="$(curl -s -c "$SJAR" -b "$SJAR" "$BASE/public/setup.php?token=${SETUP}")"
echo "$setup_form" | grep -q "Create the Owner" && pass "the right token shows the form" || bad "the right token should show the form"
scsrf="$(echo "$setup_form" | grep -oE 'name="okv_csrf" value="[^"]+"' | head -1 | sed -E 's/.*value="([^"]+)".*/\1/')"

created="$(curl -s -c "$SJAR" -b "$SJAR" \
  --data-urlencode "token=${SETUP}" --data-urlencode "okv_csrf=${scsrf}" \
  --data-urlencode "first_name=Kumbish" --data-urlencode "last_name=Putleh" \
  --data-urlencode "email=${OWNER_EMAIL}" --data-urlencode "phone=${OWNER_PH}" \
  --data-urlencode "password=${OWNER_PW}" --data-urlencode "confirm_password=${OWNER_PW}" \
  "$BASE/public/setup.php")"
echo "$created" | grep -q "Owner account created" && pass "the Owner is created" || bad "the Owner should be created"
[ "$(code_of "$BASE/public/setup.php?token=${SETUP}")" = "409" ] && pass "setup refuses once an Owner exists" || bad "setup should refuse the second time"

echo "[login] Owner"
ocsrf="$(csrf_from "$OJAR" "$BASE/admin/login.php")"
olog="$(curl -s -c "$OJAR" -b "$OJAR" -w $'\n%{http_code}' \
  -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=${OWNER_EMAIL}" \
  --data-urlencode "password=${OWNER_PW}" --data-urlencode "okv_csrf=${ocsrf}" \
  "$BASE/api/v1/auth.php")"
ocode="$(printf '%s' "$olog" | tail -1)"; obody="$(printf '%s' "$olog" | sed '$d')"
{ [ "$ocode" = "200" ] && echo "$obody" | grep -q '"status":"ok"'; } && pass "Owner signs in" || bad "Owner sign in failed ($ocode)"

odash="$(curl -s -c "$OJAR" -b "$OJAR" "$BASE/admin/")"
echo "$odash" | grep -q "Dashboard" && pass "Owner reaches the dashboard" || bad "Owner should reach the dashboard"
echo "$odash" | grep -q "<span>Users</span>" && pass "Owner sees Users in the nav" || bad "Owner should see Users in the nav"
ousers="$(curl -s -c "$OJAR" -b "$OJAR" "$BASE/admin/users.php")"
{ echo "$ousers" | grep -q "Add a staff member" && echo "$ousers" | grep -q "</html>"; } \
  && pass "Owner Users and Roles renders fully" || bad "Owner Users and Roles should render fully (not truncate mid-page)"

echo "[create] Owner adds the Manager through the API"
ucsrf="$(csrf_from "$OJAR" "$BASE/admin/users.php")"
mk="$(curl -s -c "$OJAR" -b "$OJAR" -w $'\n%{http_code}' \
  -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=create" --data-urlencode "first_name=Manny" --data-urlencode "last_name=Gerard" \
  --data-urlencode "email=${MGR_EMAIL}" --data-urlencode "phone=${MGR_PH}" \
  --data-urlencode "password=${MGR_PW}" --data-urlencode "role=manager" --data-urlencode "okv_csrf=${ucsrf}" \
  "$BASE/api/v1/users.php")"
mkcode="$(printf '%s' "$mk" | tail -1)"
[ "$mkcode" = "201" ] && pass "Owner creates the Manager" || bad "Owner should create the Manager ($mkcode)"

echo "[guest] no session"
[ "$(code_of "$BASE/admin/")" = "302" ] && pass "guest at /admin/ is redirected" || bad "guest at /admin/ should redirect"
[ "$(code_of "$BASE/admin/users.php")" = "302" ] && pass "guest at Users is redirected" || bad "guest at Users should redirect"

echo "[login] Manager"
mcsrf="$(csrf_from "$MJAR" "$BASE/admin/login.php")"
mlog="$(curl -s -c "$MJAR" -b "$MJAR" -w $'\n%{http_code}' \
  -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=${MGR_EMAIL}" \
  --data-urlencode "password=${MGR_PW}" --data-urlencode "okv_csrf=${mcsrf}" \
  "$BASE/api/v1/auth.php")"
mcode="$(printf '%s' "$mlog" | tail -1)"; mbody="$(printf '%s' "$mlog" | sed '$d')"
{ [ "$mcode" = "200" ] && echo "$mbody" | grep -q '"status":"ok"'; } && pass "Manager signs in" || bad "Manager sign in failed ($mcode)"

mdash="$(curl -s -c "$MJAR" -b "$MJAR" "$BASE/admin/")"
echo "$mdash" | grep -q "<span>Orders</span>" && pass "Manager sees Orders in the nav" || bad "Manager should see Orders in the nav"
echo "$mdash" | grep -q "<span>Users</span>" && bad "Manager should NOT see Users in the nav" || pass "Manager does not see Users in the nav"
[ "$(code_of -c "$MJAR" -b "$MJAR" "$BASE/admin/users.php")" = "403" ] && pass "Manager is refused Users and Roles (403)" || bad "Manager should get 403 on Users"

mmk="$(curl -s -c "$MJAR" -b "$MJAR" -w $'\n%{http_code}' \
  -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=create" --data-urlencode "first_name=X" --data-urlencode "last_name=Y" \
  --data-urlencode "email=x@okveggies.com.ng" --data-urlencode "phone=08030000009" \
  --data-urlencode "password=some-strong-9999" --data-urlencode "role=manager" --data-urlencode "okv_csrf=${mcsrf}" \
  "$BASE/api/v1/users.php")"
mmkcode="$(printf '%s' "$mmk" | tail -1)"
[ "$mmkcode" = "403" ] && pass "Manager cannot create staff through the API (403)" || bad "Manager create should be 403 ($mmkcode)"

echo "[account] Owner changes own password"
oacct="$(curl -s -c "$OJAR" -b "$OJAR" "$BASE/admin/account.php")"
{ echo "$oacct" | grep -q "Change your password" && echo "$oacct" | grep -q "</html>"; } \
  && pass "Owner account page renders fully" || bad "Owner account page should render fully"
acsrf="$(csrf_from "$OJAR" "$BASE/admin/account.php")"
NEWPW="owner-brandnew-4242"
chg="$(curl -s -c "$OJAR" -b "$OJAR" -w $'\n%{http_code}' -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=change_password" --data-urlencode "current_password=${OWNER_PW}" \
  --data-urlencode "new_password=${NEWPW}" --data-urlencode "confirm_password=${NEWPW}" \
  --data-urlencode "okv_csrf=${acsrf}" "$BASE/api/v1/auth.php")"
[ "$(printf '%s' "$chg" | tail -1)" = "200" ] && pass "Owner changes their password" || bad "change password should succeed"

NJAR="$(jar)"; ncsrf="$(csrf_from "$NJAR" "$BASE/admin/login.php")"
newcode="$(curl -s -c "$NJAR" -b "$NJAR" -o /dev/null -w '%{http_code}' -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=${OWNER_EMAIL}" \
  --data-urlencode "password=${NEWPW}" --data-urlencode "okv_csrf=${ncsrf}" "$BASE/api/v1/auth.php")"
[ "$newcode" = "200" ] && pass "the new password signs in" || bad "the new password should sign in ($newcode)"

OJAR2="$(jar)"; ocsrf2="$(csrf_from "$OJAR2" "$BASE/admin/login.php")"
oldcode="$(curl -s -c "$OJAR2" -b "$OJAR2" -o /dev/null -w '%{http_code}' -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=${OWNER_EMAIL}" \
  --data-urlencode "password=${OWNER_PW}" --data-urlencode "okv_csrf=${ocsrf2}" "$BASE/api/v1/auth.php")"
[ "$oldcode" = "401" ] && pass "the old password no longer works" || bad "the old password should fail ($oldcode)"

echo "[throttle] login lockout"
rcsrf="$(csrf_from "$RJAR" "$BASE/admin/login.php")"
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -c "$RJAR" -b "$RJAR" -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
    --data-urlencode "action=login" --data-urlencode "identifier=locktest@okveggies.com.ng" \
    --data-urlencode "password=wrong-guess-${i}" --data-urlencode "okv_csrf=${rcsrf}" \
    "$BASE/api/v1/auth.php"
done
sixth="$(curl -s -c "$RJAR" -b "$RJAR" -w $'\n%{http_code}' -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=locktest@okveggies.com.ng" \
  --data-urlencode "password=wrong-guess-6" --data-urlencode "okv_csrf=${rcsrf}" \
  "$BASE/api/v1/auth.php")"
scode="$(printf '%s' "$sixth" | tail -1)"; sbody="$(printf '%s' "$sixth" | sed '$d')"
{ [ "$scode" = "429" ] && echo "$sbody" | grep -q '"code":"rate_limited"'; } && pass "identifier locks after 5 failed tries" || bad "6th try should be rate limited ($scode)"

echo "[logout] ends the session"
LJAR="$(jar)"; lcsrf="$(csrf_from "$LJAR" "$BASE/admin/login.php")"
curl -s -o /dev/null -c "$LJAR" -b "$LJAR" -H 'X-Requested-With: fetch' -H 'Accept: application/json' \
  --data-urlencode "action=login" --data-urlencode "identifier=${OWNER_EMAIL}" \
  --data-urlencode "password=${NEWPW}" --data-urlencode "okv_csrf=${lcsrf}" "$BASE/api/v1/auth.php"
before="$(code_of -c "$LJAR" -b "$LJAR" "$BASE/admin/")"
locsrf="$(csrf_from "$LJAR" "$BASE/admin/")"
curl -s -o /dev/null -c "$LJAR" -b "$LJAR" \
  --data-urlencode "action=logout" --data-urlencode "okv_csrf=${locsrf}" "$BASE/api/v1/auth.php"
after="$(code_of -c "$LJAR" -b "$LJAR" "$BASE/admin/")"
{ [ "$before" = "200" ] && [ "$after" = "302" ]; } \
  && pass "logout ends the session (dashboard then redirect)" || bad "logout should end the session (before=$before after=$after)"

echo ""
if [ "$fail" -eq 0 ]; then echo "[smoke] all green."; else echo "[smoke] failures above."; fi
exit $fail
