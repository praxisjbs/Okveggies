<?php
/**
 * Read-only business dashboard summaries for the Pro Portal.
 *
 * Every database read is scoped by the signed-in customer's user id or by the
 * business profile found through that id. Delivery dates come from Delivery,
 * which owns the configured weekday, lead, cutoff and exception rules.
 */
final class ProDashboard
{
    private const TZ = 'Africa/Lagos';

    public static function overview(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ)))
            ->setTimezone(new DateTimeZone(self::TZ));

        $business = Database::one(
            'SELECT id, business_name, credit_status, credit_days, credit_limit_subunit
               FROM business_customers
              WHERE user_id = :user_id
              LIMIT 1',
            [':user_id' => $userId]
        );

        $orders = Database::all(
            'SELECT id, order_number, order_status, order_total_subunit, preferred_delivery_date, created_at
               FROM orders
              WHERE user_id = :user_id
              ORDER BY created_at DESC, id DESC
              LIMIT 5',
            [':user_id' => $userId]
        );
        foreach ($orders as &$order) {
            $order['status_label'] = OrderLifecycle::customerLabel((string) $order['order_status']);
        }
        unset($order);

        $lists = Database::all(
            'SELECT t.id, t.name, t.updated_at,
                    (SELECT COUNT(*) FROM kitchen_run_template_items i WHERE i.template_id = t.id) AS item_count
               FROM kitchen_run_templates t
              WHERE t.user_id = :user_id
              ORDER BY t.updated_at DESC, t.id DESC
              LIMIT 3',
            [':user_id' => $userId]
        );

        $deliveryDates = Delivery::nextEligibleDates('business', 1, $now);

        return [
            'business' => $business,
            'credit' => $business === null ? self::creditSnapshot(null, null) : Credit::summaryForBusiness($business, $now),
            'orders' => $orders,
            'saved_lists' => $lists,
            'next_delivery' => $deliveryDates[0] ?? null,
        ];
    }

    /** Build the displayed credit figures from integer-subunit database values. */
    public static function creditSnapshot(?array $business, ?array $ledger): array
    {
        if ($business === null) {
            return [
                'state' => 'not_requested',
                'approved' => false,
                'limit_subunit' => 0,
                'outstanding_subunit' => 0,
                'available_subunit' => 0,
                'overdue_subunit' => 0,
                'earliest_due_date' => null,
            ];
        }

        return Credit::snapshot($business, $ledger ?? []);
    }
}
