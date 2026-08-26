<?php
/**
 * includes/classes/RateLimiter.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Simple per-bucket rate limiting backed by the rate_limits table.
 * Used to throttle login, OTP and password endpoints. A bucket is any string
 * you choose, for example "login:" . $ip or "otp:" . $email.
 * -----------------------------------------------------------------------------
 */

final class RateLimiter
{
    /**
     * Record a hit against a bucket. Returns true if the request is still within
     * the allowance, false if the limit has been exceeded for the current window.
     */
    public static function hit(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $db  = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + $windowSeconds);

        $stmt = $db->prepare(
            'INSERT INTO rate_limits (bucket, hits, window_started_at, expires_at)
             VALUES (:b, 1, :now, :exp)
             ON DUPLICATE KEY UPDATE
               hits              = IF(expires_at < :now2, 1, hits + 1),
               window_started_at = IF(expires_at < :now3, :now4, window_started_at),
               expires_at        = IF(expires_at < :now5, :exp2, expires_at)'
        );
        $stmt->execute([
            ':b' => $bucket, ':now' => $now, ':exp' => $exp,
            ':now2' => $now, ':now3' => $now, ':now4' => $now, ':now5' => $now, ':exp2' => $exp,
        ]);

        $row = Database::one('SELECT hits FROM rate_limits WHERE bucket = :b', [':b' => $bucket]);
        $hits = (int) ($row['hits'] ?? 0);
        return $hits <= $maxHits;
    }

    /** How many hits are recorded in the current window. */
    public static function count(string $bucket): int
    {
        $row = Database::one(
            'SELECT hits FROM rate_limits WHERE bucket = :b AND expires_at >= :now',
            [':b' => $bucket, ':now' => date('Y-m-d H:i:s')]
        );
        return (int) ($row['hits'] ?? 0);
    }

    public static function reset(string $bucket): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = :b', [':b' => $bucket]);
    }

    /** Housekeeping: drop expired windows. Call from a cron. */
    public static function gc(): int
    {
        return Database::run('DELETE FROM rate_limits WHERE expires_at < :now', [':now' => date('Y-m-d H:i:s')]);
    }
}
