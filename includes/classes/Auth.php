<?php
/**
 * includes/classes/Auth.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Shared sign-in and identity helpers used by the auth controller,
 * every account-creation path, and the tests, so the identity rules live in one
 * testable place instead of being copy-pasted across HTTP handlers.
 *
 * One real person is one identity. An identifier is a phone number or an email
 * address (both unique on users), and every path that stores or looks one up
 * canonicalises it the same way: email lower cased, phone in E.164. Staff
 * access is a role on that one identity (user_roles), never a second row, so
 * "login by phone or email" can be relied on and nothing ever has to choose
 * between two rows for the same person.
 *
 * startSession() is the one place a signed-in session is created, for staff and
 * customers alike, so session hardening happens once and the same way.
 * -----------------------------------------------------------------------------
 */

final class Auth
{
    /**
     * The canonical stored form of an email: trimmed and lower cased. Returns
     * null when the input is not a plausible email address. Every path that
     * stores or matches an email goes through this, so a person who types
     * Ada@Example.com and ADA@example.com is one account, not two.
     */
    public static function canonicalEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $email;
    }

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
     * Every user row matching an identifier. Zero or one is the normal answer:
     * every stored identifier is canonical and unique. Two rows means the data
     * invariant is broken (pre-migration history or a hand edit); the caller
     * must fail closed, never pick one.
     */
    public static function findAllByIdentifier(string $identifier): array
    {
        $canon = self::canonicalIdentifier($identifier);
        if ($canon === null) {
            return [];
        }
        [$field, $value] = $canon;
        // The field is a server-chosen literal ('email' or 'phone'), and the
        // value is bound, so this is not a SQL injection surface.
        return Database::all('SELECT * FROM users WHERE ' . $field . ' = :v', [':v' => $value]);
    }

    /**
     * Find the user for an identifier, or null. When two rows share one
     * canonical identifier the lookup refuses and logs a repair alarm, because
     * silently choosing one would sign a person into the wrong account.
     */
    public static function findByIdentifier(string $identifier): ?array
    {
        $rows = self::findAllByIdentifier($identifier);
        if (count($rows) === 1) {
            return $rows[0];
        }
        if (count($rows) > 1) {
            self::logIdentityConflict($identifier, count($rows));
        }
        return null;
    }

    /**
     * Field-specific duplicate check shared by every path that creates or edits
     * an identity (registration, staff creation, customer creation, guest
     * checkout, profile edit, first Owner setup). Returns null when the email
     * and phone are both free, otherwise:
     *   ['field' => 'email'|'phone'|'both',
     *    'email_user_id' => ?int, 'phone_user_id' => ?int]
     * $excludeUserId is for edits, so a row never collides with itself.
     */
    public static function findIdentityConflict(string $email, string $phone, ?int $excludeUserId = null): ?array
    {
        $emailUserId = self::matchingUserId('email', $email, $excludeUserId);
        $phoneUserId = self::matchingUserId('phone', $phone, $excludeUserId);
        if ($emailUserId === null && $phoneUserId === null) {
            return null;
        }
        $field = $emailUserId !== null && $phoneUserId !== null
            ? 'both'
            : ($emailUserId !== null ? 'email' : 'phone');
        return ['field' => $field, 'email_user_id' => $emailUserId, 'phone_user_id' => $phoneUserId];
    }

    /**
     * The id of a user stored under this canonical value, or null. The field is
     * a server-chosen literal and the value is bound. When more than one row
     * matches, the invariant is broken: the alarm is logged and the first id is
     * still reported, so creation flows refuse rather than add a third row.
     */
    private static function matchingUserId(string $field, string $value, ?int $excludeUserId): ?int
    {
        if ($value === '') {
            return null;
        }
        $sql    = 'SELECT id FROM users WHERE ' . $field . ' = :v';
        $params = [':v' => $value];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :x';
            $params[':x'] = $excludeUserId;
        }
        $rows = Database::all($sql, $params);
        if (count($rows) > 1) {
            self::logIdentityConflict($value, count($rows), $field);
        }
        return $rows === [] ? null : (int) $rows[0]['id'];
    }

    /** A server-side repair alarm. The caller sees a plain generic message. */
    private static function logIdentityConflict(string $identifier, int $count, ?string $fieldHint = null): void
    {
        $canon = self::canonicalIdentifier($identifier);
        $field = $fieldHint ?? ($canon !== null ? $canon[0] : 'identifier');
        $fingerprint = substr(sha1($canon !== null ? $canon[1] : strtolower(trim($identifier))), 0, 12);
        error_log(
            'identity conflict: ' . $count . ' users share one canonical ' . $field
            . ' (fingerprint ' . $fingerprint . '). Look in user_identity_conflicts and repair before this identifier is safe.'
        );
    }

    /** True when the user holds at least one role. Roles are what make staff. */
    public static function userHasStaffRole(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $row = Database::one('SELECT COUNT(*) AS c FROM user_roles WHERE user_id = :u', [':u' => $userId]);
        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * True when a user row is staff: it holds a role, or it carries the legacy
     * staff account type. A customer who is also staff is one row with a
     * household or business account type plus a role, so both halves matter.
     */
    public static function isStaffUser(array $user): bool
    {
        if ((string) ($user['user_type'] ?? '') === 'staff') {
            return true;
        }
        return self::userHasStaffRole((int) ($user['id'] ?? 0));
    }

    /** A stable, non-reversible bucket key for rate limiting by identifier. */
    public static function rateBucket(string $identifier): string
    {
        $canon = self::canonicalIdentifier($identifier);
        $value = $canon !== null ? $canon[1] : strtolower(trim($identifier));
        return sha1($value);
    }

    /**
     * Where a signed-in user belongs. Staff first: one person is one identity,
     * so a team member who is also a customer lands on the admin panel, and the
     * storefront stays one tap away. Business customers go to the Pro Portal,
     * everyone else to the shop.
     */
    public static function landingPath(array $user): string
    {
        $type = (string) ($user['user_type'] ?? 'household');
        if ($type === 'staff') {
            return '/admin';
        }
        if ((int) ($user['id'] ?? 0) > 0 && self::isStaffUser($user)) {
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
        // The password marker this session logged in under. A later password
        // change moves users.password_changed_at on, and the staff gate signs
        // any session holding an older marker out. Missing on a row that did not
        // select it (a fresh registration) is fine: it reads as no change.
        $_SESSION['pwd_epoch']      = (string) ($user['password_changed_at'] ?? '');
        Rbac::loadFromDb((int) $user['id']);

        // Fold this browser's guest basket into the account now that the
        // customer is signed in. A no-op when there is no guest basket, and its
        // failure must never block a sign-in, so it is logged, not thrown.
        try {
            Basket::mergeGuestIntoAccount((int) $user['id']);
        } catch (Throwable $e) {
            error_log('basket merge on sign-in failed: ' . $e->getMessage());
        }
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
