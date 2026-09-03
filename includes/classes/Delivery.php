<?php
/**
 * includes/classes/Delivery.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery day and zone eligibility, always evaluated in
 * Africa/Lagos so a customer near midnight and the server never disagree about
 * which day it is.
 *
 * A day is offered only when three things hold: the customer type is allowed to
 * receive on that weekday, the order clears the minimum lead days, and it is
 * placed before the cutoff on the day before. A dated exception can close an
 * otherwise open day, or open a normally closed one.
 *
 * The rule itself (eligibleFromRules) is pure: it takes the rules and any
 * exception as plain arrays and holds no database, so it is unit tested in
 * scripts/tests/DeliveryTest.php. The picker loads the rules once and the
 * exceptions for the whole window once, then evaluates every candidate day in
 * memory, so rendering fourteen options is two queries, not one per day.
 * -----------------------------------------------------------------------------
 */

final class Delivery
{
    private const TZ = 'Africa/Lagos';

    /** How far ahead the picker looks for eligible days. */
    private const HORIZON_DAYS = 90;

    /**
     * The cutoff used only when a day is opened by an exception yet has no
     * weekday rule of its own to read a cutoff from. Every seeded delivery day
     * carries its own cutoff_time, so this is a rare edge, not the usual path.
     */
    private const DEFAULT_CUTOFF = '16:00';

    public static function validCutoff(string $cutoff): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff);
    }

    public static function validDate(string $date): bool
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TZ));
        return $value !== false && $value->format('Y-m-d') === $date;
    }

    /** The allowed-day rules for a customer type, keyed by ISO day of week (1..7). */
    public static function rulesByDay(string $customerType): array
    {
        $rules = [];
        $rows = Database::all(
            'SELECT day_of_week, is_active, cutoff_time, minimum_lead_days
               FROM allowed_delivery_days
              WHERE customer_type = :type
              ORDER BY day_of_week',
            [':type' => $customerType]
        );
        foreach ($rows as $row) {
            $rules[(int) $row['day_of_week']] = $row;
        }
        return $rules;
    }

    /** The active delivery zones, for the checkout area select and the admin screen. */
    public static function zonesActive(): array
    {
        return Database::all(
            'SELECT id, name, slug, area_note FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order, name'
        );
    }

    public static function zoneIsActive(array $zone): bool
    {
        return !empty($zone['is_active']);
    }

    /** Exceptions that fall between two dates, keyed by date. */
    private static function exceptionsBetween(string $from, string $to): array
    {
        $map = [];
        $rows = Database::all(
            'SELECT exception_date, is_available, reason, replacement_date
               FROM delivery_date_exceptions
              WHERE exception_date BETWEEN :from AND :to',
            [':from' => $from, ':to' => $to]
        );
        foreach ($rows as $row) {
            $map[(string) $row['exception_date']] = $row;
        }
        return $map;
    }

    /**
     * The authoritative check for one date. Used when an order is placed, where
     * one date is being confirmed, so the two small lookups are the right cost.
     */
    public static function isEligible(string $date, string $customerType, ?DateTimeImmutable $now = null): array
    {
        $rules     = self::rulesByDay($customerType);
        $exception = self::exceptionsBetween($date, $date)[$date] ?? null;
        return self::eligibleFromRules($date, $rules, $exception, $now);
    }

    /**
     * The days a customer may pick, soonest first. Loads the rules once and the
     * exceptions for the whole horizon once, then evaluates each candidate day
     * with the pure rule.
     */
    public static function nextEligibleDates(string $customerType, int $count = 14, ?DateTimeImmutable $now = null): array
    {
        $now   = self::lagosNow($now);
        $today = $now->setTime(0, 0);
        $rules = self::rulesByDay($customerType);
        $exceptions = self::exceptionsBetween(
            $today->format('Y-m-d'),
            $today->modify('+' . self::HORIZON_DAYS . ' days')->format('Y-m-d')
        );

        $out = [];
        for ($i = 0; $i < self::HORIZON_DAYS && count($out) < $count; $i++) {
            $date  = $today->modify('+' . $i . ' days')->format('Y-m-d');
            $check = self::eligibleFromRules($date, $rules, $exceptions[$date] ?? null, $now);
            if ($check['eligible']) {
                $out[] = ['date' => $date] + $check;
            }
        }
        return $out;
    }

    /**
     * The pure eligibility rule. Given a date, the weekday rules keyed by day of
     * week, and any exception for that exact date, decide whether the day can be
     * chosen and, when it cannot, why.
     */
    public static function eligibleFromRules(string $date, array $rules, ?array $exception = null, ?DateTimeImmutable $now = null): array
    {
        $now    = self::lagosNow($now);
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TZ));
        if (!$target || $target->format('Y-m-d') !== $date) {
            return ['eligible' => false, 'reason' => 'Choose a valid delivery date.'];
        }

        // A closed exception shuts the day, whatever the weekday rule says.
        if ($exception !== null && empty($exception['is_available'])) {
            return [
                'eligible'          => false,
                'reason'            => trim((string) ($exception['reason'] ?? '')) ?: 'This delivery date is not available.',
                'replacement_date'  => $exception['replacement_date'] ?? null,
            ];
        }

        $day       = (int) $target->format('N');
        $rule      = $rules[$day] ?? null;
        $ruleOpen  = $rule !== null && !empty($rule['is_active']);
        $forcedOpen = $exception !== null && !empty($exception['is_available']);

        if (!$ruleOpen && !$forcedOpen) {
            return ['eligible' => false, 'reason' => 'We do not deliver on that day for this account.'];
        }

        $lead     = (int) ($rule['minimum_lead_days'] ?? 1);
        $earliest = $now->setTime(0, 0)->modify('+' . $lead . ' days');
        if ($target < $earliest) {
            return ['eligible' => false, 'reason' => 'Pick a delivery day with enough time for us to source and pack your order.'];
        }

        $cutoff   = (string) ($rule['cutoff_time'] ?? '') !== ''
            ? (string) $rule['cutoff_time']
            : self::DEFAULT_CUTOFF;
        $previous = $target->modify('-1 day')->setTime((int) substr($cutoff, 0, 2), (int) substr($cutoff, 3, 2));
        if ($now >= $previous) {
            return ['eligible' => false, 'reason' => 'The order cutoff for that delivery day has passed. Pick a later day.'];
        }

        return ['eligible' => true, 'reason' => ''];
    }

    private static function lagosNow(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->setTimezone(new DateTimeZone(self::TZ));
    }
}
