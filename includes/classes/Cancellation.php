<?php
/**
 * includes/classes/Cancellation.php
 * -----------------------------------------------------------------------------
 * OK Veggies. When an order may be cancelled, by whom, and what happens to the
 * money. See docs/PRD.md Section 9 and Section 11.
 *
 * The cancellation flow and its screens are M6, where the order detail lives.
 * This class is the policy those screens read, built here in M5 because every
 * question it answers is a money question and money belongs in this milestone.
 * M6 wires it to buttons; it does not get to reinvent the rules.
 *
 * The cutoff is deliberately its own setting rather than the delivery cutoff.
 * They start equal and mean different things: the delivery cutoff is about
 * scheduling a van, this one is about the point at which the produce has been
 * bought from a farmer and a cancellation costs real stock. They will drift.
 *
 * Everything here is pure: no database, no session, no clock of its own unless
 * one is passed. Unit tested in scripts/tests/CancellationTest.php.
 * -----------------------------------------------------------------------------
 */

final class Cancellation
{
    private const TZ = 'Africa/Lagos';

    /**
     * The moment after which a customer can no longer cancel unaided: the
     * cutoff time on the day BEFORE the delivery date, in Lagos time, so a
     * customer near midnight and the server never disagree.
     */
    public static function deadline(string $deliveryDate, string $cutoffTime): ?DateTimeImmutable
    {
        $zone = new DateTimeZone(self::TZ);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $deliveryDate, $zone);
        if ($date === false) {
            return null;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $cutoffTime, $m)) {
            return null;
        }
        return $date->modify('-1 day')->setTime((int) $m[1], (int) $m[2]);
    }

    /** Whether there is still time to cancel unaided. */
    public static function isWithinCutoff(string $deliveryDate, string $cutoffTime, ?DateTimeImmutable $now = null): bool
    {
        $deadline = self::deadline($deliveryDate, $cutoffTime);
        if ($deadline === null) {
            // An unreadable date or time is not a licence to cancel late.
            return false;
        }
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ)))
            ->setTimezone(new DateTimeZone(self::TZ));
        return $now < $deadline;
    }

    /** Order statuses past which nothing is cancellable, whatever the clock says. */
    public const CLOSED_STATUSES = ['delivered', 'cancelled', 'refunded'];

    /**
     * Whether the customer may cancel this order themselves.
     *
     * Unpaid and before the cutoff has cost the business nothing, so making
     * someone wait on a human to undo something free is friction that loses the
     * next order. Once money or produce is committed it stops being self
     * service and becomes a staff decision.
     */
    public static function customerMayCancel(
        string $orderStatus,
        string $paymentStatus,
        bool $withinCutoff,
        bool $selfServeAllowed
    ): bool {
        if (!$selfServeAllowed) {
            return false;
        }
        if (in_array($orderStatus, self::CLOSED_STATUSES, true)) {
            return false;
        }
        if ($paymentStatus !== Payments::STATUS_UNPAID) {
            return false;
        }
        return $withinCutoff;
    }

    /** Whether staff may cancel it. Wider, but not unlimited. */
    public static function staffMayCancel(string $orderStatus): bool
    {
        return !in_array($orderStatus, self::CLOSED_STATUSES, true);
    }

    /**
     * What happens to money already paid when an order is cancelled.
     *
     * Before the cutoff everything goes back. After it, a deposit may be kept
     * if the business has said so at checkout, because a deposit whose only
     * outcome is a full refund is not a deposit and will not protect anyone the
     * week four crates are cancelled on the morning of delivery. Anything paid
     * beyond the deposit always goes back: the business is protecting its
     * committed cost, not keeping the whole order.
     */
    public static function moneyOutcome(
        int $paidSubunit,
        int $depositSubunit,
        bool $withinCutoff,
        bool $forfeitAfterCutoff
    ): array {
        $paid = max(0, $paidSubunit);
        if ($paid === 0) {
            return ['refund_subunit' => 0, 'forfeit_subunit' => 0, 'reason' => 'nothing_paid'];
        }
        if ($withinCutoff || !$forfeitAfterCutoff) {
            return ['refund_subunit' => $paid, 'forfeit_subunit' => 0, 'reason' => 'full_refund'];
        }

        $forfeit = min($paid, max(0, $depositSubunit));
        return [
            'refund_subunit'  => $paid - $forfeit,
            'forfeit_subunit' => $forfeit,
            'reason'          => $forfeit > 0 ? 'deposit_kept' : 'full_refund',
        ];
    }

    /**
     * The line the customer reads at checkout, so the rule is never a surprise
     * afterwards. Numerals, units, no jargon.
     */
    public static function policyLine(string $cutoffTime, bool $forfeitAfterCutoff): string
    {
        $when = 'You can cancel free up to ' . $cutoffTime . ' the day before your delivery.';
        if (!$forfeitAfterCutoff) {
            return $when . ' After that, ask us and we will still return anything you have paid.';
        }
        return $when . ' After that we have already bought your produce, so a deposit is not returned.';
    }

    /** The same rule, said to staff, with the figures filled in. */
    public static function staffSummary(array $outcome): string
    {
        if ($outcome['forfeit_subunit'] > 0) {
            return 'Refund ' . Money::format($outcome['refund_subunit'])
                 . ' and keep ' . Money::format($outcome['forfeit_subunit']) . ' as the deposit.';
        }
        if ($outcome['refund_subunit'] > 0) {
            return 'Refund ' . Money::format($outcome['refund_subunit']) . ' in full.';
        }
        return 'Nothing has been paid, so there is nothing to refund.';
    }
}
