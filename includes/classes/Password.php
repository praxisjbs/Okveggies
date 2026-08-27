<?php
/**
 * includes/classes/Password.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One place to hash, verify and check the strength of a password.
 * Staff use it now (milestone M1); customer accounts reuse it in Part 2, so the
 * policy lives here and nowhere else.
 *
 * Hashing is bcrypt through PHP's password_hash, with the cost read from
 * BCRYPT_COST in .env. Verification is password_verify against
 * users.password_hash. Never store or compare a plain password anywhere else.
 *
 * Policy (PRD Section 10.1: a minimum length and not a common password):
 *   - at least PASSWORD_MIN_LENGTH characters (default 10),
 *   - not one of the most common passwords,
 *   - not the person's own email or phone,
 *   - at most 72 bytes, because bcrypt silently ignores anything past that.
 * Length carries the weight here; we do not force a mix of character types,
 * which pushes people towards predictable patterns (Passw0rd!) and is weaker.
 * -----------------------------------------------------------------------------
 */

final class Password
{
    /** A short list of the passwords attackers try first. Compared lower-cased. */
    private const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', 'qwerty', 'qwerty123',
        'asdfghjkl', '12345678', '123456789', '1234567890', '111111', '000000',
        'iloveyou', 'admin', 'admin123', 'administrator', 'letmein', 'welcome',
        'welcome1', 'monkey', 'dragon', 'football', 'abc12345', 'okveggies',
        'okveggies1', 'changeme', 'default', 'secret', 'sunshine', 'princess',
        'trustno1', 'whatever', 'superman', 'baseball', 'starwars', 'lagos123',
    ];

    /** Hash a plain password with bcrypt at the configured cost. */
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /** True if the plain password matches the stored hash. */
    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /** True if the stored hash should be re-made at the current cost. */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /** The configured minimum length, floored at 8 so it can never be set weak. */
    public static function minLength(): int
    {
        $min = (int) env('PASSWORD_MIN_LENGTH', 10);
        return max(8, $min);
    }

    /**
     * Check a candidate password against the policy. Returns null when it is
     * fine, or a plain, ready-to-show message explaining what to change. Pass the
     * email and phone so a password cannot simply repeat them.
     */
    public static function policyError(string $plain, string $email = '', string $phone = ''): ?string
    {
        $min = self::minLength();

        if (mb_strlen($plain) < $min) {
            return 'Use at least ' . $min . ' characters.';
        }
        if (strlen($plain) > 72) {
            return 'That password is too long. Use at most 72 characters.';
        }

        $lower = strtolower(trim($plain));
        if ($lower === '' ) {
            return 'Use at least ' . $min . ' characters.';
        }
        if (in_array($lower, self::COMMON, true)) {
            return 'That password is too common. Pick something harder to guess.';
        }
        if ($email !== '' && $lower === strtolower(trim($email))) {
            return 'Your password cannot be your email address.';
        }
        if ($phone !== '' && $lower === strtolower(trim($phone))) {
            return 'Your password cannot be your phone number.';
        }
        return null;
    }

    /** True if the password passes the policy. */
    public static function isAcceptable(string $plain, string $email = '', string $phone = ''): bool
    {
        return self::policyError($plain, $email, $phone) === null;
    }

    /** bcrypt cost from .env, clamped to a sane range. */
    private static function cost(): int
    {
        $cost = (int) env('BCRYPT_COST', 12);
        if ($cost < 10) { $cost = 10; }
        if ($cost > 14) { $cost = 14; }
        return $cost;
    }
}
