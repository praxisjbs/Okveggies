#!/usr/bin/env php
<?php
/**
 * scripts/tests/db_reset.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Proves the migration chain from zero and proves it twice.
 *
 * Requirement 1 of the release gate is a fresh MySQL 8 migration from nothing.
 * Requirement 2 is a second run that applies nothing. Both were recorded by
 * hand in every milestone and neither was ever automated, so "the second run
 * reported nothing to apply" was a sentence in a progress log rather than a
 * check that can fail a build.
 *
 * It refuses to run anywhere it could do harm:
 *   - APP_ENV=production is refused outright;
 *   - the database name must end in `_test`, or OKV_ALLOW_DB_RESET=yes must be
 *     set deliberately for a differently named disposable database.
 *
 * Every table in the target schema is dropped, so this is a destructive tool.
 * That is why the two guards above exist and why the name rule is a suffix
 * rather than a substring.
 *
 *   php scripts/tests/db_reset.php
 *
 * Exit codes: 0 proved, 1 a check failed, 2 refused or misconfigured.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This runs from the CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/env.php';
require_once $root . '/includes/classes/Migrator.php';

$env = strtolower(trim((string) env('APP_ENV', 'production')));
if ($env === 'production') {
    fwrite(STDERR, "REFUSING TO RUN: APP_ENV=production. This drops every table.\n");
    exit(2);
}

$dbName = trim((string) env('DB_NAME', ''));
$allowed = (bool) preg_match('/_test$/', $dbName) || strtolower((string) getenv('OKV_ALLOW_DB_RESET')) === 'yes';
if (!$allowed) {
    fwrite(STDERR, "REFUSING TO RUN: DB_NAME is '$dbName'.\n");
    fwrite(STDERR, "A destructive reset needs a database whose name ends in _test,\n");
    fwrite(STDERR, "or OKV_ALLOW_DB_RESET=yes set deliberately.\n");
    exit(2);
}

$dir = $root . '/migrations';
if (!is_dir($dir)) {
    fwrite(STDERR, "No migrations/ directory.\n");
    exit(2);
}

try {
    $pdo = Migrator::connectFromEnv();
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(2);
}

echo "[migrate] target database: $dbName\n";

// --- 1. Empty the database ------------------------------------------------
$tables = $pdo->query(
    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN);

echo '[migrate] dropping ' . count($tables) . " existing table(s) for a true fresh start\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$remaining = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();
if ($remaining !== 0) {
    fwrite(STDERR, "FAIL: $remaining table(s) survived the drop.\n");
    exit(1);
}

// --- 2. First run: from zero ---------------------------------------------
$first = [];
$t0 = microtime(true);
try {
    Migrator::apply($pdo, $dir, false, function (string $m) use (&$first) { $first[] = $m; });
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: the fresh migration run threw: ' . $e->getMessage() . "\n");
    exit(1);
}
$firstMs = (int) round((microtime(true) - $t0) * 1000);
$appliedFirst = count(array_filter($first, static fn(string $l): bool => str_starts_with($l, 'applied ')));
$expected = count(Migrator::files($dir));

echo "[migrate] fresh run applied $appliedFirst of $expected migration file(s) in {$firstMs} ms\n";
if ($appliedFirst !== $expected) {
    fwrite(STDERR, "FAIL: expected $expected migrations on an empty database, applied $appliedFirst.\n");
    foreach ($first as $line) {
        fwrite(STDERR, "       $line\n");
    }
    exit(1);
}

// Nothing may be left pending once the first run finishes.
$pending = 0;
foreach (Migrator::status($pdo, $dir) as [$state, $version, $file]) {
    if ($state !== 'OK') {
        $pending++;
        fwrite(STDERR, "FAIL: $version is $state after the fresh run.\n");
    }
}
if ($pending > 0) {
    exit(1);
}

// --- 3. Second run: idempotency ------------------------------------------
$second = [];
try {
    Migrator::apply($pdo, $dir, false, function (string $m) use (&$second) { $second[] = $m; });
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: the second migration run threw: ' . $e->getMessage() . "\n");
    exit(1);
}
$appliedSecond = count(array_filter($second, static fn(string $l): bool => str_starts_with($l, 'applied ')));
echo "[migrate] second run applied $appliedSecond migration(s)\n";
if ($appliedSecond !== 0) {
    fwrite(STDERR, "FAIL: the second run was not idempotent, it applied $appliedSecond.\n");
    foreach ($second as $line) {
        fwrite(STDERR, "       $line\n");
    }
    exit(1);
}

// The tracking table must hold exactly one row per migration file.
$recorded = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
echo "[migrate] schema_migrations holds $recorded row(s) for $expected file(s)\n";
if ($recorded !== $expected) {
    fwrite(STDERR, "FAIL: $recorded recorded against $expected files.\n");
    exit(1);
}

// --- 4. The tables the release actually needs must exist -----------------
$required = ['users', 'orders', 'order_items', 'payments', 'payment_transactions', 'refunds',
             'credit_transactions', 'kitchen_run_requests', 'issue_reports', 'content_pages',
             'notifications', 'notification_deliveries', 'audit_logs', 'site_settings'];
$missing = [];
foreach ($required as $table) {
    $found = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '
        . $pdo->quote($table)
    )->fetchColumn();
    if ($found !== 1) {
        $missing[] = $table;
    }
}
$tableCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();
echo "[migrate] $tableCount table(s) present; all " . count($required) . " required tables checked\n";
if ($missing) {
    fwrite(STDERR, 'FAIL: missing table(s) after migration: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "\nRELEASE MIGRATE OK: $expected migrations applied from zero in {$firstMs} ms, second run applied 0, $tableCount tables present.\n";
exit(0);
