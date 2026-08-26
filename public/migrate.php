<?php
/**
 * public/migrate.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Token-guarded web migration runner, for shared hosting with no
 * shell. The deploy workflow calls this with a secret token after uploading the
 * files, so migrations apply on the server without SSH. It can also be hit from
 * a browser with ?token=... during a manual first deploy.
 *
 * Security:
 *   - Fails closed: if MIGRATE_TOKEN is not set in .env, this returns 404.
 *   - Wrong or missing token returns 404 (does not reveal that it exists).
 *   - The token is compared with hash_equals. Prefer the X-Migrate-Token header
 *     over ?token= so the secret does not land in access logs.
 *   - Idempotent: it only applies migrations not already recorded.
 * Set a strong MIGRATE_TOKEN in the server .env: openssl rand -hex 32
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__);
require_once $root . '/includes/config/env.php';
require_once $root . '/includes/classes/Migrator.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$expected = (string) env('MIGRATE_TOKEN', '');
$given = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? ($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($expected === '' || !is_string($given) || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo "Not found.\n";
    exit;
}

$action = $_GET['action'] ?? 'migrate';
$dir    = $root . '/migrations';

try {
    $pdo = Migrator::connectFromEnv();

    if ($action === 'status') {
        foreach (Migrator::status($pdo, $dir) as [$state, $ver, $file]) {
            printf("%-8s  %s\n", $state, $ver);
        }
        echo "STATUS OK\n";
        exit;
    }

    $force = isset($_GET['force']);
    Migrator::apply($pdo, $dir, $force, function (string $m) {
        echo $m . "\n";
        @ob_flush(); @flush();
    });
    echo "MIGRATE OK\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('web migrate failed: ' . $e->getMessage());
    // This endpoint is token-gated (effectively admin only), so surfacing the
    // migration error here is what makes a failed deploy diagnosable.
    echo 'MIGRATE FAILED: ' . $e->getMessage() . "\n";
}
