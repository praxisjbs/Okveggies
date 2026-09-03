<?php
/**
 * scripts/payment_healthcheck.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The payment health check, for a shell.
 *
 *   php scripts/payment_healthcheck.php
 *
 * Shared hosting with no shell should use public/healthcheck.php instead, which
 * runs the same checks from a browser. Both call PaymentHealth so the two
 * answers can never disagree. Exits 0 when clear and 1 when not, so this one
 * can gate a deploy.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sections = PaymentHealth::run();
$failures = PaymentHealth::failureCount($sections);
$symbols  = ['ok' => 'ok  ', 'fail' => 'FAIL', 'warn' => 'WARN', 'note' => 'note'];

echo PHP_EOL, 'OK Veggies payment health check', PHP_EOL, PHP_EOL;
foreach ($sections as $title => $checks) {
    echo $title, PHP_EOL;
    foreach ($checks as $check) {
        printf(
            "  %s  %s%s" . PHP_EOL,
            $symbols[$check['state']] ?? '?   ',
            $check['label'],
            $check['detail'] !== '' ? '  (' . $check['detail'] . ')' : ''
        );
    }
    echo PHP_EOL;
}

if ((string) env('PAYSTACK_SECRET_KEY', '') !== '') {
    echo Paystack::domain() === 'live'
        ? 'LIVE MODE. Payments made here move real money.' . PHP_EOL . PHP_EOL
        : 'TEST MODE. No real money moves.' . PHP_EOL . PHP_EOL;
}

if ($failures === 0) {
    echo 'All clear. This server can take a payment.', PHP_EOL, PHP_EOL;
    exit(0);
}
echo $failures . ' check(s) failed. Fix these before taking a payment.', PHP_EOL, PHP_EOL;
exit(1);
