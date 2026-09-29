<?php
/**
 * includes/classes/OrderMoney.php
 * -----------------------------------------------------------------------------
 * OK Veggies. How an order's money reads to a person, in one place.
 *
 * An order on the credit line has two layers, the way a card purchase does:
 *
 *   the order layer   The goods are covered. The customer sees "Paid with your
 *                     credit line", never "unpaid" and never a Pay now button.
 *   the credit layer  What the business still owes OK Veggies, and by when. It
 *                     comes from the credit ledger, so repaying it, by card or
 *                     by transfer, frees the limit exactly as it always did.
 *
 * Nothing here writes. The cash fields on the order (amount_paid_subunit and the
 * payment rows) stay cash only, which is why settlement, cancellation, refunds
 * and the Payments screen are untouched. This class only decides the words, the
 * tone and what is still owed in cash, so the confirmation page, the account
 * list, the Pro list, the admin order, the customer profile and the emails
 * cannot disagree.
 *
 * describe() is pure and unit tested. attach() and forOrder() add the one query
 * that reads the ledger.
 * -----------------------------------------------------------------------------
 */

final class OrderMoney
{
    public const KIND_CANCELLED     = 'cancelled';
    public const KIND_CREDIT        = 'credit';
    public const KIND_CREDIT_REPAID = 'credit_repaid';
    public const KIND_PAID          = 'paid';
    public const KIND_PART_PAID     = 'part_paid';
    public const KIND_UNPAID        = 'unpaid';
    public const KIND_ON_DELIVERY   = 'on_delivery';

    private const TZ = 'Africa/Lagos';

    // -------------------------------------------------------------------------
    // Pure
    // -------------------------------------------------------------------------

    /**
     * Describe one order.
     *
     * $order needs: order_status, payment_option, payment_status,
     * order_total_subunit, amount_paid_subunit, balance_due_subunit.
     * $credit is the ledger summary for this order, or null when it has no
     * charge: ['charged_subunit' => int, 'open_subunit' => int, 'due_date' => ?string].
     *
     * @return array{
     *   kind:string, settled:bool, headline:string, badge:string, tone:string,
     *   owed_subunit:int, on_credit:bool, credit_open_subunit:int,
     *   credit_due_date:?string, credit_line:string
     * }
     */
    public static function describe(array $order, ?array $credit = null): array
    {
        $status   = (string) ($order['order_status'] ?? '');
        $option   = (string) ($order['payment_option'] ?? '');
        $paidFlag = (string) ($order['payment_status'] ?? 'unpaid');
        $balance  = max(0, (int) ($order['balance_due_subunit'] ?? 0));

        $base = [
            'kind'               => self::KIND_UNPAID,
            'settled'            => false,
            'headline'           => '',
            'badge'              => '',
            'tone'               => 'warn',
            'owed_subunit'       => $balance,
            'on_credit'          => false,
            'credit_open_subunit' => 0,
            'credit_due_date'    => null,
            'credit_line'        => '',
        ];

        if ($status === 'cancelled') {
            return [
                'kind' => self::KIND_CANCELLED, 'settled' => true,
                'headline' => 'Cancelled.', 'badge' => 'Cancelled', 'tone' => 'neutral',
                'owed_subunit' => 0,
            ] + $base;
        }

        // The credit layer. Only an order that really has a posted charge counts:
        // a stray on-account flag with no ledger row falls through to the cash
        // reading below, which is the safe direction to be wrong in.
        if ($option === 'on_account' && $credit !== null && (int) ($credit['charged_subunit'] ?? 0) > 0) {
            $open = max(0, (int) ($credit['open_subunit'] ?? 0));
            $due  = self::dateOrNull($credit['due_date'] ?? null);
            if ($open > 0) {
                return [
                    'kind' => self::KIND_CREDIT, 'settled' => true,
                    'headline' => 'Paid with your credit line.',
                    'badge' => 'On your credit line', 'tone' => 'good',
                    'owed_subunit' => 0, 'on_credit' => true,
                    'credit_open_subunit' => $open, 'credit_due_date' => $due,
                    'credit_line' => self::creditLine($open, $due),
                ] + $base;
            }
            return [
                'kind' => self::KIND_CREDIT_REPAID, 'settled' => true,
                'headline' => 'Paid with your credit line. Repaid.',
                'badge' => 'Credit repaid', 'tone' => 'good',
                'owed_subunit' => 0, 'on_credit' => true,
                'credit_open_subunit' => 0, 'credit_due_date' => $due,
                'credit_line' => '',
            ] + $base;
        }

        if ($paidFlag === 'paid' || $balance === 0 && (int) ($order['amount_paid_subunit'] ?? 0) > 0) {
            return [
                'kind' => self::KIND_PAID, 'settled' => true,
                'headline' => 'Paid in full.', 'badge' => 'Paid in full', 'tone' => 'good',
                'owed_subunit' => 0,
            ] + $base;
        }

        if ($paidFlag === 'part_paid') {
            return [
                'kind' => self::KIND_PART_PAID,
                'headline' => 'Part paid, a balance is still owed.',
                'badge' => Money::format($balance) . ' still to pay', 'tone' => 'warn',
            ] + $base;
        }

        if ($option === 'pay_on_delivery') {
            return [
                'kind' => self::KIND_ON_DELIVERY,
                'headline' => 'Pay on delivery.',
                'badge' => 'Pay on delivery', 'tone' => 'neutral',
            ] + $base;
        }

        return [
            'kind' => self::KIND_UNPAID,
            'headline' => 'Nothing has been paid yet.',
            'badge' => Money::format($balance) . ' still to pay', 'tone' => 'warn',
        ] + $base;
    }

    /** "Repay 8,000 by Friday 3rd October." One short sentence, no jargon. */
    public static function creditLine(int $openSubunit, ?string $dueDate): string
    {
        $amount = Money::format($openSubunit);
        if ($dueDate === null) {
            return 'Repay ' . $amount . ' to OK Veggies.';
        }
        $stamp = strtotime($dueDate . ' 12:00:00');
        return $stamp === false
            ? 'Repay ' . $amount . ' to OK Veggies.'
            : 'Repay ' . $amount . ' by ' . date('l jS F', $stamp) . '.';
    }

    /** Whether the customer has any cash to hand over for this order right now. */
    public static function needsPayment(array $described): bool
    {
        return !$described['settled'] && $described['owed_subunit'] > 0;
    }

    private static function dateOrNull($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : null;
    }

    // -------------------------------------------------------------------------
    // Reading the ledger
    // -------------------------------------------------------------------------

    /**
     * The credit summary for a set of orders, keyed by order id. One query, and
     * only for orders that could carry a charge. Read from the same journal the
     * credit screens read, so the two can never disagree.
     *
     * @param  list<int> $orderIds
     * @return array<int, array{charged_subunit:int, open_subunit:int, due_date:?string}>
     */
    public static function creditFor(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            'SELECT order_id,
                    COALESCE(SUM(CASE WHEN transaction_type = \'charge\' THEN amount_subunit ELSE 0 END), 0) AS charged,
                    COALESCE(SUM(amount_subunit), 0) AS open_total,
                    MAX(CASE WHEN transaction_type = \'charge\' THEN due_date END) AS due_date
               FROM credit_transactions
              WHERE order_id IN (' . $placeholders . ')
           GROUP BY order_id',
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['order_id']] = [
                'charged_subunit' => (int) $row['charged'],
                'open_subunit'    => (int) $row['open_total'],
                'due_date'        => $row['due_date'] === null ? null : (string) $row['due_date'],
            ];
        }
        return $out;
    }

    /**
     * Add a 'money' description to every order row. Rows need the fields
     * describe() names plus an id.
     *
     * @param  list<array<string,mixed>> $orders
     * @return list<array<string,mixed>>
     */
    public static function attach(array $orders): array
    {
        $onAccount = [];
        foreach ($orders as $order) {
            if ((string) ($order['payment_option'] ?? '') === 'on_account') {
                $onAccount[] = (int) $order['id'];
            }
        }
        $credit = self::creditFor($onAccount);
        foreach ($orders as &$order) {
            $order['money'] = self::describe($order, $credit[(int) $order['id']] ?? null);
        }
        unset($order);
        return $orders;
    }

    /** One order row that already carries the fields describe() needs. */
    public static function fromRow(array $order): array
    {
        $id = (int) ($order['id'] ?? 0);
        $credit = (string) ($order['payment_option'] ?? '') === 'on_account' && $id > 0
            ? (self::creditFor([$id])[$id] ?? null)
            : null;
        return self::describe($order, $credit);
    }

    /** One order by id, or null. */
    public static function forOrder(int $orderId): ?array
    {
        $order = Database::one(
            'SELECT id, order_status, payment_option, payment_status, order_total_subunit,
                    amount_paid_subunit, balance_due_subunit
               FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
        if ($order === null) {
            return null;
        }
        $credit = (string) $order['payment_option'] === 'on_account' ? self::creditFor([$orderId]) : [];
        return self::describe($order, $credit[$orderId] ?? null);
    }
}
