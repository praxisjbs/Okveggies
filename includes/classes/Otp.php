<?php
/**
 * includes/classes/Otp.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One-time codes for account activation (email now, SMS later).
 * Activation is required before a customer can place a pay-on-delivery order.
 * Codes are six digits, stored hashed, single use, and time limited.
 * -----------------------------------------------------------------------------
 */

final class Otp
{
    public const TTL_SECONDS = 900; // 15 minutes

    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Issue a code for an identifier (email or phone). Any earlier unused code
     * for the same identifier and purpose is cleared first. Returns the plain
     * code so the caller can send it by email or SMS.
     */
    public static function issue(string $identifier, string $channel = 'email', string $purpose = 'account_activation', ?int $userId = null, int $ttl = self::TTL_SECONDS): string
    {
        Database::run(
            'DELETE FROM otp_verifications WHERE identifier = :id AND purpose = :p AND consumed_at IS NULL',
            [':id' => $identifier, ':p' => $purpose]
        );
        $code = self::generateCode();
        Database::run(
            'INSERT INTO otp_verifications (user_id, identifier, channel, purpose, code_hash, expires_at)
             VALUES (:u, :id, :ch, :p, :h, :exp)',
            [
                ':u' => $userId, ':id' => $identifier, ':ch' => $channel, ':p' => $purpose,
                ':h' => password_hash($code, PASSWORD_DEFAULT),
                ':exp' => date('Y-m-d H:i:s', time() + $ttl),
            ]
        );
        return $code;
    }

    /**
     * Verify a code. Returns true and marks it used on success. Counts attempts
     * and refuses once the per-code attempt cap is passed.
     */
    public static function verify(string $identifier, string $purpose, string $code): bool
    {
        $row = Database::one(
            'SELECT * FROM otp_verifications
              WHERE identifier = :id AND purpose = :p AND consumed_at IS NULL AND expires_at >= :now
              ORDER BY id DESC LIMIT 1',
            [':id' => $identifier, ':p' => $purpose, ':now' => date('Y-m-d H:i:s')]
        );
        if (!$row) {
            return false;
        }
        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            return false;
        }
        if (password_verify($code, $row['code_hash'])) {
            Database::run('UPDATE otp_verifications SET consumed_at = :now WHERE id = :id',
                [':now' => date('Y-m-d H:i:s'), ':id' => $row['id']]);
            return true;
        }
        Database::run('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id', [':id' => $row['id']]);
        return false;
    }
}
