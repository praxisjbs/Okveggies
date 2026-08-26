<?php
/**
 * includes/classes/Money.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Money handling. Every amount in the system is an integer number
 * of subunits (kobo). 100 kobo = 1 naira. Never a float in storage, never a
 * float in arithmetic that must be exact. This class is the ONLY place that
 * formats or parses naira. Do not hand-format money in a template.
 * -----------------------------------------------------------------------------
 */

final class Money
{
    public const SYMBOL   = '₦';
    public const CODE     = 'NGN';
    public const SUBUNITS = 100; // kobo per naira

    /**
     * Format subunits for display.
     *   Money::format(800000)        -> "₦8,000"
     *   Money::format(800050)        -> "₦8,000.50"   (kobo shown only when present)
     *   Money::format(800000, true)  -> "₦8,000.00"   (force kobo)
     *   Money::format(800000, false, false) -> "8,000" (no symbol)
     */
    public static function format(int $subunit, ?bool $withKobo = null, bool $withSymbol = true): string
    {
        $negative = $subunit < 0;
        $abs      = abs($subunit);
        $naira    = intdiv($abs, self::SUBUNITS);
        $kobo     = $abs % self::SUBUNITS;

        if ($withKobo === null) {
            $withKobo = ($kobo !== 0);
        }

        $text = number_format($naira, 0, '.', ',');
        if ($withKobo) {
            $text .= '.' . str_pad((string) $kobo, 2, '0', STR_PAD_LEFT);
        }
        if ($withSymbol) {
            $text = self::SYMBOL . $text;
        }
        return ($negative ? '-' : '') . $text;
    }

    /** Subunits to a naira float. For display maths only, never for storage. */
    public static function toNaira(int $subunit): float
    {
        return $subunit / self::SUBUNITS;
    }

    /**
     * Parse a naira value (float, int, or a string that may carry ₦, commas or
     * spaces) into integer subunits. "₦8,000" -> 800000, "8000.50" -> 800050.
     */
    public static function toSubunit($naira): int
    {
        if (is_int($naira)) {
            return $naira * self::SUBUNITS;
        }
        if (is_float($naira)) {
            return (int) round($naira * self::SUBUNITS);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $naira);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return 0;
        }
        return (int) round(((float) $clean) * self::SUBUNITS);
    }

    /**
     * Deposit amount in subunits for a percentage of a total.
     * Rounded to the nearest subunit. Money::deposit(1690000, 30) -> 507000.
     */
    public static function deposit(int $totalSubunit, float $percentage): int
    {
        if ($percentage <= 0)   return 0;
        if ($percentage >= 100) return $totalSubunit;
        return (int) round($totalSubunit * $percentage / 100);
    }

    /** Balance left after a deposit (or any part payment). Never below zero. */
    public static function balance(int $totalSubunit, int $paidSubunit): int
    {
        $left = $totalSubunit - $paidSubunit;
        return $left > 0 ? $left : 0;
    }

    /** Sum a list of subunit amounts. */
    public static function sum(array $subunits): int
    {
        $total = 0;
        foreach ($subunits as $s) {
            $total += (int) $s;
        }
        return $total;
    }

    /** A line total: quantity (may be fractional, e.g. 0.5 kg) times unit price. */
    public static function lineTotal($quantity, int $unitPriceSubunit): int
    {
        return (int) round(((float) $quantity) * $unitPriceSubunit);
    }
}
