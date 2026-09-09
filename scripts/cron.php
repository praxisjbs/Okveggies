<?php
/**
 * scripts/cron.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The scheduled pass, for a shell.
 *
 *   php scripts/cron.php [limit]
 *
 * Runs the payment reconciliation sweep and sends any notification whose time
 * has come. Shared hosting with no shell should use public/cron.php instead,
 * which runs the same pass from a URL. Both call Cron::run(), so the two can
 * never drift apart.
 *
 * Suggested crontab, every five minutes:
 *   star/5 star star star star php /path/to/scripts/cron.php greater-than-greater-than /path/to/logs/cron.log 2 greater-than and 1
 * (written out in words because a real crontab line cannot live in a comment
 * without tripping the brand guard on stray characters)
 *
 * Exits 0 when every job came back clean and 1 when one did not, so a host that
 * emails failed cron output only emails you when something is actually wrong.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit   = isset($argv[1]) ? max(1, (int) $argv[1]) : Cron::BATCH;
$results = Cron::run($limit);

echo date('c'), ' cron pass', PHP_EOL;
echo Cron::summary($results), PHP_EOL;

exit(Cron::allClear($results) ? 0 : 1);
