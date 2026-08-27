<?php
/**
 * includes/classes/Customer.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The signed-in customer, read from the session. Customers are
 * household or business account types (never staff). Staff use Rbac; this class
 * is the customer side, used by the account area, the activation flow and the
 * account controller.
 *
 * A customer can sign in and shop straight away. Activation (a verified email)
 * is only required before a pay-on-delivery order, so isActivated() gates that
 * one flow later, not sign in or browsing.
 * -----------------------------------------------------------------------------
 */

final class Customer
{
    /** The account types that are customers, not staff. */
    public const TYPES = ['household', 'business'];

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id'])
            && in_array((string) ($_SESSION['user_type'] ?? ''), self::TYPES, true);
    }

    public static function id(): ?int
    {
        return self::isLoggedIn() ? (int) $_SESSION['user_id'] : null;
    }

    public static function type(): ?string
    {
        return self::isLoggedIn() ? (string) $_SESSION['user_type'] : null;
    }

    public static function isBusiness(): bool
    {
        return self::type() === 'business';
    }

    public static function isActivated(): bool
    {
        return self::isLoggedIn() && !empty($_SESSION['email_verified']);
    }

    public static function firstName(): string
    {
        return self::isLoggedIn() ? (string) ($_SESSION['first_name'] ?? '') : '';
    }

    /** Mark the session activated (after a successful OTP verify). */
    public static function markActivated(): void
    {
        if (self::isLoggedIn()) {
            $_SESSION['email_verified'] = true;
        }
    }

    /** The current customer's row, or null. Reads the database, so use sparingly. */
    public static function current(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        return Database::one(
            'SELECT id, first_name, last_name, email, phone, user_type, status, email_verified_at, created_at
               FROM users WHERE id = :id',
            [':id' => $id]
        );
    }

    /** For a page: send a guest to sign in. */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            okv_redirect('/account.php?mode=signin');
        }
    }

    /** For an API action: stop with a 401 JSON body if not a signed-in customer. */
    public static function requireLoginApi(): void
    {
        if (!self::isLoggedIn()) {
            okv_error('Please sign in first.', 401, 'unauthenticated');
        }
    }
}
