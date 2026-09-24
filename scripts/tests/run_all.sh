#!/usr/bin/env bash
# =============================================================================
# scripts/tests/run_all.sh
# OK Veggies. Every suite, in one command, with one count at the end.
#
# THIS IS NOT THE RELEASE GATE. It reports skips (this line here is the whole
# difference), because on a laptop "no Chromium installed" is information, not
# a defect. A release cannot carry that meaning, so for release evidence run
# scripts/tests/release_gate.sh instead: it refuses to start unless everything
# it needs is present and a suite that did not run is a failure, never a skip.
# Both read .env through the same parser, scripts/tests/lib/env_value.php.
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
db_probe="$(php -r 'require "includes/bootstrap.php"; Database::one("SELECT 1"); fwrite(STDOUT, "OKV_DB_READY");' 2>/dev/null)"
if [ "$db_probe" = "OKV_DB_READY" ]; then
  # Every suite on disk, by glob - the same rule the release gate runs by, so
  # a suite added to scripts/tests cannot quietly exist in no runner.
  for suite_file in scripts/tests/*_db_test.php; do
    suite="$(basename "$suite_file" .php)"
    # Needs the stand-in payment gateway; it gets its own pass below.
    [ "$suite" = "refund_cancellation_db_test" ] && continue
    run "$suite" php "$suite_file"
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
  for suite_file in scripts/tests/*_http_test.php; do
    run "$(basename "$suite_file" .php)" php "$suite_file"
  done
else
  skip "all HTTP suites" "nothing answering on $BASE"
fi

echo
echo "[tests] 4. Browser pass at 390px and 1440px"
if [ ! -d node_modules/playwright ]; then
  skip "visual_pass.mjs" "playwright not installed (npm install)"
  skip "axe_suite.mjs" "playwright not installed (npm install)"
  skip "kitchen_runs_visual_test.mjs" "playwright not installed (npm install)"
  skip "motion_visual_test.mjs" "playwright not installed (npm install)"
  skip "zone_picker_visual_test.mjs" "playwright not installed (npm install)"
elif ! curl -fsS -o /dev/null --max-time 5 "$BASE/index.php" 2>/dev/null; then
  skip "visual_pass.mjs" "nothing answering on $BASE"
  skip "axe_suite.mjs" "nothing answering on $BASE"
  skip "kitchen_runs_visual_test.mjs" "nothing answering on $BASE"
  run "zone_picker_visual_test.mjs" node scripts/tests/zone_picker_visual_test.mjs
  run "motion_visual_test.mjs" node scripts/tests/motion_visual_test.mjs
else
  run "visual_pass.mjs" node scripts/tests/visual_pass.mjs
  run "homepage_visual_test.mjs" node scripts/tests/homepage_visual_test.mjs
  run "axe_suite.mjs" node scripts/tests/axe_suite.mjs
  run "content_admin_visual_test.mjs" node scripts/tests/content_admin_visual_test.mjs
  run "public_content_visual_test.mjs" php scripts/tests/public_content_visual_fixture.php
  run "role_journeys.mjs" node scripts/tests/role_journeys.mjs
  run "kitchen_runs_visual_test.mjs" node scripts/tests/kitchen_runs_visual_test.mjs
  run "zone_picker_visual_test.mjs" node scripts/tests/zone_picker_visual_test.mjs
  run "motion_visual_test.mjs" node scripts/tests/motion_visual_test.mjs
fi

echo
echo "[tests] 5. Static guards"
run "brand-check.sh" bash scripts/brand-check.sh
run "motion_coverage_test.mjs" node scripts/tests/motion_coverage_test.mjs
run "lesser_text_test.mjs" node scripts/tests/lesser_text_test.mjs
run "image_contract_test.mjs" node scripts/tests/image_contract_test.mjs
run "zone_picker_test.mjs" node scripts/tests/zone_picker_test.mjs

echo
echo "-------------------------------------------------------------"
printf '[tests] %d suites passed, %d failed, %d skipped.\n' "$passed" "$failed" "$skipped"
if [ ${#failures[@]} -gt 0 ]; then
  printf '[tests] failing: %s\n' "${failures[*]}"
fi
[ "$failed" -eq 0 ] || exit 1
echo "[tests] All green."
