<?php
/**
 * includes/classes/OrderNumber.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Human-readable, per-year sequence numbers for orders and kitchen
 * runs. Format: PREFIX + two-digit year + zero-padded sequence, for example
 * OKV26001. The sequence resets each year and is padded to at least three
 * digits, growing naturally beyond 999. This is the ONLY place a number is
 * built. Never hand-build one.
 * -----------------------------------------------------------------------------
 */

final class OrderNumber
{
    /** Pure formatter. OrderNumber::format('OKV', 26, 1) -> "OKV26001". */
    public static function format(string $prefix, int $year2, int $seq): string
    {
        return $prefix
            . str_pad((string) ($year2 % 100), 2, '0', STR_PAD_LEFT)
            . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /** Next order number, e.g. OKV26001. Prefix defaults to OKV. */
    public static function nextOrderNumber(PDO $db, string $prefix = 'OKV', ?int $year = null): string
    {
        $year  = $year ?? (int) date('Y');
        $year2 = $year % 100;
        $seq   = self::nextSequence($db, 'order:' . $year2);
        return self::format($prefix, $year2, $seq);
    }

    /** Next kitchen-run number, e.g. KR26001. */
    public static function nextKitchenRunNumber(PDO $db, string $prefix = 'KR', ?int $year = null): string
    {
        $year  = $year ?? (int) date('Y');
        $year2 = $year % 100;
        $seq   = self::nextSequence($db, 'kitchen_run:' . $year2);
        return self::format($prefix, $year2, $seq);
    }

    /**
     * Atomically increment and return a named counter. Uses the single-statement
     * INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID so two concurrent
     * requests can never receive the same value.
     */
    public static function nextSequence(PDO $db, string $name): int
    {
        // Wrapping the initial value in LAST_INSERT_ID(1) makes the very first
        // insert also set lastInsertId, so the sequence starts at 1, not 0.
        $stmt = $db->prepare(
            'INSERT INTO counters (name, value) VALUES (:n, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)'
        );
        $stmt->execute([':n' => $name]);
        return (int) $db->lastInsertId();
    }
}
