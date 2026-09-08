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

        $ledger = null;
        if ($business !== null && (string) $business['credit_status'] === 'approved') {
            $ledger = Database::one(
                'SELECT COALESCE(SUM(CASE WHEN status = :open_total THEN amount_subunit ELSE 0 END), 0) AS outstanding_subunit,
                        MIN(CASE WHEN status = :open_due AND amount_subunit > 0 THEN due_date END) AS earliest_due_date,
                        COALESCE(SUM(CASE WHEN status = :open_overdue AND amount_subunit > 0 AND due_date < :today THEN amount_subunit ELSE 0 END), 0) AS overdue_subunit
                   FROM credit_transactions
                  WHERE business_customer_id = :business_id',
                [
                    ':open_total' => 'open',
                    ':open_due' => 'open',
                    ':open_overdue' => 'open',
                    ':today' => $now->format('Y-m-d'),
                    ':business_id' => (int) $business['id'],
                ]
            );
        }

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
            'credit' => self::creditSnapshot($business, $ledger),
            'orders' => $orders,
            'saved_lists' => $lists,
            'next_delivery' => $deliveryDates[0] ?? null,
        ];
    }

    /** Build the displayed credit figures from integer-subunit database values. */
    public static function creditSnapshot(?array $business, ?array $ledger): array
    {
        $approved = $business !== null
            && (string) ($business['credit_status'] ?? '') === 'approved'
            && $business['credit_limit_subunit'] !== null;

        if (!$approved) {
            return [
                'approved' => false,
                'limit_subunit' => 0,
                'outstanding_subunit' => 0,
                'available_subunit' => 0,
                'overdue_subunit' => 0,
                'earliest_due_date' => null,
            ];
        }

        $limit = max(0, (int) $business['credit_limit_subunit']);
        $outstanding = max(0, (int) ($ledger['outstanding_subunit'] ?? 0));

        return [
            'approved' => true,
            'limit_subunit' => $limit,
            'outstanding_subunit' => $outstanding,
            'available_subunit' => max(0, $limit - $outstanding),
            'overdue_subunit' => max(0, (int) ($ledger['overdue_subunit'] ?? 0)),
            'earliest_due_date' => !empty($ledger['earliest_due_date']) ? (string) $ledger['earliest_due_date'] : null,
        ];
    }
}
