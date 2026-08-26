#!/usr/bin/env php
<?php
/**
 * scripts/migrate.php
 * -----------------------------------------------------------------------------
 * OK Veggies. CLI migration runner. A thin wrapper around Migrator (the shared
 * engine). Use this on a machine with shell access (local dev, or a host that
 * offers SSH). On a shell-less shared host, migrations run through
 * public/migrate.php instead, called by the deploy workflow.
 *
 *   php scripts/migrate.php          apply pending
 *   php scripts/migrate.php --status show applied vs pending
 *   php scripts/migrate.php --force  re-apply even if a checksum changed
 *   php scripts/migrate.php --dry    show what would run, do not execute
 *
 * Exit codes: 0 success, 1 a migration failed, 2 config / usage error.
 * -----------------------------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI. On a shell-less host use public/migrate.php.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config/env.php';
require_once $root . '/includes/classes/Migrator.php';

$args       = array_slice($argv, 1);
$flagForce  = in_array('--force',  $args, true);
$flagDry    = in_array('--dry',    $args, true);
$flagStatus = in_array('--status', $args, true);

$dir = $root . '/migrations';
if (!is_dir($dir)) { fwrite(STDERR, "No migrations/ directory.\n"); exit(2); }

try {
    $pdo = Migrator::connectFromEnv();
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(2);
}

if ($flagStatus || $flagDry) {
    printf("%-8s  %s\n", 'STATE', 'VERSION');
    foreach (Migrator::status($pdo, $dir) as [$state, $ver, $file]) {
        printf("%-8s  %s\n", $state, $ver);
    }
    if ($flagDry) { fwrite(STDOUT, "[migrate] --dry: no changes made.\n"); }
    exit(0);
}

try {
    Migrator::apply($pdo, $dir, $flagForce, function (string $m) {
        fwrite(STDOUT, "[migrate] $m\n");
    });
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] ' . $e->getMessage() . "\n");
    exit(1);
}
