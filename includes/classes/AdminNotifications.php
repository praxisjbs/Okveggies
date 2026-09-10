<?php
/**
 * Read-only staff notification feed and operational attention counts.
 * The event catalogue is also the permission boundary for bell, API and page.
 */
final class AdminNotifications
{
    public const RECENT_LIMIT = 20;
    public const HISTORY_LIMIT = 100;

    public const EVENTS = [
        'admin_new_order' => ['permission' => 'orders.view', 'related_type' => 'order'],
        'admin_new_kitchen_run' => ['permission' => 'kitchen_runs.view', 'related_type' => 'kitchen_run'],
        'admin_kitchen_run_approved' => ['permission' => 'kitchen_runs.view', 'related_type' => 'kitchen_run'],
        'admin_kitchen_run_cancelled' => ['permission' => 'kitchen_runs.view', 'related_type' => 'kitchen_run'],
        'admin_manual_payment_proof' => ['permission' => 'payments.view', 'related_type' => 'payment_proof'],
        'refund_failed' => ['permission' => 'payments.view', 'related_type' => 'order'],
        'admin_new_contact' => ['permission' => 'messages.view', 'related_type' => 'contact_message'],
        'admin_new_credit_application' => ['permission' => 'credit.view', 'related_type' => 'credit_application'],
        'admin_new_issue_report' => ['permission' => 'issues.view', 'related_type' => 'issue_report'],
    ];

    public static function permissionForEvent(string $event): ?string
    {
        return self::EVENTS[$event]['permission'] ?? null;
    }

    public static function hrefFor(string $relatedType, ?int $relatedId): string
    {
        $id = max(0, (int) $relatedId);
        return match ($relatedType) {
            'order' => '/admin/orders.php' . ($id > 0 ? '?order=' . $id : ''),
            'kitchen_run' => '/admin/kitchen_runs.php' . ($id > 0 ? '?request=' . $id : ''),
            'payment_proof' => '/admin/payments.php#queue-heading',
            'contact_message' => '/admin/content.php' . ($id > 0 ? '?message=' . $id : ''),
            'credit_application' => '/admin/credit.php',
            'issue_report' => '/admin/make_it_right.php' . ($id > 0 ? '?issue=' . $id : ''),
            default => '/admin/',
        };
    }

    /** @return array<int, string> */
    public static function allowedEvents(): array
    {
        $allowed = [];
        foreach (self::EVENTS as $event => $definition) {
            if (Rbac::can((string) $definition['permission'])) {
                $allowed[] = $event;
            }
        }
        return $allowed;
    }

    /** Recent permission-safe notification messages for one staff member. */
    public static function recent(int $userId, bool $history = false): array
    {
        $events = self::allowedEvents();
        if ($userId < 1 || !$events) {
            return [];
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        $limit = $history ? self::HISTORY_LIMIT : self::RECENT_LIMIT;
        $rows = Database::all(
            'SELECT n.id, n.event_type, n.title, n.body, n.related_type, n.related_id,
                    n.priority, n.created_at, d.id AS delivery_id, d.read_at
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.user_id = :user AND d.channel = :channel
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
        $events = self::allowedEvents();
        if ($userId < 1 || !$events) {
            return 0;
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        $row = Database::one(
            'SELECT COUNT(*) AS n
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.user_id = :user AND d.channel = :channel AND d.read_at IS NULL
                AND n.event_type IN (' . $in . ')',
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    public static function markRead(int $userId, int $deliveryId): bool
    {
        $events = self::allowedEvents();
        if ($userId < 1 || $deliveryId < 1 || !$events) {
            return false;
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':delivery'] = $deliveryId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        return Database::run(
            'UPDATE notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
                SET d.read_at = COALESCE(d.read_at, NOW())
              WHERE d.id = :delivery AND d.user_id = :user AND d.channel = :channel
                AND n.event_type IN (' . $in . ')',
            $params
        ) > 0;
    }

    public static function markAllRead(int $userId): int
    {
        $events = self::allowedEvents();
        if ($userId < 1 || !$events) {
            return 0;
        }
        [$in, $params] = self::eventParams($events);
        $params[':user'] = $userId;
        $params[':channel'] = Notifications::CHANNEL_IN_APP;
        return Database::run(
            'UPDATE notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
                SET d.read_at = NOW()
              WHERE d.user_id = :user AND d.channel = :channel AND d.read_at IS NULL
                AND n.event_type IN (' . $in . ')',
            $params
        );
    }

    /** Live work queues. Only a queue whose page the user may open is queried. */
    public static function attention(): array
    {
        $items = [];
        if (Rbac::can('orders.view')) {
            self::addAttention($items, 'Orders to confirm', self::countWhere('SELECT COUNT(*) AS n FROM orders WHERE order_status = :value', 'pending'), '/admin/orders.php?status=pending');
        }
        if (Rbac::can('kitchen_runs.view')) {
            self::addAttention($items, 'Kitchen Runs to price', self::countWhere('SELECT COUNT(*) AS n FROM kitchen_run_requests WHERE status = :value', 'submitted'), '/admin/kitchen_runs.php?status=submitted');
        }
        if (Rbac::can('payments.view')) {
            self::addAttention($items, 'Payment proofs to review', self::countWhere('SELECT COUNT(*) AS n FROM manual_payment_proofs WHERE status = :value', ManualPayments::PROOF_PENDING), '/admin/payments.php#queue-heading');
            self::addAttention($items, 'Failed refunds to check', self::countWhere('SELECT COUNT(*) AS n FROM refunds WHERE status = :value', Refunds::STATUS_FAILED), '/admin/payments.php#recent-heading');
        }
        if (Rbac::can('messages.view')) {
            self::addAttention($items, 'New customer messages', self::countWhere('SELECT COUNT(*) AS n FROM contact_messages WHERE status = :value', 'new'), '/admin/content.php?status=new');
        }
        if (Rbac::can('credit.view')) {
            self::addAttention($items, 'Credit applications to review', self::countWhere('SELECT COUNT(*) AS n FROM credit_applications WHERE status = :value', 'pending'), '/admin/credit.php');
        }
        if (Rbac::can('issues.view')) {
            self::addAttention($items, 'Make It Right reports to resolve', self::countWhere('SELECT COUNT(*) AS n FROM issue_reports WHERE status = :value', 'open'), '/admin/make_it_right.php');
        }
        return $items;
    }

    public static function payload(int $userId, bool $history = false): array
    {
        return [
            'unread_count' => self::unreadCount($userId),
            'attention' => self::attention(),
            'notifications' => self::recent($userId, $history),
        ];
    }

    private static function countWhere(string $sql, string $value): int
    {
        $row = Database::one($sql, [':value' => $value]);
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
