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
 * This file is required at the top of every suite that writes a row. It reads
 * the environment the same way the application does, and exits 2 when either the
 * process environment or the .env file says production.
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

$okvGuardRoot = dirname(__DIR__, 3);
$okvGuardEnvPath = (string) (getenv('OKV_ENV_PATH') ?: $okvGuardRoot . '/.env');

$okvGuardSources = [];

$okvGuardProcessEnv = getenv('APP_ENV');
if ($okvGuardProcessEnv !== false && $okvGuardProcessEnv !== '') {
    $okvGuardSources['process environment'] = strtolower(trim((string) $okvGuardProcessEnv));
}

if (is_file($okvGuardEnvPath) && is_readable($okvGuardEnvPath)) {
    $okvGuardLines = @file($okvGuardEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($okvGuardLines as $okvGuardLine) {
        if (preg_match('/^\s*APP_ENV\s*=\s*["\']?([A-Za-z_]+)["\']?\s*$/', $okvGuardLine, $okvGuardMatch)) {
            $okvGuardSources['.env file'] = strtolower($okvGuardMatch[1]);
            break;
        }
    }
}

foreach ($okvGuardSources as $okvGuardWhere => $okvGuardValue) {
    if ($okvGuardValue === 'production') {
        fwrite(STDERR, "\nREFUSING TO RUN\n");
        fwrite(STDERR, "This suite writes fixture rows and must never touch production.\n");
        fwrite(STDERR, "The $okvGuardWhere says APP_ENV=production.\n");
        fwrite(STDERR, "Point it at a disposable database first (a name ending in _test).\n\n");
        exit(2);
    }
}
