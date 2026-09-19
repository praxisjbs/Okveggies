<?php
/**
 * scripts/tests/lib/scratch_guard.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Refuses to run a fixture-writing suite against production.
 *
 * Every database and HTTP suite in this folder creates users, orders, payments,
 * roles and settings. They are written for a disposable database, and pointed at
 * the live one they would write test customers into a real shop. Until now only
 * `seed_visual_fixture.php` carried a guard, so 58 suites could be run against
 * production by accident.
 *
 * This file is required at the top of every suite that writes a row. It applies
 * the same two rules the release gate applies, so a suite run by hand is no
 * weaker than a suite run in a batch:
 *
 *   1. APP_ENV must not be production; and
 *   2. DB_NAME must end in `_test`, unless OKV_ALLOW_DB_RESET=yes says the
 *      operator means it.
 *
 * It fails closed. An APP_ENV nobody set, an unreadable .env or a value that
 * cannot be parsed all count as production, because the one situation a guard
 * must never produce is "I could not tell, so I let it through". That matches
 * `db_reset.php`, `fixture_orphans.php` and `seed_visual_fixture.php`, which all
 * default APP_ENV to production for the same reason.
 *
 * The .env parse rules live in `scripts/tests/lib/env_value.php`, so this guard
 * and the release gate read the file the same way.
 *
 * Two guards, deliberately: this one protects a suite invoked directly by a
 * person, and `scripts/tests/release_gate.sh` carries the same check so a batch
 * run stops before the first suite starts.
 * -----------------------------------------------------------------------------
 */

if (defined('OKV_SCRATCH_GUARDED')) {
    return;
}
define('OKV_SCRATCH_GUARDED', true);

require_once __DIR__ . '/env_value.php';

$okvGuardEnvPath = okv_test_env_path();

$okvGuardRefuse = static function (string $reason, string $remedy): void {
    fwrite(STDERR, "\nREFUSING TO RUN\n");
    fwrite(STDERR, "This suite writes fixture rows and must never touch production.\n");
    fwrite(STDERR, $reason . "\n");
    fwrite(STDERR, $remedy . "\n\n");
    exit(2);
};

// --- 1. APP_ENV --------------------------------------------------------------
$okvGuardEnv = okv_test_env_value('APP_ENV');
if ($okvGuardEnv === null || $okvGuardEnv === '') {
    $okvGuardRefuse(
        'APP_ENV is not set in the process environment or in ' . $okvGuardEnvPath . '.',
        'Set APP_ENV explicitly. A guard that cannot tell treats it as production.'
    );
}
if (strtolower((string) $okvGuardEnv) === 'production') {
    $okvGuardRefuse(
        'APP_ENV is production.',
        'Point it at a disposable database first (a name ending in _test).'
    );
}

// --- 2. DB_NAME --------------------------------------------------------------
// The release gate refuses a database whose name does not end in _test. A suite
// run by hand writes the same rows, so it carries the same rule and the same
// deliberate override.
$okvGuardDb = okv_test_env_value('DB_NAME');
$okvGuardOverride = strtolower(trim((string) getenv('OKV_ALLOW_DB_RESET'))) === 'yes';
if (!$okvGuardOverride && !preg_match('/_test$/', (string) $okvGuardDb)) {
    $okvGuardRefuse(
        'DB_NAME is ' . ($okvGuardDb === null || $okvGuardDb === '' ? 'not set' : "'" . $okvGuardDb . "'") . '.',
        'A fixture-writing suite needs a database whose name ends in _test,' . "\n"
        . 'or OKV_ALLOW_DB_RESET=yes set deliberately for a differently named disposable one.'
    );
}

unset($okvGuardEnvPath, $okvGuardRefuse, $okvGuardEnv, $okvGuardDb, $okvGuardOverride);
