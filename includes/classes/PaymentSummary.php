<?php
/**
 * includes/classes/PaymentSummary.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The money story of one order, in the words a customer reads it:
 * what has been received, what is still due, and what is waiting to be checked.
 *
 * One builder feeds every place that says it: the green "Payment received"
 * screen, the Payments panel on the order page, and the emails. They cannot
 * disagree because they read the same numbers, and the numbers are the payment
 * rows themselves rather than a running total, the rule Payments::recomputeOrder
 * keeps.
 *
 * The state is the one word the customer needs first:
 *
 *   paid        everything on the order has been received
 *   part_paid   something has been received and something is still due
 *   awaiting    a transfer receipt is with the team and not yet verified
 *   declined    the last receipt could not be confirmed, so nothing is credited
 *   unpaid      nothing has been received and nothing is waiting
 *   cancelled   the order was cancelled, so "due" no longer applies
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/PaymentSummaryTest.php.
 * -----------------------------------------------------------------------------
 */

final class PaymentSummary
{
    public const STATE_PAID      = 'paid';
    public const STATE_PART_PAID = 'part_paid';
    public const STATE_AWAITING  = 'awaiting';
    public const STATE_DECLINED  = 'declined';
    public const STATE_UNPAID    = 'unpaid';
    public const STATE_CANCELLED = 'cancelled';

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /**
     * The overall state from the facts of an order.
     *
     * A receipt waiting for review outranks part paid: the customer has done
     * something and is waiting on us, and that is the thing to say first. A
     * declined receipt only shows while nothing has been received, because once
     * money has arrived "part paid" is the truer thing to say.
     */
    public static function state(bool $cancelled, int $total, int $received, bool $awaiting, bool $declined): string
    {
        if ($cancelled) {
            return self::STATE_CANCELLED;
        }
        if ($total > 0 && $received >= $total) {
            return self::STATE_PAID;
        }
        if ($awaiting) {
            return self::STATE_AWAITING;
        }
        if ($received > 0) {
            return self::STATE_PART_PAID;
        }
        return $declined ? self::STATE_DECLINED : self::STATE_UNPAID;
    }

    /** What is still owed on the whole order. Never negative: an overpayment is not a debt. */
    public static function stillDue(int $total, int $received): int
    {
        return Money::balance($total, $received);
    }

    /** How a payment was made, in words. */
    public static function methodLabel(string $provider, string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($provider === 'manual') {
            return [
                'transfer' => 'Bank transfer',
                'cash'     => 'Cash',
            ][$channel] ?? 'Recorded by our team';
        }
        if ($provider === 'paystack') {
            return [
                'card'          => 'Card on Paystack',
                'bank_transfer' => 'Bank transfer on Paystack',
                'bank'          => 'Bank on Paystack',
                'ussd'          => 'USSD on Paystack',
                'mobile_money'  => 'Mobile money on Paystack',
                'qr'            => 'QR on Paystack',
            ][$channel] ?? 'Paystack';
        }
        if ($provider === 'account') {
            return 'Business credit';
        }
        if ($provider === 'wallet') {
            return 'Wallet';
        }
        return 'Payment';
    }

    /** One line's own status, for its badge. */
    public static function lineStatus(int $expected, int $paid, bool $awaiting, bool $declined): string
    {
        if ($expected > 0 && $paid >= $expected) {
            return self::STATE_PAID;
        }
        if ($awaiting) {
            return self::STATE_AWAITING;
        }
        if ($paid > 0) {
            return self::STATE_PART_PAID;
        }
        return $declined ? self::STATE_DECLINED : self::STATE_UNPAID;
    }

    /** The heading of the green screen, and of the panel when something has arrived. */
    public static function headline(string $state): string
    {
        return [
            self::STATE_PAID      => 'Payment received',
            self::STATE_PART_PAID => 'Payment received',
            self::STATE_AWAITING  => 'Payment pending verification',
            self::STATE_DECLINED  => 'We could not confirm your transfer',
            self::STATE_UNPAID    => 'No payment received yet',
            self::STATE_CANCELLED => 'This order was cancelled',
        ][$state] ?? 'Payment';
    }

    /** The one plain sentence under the heading. */
    public static function detailLine(string $state, int $due): string
    {
        switch ($state) {
            case self::STATE_PAID:
                return 'Nothing is left to pay on this order.';
            case self::STATE_PART_PAID:
                return 'You still owe ' . Money::format($due) . ' on this order.';
            case self::STATE_AWAITING:
                return 'We have your receipt and our team is checking it against the bank. You will get an email as soon as it is confirmed.';
            case self::STATE_DECLINED:
                return 'Nothing has been taken into your order yet. Upload a clearer receipt, or message us if you think we made a mistake.';
            case self::STATE_CANCELLED:
                return 'Nothing more is due on it.';
            default:
                return 'You owe ' . Money::format($due) . ' on this order.';
        }
    }

    // -------------------------------------------------------------------------
    // Building the summary
    // -------------------------------------------------------------------------

    /**
     * Everything the screens need about an order's money, or null when there is
     * no such order. Amounts are subunits; nothing here formats naira.
     */
    public static function forOrder(int $orderId): ?array
    {
        $order = Database::one(
            'SELECT id, order_number, order_status, payment_option, order_total_subunit,
                    amount_paid_subunit, preferred_delivery_date, user_id
               FROM orders WHERE id = :id',
            [':id' => $orderId]
        );
        if (!$order) {
            return null;
        }

        $payments = Database::all(
            'SELECT id, payment_type, provider, expected_amount_subunit, paid_amount_subunit,
                    refunded_amount_subunit, status, due_at, confirmed_at
               FROM payments
              WHERE order_id = :order AND status <> :void
              ORDER BY id',
            [':order' => $orderId, ':void' => Payments::STATUS_VOID]
        );

        $lines        = [];
        $receipts     = [];
        $viaTransfer  = false;
        $received     = 0;
        $refunded     = 0;
        $anyAwaiting  = false;
        $anyDeclined  = false;
        $lastReceived = null;

        foreach ($payments as $payment) {
            $paymentId = (int) $payment['id'];
            $expected  = (int) $payment['expected_amount_subunit'];
            $paid      = (int) $payment['paid_amount_subunit'];
            $received += $paid;
            $refunded += (int) $payment['refunded_amount_subunit'];
            $viaTransfer = $viaTransfer
                || ((string) $payment['provider'] === 'manual'
                    && in_array((string) $payment['payment_type'], ['pay_in_full', 'deposit'], true));

            // The newest receipt on this payment decides whether it is waiting
            // or was declined; an older declined one does not count once a newer
            // one is in.
            $proof = Database::one(
                'SELECT mp.id AS proof_id, mp.status, mp.amount_subunit, mp.review_note, mp.created_at, mp.reviewed_at
                   FROM manual_payment_proofs mp
                   JOIN payment_transactions t ON t.id = mp.payment_transaction_id
                  WHERE t.payment_id = :payment AND mp.submitted_by_customer = 1
                  ORDER BY mp.id DESC
                  LIMIT 1',
                [':payment' => $paymentId]
            );
            $awaiting = $proof !== null && (string) $proof['status'] === TransferProofs::PROOF_SUBMITTED;
            $declined = $proof !== null && (string) $proof['status'] === TransferProofs::PROOF_DECLINED;
            $lineDone = $expected > 0 && $paid >= $expected;
            $anyAwaiting = $anyAwaiting || ($awaiting && !$lineDone);
            $anyDeclined = $anyDeclined || ($declined && !$lineDone);

            $successes = Database::all(
                'SELECT provider, channel, amount_subunit, paid_at
                   FROM payment_transactions
                  WHERE payment_id = :payment AND status = :success
                  ORDER BY COALESCE(paid_at, created_at), id',
                [':payment' => $paymentId, ':success' => Payments::TXN_SUCCESS]
            );
            $lastSuccess = $successes ? $successes[count($successes) - 1] : null;
            if ($lastSuccess !== null) {
                $at = (string) ($lastSuccess['paid_at'] ?? '');
                if ($lastReceived === null || strcmp($at, (string) $lastReceived['at']) >= 0) {
                    $lastReceived = [
                        'amount_subunit' => (int) ($lastSuccess['amount_subunit'] ?? 0),
                        'at'             => $at,
                        'method'         => self::methodLabel((string) $lastSuccess['provider'], (string) ($lastSuccess['channel'] ?? '')),
                        'type'           => (string) $payment['payment_type'],
                    ];
                }
            }

            $lines[] = [
                'payment_id'      => $paymentId,
                'provider'        => (string) $payment['provider'],
                'type'            => (string) $payment['payment_type'],
                'label'           => TransferProofs::typeLabel((string) $payment['payment_type']),
                'method'          => $lastSuccess !== null
                    ? self::methodLabel((string) $lastSuccess['provider'], (string) ($lastSuccess['channel'] ?? ''))
                    : self::methodLabel((string) $payment['provider'], ''),
                'expected_subunit' => $expected,
                'paid_subunit'    => $paid,
                'due_subunit'     => Money::balance($expected, $paid),
                'status'          => self::lineStatus($expected, $paid, $awaiting, $declined),
                'due_at'          => $payment['due_at'] !== null ? (string) $payment['due_at'] : null,
                'paid_at'         => $lastSuccess !== null ? (string) ($lastSuccess['paid_at'] ?? '') : '',
                'proof'           => $proof,
            ];
            if ($proof !== null && !$lineDone && ($awaiting || $declined)) {
                $receipts[] = [
                    'payment_id'     => $paymentId,
                    'status'         => (string) $proof['status'],
                    'amount_subunit' => (int) $proof['amount_subunit'],
                    'note'           => (string) ($proof['review_note'] ?? ''),
                    'submitted_at'   => (string) $proof['created_at'],
                    'reviewed_at'    => (string) ($proof['reviewed_at'] ?? ''),
                ];
            }
        }

        // Money handed back because a line was out of stock is money handed back too.
        $refunded += Shortages::returnedSubunit((int) $order['id']);

        $total     = (int) $order['order_total_subunit'];
        $cancelled = (string) $order['order_status'] === 'cancelled';
        $state     = self::state($cancelled, $total, $received, $anyAwaiting, $anyDeclined);
        $due       = self::stillDue($total, $received);

        return [
            'order_id'        => (int) $order['id'],
            'order_number'    => (string) $order['order_number'],
            'order_status'    => (string) $order['order_status'],
            'user_id'         => $order['user_id'] === null ? null : (int) $order['user_id'],
            'delivery_date'   => (string) $order['preferred_delivery_date'],
            'total_subunit'   => $total,
            'received_subunit' => $received,
            'refunded_subunit' => $refunded,
            'due_subunit'     => $due,
            'state'           => $state,
            'headline'        => self::headline($state),
            'detail'          => self::detailLine($state, $due),
            'lines'           => $lines,
            'receipts'        => $receipts,
            'last_received'   => $lastReceived,
            'has_received'    => $received > 0,
            'via_transfer'    => $viaTransfer,
        ];
    }
}
