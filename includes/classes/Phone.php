<?php
/**
 * includes/classes/Phone.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One place to turn a phone number, however it was typed, into a
 * single canonical form so storage, lookup and login all agree. We store
 * Nigerian numbers in E.164 (for example +2348012345678), which is also the
 * form Paystack and an SMS provider will expect later.
 *
 * People type the same number many ways: 08012345678, 0801 234 5678,
 * +234 801 234 5678, 2348012345678. All of those normalise to +2348012345678.
 * A number we cannot make sense of returns null, and the caller shows a plain
 * "enter a valid phone number" message.
 *
 * Login and registration must use this on both sides, or "login by phone" will
 * silently miss a real account whose number was stored in another shape.
 * -----------------------------------------------------------------------------
 */

final class Phone
{
    /**
     * Normalise a Nigerian phone number to E.164 (+234XXXXXXXXXX), or null when
     * the input is not a phone number we recognise.
     */
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Keep digits only. A leading "00" is the international dialling prefix,
        // treat it like a plus.
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (strncmp($digits, '00', 2) === 0) {
            $digits = substr($digits, 2);
        }

        // Reduce whatever we have to the 10 digit national number (without the
        // leading 0), then rebuild it in E.164.
        $national = null;
        if (strncmp($digits, '234', 3) === 0) {
            $rest = substr($digits, 3);
            if (strlen($rest) === 10) {
                $national = $rest;
            } elseif (strlen($rest) === 11 && $rest[0] === '0') {
                $national = substr($rest, 1);
            }
        } elseif ($digits[0] === '0') {
            $rest = substr($digits, 1);
            if (strlen($rest) === 10) {
                $national = $rest;
            }
        } elseif (strlen($digits) === 10) {
            $national = $digits;
        }

        if ($national === null || !preg_match('/^[789]\d{9}$/', $national)) {
            return null;
        }
        return '+234' . $national;
    }

    /** True if the input normalises to a valid Nigerian mobile number. */
    public static function isValid(?string $raw): bool
    {
        return self::normalize($raw) !== null;
    }

    /**
     * A friendly local form for display (0801 234 5678). Falls back to the
     * given value if it is not a number we recognise, so it is safe on any input.
     */
    public static function display(?string $raw): string
    {
        $e164 = self::normalize($raw);
        if ($e164 === null) {
            return trim((string) $raw);
        }
        $national = substr($e164, 4); // drop the +234
        return '0' . substr($national, 0, 3) . ' ' . substr($national, 3, 3) . ' ' . substr($national, 6);
    }
}
