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
     * Stages at which a customer may still cancel unaided. Once an order is
     * packed, a crate has been filled with somebody's name on it and a driver's
     * round has been built around it, so undoing it stops being a form submit
     * and becomes a conversation. Before this, the order exists only as a row.
     */
    public const SELF_SERVICE_STATUSES = ['pending', 'confirmed'];

    /**
     * Stages at which the business has spent real money on this specific order:
     * the produce is bought and, once dispatched, it is on a van. A
     * cancellation here is allowed and is not free.
     */
    public const COMMITTED_STATUSES = ['packed', 'dispatched'];

    /** Whether the produce for this order has already left on the van. */
    public static function isDispatched(string $orderStatus): bool
    {
        return $orderStatus === 'dispatched';
    }

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
        // A packed or dispatched order is never self service, whatever the
        // clock says. A pay-on-delivery order can be unpaid and already on the
        // van, and before this check that combination let a customer cancel
        // produce that was at their gate.
        if (!in_array($orderStatus, self::SELF_SERVICE_STATUSES, true)) {
            return false;
        }
        if ($paymentStatus !== Payments::STATUS_UNPAID) {
            return false;
        }
        return $withinCutoff;
    }

    /**
     * Whether staff may cancel it. Wider than self service, and still not
     * unlimited: a delivered order is history, and a business that has switched
     * off cancellation after dispatch is saying a van already out is settled at
     * the door rather than on a screen.
     */
    public static function staffMayCancel(string $orderStatus, bool $afterDispatchAllowed = true): bool
    {
        if (in_array($orderStatus, self::CLOSED_STATUSES, true)) {
            return false;
        }
        if (!$afterDispatchAllowed && self::isDispatched($orderStatus)) {
            return false;
        }
        return true;
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
        bool $forfeitAfterCutoff,
        string $orderStatus = 'pending',
        bool $dispatchedForfeit = true
    ): array {
        $paid = max(0, $paidSubunit);
        if ($paid === 0) {
            return ['refund_subunit' => 0, 'forfeit_subunit' => 0, 'reason' => 'nothing_paid'];
        }

        // Dispatch is a stronger fact than the clock. The cutoff rule is about
        // when the produce was likely bought; dispatch is about produce that is
        // demonstrably bought, packed and on the road, so it keeps the deposit
        // even inside the cutoff. It is its own setting rather than a silent
        // override, because a business that has chosen to always refund in full
        // is entitled to mean it.
        if (self::isDispatched($orderStatus) && $dispatchedForfeit) {
            $forfeit = min($paid, max(0, $depositSubunit));
            return [
                'refund_subunit'  => $paid - $forfeit,
                'forfeit_subunit' => $forfeit,
                'reason'          => $forfeit > 0 ? 'deposit_kept_dispatched' : 'full_refund',
            ];
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
    public static function policyLine(
        string $cutoffTime,
        bool $forfeitAfterCutoff,
        bool $afterDispatchAllowed = true,
        bool $dispatchedForfeit = true
    ): string {
        $when = 'You can cancel free up to ' . $cutoffTime . ' the day before your delivery.';
        $after = $forfeitAfterCutoff
            ? ' After that we have already bought your produce, so a deposit is not returned.'
            : ' After that, ask us and we will still return anything you have paid.';

        // The one people find out about at the door, so it is said here first.
        if (!$afterDispatchAllowed) {
            return $when . $after . ' Once your order is on the way it cannot be cancelled here, so please tell the driver.';
        }
        $dispatch = $dispatchedForfeit
            ? ' You can still cancel after it has been dispatched, but the produce has been bought and the van has run, so a deposit is kept.'
            : ' You can still cancel after it has been dispatched, and we will return anything you have paid.';
        return $when . $after . $dispatch;
    }

    /**
     * The terms for the stage this order has actually reached, said to the
     * customer on their own order. Shorter than the checkout line because they
     * are looking at one order rather than deciding a rule.
     */
    public static function termsLine(
        string $orderStatus,
        string $cutoffTime,
        bool $forfeitAfterCutoff,
        bool $afterDispatchAllowed = true,
        bool $dispatchedForfeit = true
    ): string {
        if (in_array($orderStatus, self::CLOSED_STATUSES, true)) {
            return 'This order is finished, so there is nothing left to cancel.';
        }
        if (self::isDispatched($orderStatus)) {
            if (!$afterDispatchAllowed) {
                return 'This order is on the way. Tell the driver when they arrive and we will sort it out with you there.';
            }
            return $dispatchedForfeit
                ? 'This order is on the way. We can still cancel it, but the produce has been bought and the van has run, so a deposit is kept.'
                : 'This order is on the way. We can still cancel it and return anything you have paid.';
        }
        if (in_array($orderStatus, self::COMMITTED_STATUSES, true)) {
            return 'This order is packed and waiting for the van, so our team cancels it rather than the screen. Ask us and we will tell you exactly what comes back.';
        }
        return 'You can cancel this order free up to ' . $cutoffTime . ' the day before your delivery.'
             . ($forfeitAfterCutoff ? ' After that a deposit is kept, because your produce will already have been bought.' : '');
    }

    /** Why a deposit was kept, in the customer's words, for the email and the screen. */
    public static function forfeitReason(string $reason): string
    {
        return $reason === 'deposit_kept_dispatched'
            ? 'because the order had already been dispatched and the produce was on its way to you'
            : 'because the cancellation came after the cutoff and your produce had already been bought from the farmer';
    }

    /** The same rule, said to staff, with the figures filled in. */
    public static function staffSummary(array $outcome): string
    {
        if ($outcome['forfeit_subunit'] > 0) {
            return 'Refund ' . Money::format($outcome['refund_subunit'])
                 . ' and keep ' . Money::format($outcome['forfeit_subunit']) . ' as the deposit'
                 . (($outcome['reason'] ?? '') === 'deposit_kept_dispatched' ? ', because this order has already been dispatched.' : '.');
        }
        if ($outcome['refund_subunit'] > 0) {
            return 'Refund ' . Money::format($outcome['refund_subunit']) . ' in full.';
        }
        return 'Nothing has been paid, so there is nothing to refund.';
    }
}
