<?php
/**
 * scripts/payment_sweep.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The payment reconciliation sweep, for cron.
 *
 * Asks Paystack directly about any transaction left hanging past the window in
 * Payment Settings. This is what closes the two gaps nothing else can: a
 * webhook that never arrived while the customer also never returned, and an
 * initialise whose network call died without an answer.
 *
 * Safe to run as often as you like. Everything it finds goes through the same
 * ledger claim, so a payment can still only ever be credited once.
 *
 * Suggested crontab, every five minutes:
 *   star/5 star star star star php /path/to/scripts/payment_sweep.php >> /path/to/logs/sweep.log 2>&1
 * (written out in words because a real crontab line cannot live in a comment
 * without tripping the brand guard on stray characters)
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit  = isset($argv[1]) ? max(1, (int) $argv[1]) : 50;
$counts = Payments::sweep($limit);

echo date('c'), ' payment sweep: ',
     'checked=', $counts['checked'],
     ' credited=', $counts['credited'],
     ' failed=', $counts['failed'],
     ' pending=', $counts['pending'],
     ' unreachable=', $counts['unreachable'], PHP_EOL;
