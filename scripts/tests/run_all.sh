#!/usr/bin/env bash
# =============================================================================
# scripts/tests/run_all.sh
# OK Veggies. Every suite, in one command, with one count at the end.
#
# The unit suite needs nothing. The database suites need a migrated scratch
# database in .env. The HTTP suites need the site answering, and the refund
# suite needs the stand-in gateway. This starts what it can and says plainly
# what it skipped, so a green line here means the same thing every time.
#
#   php scripts/migrate.php
#   bash scripts/tests/run_all.sh
#
# SCRATCH DATABASES ONLY. Every suite writes rows.
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" || exit 2

BASE="${OKV_TEST_BASE:-http://127.0.0.1:8123}"
GATEWAY="${PAYSTACK_TEST_GATEWAY:-http://127.0.0.1:8124}"

passed=0
failed=0
skipped=0
failures=()

run() { # label  command...
  local label="$1"; shift
  local output
  output="$("$@" 2>&1)"
  local code=$?
  local summary
  summary="$(printf '%s\n' "$output" | grep -E 'assertions? passed|checks passed' | tail -1)"
  if [ $code -eq 0 ]; then
    printf '  ok   %-34s %s\n' "$label" "${summary:-done}"
    passed=$((passed + 1))
  else
    printf '  FAIL %-34s %s\n' "$label" "${summary:-see below}"
    printf '%s\n' "$output" | grep -E 'FAIL|Fatal|Uncaught' | head -6 | sed 's/^/         /'
    failed=$((failed + 1))
    failures+=("$label")
  fi
}

skip() { printf '  skip %-34s %s\n' "$1" "$2"; skipped=$((skipped + 1)); }

echo "[tests] 1. Unit suite (no database needed)"
run "run.php" php scripts/tests/run.php

echo
echo "[tests] 2. Database suites (MySQL 8 from .env)"
if php -r 'require "includes/bootstrap.php"; Database::one("SELECT 1");' >/dev/null 2>&1; then
  for suite in \
    auth_db_test basket_db_test cancellation_db_test checkout_db_test combos_db_test \
    credit_admin_db_test credit_customer_db_test credit_orders_db_test \
    customer_auth_db_test customers_db_test delivery_db_test kitchen_lists_db_test \
    kitchen_runs_db_test manifest_db_test notifications_db_test order_lifecycle_db_test \
    payments_db_test pricing_db_test pro_dashboard_db_test pro_orders_db_test \
    settings_db_test staff_password_reset_db_test
  do
    run "$suite" php "scripts/tests/$suite.php"
  done

  if curl -fsS -o /dev/null --max-time 3 "$GATEWAY/refund" -d '{}' 2>/dev/null; then
    run "refund_cancellation_db_test" php scripts/tests/refund_cancellation_db_test.php
  else
    skip "refund_cancellation_db_test" "no stand-in gateway on $GATEWAY"
  fi
else
  skip "all database suites" "no database reachable from .env"
fi

echo
echo "[tests] 3. HTTP suites (the site answering on $BASE)"
if curl -fsS -o /dev/null --max-time 5 "$BASE/index.php" 2>/dev/null; then
  for suite in \
    cancellation_http_test credit_checkout_http_test customer_http_test delivery_http_test \
    kitchen_runs_http_test order_lifecycle_http_test order_trail_http_test settings_http_test
  do
    run "$suite" php "scripts/tests/$suite.php"
  done
else
  skip "all HTTP suites" "nothing answering on $BASE"
fi

echo
echo "[tests] 4. Browser pass at 390px and 1440px"
if [ ! -d node_modules/playwright ]; then
  skip "visual_pass.mjs" "playwright not installed (npm install)"
elif ! curl -fsS -o /dev/null --max-time 5 "$BASE/index.php" 2>/dev/null; then
  skip "visual_pass.mjs" "nothing answering on $BASE"
else
  run "visual_pass.mjs" node scripts/tests/visual_pass.mjs
fi

echo
echo "[tests] 5. Static guards"
run "brand-check.sh" bash scripts/brand-check.sh

echo
echo "-------------------------------------------------------------"
printf '[tests] %d suites passed, %d failed, %d skipped.\n' "$passed" "$failed" "$skipped"
if [ ${#failures[@]} -gt 0 ]; then
  printf '[tests] failing: %s\n' "${failures[*]}"
fi
[ "$failed" -eq 0 ] || exit 1
echo "[tests] All green."
