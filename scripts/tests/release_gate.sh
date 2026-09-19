#!/usr/bin/env bash
# =============================================================================
# scripts/tests/release_gate.sh
# OK Veggies. The release-candidate gate. One command, no skips.
#
# `run_all.sh` is the developer's convenience runner: it starts what it can and
# says plainly what it skipped, which is right for a laptop and wrong for a
# release. This is the strict one. It refuses to start unless everything it
# needs is present, and it treats a suite that did not run as a failure, because
# "0 failed, 3 skipped" is not a pass.
#
# It proves, in order:
#   1. that the server really is MySQL 8, then the migration chain from empty,
#      then a second run that applies nothing;
#   2. the unit suite, and PHP and JavaScript syntax on every file we ship;
#   3. every database suite on disk, by glob, so a new suite cannot be forgotten;
#   4. every HTTP suite on disk, by glob;
#   5. that the suites cleaned up after themselves;
#   6. the brand guard;
#   7. the browser pass over the storefront, the content pages, the admin editor
#      and the role journeys;
#   8. the deployment smoke checks against the local web server;
#   9. cleanup again, after the browser fixtures are torn down.
#
# Services it starts: a mail sink, the approved Paystack stand-in, and a web
# server on the Apache-equivalent test router.
#
#   cp scripts/tests/ci.env.example .env
#   bash scripts/tests/release_gate.sh
#
# Environment:
#   OKV_TEST_PORT        default 8123
#   OKV_GATEWAY_PORT     default 8124
#   SMTP_SINK_PORT       default 2525
#   OKV_GATE_LOG_DIR     default a fresh temporary directory
#   OKV_ALLOW_ANY_DB_VERSION=yes  run against a server that is not MySQL 8
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" || exit 2

BASE_HOST="127.0.0.1"
BASE_PORT="${OKV_TEST_PORT:-8123}"
GATEWAY_PORT="${OKV_GATEWAY_PORT:-8124}"
SINK_PORT="${SMTP_SINK_PORT:-2525}"
BASE="http://$BASE_HOST:$BASE_PORT"
GATEWAY="http://$BASE_HOST:$GATEWAY_PORT"

LOG_DIR="${OKV_GATE_LOG_DIR:-$(mktemp -d /tmp/okv-release-gate.XXXXXX)}"
mkdir -p "$LOG_DIR"

passed=0
failed=0
failures=()

line() { printf '%s\n' "$1"; }
section() { printf '\n=== %s ===\n' "$1"; }

run() { # label  command...
  local label="$1"; shift
  local out="$LOG_DIR/${label//[^A-Za-z0-9_.-]/_}.log"
  "$@" >"$out" 2>&1
  local code=$?
  local summary
  summary="$(grep -E 'assertions? passed|checks passed|RELEASE MIGRATE OK|ORPHANS OK|FIXTURE TEARDOWN OK|MIGRATE OK' "$out" | tail -1)"
  if [ $code -eq 0 ]; then
    printf '  ok   %-38s %s\n' "$label" "${summary:-done}"
    passed=$((passed + 1))
  else
    printf '  FAIL %-38s %s\n' "$label" "${summary:-exit $code}"
    grep -E 'FAIL|Fatal|Uncaught|Error:|REFUSING' "$out" | head -8 | sed 's/^/         /'
    printf '         log: %s\n' "$out"
    failed=$((failed + 1))
    failures+=("$label")
  fi
}

# -----------------------------------------------------------------------------
# Preflight. Nothing is skipped, so everything needed is checked up front and a
# missing prerequisite stops the run before the first suite touches the database.
# -----------------------------------------------------------------------------
problems=()

if [ ! -f .env ]; then
  problems+=(".env is missing. Run: cp scripts/tests/ci.env.example .env")
else
  # Read .env through the same parser the per-suite guard uses, so the two
  # cannot disagree. A plain `cut -d= -f2-` keeps a trailing comment, and
  # `APP_ENV=production # live` then matches nothing and starts the run.
  env_app_env="$(php scripts/tests/lib/env_value.php APP_ENV | tr 'A-Z' 'a-z')"
  env_db_name="$(php scripts/tests/lib/env_value.php DB_NAME)"
  if [ "$env_app_env" = "production" ]; then
    problems+=("APP_ENV=production in .env. The gate writes fixture rows and will not run against production.")
  fi
  if [[ ! "$env_db_name" =~ _test$ ]] && [ "${OKV_ALLOW_DB_RESET:-}" != "yes" ]; then
    problems+=("DB_NAME is '$env_db_name'. A destructive release run needs a name ending in _test.")
  fi
fi

for tool in php node curl; do
  command -v "$tool" >/dev/null 2>&1 || problems+=("$tool is not installed.")
done

if [ ! -d node_modules/playwright ] && [ -z "${OKV_PLAYWRIGHT_PATH:-}" ]; then
  problems+=("playwright is not installed. Run: npm install && npx playwright install chromium")
  problems+=("(or point OKV_PLAYWRIGHT_PATH at a playwright module already on this machine)")
fi

php_ext_missing=()
for ext in curl pdo_mysql mbstring gd xml zip fileinfo openssl; do
  php -m 2>/dev/null | grep -qi "^$ext$" || php_ext_missing+=("$ext")
done
if [ ${#php_ext_missing[@]} -gt 0 ]; then
  problems+=("PHP extension(s) missing: ${php_ext_missing[*]}")
fi

if [ ${#problems[@]} -gt 0 ]; then
  line "[gate] REFUSING TO START. The release gate runs every suite or nothing."
  for problem in "${problems[@]}"; do
    line "       - $problem"
  done
  line "[gate] Nothing was run and nothing was written."
  exit 2
fi

# The database itself, through the application's own bootstrap.
if [ "$(php -r 'require "includes/bootstrap.php"; Database::one("SELECT 1"); fwrite(STDOUT, "OKV_DB_READY");' 2>/dev/null)" != "OKV_DB_READY" ]; then
  line "[gate] REFUSING TO START: the database in .env is not reachable."
  php -r 'require "includes/bootstrap.php"; Database::one("SELECT 1");' 2>&1 | tail -3 | sed 's/^/       /'
  exit 2
fi

# The release runs on MySQL 8. MariaDB accepts `ADD COLUMN IF NOT EXISTS`, which
# MySQL 8 rejects outright, so a chain that migrates cleanly on MariaDB can still
# fail the production deploy with a syntax error. A gate whose first section is
# titled "Fresh MySQL 8 migration" has to prove the server is one.
db_version="$(php -r 'require "includes/bootstrap.php"; $r = Database::one("SELECT VERSION() AS v"); fwrite(STDOUT, (string) ($r["v"] ?? ""));' 2>/dev/null)"
case "$db_version" in
  8.*) : ;;
  *)
    line "[gate] REFUSING TO START: the database reports '$db_version'."
    line "       The release gate proves the migration chain on MySQL 8, which is"
    line "       what production runs. MariaDB accepts DDL that MySQL 8 rejects."
    line "       Set OKV_ALLOW_ANY_DB_VERSION=yes to override this deliberately."
    [ "${OKV_ALLOW_ANY_DB_VERSION:-}" = "yes" ] || exit 2
    line "[gate] OKV_ALLOW_ANY_DB_VERSION=yes, continuing against '$db_version'."
    ;;
esac
line "[gate] server:  $db_version"

line "[gate] root:    $ROOT"
line "[gate] base:    $BASE"
line "[gate] gateway: $GATEWAY"
line "[gate] sink:    $BASE_HOST:$SINK_PORT"
line "[gate] logs:    $LOG_DIR"

# -----------------------------------------------------------------------------
# Services.
# -----------------------------------------------------------------------------
sink_pid=""; gateway_pid=""; site_pid=""

cleanup() {
  for pid in "$site_pid" "$gateway_pid" "$sink_pid"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null
  done
  wait 2>/dev/null
}
trap cleanup EXIT

wait_for_port() { # host port label
  local i
  for i in $(seq 1 80); do
    if (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null; then
      exec 3<&- 2>/dev/null
      return 0
    fi
    sleep 0.25
  done
  return 1
}

SMTP_SINK_PORT="$SINK_PORT" php scripts/tests/fake/smtp_sink.php >"$LOG_DIR/service-sink.log" 2>&1 &
sink_pid=$!

php -S "$BASE_HOST:$GATEWAY_PORT" -t scripts/tests/fake scripts/tests/fake/paystack.php >"$LOG_DIR/service-gateway.log" 2>&1 &
gateway_pid=$!

php -d display_errors=0 -S "$BASE_HOST:$BASE_PORT" -t "$ROOT" scripts/tests/public_content_router.php >"$LOG_DIR/service-site.log" 2>&1 &
site_pid=$!

service_problems=()
wait_for_port "$BASE_HOST" "$SINK_PORT"    || service_problems+=("mail sink")
wait_for_port "$BASE_HOST" "$GATEWAY_PORT" || service_problems+=("paystack stand-in")
wait_for_port "$BASE_HOST" "$BASE_PORT"    || service_problems+=("web server")
if [ ${#service_problems[@]} -gt 0 ]; then
  line "[gate] REFUSING TO RUN: could not start ${service_problems[*]}."
  tail -5 "$LOG_DIR"/service-*.log 2>/dev/null | sed 's/^/       /'
  exit 2
fi
line "[gate] services up: sink, stand-in gateway, web server"

# -----------------------------------------------------------------------------
section "1. Fresh MySQL 8 migration from zero, then idempotency"
run "db_reset (fresh + second run)" php scripts/tests/db_reset.php

# -----------------------------------------------------------------------------
section "2. Unit suite, PHP syntax and JavaScript syntax"
run "unit (run.php)" php scripts/tests/run.php

# PHP is the language this release ships. The gate linted every JavaScript file
# and none of the PHP, so a parse error in a rarely loaded page could reach a
# deploy with the gate green.
php_fail=0
php_count=0
while IFS= read -r file; do
  php_count=$((php_count + 1))
  if ! php -l "$file" >"$LOG_DIR/php-lint.log" 2>&1; then
    php_fail=$((php_fail + 1))
    printf '  FAIL %s\n' "$file"
    tail -3 "$LOG_DIR/php-lint.log" | sed 's/^/         /'
  fi
done < <(find . -name '*.php' -not -path './node_modules/*' -not -path './vendor/*' -not -path './.git/*')
if [ $php_fail -eq 0 ]; then
  printf '  ok   %-38s %s\n' "php syntax" "$php_count file(s) parsed"
  passed=$((passed + 1))
else
  printf '  FAIL %-38s %s\n' "php syntax" "$php_fail of $php_count failed"
  failed=$((failed + 1))
  failures+=("php syntax")
fi

js_fail=0
js_count=0
while IFS= read -r file; do
  js_count=$((js_count + 1))
  if ! node --check "$file" >"$LOG_DIR/js-$(basename "$file").log" 2>&1; then
    js_fail=$((js_fail + 1))
    printf '  FAIL %s\n' "$file"
    tail -3 "$LOG_DIR/js-$(basename "$file").log" | sed 's/^/         /'
  fi
done < <(find assets/js scripts -name '*.js' -not -name '*.min.js'; find scripts/tests -name '*.mjs')
if [ $js_fail -eq 0 ]; then
  printf '  ok   %-38s %s\n' "javascript syntax" "$js_count file(s) parsed"
  passed=$((passed + 1))
else
  printf '  FAIL %-38s %s\n' "javascript syntax" "$js_fail of $js_count failed"
  failed=$((failed + 1))
  failures+=("javascript syntax")
fi

# -----------------------------------------------------------------------------
# Every suite on disk, by glob. A hand-maintained list is how two suites sat in
# no runner for three milestones.
section "3. Database suites (every *_db_test.php on disk)"
db_count=0
for file in scripts/tests/*_db_test.php; do
  db_count=$((db_count + 1))
  run "$(basename "$file" .php)" php "$file"
done
[ $db_count -gt 0 ] || { printf '  FAIL no database suites found\n'; failed=$((failed + 1)); failures+=("database suites missing"); }

section "4. HTTP suites (every *_http_test.php on disk)"
http_count=0
for file in scripts/tests/*_http_test.php; do
  http_count=$((http_count + 1))
  run "$(basename "$file" .php)" php "$file"
done
[ $http_count -gt 0 ] || { printf '  FAIL no HTTP suites found\n'; failed=$((failed + 1)); failures+=("http suites missing"); }

# -----------------------------------------------------------------------------
section "5. Fixture cleanup after the database and HTTP suites"
run "fixture_orphans" php scripts/tests/fixture_orphans.php

# -----------------------------------------------------------------------------
section "6. Brand guard"
run "brand-check.sh" bash scripts/brand-check.sh

# -----------------------------------------------------------------------------
section "7. Browser pass at 390px and 1440px"
run "seed_visual_fixture" php scripts/tests/seed_visual_fixture.php
run "visual_pass.mjs" node scripts/tests/visual_pass.mjs
run "homepage_visual_test.mjs" node scripts/tests/homepage_visual_test.mjs
run "content_admin_visual_test.mjs" node scripts/tests/content_admin_visual_test.mjs
run "public_content_visual_test.mjs" php scripts/tests/public_content_visual_fixture.php
run "role_journeys.mjs" node scripts/tests/role_journeys.mjs

# -----------------------------------------------------------------------------
section "8. Deployment smoke checks against the local server"
VERIFY_BASE_URL="$BASE" run "verify.sh" bash scripts/verify.sh "$BASE"

# -----------------------------------------------------------------------------
section "9. Teardown and final cleanup proof"
OKV_FIXTURE_TEARDOWN=1 run "fixture teardown" php scripts/tests/seed_visual_fixture.php
run "fixture_orphans (after teardown)" php scripts/tests/fixture_orphans.php

# -----------------------------------------------------------------------------
printf '\n-------------------------------------------------------------\n'
printf '[gate] %d suites passed, %d failed, 0 skipped.\n' "$passed" "$failed"
if [ ${#failures[@]} -gt 0 ]; then
  printf '[gate] failing: %s\n' "${failures[*]}"
fi
printf '[gate] logs: %s\n' "$LOG_DIR"

if [ $failed -ne 0 ]; then
  printf '[gate] RELEASE GATE FAILED.\n'
  exit 1
fi
printf '[gate] RELEASE GATE PASSED with 0 failed and 0 skipped.\n'
exit 0
