<?php
/**
 * public/cron.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Token-guarded scheduled pass for a URL, for shared hosting where
 * there is no shell.
 *
 *   https://your-site/public/cron.php?token=YOUR_MIGRATE_TOKEN
 *
 * A cPanel cron job calling curl on this address does everything a real cron
 * would: it reconciles hanging Paystack transactions and sends any notification
 * whose time has come. See docs/DEPLOYMENT.md for the exact cron line.
 *
 * Security matches public/migrate.php and public/healthcheck.php exactly, and
 * reuses the same token so there is no third secret to keep:
 *   - Fails closed. No MIGRATE_TOKEN in .env means 404.
 *   - A wrong or missing token returns 404 and never reveals that it exists.
 *   - Compared with hash_equals.
 *   - Prefer the X-Migrate-Token header so the secret stays out of access logs.
 *
 * Plain text, and never indexed. It changes data, so it is deliberately not
 * something a search engine or a stray visitor can set off.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__);
require_once $root . '/includes/config/env.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$expected = (string) env('MIGRATE_TOKEN', '');
$given    = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? ($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($expected === '' || !is_string($given) || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo "Not found.\n";
    exit;
}

require_once $root . '/includes/bootstrap.php';

$limit   = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : Cron::BATCH;
$results = Cron::run($limit);

echo 'OK Veggies cron pass', "\n";
echo date('c'), "\n\n";
echo Cron::summary($results), "\n\n";

if (!Cron::allClear($results)) {
    http_response_code(500);
    echo "CRON FAILED\n";
    exit;
}
echo "CRON OK\n";
