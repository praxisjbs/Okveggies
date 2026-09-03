<?php
/**
 * public/healthcheck.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Token-guarded payment health check for a browser, for shared
 * hosting where there is no shell.
 *
 *   https://your-site/public/healthcheck.php?token=YOUR_MIGRATE_TOKEN
 *
 * Answers one question: can this server take a payment right now. Every check
 * is a read. Nothing here takes a payment, writes a row or changes a setting.
 *
 * Security matches public/migrate.php exactly, and reuses the same token so
 * there is no second secret to keep:
 *   - Fails closed. No MIGRATE_TOKEN in .env means 404.
 *   - A wrong or missing token returns 404 and never reveals that it exists.
 *   - Compared with hash_equals.
 *   - Prefer the X-Migrate-Token header so the secret stays out of access logs.
 *
 * Plain text on purpose. This page has to be readable at the exact moment the
 * application is too broken to render anything else, so it loads the bootstrap
 * inside a try and reports a failure there as its first finding rather than
 * dying on it.
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

echo "OK Veggies payment health check\n";
echo str_repeat('=', 60), "\n";
echo date('c'), "\n\n";

// The bootstrap is loaded here, inside a try, because a class missing from its
// require list is one of the things this page exists to catch.
try {
    require_once $root . '/includes/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAIL  the application could not start\n";
    echo "      " . $e->getMessage() . "\n";
    echo "      " . $e->getFile() . ':' . $e->getLine() . "\n\n";
    echo "Nothing else could be checked. Fix this first.\n";
    exit;
}

if (!class_exists('PaymentHealth')) {
    http_response_code(500);
    echo "FAIL  PaymentHealth is not loaded, so this server is running an older deploy.\n";
    echo "      Deploy the current branch, then reload this page.\n";
    exit;
}

$sections = PaymentHealth::run();
$failures = PaymentHealth::failureCount($sections);

$symbols = ['ok' => 'ok  ', 'fail' => 'FAIL', 'warn' => 'WARN', 'note' => 'note'];

foreach ($sections as $title => $checks) {
    echo $title, "\n";
    foreach ($checks as $check) {
        printf(
            "  %s  %s%s\n",
            $symbols[$check['state']] ?? '?   ',
            $check['label'],
            $check['detail'] !== '' ? '  (' . $check['detail'] . ')' : ''
        );
    }
    echo "\n";
}

// Taking real money is exactly when nobody should have to guess which keys are
// loaded, so the mode is stated on its own, loudly, at the end.
echo str_repeat('=', 60), "\n";
if (class_exists('Paystack') && (string) env('PAYSTACK_SECRET_KEY', '') !== '') {
    if (Paystack::domain() === 'live') {
        echo "LIVE MODE. Payments made here move real money.\n";
        echo "The webhook URL must be set in Paystack's LIVE settings, not test.\n";
    } else {
        echo "TEST MODE. No real money moves. Paystack test cards only.\n";
        echo "The webhook URL must be set in Paystack's TEST settings.\n";
    }
} else {
    echo "No Paystack key is loaded, so no payment can be taken.\n";
}
echo str_repeat('=', 60), "\n\n";

if ($failures === 0) {
    echo "All clear. This server can take a payment.\n";
    exit;
}
http_response_code(500);
echo $failures . " check(s) failed. Fix these before taking a payment.\n";
