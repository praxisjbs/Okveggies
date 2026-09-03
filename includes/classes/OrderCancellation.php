<?php
/**
 * Database orchestration for cancelling an order.
 *
 * Cancellation owns the policy. This class only reads that policy, records the
 * decision once, and hands Paystack money back through Refunds.
 */
final class OrderCancellation
{
    public const CUSTOMER_REASONS = [
        'changed_mind'       => 'I changed my mind',
        'delivery_date'      => 'The delivery day no longer works',
        'ordered_by_mistake' => 'I placed the order by mistake',
        'other'              => 'Another reason',
    ];

    public const STAFF_REASONS = [
        'customer_requested' => 'Customer asked to cancel',
        'stock_unavailable'  => 'Produce is unavailable',
        'delivery_problem'   => 'Delivery cannot be completed',
        'duplicate_order'    => 'Duplicate order',
        'other'              => 'Another reason',
    ];

    /** Allocate a refund over newest transactions first. */
    public static function refundPlan(array $transactions, int $amountSubunit): array
    {
        $left = max(0, $amountSubunit);
        $paystack = [];
        $manual = 0;

        foreach ($transactions as $transaction) {
            if ($left < 1) {
                break;
            }
            $available = max(0, (int) ($transaction['refundable_subunit'] ?? 0));
            $amount = min($left, $available);
            if ($amount < 1) {
                continue;
            }
            if ((string) ($transaction['provider'] ?? '') === 'paystack') {
                $paystack[] = [
                    'transaction_id' => (int) $transaction['id'],
                    'amount_subunit'  => $amount,
                ];
            } else {
                $manual += $amount;
            }
            $left -= $amount;
        }

        return [
            'paystack'        => $paystack,
            'manual_subunit'  => $manual,
            'unmatched_subunit' => $left,
        ];
    }

    /** Customer copy for a refund in the context of its cancellation attempt. */
    public static function refundStatusLine(string $refundStatus, string $cancellationStatus): string
    {
        if ($refundStatus === Refunds::STATUS_REQUESTED
            && in_array($cancellationStatus, ['failed', 'failed_manual'], true)) {
            return 'We could not confirm whether Paystack received this refund. Our team must check it before trying anything again.';
        }
        return Refunds::customerStatusLine($refundStatus);
    }

    /** Current policy and recorded outcome for a customer-owned order. */
    public static function forCustomer(int $orderId, int $userId): ?array
    {
        $order = self::order($orderId, $userId);
        return $order ? self::decorate($order, false) : null;
    }

    /** Current policy and recorded outcome for staff. */
    public static function forStaff(int $orderId): ?array
    {
        $order = self::order($orderId, null);
        return $order ? self::decorate($order, true) : null;
    }

    /** Cancel an order owned by this customer. */
    public static function cancelForCustomer(
        int $orderId,
        int $userId,
        string $reasonCode,
        string $reasonText
    ): array {
        return self::cancel($orderId, $userId, 'customer', $reasonCode, $reasonText, false);
    }

    /** Cancel as staff. Paid orders also require the refund permission. */
    public static function cancelForStaff(
        int $orderId,
        int $staffId,
        string $reasonCode,
        string $reasonText,
        bool $mayRefund
    ): array {
        return self::cancel($orderId, $staffId, 'staff', $reasonCode, $reasonText, $mayRefund);
    }

    private static function cancel(
        int $orderId,
        int $actorId,
        string $actorType,
        string $reasonCode,
        string $reasonText,
        bool $mayRefund
    ): array {
        $allowedReasons = $actorType === 'customer' ? self::CUSTOMER_REASONS : self::STAFF_REASONS;
        if (!isset($allowedReasons[$reasonCode])) {
            return ['ok' => false, 'code' => 'reason_required', 'message' => 'Choose a reason for cancelling this order.'];
        }
        $reasonText = trim($reasonText);
        if (mb_strlen($reasonText) > 1000) {
            return ['ok' => false, 'code' => 'reason_too_long', 'message' => 'Keep the note to 1,000 characters or fewer.'];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $params = [':id' => $orderId];
            $ownerClause = '';
            if ($actorType === 'customer') {
                $ownerClause = ' AND o.user_id = :user';
                $params[':user'] = $actorId;
            }
            $order = Database::one(
                'SELECT o.*, c.id AS cancellation_id, c.refund_status AS cancellation_refund_status
                   FROM orders o
                   LEFT JOIN order_cancellations c ON c.order_id = o.id
                  WHERE o.id = :id' . $ownerClause . '
                  FOR UPDATE',
                $params
            );
            if (!$order) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That order could not be found.'];
            }
            if ($order['cancellation_id'] !== null || (string) $order['order_status'] === 'cancelled') {
                $pdo->commit();
                return [
                    'ok' => true,
                    'code' => 'already_cancelled',
                    'message' => 'This order is already cancelled. No second cancellation or refund was made.',
                    'refund_status' => (string) ($order['cancellation_refund_status'] ?? 'not_required'),
                ];
            }

            $withinCutoff = Cancellation::isWithinCutoff(
                (string) $order['preferred_delivery_date'],
                Settings::str('cancellation_cutoff_time', '18:00')
            );
            $movingPayment = Database::one(
                'SELECT t.id
                   FROM payment_transactions t
                   JOIN payments p ON p.id = t.payment_id
                  WHERE p.order_id = :order
                    AND t.status IN (:initialized, :unknown)
                  LIMIT 1
                  FOR UPDATE',
                [
                    ':order'       => $orderId,
                    ':initialized' => Payments::TXN_INITIALIZED,
                    ':unknown'     => Payments::TXN_UNKNOWN,
                ]
            );
            if ($movingPayment) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'code' => 'payment_in_progress',
                    'message' => 'A payment attempt is still being checked. Please wait for its result before cancelling.',
                ];
            }
            if ($actorType === 'customer') {
                $eligible = Cancellation::customerMayCancel(
                    (string) $order['order_status'],
                    (string) $order['payment_status'],
                    $withinCutoff,
                    Settings::bool('cancellation_customer_allowed', true)
                );
            } else {
                $eligible = Cancellation::staffMayCancel((string) $order['order_status']);
            }
            if (!$eligible) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_eligible', 'message' => 'This order can no longer be cancelled this way. Reload it to see the current position.'];
            }

            $paid = max(0, (int) $order['amount_paid_subunit']);
            if ($actorType === 'staff' && $paid > 0 && !$mayRefund) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'refund_permission_required', 'message' => 'A paid order can only be cancelled by someone who may also issue its refund.'];
            }

            $outcome = Cancellation::moneyOutcome(
                $paid,
                (int) ($order['deposit_required_subunit'] ?? 0),
                $withinCutoff,
                Settings::bool('cancellation_deposit_forfeit_after_cutoff', true)
            );
            $transactions = self::refundableTransactions($orderId);
            $plan = self::refundPlan($transactions, (int) $outcome['refund_subunit']);
            $refundRequired = (int) $outcome['refund_subunit'] > 0;

            Database::run(
                'INSERT INTO order_cancellations
                    (order_id, cancelled_by, cancelled_by_type, reason_code, reason_text,
                     fulfilment_stage, inventory_action, refund_required, refund_status)
                 VALUES (:order, :actor, :actor_type, :reason_code, :reason_text,
                         :stage, :inventory, :refund_required, :refund_status)',
                [
                    ':order'           => $orderId,
                    ':actor'           => $actorId,
                    ':actor_type'      => $actorType,
                    ':reason_code'     => $reasonCode,
                    ':reason_text'     => $reasonText !== '' ? $reasonText : null,
                    ':stage'           => (string) $order['order_status'],
                    ':inventory'       => 'none',
                    ':refund_required' => $refundRequired ? 1 : 0,
                    ':refund_status'   => $refundRequired ? 'pending' : 'not_required',
                ]
            );
            $cancellationId = (int) $pdo->lastInsertId();

            Database::run(
                'UPDATE orders SET order_status = :status, cancelled_at = NOW() WHERE id = :id',
                [':status' => 'cancelled', ':id' => $orderId]
            );
            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, note, changed_by)
                 VALUES (:order, :old, :new, :source, :note, :actor)',
                [
                    ':order'  => $orderId,
                    ':old'    => (string) $order['order_status'],
                    ':new'    => 'cancelled',
                    ':source' => $actorType,
                    ':note'   => $allowedReasons[$reasonCode] . ($reasonText !== '' ? ': ' . $reasonText : ''),
                    ':actor'  => $actorId,
                ]
            );
            Database::run(
                'UPDATE delivery_schedules SET status = \'cancelled\', updated_by = :actor WHERE order_id = :order',
                [':actor' => $actorId, ':order' => $orderId]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $refundResults = [];
        foreach ($plan['paystack'] as $refund) {
            try {
                $refundResults[] = Refunds::request(
                    (int) $refund['transaction_id'],
                    (int) $refund['amount_subunit'],
                    'Refund after order cancellation.',
                    'Cancellation ' . $cancellationId . '.',
                    $actorId
                );
            } catch (Throwable $e) {
                error_log('order cancellation refund failed for order ' . $orderId . ': ' . $e->getMessage());
                $refundResults[] = ['ok' => false, 'code' => 'exception'];
            }
        }

        $refundStatus = self::resultingRefundStatus($outcome, $plan, $refundResults);
        Database::run(
            'UPDATE order_cancellations SET refund_status = :status WHERE id = :id',
            [':status' => $refundStatus, ':id' => $cancellationId]
        );

        return [
            'ok'                => true,
            'code'              => 'cancelled',
            'message'           => self::successMessage($refundStatus),
            'refund_status'     => $refundStatus,
            'refund_subunit'    => (int) $outcome['refund_subunit'],
            'forfeit_subunit'   => (int) $outcome['forfeit_subunit'],
            'manual_subunit'    => (int) $plan['manual_subunit'] + (int) $plan['unmatched_subunit'],
        ];
    }

    /** Refresh the cancellation's aggregate label after a refund webhook. */
    public static function syncRefundStatus(int $orderId): void
    {
        $cancellation = Database::one(
            'SELECT id, refund_required, refund_status
               FROM order_cancellations
              WHERE order_id = :order',
            [':order' => $orderId]
        );
        if (!$cancellation) {
            return;
        }
        if ((int) $cancellation['refund_required'] !== 1) {
            Database::run('UPDATE order_cancellations SET refund_status = :status WHERE id = :id', [':status' => 'not_required', ':id' => (int) $cancellation['id']]);
            return;
        }

        $rows = Database::all(
            'SELECT amount_subunit, status FROM refunds
              WHERE order_id = :order AND merchant_note = :marker',
            [':order' => $orderId, ':marker' => 'Cancellation ' . (int) $cancellation['id'] . '.']
        );
        $hasFailure = false;
        $allProcessed = count($rows) > 0;
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (in_array($status, [Refunds::STATUS_FAILED, Refunds::STATUS_REQUESTED], true)) {
                $hasFailure = true;
            }
            if ($status !== Refunds::STATUS_PROCESSED) {
                $allProcessed = false;
            }
        }
        $hasManual = in_array((string) $cancellation['refund_status'], ['pending_manual', 'manual_required', 'failed_manual'], true);
        if ($hasFailure) {
            $status = $hasManual ? 'failed_manual' : 'failed';
        } elseif ($hasManual) {
            $status = $allProcessed ? 'manual_required' : 'pending_manual';
        } else {
            $status = $allProcessed ? 'processed' : 'pending';
        }
        Database::run(
            'UPDATE order_cancellations SET refund_status = :status WHERE id = :id',
            [':status' => $status, ':id' => (int) $cancellation['id']]
        );
    }

    private static function resultingRefundStatus(array $outcome, array $plan, array $results): string
    {
        if ((int) $outcome['refund_subunit'] < 1) {
            return 'not_required';
        }
        $manual = (int) $plan['manual_subunit'] + (int) $plan['unmatched_subunit'];
        $hasFailure = false;
        $allProcessed = count($results) > 0;
        foreach ($results as $result) {
            if (empty($result['ok'])) {
                $hasFailure = true;
                $allProcessed = false;
                continue;
            }
            if (($result['status'] ?? '') !== Refunds::STATUS_PROCESSED) {
                $allProcessed = false;
            }
        }
        if ($hasFailure) {
            return $manual > 0 ? 'failed_manual' : 'failed';
        }
        if ($manual > 0) {
            return count($results) > 0 ? 'pending_manual' : 'manual_required';
        }
        return $allProcessed ? 'processed' : 'pending';
    }

    private static function successMessage(string $refundStatus): string
    {
        if ($refundStatus === 'not_required') {
            return 'Order cancelled. There was nothing to refund.';
        }
        if ($refundStatus === 'processed') {
            return 'Order cancelled. Paystack has confirmed the refund.';
        }
        if (in_array($refundStatus, ['manual_required', 'pending_manual'], true)) {
            return 'Order cancelled. Money recorded by staff still needs to be returned to the customer.';
        }
        if (in_array($refundStatus, ['failed', 'failed_manual'], true)) {
            return 'Order cancelled, but the refund needs attention.';
        }
        return 'Order cancelled. The refund has been raised and is not confirmed yet.';
    }

    private static function refundableTransactions(int $orderId): array
    {
        return Database::all(
            'SELECT t.id, t.provider,
                    GREATEST(COALESCE(t.amount_subunit, 0) - COALESCE((
                        SELECT SUM(r.amount_subunit) FROM refunds r
                         WHERE r.payment_transaction_id = t.id AND r.status <> :failed
                    ), 0), 0) AS refundable_subunit
               FROM payment_transactions t
               JOIN payments p ON p.id = t.payment_id
              WHERE p.order_id = :order
                AND COALESCE(t.amount_subunit, 0) > 0
                AND t.status IN (:success, :part_refunded)
              ORDER BY COALESCE(t.paid_at, t.created_at) DESC, t.id DESC',
            [
                ':failed'        => Refunds::STATUS_FAILED,
                ':order'         => $orderId,
                ':success'       => Payments::TXN_SUCCESS,
                ':part_refunded' => 'part_refunded',
            ]
        );
    }

    private static function order(int $orderId, ?int $userId): ?array
    {
        $params = [':id' => $orderId];
        $owner = '';
        if ($userId !== null) {
            $owner = ' AND o.user_id = :user';
            $params[':user'] = $userId;
        }
        return Database::one(
            'SELECT o.*, c.id AS cancellation_id, c.cancelled_by_type, c.reason_code,
                    c.reason_text, c.refund_required, c.refund_status, c.cancelled_at AS cancellation_at
               FROM orders o
               LEFT JOIN order_cancellations c ON c.order_id = o.id
              WHERE o.id = :id' . $owner,
            $params
        );
    }

    private static function decorate(array $order, bool $staff): array
    {
        $cutoff = Settings::str('cancellation_cutoff_time', '18:00');
        $within = Cancellation::isWithinCutoff((string) $order['preferred_delivery_date'], $cutoff);
        $customerAllowed = Settings::bool('cancellation_customer_allowed', true);
        $mayCancel = $staff
            ? Cancellation::staffMayCancel((string) $order['order_status'])
            : Cancellation::customerMayCancel(
                (string) $order['order_status'],
                (string) $order['payment_status'],
                $within,
                $customerAllowed
            );
        $outcome = Cancellation::moneyOutcome(
            (int) $order['amount_paid_subunit'],
            (int) ($order['deposit_required_subunit'] ?? 0),
            $within,
            Settings::bool('cancellation_deposit_forfeit_after_cutoff', true)
        );

        $movingPayment = Database::one(
            'SELECT t.id
               FROM payment_transactions t
               JOIN payments p ON p.id = t.payment_id
              WHERE p.order_id = :order
                AND t.status IN (:initialized, :unknown)
              LIMIT 1',
            [
                ':order'       => (int) $order['id'],
                ':initialized' => Payments::TXN_INITIALIZED,
                ':unknown'     => Payments::TXN_UNKNOWN,
            ]
        );
        if ($movingPayment && $order['cancellation_id'] === null) {
            $mayCancel = false;
            $restriction = 'A payment attempt is still being checked. Wait for its result before cancelling.';
        }

        $restriction = $restriction ?? '';
        if (!$mayCancel && $order['cancellation_id'] === null) {
            if (in_array((string) $order['order_status'], Cancellation::CLOSED_STATUSES, true)) {
                $restriction = 'This order can no longer be cancelled.';
            } elseif (!$staff && (string) $order['payment_status'] !== Payments::STATUS_UNPAID) {
                $restriction = 'Money has been paid on this order, so our team needs to cancel it and arrange the refund.';
            } elseif (!$staff && !$customerAllowed) {
                $restriction = 'Please ask our team to cancel this order for you.';
            } elseif (!$staff && !$within) {
                $restriction = 'The cancellation cutoff has passed because your produce may already have been bought. Please ask our team for help.';
            }
        }

        $order['within_cutoff'] = $within;
        $order['may_cancel'] = $mayCancel && $order['cancellation_id'] === null;
        $order['restriction'] = $restriction;
        $order['money_outcome'] = $outcome;
        $order['deadline'] = Cancellation::deadline((string) $order['preferred_delivery_date'], $cutoff);
        $order['refunds'] = Refunds::forOrder((int) $order['id']);
        return $order;
    }
}
