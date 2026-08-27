<?php
/**
 * includes/classes/Auth.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Shared sign-in helpers used by the auth controller and the tests,
 * so the login rules live in one testable place instead of inside an HTTP
 * handler that exits.
 *
 * An identifier is a phone number or an email address (both unique on users).
 * We canonicalise it the same way on registration and on login: email lower
 * cased, phone in E.164. That is the only way "login by phone or email" can be
 * relied on.
 *
 * startSession() is the one place a signed-in session is created, for staff and
 * customers alike, so session hardening happens once and the same way.
 * -----------------------------------------------------------------------------
 */

final class Auth
{
    /**
     * Turn an identifier into [field, value], where field is 'email' or 'phone'
     * and value is the canonical stored form. Returns null when the input is
     * neither a plausible email nor a phone number.
     */
    public static function canonicalIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        if (strpos($identifier, '@') !== false) {
            return ['email', strtolower($identifier)];
        }
        $phone = Phone::normalize($identifier);
        return $phone === null ? null : ['phone', $phone];
    }

    /**
     * Find the user for an identifier, or null. The field is a server chosen
     * literal ('email' or 'phone'), and the value is bound, so this is not a
     * SQL injection surface.
     */
    public static function findByIdentifier(string $identifier): ?array
    {
        $canon = self::canonicalIdentifier($identifier);
        if ($canon === null) {
            return null;
        }
        [$field, $value] = $canon;
        return Database::one(
            'SELECT * FROM users WHERE ' . $field . ' = :v LIMIT 1',
            [':v' => $value]
        );
    }

    /** A stable, non-reversible bucket key for rate limiting by identifier. */
    public static function rateBucket(string $identifier): string
    {
        $canon = self::canonicalIdentifier($identifier);
        $value = $canon !== null ? $canon[1] : strtolower(trim($identifier));
        return sha1($value);
    }

    /** Where a signed-in user belongs: staff to admin, business to the Pro Portal, everyone else to the shop. */
    public static function landingPath(array $user): string
    {
        $type = (string) ($user['user_type'] ?? 'household');
        if ($type === 'staff') {
            return '/admin';
        }
        if ($type === 'business') {
            return '/pro';
        }
        return '/';
    }

    /**
     * Start a hardened session for a signed-in user (staff or customer). Fresh
     * session id, the identity and activation flag in the session, and the RBAC
     * set loaded (empty for a customer, real permissions for staff).
     */
    public static function startSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']        = (int) $user['id'];
        $_SESSION['user_type']      = (string) ($user['user_type'] ?? 'household');
        $_SESSION['email_verified'] = !empty($user['email_verified_at']);
        $_SESSION['first_name']     = (string) ($user['first_name'] ?? '');
        Rbac::loadFromDb((int) $user['id']);
    }

    /** Tear a session down completely: data cleared, cookie expired, session destroyed. */
    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
