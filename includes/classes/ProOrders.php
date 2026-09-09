<?php
/** Read-only business order history and owned order detail for the Pro Portal. */
final class ProOrders
{
    public const PER_PAGE = 25;
    public const STATUSES = ['pending', 'confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'];
    public const PAYMENT_STATES = ['unpaid', 'part_paid', 'paid'];

    public static function listing(int $userId, array $filters, int $page): array
    {
        $where = ['o.user_id = :user_id'];
        $params = [':user_id' => $userId];
        $status = in_array((string) ($filters['status'] ?? ''), self::STATUSES, true) ? (string) $filters['status'] : '';
        $payment = in_array((string) ($filters['payment'] ?? ''), self::PAYMENT_STATES, true) ? (string) $filters['payment'] : '';
        $delivery = Delivery::validDate((string) ($filters['delivery'] ?? '')) ? (string) $filters['delivery'] : '';
        if ($status !== '') { $where[] = 'o.order_status = :status'; $params[':status'] = $status; }
        if ($payment !== '') { $where[] = 'o.payment_status = :payment'; $params[':payment'] = $payment; }
        if ($delivery !== '') { $where[] = 'o.preferred_delivery_date = :delivery'; $params[':delivery'] = $delivery; }
        $clause = implode(' AND ', $where);
        $count = (int) (Database::one('SELECT COUNT(*) AS total FROM orders o WHERE ' . $clause, $params)['total'] ?? 0);
        $lastPage = max(1, (int) ceil($count / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = Database::getInstance()->getConnection()->prepare(
            'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.order_total_subunit,
                    o.balance_due_subunit, o.preferred_delivery_date, o.created_at
               FROM orders o WHERE ' . $clause . '
              ORDER BY o.created_at DESC, o.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $orders = $stmt->fetchAll();
        foreach ($orders as &$order) { $order['status_label'] = OrderLifecycle::customerLabel((string) $order['order_status']); }
        unset($order);
        return compact('orders', 'count', 'page', 'lastPage', 'status', 'payment', 'delivery');
    }

    public static function detail(int $orderId, int $userId): ?array
    {
        $document = OrderDocument::loadForCustomer($orderId, $userId);
        if ($document === null) { return null; }
        $history = Database::all(
            'SELECT new_status, created_at FROM order_status_history WHERE order_id = :order_id ORDER BY created_at, id',
            [':order_id' => $orderId]
        );
        $kitchenRun = Database::one(
            'SELECT id, request_number FROM kitchen_run_requests WHERE converted_order_id = :order_id AND user_id = :user_id',
            [':order_id' => $orderId, ':user_id' => $userId]
        );
        $creditCharge = Database::one(
            'SELECT ct.id, ct.amount_subunit, ct.due_date, ct.status
               FROM credit_transactions ct
               JOIN business_customers bc ON bc.id = ct.business_customer_id
              WHERE ct.order_id = :order_id AND bc.user_id = :user_id AND ct.transaction_type = :charge
              ORDER BY ct.id LIMIT 1',
            [':order_id' => $orderId, ':user_id' => $userId, ':charge' => 'charge']
        );
        $document['trail'] = OrderLifecycle::customerTrail($history);
        $document['kitchen_run'] = $kitchenRun;
        $document['credit_charge'] = $creditCharge;
        return $document;
    }
}
