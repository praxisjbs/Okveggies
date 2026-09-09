<?php
/**
 * includes/classes/Cron.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The scheduled pass: everything the shop has to do on a clock
 * rather than in answer to somebody clicking something.
 *
 * Two jobs today, and both of them close a gap the customer would otherwise
 * fall into:
 *
 *   1. The payment reconciliation sweep. A customer who paid and then closed
 *      the tab before Paystack sent us anything leaves a paid transaction we
 *      have not credited. The sweep asks Paystack directly and settles it.
 *   2. Due notifications. The one reminder an unpaid online order gets is
 *      written at checkout and sent from here when its time comes.
 *
 * Safe to run as often as you like, and safe to run twice at once. The sweep
 * credits through the same ledger claim as the webhook, so a payment can still
 * only be credited once, and a notification is only ever sent by the pass that
 * flips its row out of the queue.
 *
 * Driven two ways, because the shop runs on hosting with no shell:
 *   scripts/cron.php   for a real cron on a host that has one
 *   public/cron.php    for a cPanel cron calling curl, or an external pinger
 * Both call run(). See docs/DEPLOYMENT.md for the cPanel setup.
 * -----------------------------------------------------------------------------
 */

final class Cron
{
    /** How many rows one pass will touch per job. */
    public const BATCH = 50;

    /**
     * Run the scheduled pass. Never throws: one job failing must not stop the
     * other, and a cron that dies is a cron nobody notices has died.
     *
     * @return array<int, array{job: string, ok: bool, detail: string}>
     */
    public static function run(int $limit = self::BATCH): array
    {
        $limit = max(1, min(200, $limit));
        return [
            self::job('payment sweep', static function () use ($limit): string {
                $counts = Payments::sweep($limit);
                return 'checked=' . $counts['checked']
                     . ' credited=' . $counts['credited']
                     . ' failed=' . $counts['failed']
                     . ' pending=' . $counts['pending']
                     . ' unreachable=' . $counts['unreachable'];
            }),
            self::job('due notifications', static function () use ($limit): string {
                $counts = Notifications::flushDue($limit);
                return 'due=' . $counts['due']
                     . ' sent=' . $counts['sent']
                     . ' skipped=' . $counts['skipped']
                     . ' failed=' . $counts['failed'];
            }),
        ];
    }

    /** Run one job and report what happened, whether or not it worked. */
    private static function job(string $name, callable $work): array
    {
        try {
            return ['job' => $name, 'ok' => true, 'detail' => (string) $work()];
        } catch (Throwable $e) {
            error_log('Cron: ' . $name . ' failed: ' . $e->getMessage());
            return ['job' => $name, 'ok' => false, 'detail' => 'failed, see the error log'];
        }
    }

    /** One line per job, for a log file or a browser. */
    public static function summary(array $results): string
    {
        $lines = [];
        foreach ($results as $result) {
            $lines[] = ($result['ok'] ? 'ok   ' : 'FAIL ') . $result['job'] . ': ' . $result['detail'];
        }
        return implode(PHP_EOL, $lines);
    }

    /** True when every job in a pass came back clean, so a caller can exit 1. */
    public static function allClear(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result['ok']) {
                return false;
            }
        }
        return true;
    }
}
