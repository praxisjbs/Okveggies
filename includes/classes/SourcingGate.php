<?php
/**
 * includes/classes/SourcingGate.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Whether an order may move from Placed to Sourced.
 *
 * Sourcing means spending OK Veggies' own money on produce, so the order has to
 * be covered first. The Owner's rules from the 23 Sep review:
 *
 *   households          pay what checkout asked for, no credit line
 *   businesses          a posted credit charge, or the same payment as a household
 *   kitchen runs        may send a list without paying, so an order that came from
 *                       a Kitchen Run is exempt
 *   staff entered       a phone or WhatsApp order a colleague typed in is exempt
 *
 * "What checkout asked for" is the full total for pay in full, the deposit for a
 * deposit order, and the deposit for a pay on delivery order too, so pay on
 * delivery cannot be used to get produce bought with nothing in hand.
 *
 * The exemptions are positive and the default is closed. An order counts as
 * exempt only when it can be shown to have come from a Kitchen Run, or to have
 * been entered by a colleague for a customer. Anything else is gated, so an
 * order of unknown origin can never slip through.
 *
 * The Owner may override with a reason. That is decided by the caller, which
 * checks the permission; this class only says what the rule finds.
 *
 * evaluate() is pure and unit tested. forOrder() adds the reads.
 * -----------------------------------------------------------------------------
 */

final class SourcingGate
{
    public const OK_NOT_APPLICABLE = 'not_applicable';
    public const OK_PAID           = 'paid';
    public const OK_CREDIT         = 'credit_funded';
    public const OK_KITCHEN_RUN    = 'exempt_kitchen_run';
    public const OK_STAFF_ORDER    = 'exempt_staff_order';

    public const BLOCK_PAYMENT     = 'payment_required';
    public const BLOCK_DEPOSIT     = 'deposit_required';
    public const BLOCK_CREDIT      = 'credit_missing';

    // -------------------------------------------------------------------------
    // Pure
    // -------------------------------------------------------------------------

    /** The cash an order must hold before it can be sourced, in subunits. */
    public static function requiredCashSubunit(string $option, int $total, int $depositRequired, float $percentage): int
    {
        $total = max(0, $total);
        return match ($option) {
            'pay_in_full'     => $total,
            'deposit', 'pay_on_delivery' => $depositRequired > 0
                ? min($total, $depositRequired)
                : min($total, Money::deposit($total, $percentage)),
            default           => 0,
        };
    }

    /**
     * The decision.
     *
     * $order: order_status, payment_option, order_total_subunit,
     *         amount_paid_subunit, deposit_required_subunit.
     * $facts: exempt ('' | 'kitchen_run' | 'staff_order'), credit (the ledger
     *         summary for this order or null), deposit_percentage (float).
     *
     * @return array{allowed:bool, code:string, message:string, required_subunit:int, paid_subunit:int}
     */
    public static function evaluate(array $order, array $facts): array
    {
        $option   = (string) ($order['payment_option'] ?? '');
        $total    = (int) ($order['order_total_subunit'] ?? 0);
        $paid     = max(0, (int) ($order['amount_paid_subunit'] ?? 0));
        $required = self::requiredCashSubunit(
            $option,
            $total,
            (int) ($order['deposit_required_subunit'] ?? 0),
            (float) ($facts['deposit_percentage'] ?? 30.0)
        );

        $allow = static fn(string $code): array => [
            'allowed' => true, 'code' => $code, 'message' => '',
            'required_subunit' => $required, 'paid_subunit' => $paid,
        ];

        if ((string) ($order['order_status'] ?? '') !== 'pending') {
            return $allow(self::OK_NOT_APPLICABLE);
        }

        $exempt = (string) ($facts['exempt'] ?? '');
        if ($exempt === 'kitchen_run') {
            return $allow(self::OK_KITCHEN_RUN);
        }
        if ($exempt === 'staff_order') {
            return $allow(self::OK_STAFF_ORDER);
        }

        if ($option === 'on_account') {
            $credit = $facts['credit'] ?? null;
            if (is_array($credit) && (int) ($credit['charged_subunit'] ?? 0) > 0) {
                return $allow(self::OK_CREDIT);
            }
            return [
                'allowed' => false, 'code' => self::BLOCK_CREDIT,
                'message' => 'This order is marked on account, but no credit charge is posted for it, so it cannot be sourced.',
                'required_subunit' => $total, 'paid_subunit' => $paid,
            ];
        }

        if ($required > 0 && $paid >= $required) {
            return $allow(self::OK_PAID);
        }

        $isDeposit = $option !== 'pay_in_full';
        return [
            'allowed' => false,
            'code'    => $isDeposit ? self::BLOCK_DEPOSIT : self::BLOCK_PAYMENT,
            'message' => ($isDeposit
                    ? 'The deposit has not been paid. This order needs '
                    : 'This order has not been paid. It needs ')
                . Money::format($required) . ' before it can be sourced, and '
                . Money::format($paid) . ' has been received.',
            'required_subunit' => $required,
            'paid_subunit'     => $paid,
        ];
    }

    // -------------------------------------------------------------------------
    // Reading the facts
    // -------------------------------------------------------------------------

    /**
     * Which exemption, if any, an order can be shown to have. Closed by
     * default: a row that is neither a Kitchen Run conversion nor a colleague's
     * entry is gated.
     *
     * @param array{id:int, shopping_cart_id:mixed, created_by:mixed, user_id:mixed} $order
     */
    public static function exemptionFor(array $order): string
    {
        $converted = Database::one(
            'SELECT id FROM kitchen_run_requests WHERE converted_order_id = :id LIMIT 1',
            [':id' => (int) $order['id']]
        );
        if ($converted !== null) {
            return 'kitchen_run';
        }
        $createdBy = $order['created_by'] === null ? null : (int) $order['created_by'];
        $userId    = $order['user_id'] === null ? null : (int) $order['user_id'];
        if ($order['shopping_cart_id'] === null && $createdBy !== null && $createdBy !== $userId) {
            return 'staff_order';
        }
        return '';
    }

    /**
     * Run the rule against a stored order. Call it inside the transaction that
     * already holds the order row, so the decision and the change it allows
     * cannot be separated by another writer.
     *
     * @return array{allowed:bool, code:string, message:string, required_subunit:int, paid_subunit:int}|null
     */
    public static function forOrder(int $orderId): ?array
    {
        $order = Database::one(
            'SELECT id, user_id, created_by, shopping_cart_id, order_status, payment_option,
                    order_total_subunit, amount_paid_subunit, deposit_required_subunit
               FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
        if ($order === null) {
            return null;
        }
        $credit = (string) $order['payment_option'] === 'on_account'
            ? (OrderMoney::creditFor([$orderId])[$orderId] ?? null)
            : null;
        return self::evaluate($order, [
            'exempt'             => self::exemptionFor($order),
            'credit'             => $credit,
            'deposit_percentage' => Settings::depositPercentage(),
        ]);
    }

    /** Whether the credit line could cover this order, for the staff shortcut. */
    public static function creditShortcutOffered(array $order, ?array $facility): bool
    {
        return in_array((string) ($order['payment_option'] ?? ''), ['pay_in_full', 'deposit', 'pay_on_delivery'], true)
            && (int) ($order['amount_paid_subunit'] ?? 0) === 0
            && $facility !== null
            && Credit::mayDraw($facility, (int) ($order['order_total_subunit'] ?? 0));
    }
}
