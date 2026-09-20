<?php
/**
 * Read-only customer notification feed and actionable attention counts.
 *
 * A customer only ever sees the in-app copy of the messages sent to them, so
 * the boundary here is the set of customer-audience events in Notifications,
 * read straight from that catalogue so the two can never drift. Only delivered
 * (sent) in-app rows are surfaced: a held receipt, such as the pay-in-full
 * confirmation waiting on Paystack, is written ahead of time and must not
 * appear until it is true.
 *
 * This class knows the signed-in customer only through the user id the caller
 * passes. Every read and write is scoped to that id and to the in-app channel.
 */
final class CustomerNotifications
{
    public const RECENT_LIMIT = 20;

    /** Customer-audience events, taken from the one notification catalogue. */
    public static function events(): array
    {
        $events = [];
        foreach (Notifications::EVENTS as $event => $definition) {
            if (($definition['audience'] ?? '') === 'customer') {
                $events[] = $event;
            }
        }
        return $events;
    }

    /** The signed-in customer's own page for a notification's subject. */
    public static function hrefFor(string $relatedType, ?int $relatedId): string
    {
        $id = max(0, (int) $relatedId);
        return match ($relatedType) {
            'order' => $id > 0 ? '/public/order.php?order=' . $id : '/account.php',
            'kitchen_run' => $id > 0 ? '/kitchen-runs.php?request=' . $id : '/kitchen-runs.php',
            'credit_application' => '/pro/credit.php',
            default => '/account.php',
        };
    }

    /** Recent in-app updates for one customer, newest first. */
    public static function recent(int $userId, int $limit = self::RECENT_LIMIT): array
    {
        $events = self::events();
        if ($userId < 1 || $events === []) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        $params[':sent'] = Notifications::STATUS_SENT;
        $rows = Database::all(
            'SELECT n.id, n.event_type, n.title, n.body, n.related_type, n.related_id,
                    n.created_at, d.id AS delivery_id, d.read_at
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.user_id = :user AND d.channel = :channel AND d.status = :sent
                AND n.event_type IN (' . $in . ')
              ORDER BY d.id DESC
              LIMIT ' . $limit,
            $params
        );
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['delivery_id'] = (int) $row['delivery_id'];
            $row['related_id'] = $row['related_id'] === null ? null : (int) $row['related_id'];
            $row['is_read'] = $row['read_at'] !== null;
            $row['href'] = self::hrefFor((string) ($row['related_type'] ?? ''), $row['related_id']);
        }
        unset($row);
        return $rows;
    }

    public static function unreadCount(int $userId): int
    {
        $events = self::events();
        if ($userId < 1 || $events === []) {
            return 0;
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        $params[':sent'] = Notifications::STATUS_SENT;
        $row = Database::one(
            'SELECT COUNT(*) AS n
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.user_id = :user AND d.channel = :channel AND d.status = :sent
                AND d.read_at IS NULL AND n.event_type IN (' . $in . ')',
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    /** Mark one of the customer's own in-app updates read. */
    public static function markRead(int $userId, int $deliveryId): bool
    {
        if ($userId < 1 || $deliveryId < 1) {
            return false;
        }
        return Database::run(
            'UPDATE notification_deliveries
                SET read_at = COALESCE(read_at, NOW())
              WHERE id = :delivery AND user_id = :user AND channel = :channel',
            [':delivery' => $deliveryId, ':user' => $userId, ':channel' => Notifications::CHANNEL_IN_APP]
        ) > 0;
    }

    public static function markAllRead(int $userId): int
    {
        $events = self::events();
        if ($userId < 1 || $events === []) {
            return 0;
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        $params[':sent'] = Notifications::STATUS_SENT;
        return Database::run(
            'UPDATE notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
                SET d.read_at = NOW()
              WHERE d.user_id = :user AND d.channel = :channel AND d.status = :sent
                AND d.read_at IS NULL
                AND n.event_type IN (' . $in . ')',
            $params
        );
    }

    /**
     * Work the customer can still act on. Each count is read-only and scoped
     * to this customer, so it is safe to show without any further gate.
     */
    public static function attention(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $items = [];
        self::addAttention(
            $items,
            'Payments to complete',
            self::countWhere(
                'SELECT COUNT(DISTINCT o.id) AS n
                   FROM orders o
                   JOIN payments p ON p.order_id = o.id
                  WHERE o.user_id = :user
                    AND o.order_status <> :cancelled
                    AND p.provider = :provider
                    AND p.status IN (:unpaid, :part_paid)
                    AND p.expected_amount_subunit > p.paid_amount_subunit',
                [
                    ':user' => $userId,
                    ':cancelled' => 'cancelled',
                    ':provider' => 'paystack',
                    ':unpaid' => Payments::STATUS_UNPAID,
                    ':part_paid' => Payments::STATUS_PART_PAID,
                ]
            ),
            '/account.php'
        );
        self::addAttention(
            $items,
            'Kitchen Run quotes to review',
            self::countWhere(
                'SELECT COUNT(*) AS n FROM kitchen_run_requests
                  WHERE user_id = :user AND status = :quoted',
                [':user' => $userId, ':quoted' => 'quoted']
            ),
            '/kitchen-runs.php'
        );
        return $items;
    }

    public static function payload(int $userId): array
    {
        return [
            'unread_count' => self::unreadCount($userId),
            'attention' => self::attention($userId),
            'notifications' => self::recent($userId),
        ];
    }

    private static function countWhere(string $sql, array $params): int
    {
        $row = Database::one($sql, $params);
        return (int) ($row['n'] ?? 0);
    }

    private static function addAttention(array &$items, string $label, int $count, string $href): void
    {
        if ($count < 1) {
            return;
        }
        $items[] = ['label' => $label, 'count' => $count, 'href' => $href];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function eventParams(array $events): array
    {
        $names = [];
        $params = [];
        foreach (array_values($events) as $index => $event) {
            $name = ':event_' . $index;
            $names[] = $name;
            $params[$name] = $event;
        }
        return [implode(', ', $names), $params];
    }
}
