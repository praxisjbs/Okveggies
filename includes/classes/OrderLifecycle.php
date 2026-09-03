<?php
/** Atomic, staff-operated order lifecycle transitions for M6. */
final class OrderLifecycle
{
    private const MAP = [
        'pending'    => ['confirmed', 'cancelled'],
        'confirmed'  => ['packed', 'cancelled'],
        'packed'     => ['dispatched', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    private const PUBLIC_LABELS = [
        'pending'    => 'Placed',
        'confirmed'  => 'Sourced',
        'packed'     => 'Packed',
        'dispatched' => 'Dispatched',
        'delivered'  => 'Delivered',
        'cancelled'  => 'Cancelled',
    ];

    public static function mayTransition(string $from, string $to): bool
    {
        return in_array($to, self::MAP[$from] ?? [], true);
    }

    /** Cancellation remains on the dedicated M5/M6 cancellation path. */
    public static function staffTargets(string $status): array
    {
        return array_values(array_filter(
            self::MAP[$status] ?? [],
            static fn(string $target): bool => $target !== 'cancelled'
        ));
    }

    /** Public projection deliberately drops notes, actors and internal labels. */
    public static function customerTrail(array $history): array
    {
        $trail = [];
        foreach ($history as $event) {
            $status = (string) ($event['new_status'] ?? '');
            if (!isset(self::PUBLIC_LABELS[$status])) {
                continue;
            }
            $trail[] = [
                'status' => $status === 'confirmed' ? 'sourced' : $status,
                'label' => self::PUBLIC_LABELS[$status],
                'created_at' => (string) ($event['created_at'] ?? ''),
            ];
        }
        return $trail;
    }

    /**
     * Change status and append history in one transaction. expectedStatus is
     * the optimistic-concurrency token carried by the form.
     */
    public static function transition(
        int $orderId,
        string $expectedStatus,
        string $targetStatus,
        int $actorId,
        string $note = '',
        string $source = 'admin'
    ): array {
        if ($orderId < 1 || !isset(self::MAP[$expectedStatus]) || !isset(self::MAP[$targetStatus])) {
            return self::failure('invalid_transition', 'Choose a valid order stage.');
        }
        if ($targetStatus === 'cancelled') {
            return self::failure('cancellation_flow', 'Use the cancellation action so its money rules are applied.');
        }
        if (!self::mayTransition($expectedStatus, $targetStatus)) {
            return self::failure('invalid_transition', 'That order cannot move to the chosen stage.');
        }
        if (mb_strlen($note) > 500) {
            return self::failure('note_too_long', 'Keep the internal note to 500 characters.');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $order = Database::one('SELECT id, order_status, preferred_delivery_date FROM orders WHERE id = :id FOR UPDATE', [':id' => $orderId]);
            if (!$order) {
                $pdo->rollBack();
                return self::failure('not_found', 'That order could not be found.');
            }
            $current = (string) $order['order_status'];
            if ($current === $targetStatus) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'already_transitioned', 'status' => $current];
            }
            if ($current !== $expectedStatus) {
                $pdo->rollBack();
                return self::failure('stale', 'This order changed after the page loaded. Reload it before choosing the next stage.');
            }
            $sets = ['order_status = :target'];
            $params = [':target' => $targetStatus, ':id' => $orderId];
            if ($targetStatus === 'confirmed') {
                $sets[] = 'confirmed_at = NOW()';
                $sets[] = 'source_regions_snapshot = :regions';
                $params[':regions'] = mb_substr(trim(Settings::str('source_regions', '')), 0, 255) ?: null;
            } elseif ($targetStatus === 'delivered') {
                $sets[] = 'delivered_at = NOW()';
            }
            Database::run('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, note, changed_by)
                 VALUES (:order, :old, :new, :source, :note, :actor)',
                [':order' => $orderId, ':old' => $current, ':new' => $targetStatus,
                 ':source' => mb_substr($source, 0, 30), ':note' => trim($note) ?: null, ':actor' => $actorId]
            );
            self::syncSchedule($orderId, (string) $order['preferred_delivery_date'], $targetStatus, $actorId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'transitioned', 'status' => $targetStatus];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function syncSchedule(int $orderId, string $date, string $status, int $actorId): void
    {
        $scheduleStatus = match ($status) {
            'dispatched' => 'dispatched',
            'delivered' => 'delivered',
            default => 'scheduled',
        };
        Database::run(
            'INSERT INTO delivery_schedules (order_id, delivery_date, status, delivered_at, updated_by)
             VALUES (:order, :date, :status, :delivered, :actor)
             ON DUPLICATE KEY UPDATE delivery_date = VALUES(delivery_date), status = VALUES(status),
                 delivered_at = VALUES(delivered_at), updated_by = VALUES(updated_by)',
            [':order' => $orderId, ':date' => $date, ':status' => $scheduleStatus,
             ':delivered' => $status === 'delivered' ? date('Y-m-d H:i:s') : null, ':actor' => $actorId]
        );
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
