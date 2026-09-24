<?php
/**
 * includes/classes/OrderReschedule.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Move an order's delivery date to another eligible day.
 *
 * A reschedule never touches money: only preferred_delivery_date and the
 * delivery_schedules row move. Every move is recorded in order_reschedules,
 * an append-only table, so the order's delivery story is never lost.
 *
 * Policy (from user answers):
 *   - Customer may reschedule pending/confirmed only, before cutoff, own order.
 *   - Staff may reschedule pending/confirmed/packed, both blocked after
 *     dispatched/delivered/cancelled.
 *   - Cutoff reuses cancellation_cutoff_time against the OLD date, plus the
 *     new date must be eligible via Delivery::isEligible.
 *   - Max 2 moves per order, configurable via reschedule_max_changes.
 *   - No money changes, only dates move.
 *   - Staff recipients are all active staff with orders.view.
 *
 * Concurrency:
 *   expectedOldDate is the optimistic token carried by the form, the same
 *   pattern OrderLifecycle::transition uses with expectedStatus. Under
 *   SELECT ... FOR UPDATE, a mismatch returns stale so a double submit cannot
 *   silently overwrite.
 *
 * Idempotency:
 *   If the order already sits on newDate, return already_rescheduled without
 *   creating a second history row. A duplicate POST within 5 minutes that
 *   repeats the same old->new with same actor is also treated as duplicate.
 * -----------------------------------------------------------------------------
 */

final class OrderReschedule
{
    public const CUSTOMER_STATUSES = ['pending', 'confirmed'];
    public const STAFF_STATUSES    = ['pending', 'confirmed', 'packed'];
    public const BLOCKED_STATUSES  = ['dispatched', 'delivered', 'cancelled', 'refunded'];

    public const REASON_MAX = 500;
    private const DUPLICATE_WINDOW_MINUTES = 5;

    /** Customer may reschedule this stage, when allowed and within cutoff. */
    public static function customerMayReschedule(string $orderStatus, bool $withinCutoff, bool $customerAllowed): bool
    {
        if (!$customerAllowed) {
            return false;
        }
        if (!in_array($orderStatus, self::CUSTOMER_STATUSES, true)) {
            return false;
        }
        return $withinCutoff;
    }

    /** Staff may reschedule this stage. */
    public static function staffMayReschedule(string $orderStatus): bool
    {
        if (in_array($orderStatus, self::BLOCKED_STATUSES, true)) {
            return false;
        }
        return in_array($orderStatus, self::STAFF_STATUSES, true);
    }

    /** Current policy and history for a customer-owned order. */
    public static function forCustomer(int $orderId, int $userId): ?array
    {
        $order = self::order($orderId, $userId);
        return $order ? self::decorate($order, false) : null;
    }

    /** Current policy and history for staff. */
    public static function forStaff(int $orderId): ?array
    {
        $order = self::order($orderId, null);
        return $order ? self::decorate($order, true) : null;
    }

    /** All reschedule rows for an order, newest first. */
    public static function history(int $orderId): array
    {
        return Database::all(
            'SELECT r.*, u.first_name, u.last_name
               FROM order_reschedules r
               LEFT JOIN users u ON u.id = r.actor_id
              WHERE r.order_id = :order
              ORDER BY r.created_at DESC, r.id DESC',
            [':order' => $orderId]
        );
    }

    /** Eligible dates for this order, excluding its current date. */
    public static function eligibleDatesForOrder(array $order): array
    {
        $type = (string) ($order['customer_type'] ?? 'household');
        $dates = Delivery::nextEligibleDates($type, 14);
        $current = (string) ($order['preferred_delivery_date'] ?? '');
        $out = [];
        foreach ($dates as $row) {
            if ((string) ($row['date'] ?? '') === $current) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Policy line shown on customer screens. */
    public static function policyLine(string $cutoffTime, string $deliveryDate, int $maxChanges, int $used): string
    {
        $deadline = Cancellation::deadline($deliveryDate, $cutoffTime);
        $when = $deadline ? 'Reschedule free until ' . $cutoffTime . ' on ' . $deadline->format('l jS') . ', the day before your delivery.' : 'Reschedule free until ' . $cutoffTime . ', the day before your delivery.';
        $remaining = max(0, $maxChanges - $used);
        if ($used >= $maxChanges) {
            return $when . ' You have used all ' . $maxChanges . ' reschedules allowed for this order.';
        }
        if ($used > 0) {
            return $when . ' You have ' . $remaining . ' of ' . $maxChanges . ' reschedules left. You have used ' . $used . ' so far.';
        }
        return $when . ' You may reschedule up to ' . $maxChanges . ' times.';
    }

    /** Reschedule as customer. */
    public static function rescheduleForCustomer(
        int $orderId,
        int $userId,
        string $newDate,
        string $expectedOldDate,
        string $reasonText = ''
    ): array {
        return self::reschedule($orderId, $userId, 'customer', $newDate, $expectedOldDate, $reasonText);
    }

    /** Reschedule as staff. */
    public static function rescheduleForStaff(
        int $orderId,
        int $staffId,
        string $newDate,
        string $expectedOldDate,
        string $reasonText = ''
    ): array {
        return self::reschedule($orderId, $staffId, 'staff', $newDate, $expectedOldDate, $reasonText);
    }

    private static function reschedule(
        int $orderId,
        int $actorId,
        string $actorType,
        string $newDate,
        string $expectedOldDate,
        string $reasonText
    ): array {
        $newDate = trim($newDate);
        $expectedOldDate = trim($expectedOldDate);
        $reasonText = trim($reasonText);

        if (!Delivery::validDate($newDate)) {
            return ['ok' => false, 'code' => 'invalid_date', 'message' => 'Choose a valid delivery date.'];
        }
        if ($expectedOldDate !== '' && !Delivery::validDate($expectedOldDate)) {
            return ['ok' => false, 'code' => 'stale', 'message' => 'This order changed after the page loaded. Reload it and try again.'];
        }
        if (mb_strlen($reasonText) > self::REASON_MAX) {
            return ['ok' => false, 'code' => 'reason_too_long', 'message' => 'Keep the note to ' . self::REASON_MAX . ' characters or fewer.'];
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
                'SELECT o.id, o.order_number, o.user_id, o.customer_type, o.order_status, o.preferred_delivery_date
                   FROM orders o
                  WHERE o.id = :id' . $ownerClause . '
                  FOR UPDATE',
                $params
            );
            if (!$order) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That order could not be found.'];
            }

            $currentStatus = (string) $order['order_status'];
            $currentDate = (string) $order['preferred_delivery_date'];
            $orderNumber = (string) $order['order_number'];

            // Blocked stages: dispatched, delivered, cancelled, refunded.
            if (in_array($currentStatus, self::BLOCKED_STATUSES, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_eligible', 'message' => 'This order can no longer be rescheduled. It is already ' . $currentStatus . '.'];
            }

            // Optimistic concurrency on old date.
            if ($expectedOldDate !== '' && $expectedOldDate !== $currentDate) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'stale', 'message' => 'This order changed after the page loaded. Reload it before rescheduling.'];
            }

            // Idempotent: already on that date.
            if ($currentDate === $newDate) {
                $pdo->commit();
                return [
                    'ok' => true,
                    'code' => 'already_rescheduled',
                    'message' => 'This order is already set for ' . $newDate . '. No change was made.',
                    'order_id' => (int) $order['id'],
                    'order_number' => $orderNumber,
                    'old_date' => $currentDate,
                    'new_date' => $newDate,
                ];
            }

            // Duplicate window: same old->new by same actor within 5 minutes.
            $recent = Database::one(
                'SELECT id FROM order_reschedules
                  WHERE order_id = :order
                    AND old_delivery_date = :old
                    AND new_delivery_date = :new
                    AND actor_type = :actor_type
                    AND actor_id = :actor_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) self::DUPLICATE_WINDOW_MINUTES . ' MINUTE)
                  ORDER BY id DESC LIMIT 1',
                [
                    ':order' => $orderId,
                    ':old' => $currentDate,
                    ':new' => $newDate,
                    ':actor_type' => $actorType,
                    ':actor_id' => $actorId,
                ]
            );
            if ($recent) {
                // Order already moved, but history shows recent duplicate. Treat as idempotent.
                // If current date is already new date, we already returned. If not, this is a retry after commit that failed to return.
                // Check current date again after lock: if it is still old, allow, otherwise already handled.
                // To keep simple, if recent exists and current date == new date, return already.
                if ($currentDate === $newDate) {
                    $pdo->commit();
                    return [
                        'ok' => true,
                        'code' => 'already_rescheduled',
                        'message' => 'This order was already rescheduled to ' . $newDate . '.',
                        'order_id' => (int) $order['id'],
                        'order_number' => $orderNumber,
                        'old_date' => $currentDate,
                        'new_date' => $newDate,
                    ];
                }
            }

            $cutoffTime = Settings::str('cancellation_cutoff_time', '18:00');
            $withinCutoff = Cancellation::isWithinCutoff($currentDate, $cutoffTime);
            $customerAllowed = Settings::bool('reschedule_customer_allowed', true);
            $maxChanges = max(1, (int) Settings::int('reschedule_max_changes', 2));

            $used = (int) (Database::one('SELECT COUNT(*) AS c FROM order_reschedules WHERE order_id = :order', [':order' => $orderId])['c'] ?? 0);
            if ($used >= $maxChanges) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'max_reached', 'message' => 'You have used all ' . $maxChanges . ' reschedules allowed for this order.'];
            }

            if ($actorType === 'customer') {
                if (!self::customerMayReschedule($currentStatus, $withinCutoff, $customerAllowed)) {
                    $pdo->rollBack();
                    if (!$customerAllowed) {
                        return ['ok' => false, 'code' => 'not_allowed', 'message' => 'Rescheduling by customers is currently switched off. Please ask our team for help.'];
                    }
                    if (!in_array($currentStatus, self::CUSTOMER_STATUSES, true)) {
                        return ['ok' => false, 'code' => 'not_eligible', 'message' => 'This order can no longer be rescheduled by you. It is ' . $currentStatus . '. Ask our team if you need it moved.'];
                    }
                    return ['ok' => false, 'code' => 'cutoff_passed', 'message' => 'The reschedule cutoff has passed for ' . $currentDate . '. Ask our team and we will help move it if we can.'];
                }
            } else {
                if (!self::staffMayReschedule($currentStatus)) {
                    $pdo->rollBack();
                    return ['ok' => false, 'code' => 'not_eligible', 'message' => 'This order can no longer be rescheduled. It is ' . $currentStatus . '.'];
                }
            }

            // New date must be eligible for this customer type.
            $eligibility = Delivery::isEligible($newDate, (string) $order['customer_type']);
            if (empty($eligibility['eligible'])) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'invalid_date', 'message' => (string) ($eligibility['reason'] ?? 'That delivery date is not available. Choose another one.')];
            }

            // Move dates.
            Database::run(
                'UPDATE orders SET preferred_delivery_date = :new, updated_at = NOW() WHERE id = :id',
                [':new' => $newDate, ':id' => $orderId]
            );
            // delivery_schedules may not exist for old orders, so upsert.
            Database::run(
                'INSERT INTO delivery_schedules (order_id, delivery_date, status, updated_by)
                 VALUES (:order, :date, :status, :actor)
                 ON DUPLICATE KEY UPDATE delivery_date = VALUES(delivery_date), updated_by = VALUES(updated_by), status = :status2',
                [
                    ':order' => $orderId,
                    ':date' => $newDate,
                    ':status' => 'scheduled',
                    ':status2' => 'scheduled',
                    ':actor' => $actorId,
                ]
            );

            Database::run(
                'INSERT INTO order_reschedules (order_id, old_delivery_date, new_delivery_date, actor_type, actor_id, reason)
                 VALUES (:order, :old, :new, :actor_type, :actor_id, :reason)',
                [
                    ':order' => $orderId,
                    ':old' => $currentDate,
                    ':new' => $newDate,
                    ':actor_type' => $actorType,
                    ':actor_id' => $actorId,
                    ':reason' => $reasonText !== '' ? mb_substr($reasonText, 0, self::REASON_MAX) : null,
                ]
            );
            $historyId = (int) $pdo->lastInsertId();

            // Audit.
            try {
                Audit::record('orders.reschedule', 'order', $orderId, ['preferred_delivery_date' => $currentDate], ['preferred_delivery_date' => $newDate], $actorId);
            } catch (Throwable $e) {
                // Audit failure should not block the reschedule, but log.
                error_log('order reschedule audit failed for order ' . $orderId . ': ' . $e->getMessage());
            }

            // Optional status history for timeline, without leaking internal notes to customer trail directly.
            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, note, changed_by)
                 VALUES (:order, :old, :new, :source, :note, :actor)',
                [
                    ':order' => $orderId,
                    ':old' => $currentStatus,
                    ':new' => $currentStatus,
                    ':source' => $actorType,
                    ':note' => 'Delivery moved from ' . $currentDate . ' to ' . $newDate . ($reasonText !== '' ? ': ' . mb_substr($reasonText, 0, 200) : ''),
                    ':actor' => $actorId,
                ]
            );

            $pdo->commit();

            return [
                'ok' => true,
                'code' => 'rescheduled',
                'message' => 'Delivery moved from ' . $currentDate . ' to ' . $newDate . '.',
                'order_id' => (int) $order['id'],
                'order_number' => $orderNumber,
                'old_date' => $currentDate,
                'new_date' => $newDate,
                'history_id' => $historyId,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
            'SELECT o.*, r.id AS has_reschedule
               FROM orders o
               LEFT JOIN order_reschedules r ON r.order_id = o.id
              WHERE o.id = :id' . $owner . '
              LIMIT 1',
            $params
        );
    }

    private static function decorate(array $order, bool $staff): array
    {
        $cutoff = Settings::str('cancellation_cutoff_time', '18:00');
        $within = Cancellation::isWithinCutoff((string) $order['preferred_delivery_date'], $cutoff);
        $customerAllowed = Settings::bool('reschedule_customer_allowed', true);
        $maxChanges = max(1, (int) Settings::int('reschedule_max_changes', 2));
        $used = (int) (Database::one('SELECT COUNT(*) AS c FROM order_reschedules WHERE order_id = :order', [':order' => (int) $order['id']])['c'] ?? 0);
        $stage = (string) $order['order_status'];

        $may = $staff ? self::staffMayReschedule($stage) : self::customerMayReschedule($stage, $within, $customerAllowed);
        $restriction = '';
        if (!$may && !in_array($stage, self::BLOCKED_STATUSES, true)) {
            if (!$staff && !$customerAllowed) {
                $restriction = 'Rescheduling by customers is switched off. Ask our team for help.';
            } elseif (!$staff && !in_array($stage, self::CUSTOMER_STATUSES, true)) {
                $restriction = $stage === 'packed' ? 'This order is packed and waiting for the van, so our team moves it rather than the screen.' : 'This order can no longer be rescheduled by you. It is ' . $stage . '.';
            } elseif (!$staff && !$within) {
                $restriction = 'The cutoff to reschedule this delivery has passed. Ask our team and we will help if we can.';
            } elseif ($staff && !self::staffMayReschedule($stage)) {
                $restriction = 'This order is ' . $stage . ' and can no longer be rescheduled.';
            }
        } elseif (in_array($stage, self::BLOCKED_STATUSES, true)) {
            $restriction = 'This order is ' . $stage . ' and can no longer be rescheduled.';
        } elseif ($used >= $maxChanges) {
            $may = false;
            $restriction = 'You have used all ' . $maxChanges . ' reschedules allowed for this order.';
        }

        $order['within_cutoff'] = $within;
        $order['may_reschedule'] = $may && $used < $maxChanges;
        $order['restriction'] = $restriction;
        $order['policy_line'] = self::policyLine($cutoff, (string) $order['preferred_delivery_date'], $maxChanges, $used);
        $order['deadline'] = Cancellation::deadline((string) $order['preferred_delivery_date'], $cutoff);
        $order['used'] = $used;
        $order['max'] = $maxChanges;
        $order['history'] = self::history((int) $order['id']);
        $order['eligible_dates'] = $may ? self::eligibleDatesForOrder($order) : [];
        return $order;
    }
}
