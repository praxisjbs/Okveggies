<?php
/** Customer feed scoping, unread and attention behaviour against MySQL 8. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function cnb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cnb_eq($expected, $actual, string $label): void { cnb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$pdo = Database::getInstance()->getConnection();
$userIds = []; $notificationIds = []; $orderId = 0; $paymentId = 0; $runId = 0;

try {
    foreach (['owner', 'other'] as $key) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [
                ':first' => ucfirst($key), ':last' => 'Shopper', ':email' => 'shopper-' . $key . '-' . $suffix . '@example.test',
                ':phone' => '+23470' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
                ':type' => 'household', ':status' => 'active',
            ]
        );
        $userIds[$key] = (int) $pdo->lastInsertId();
    }
    $owner = $userIds['owner'];
    $other = $userIds['other'];

    // Three in-app rows for the owner: a delivered customer update (counts), a
    // held customer receipt not yet true (never shown), and a staff alert that
    // must never reach a customer bell.
    $rows = [
        ['order_placed', 'order', 501, 'We have your order', Notifications::STATUS_SENT, $owner],
        ['payment_confirmed', 'order', 501, 'Payment received', 'queued', $owner],
        ['admin_new_order', 'order', 501, 'New order, staff', Notifications::STATUS_SENT, $owner],
        ['order_dispatched', 'order', 501, 'On its way', Notifications::STATUS_SENT, $other],
    ];
    $ownerSentDelivery = 0; $otherDelivery = 0;
    foreach ($rows as [$event, $type, $related, $title, $deliveryStatus, $userId]) {
        Database::run(
            'INSERT INTO notifications (event_type, related_type, related_id, title, body, status)
             VALUES (:event, :type, :related, :title, :body, :status)',
            [':event' => $event, ':type' => $type, ':related' => $related, ':title' => $title, ':body' => 'Feed test body.', ':status' => 'sent']
        );
        $notificationId = (int) $pdo->lastInsertId();
        $notificationIds[] = $notificationId;
        Database::run(
            'INSERT INTO notification_deliveries
                (notification_id, user_id, channel, recipient_address, status, attempt_count, sent_at)
             VALUES (:notification, :user, :channel, :address, :status, :attempts, NOW())',
            [
                ':notification' => $notificationId, ':user' => $userId,
                ':channel' => Notifications::CHANNEL_IN_APP, ':address' => 'in-app',
                ':status' => $deliveryStatus, ':attempts' => 1,
            ]
        );
        $deliveryId = (int) $pdo->lastInsertId();
        if ($event === 'order_placed' && $userId === $owner) { $ownerSentDelivery = $deliveryId; }
        if ($userId === $other) { $otherDelivery = $deliveryId; }
    }

    cnb_eq(1, CustomerNotifications::unreadCount($owner), 'only the delivered customer update is counted, not the held receipt or the staff alert');
    $recent = CustomerNotifications::recent($owner);
    cnb_eq(1, count($recent), 'the feed shows exactly the one delivered customer update');
    cnb_eq('order_placed', (string) $recent[0]['event_type'], 'the delivered customer update is the one shown');
    cnb_eq('/public/order.php?order=501', (string) $recent[0]['href'], 'the update deep links to the signed-in order');
    cnb_eq(false, (bool) $recent[0]['is_read'], 'a new update starts unread');

    cnb_eq(false, CustomerNotifications::markRead($owner, $otherDelivery), 'a customer cannot mark another customer\'s update through a guessed id');
    cnb_eq(1, CustomerNotifications::unreadCount($other), 'the other customer\'s unread update is untouched');
    cnb_eq(true, CustomerNotifications::markRead($owner, $ownerSentDelivery), 'a customer can mark their own update read');
    cnb_eq(0, CustomerNotifications::unreadCount($owner), 'the unread count falls after an individual read');

    Database::run('UPDATE notification_deliveries SET read_at = NULL WHERE id = :id', [':id' => $ownerSentDelivery]);
    cnb_eq(1, CustomerNotifications::markAllRead($owner), 'mark all changes only the owner\'s permitted unread row');
    cnb_eq(0, CustomerNotifications::unreadCount($owner), 'mark all clears the unread count');

    cnb_eq([], CustomerNotifications::attention($owner), 'a customer with nothing owed has no attention items');

    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date)',
        [
            ':number' => 'SHOP-' . $suffix, ':user' => $owner, ':customer_type' => 'household', ':order_status' => 'confirmed',
            ':payment_option' => 'pay_in_full', ':payment_status' => 'unpaid',
            ':subtotal' => 500000, ':total' => 500000, ':balance' => 500000,
            ':delivery_date' => date('Y-m-d', strtotime('+2 days')),
        ]
    );
    $orderId = (int) $pdo->lastInsertId();
    Database::run(
        'INSERT INTO payments
            (payment_number, user_id, order_id, provider, payment_type,
             expected_amount_subunit, paid_amount_subunit, status)
         VALUES (:number, :user, :order, :provider, :ptype, :expected, :paid, :status)',
        [
            ':number' => 'PAY-' . $suffix, ':user' => $owner, ':order' => $orderId, ':provider' => 'paystack',
            ':ptype' => 'online', ':expected' => 500000, ':paid' => 0, ':status' => Payments::STATUS_UNPAID,
        ]
    );
    $paymentId = (int) $pdo->lastInsertId();

    Database::run(
        'INSERT INTO kitchen_run_requests (request_number, user_id, customer_type, input_mode, pricing_mode, status)
         VALUES (:number, :user, :customer_type, :input_mode, :pricing_mode, :status)',
        [
            ':number' => 'KR-' . $suffix, ':user' => $owner, ':customer_type' => 'business',
            ':input_mode' => 'text', ':pricing_mode' => 'by_us', ':status' => 'quoted',
        ]
    );
    $runId = (int) $pdo->lastInsertId();

    $labels = array_column(CustomerNotifications::attention($owner), 'label');
    cnb_ok(in_array('Payments to complete', $labels, true), 'an order with an online balance surfaces a payment to complete');
    cnb_ok(in_array('Kitchen Run quotes to review', $labels, true), 'a quoted Kitchen Run surfaces a quote to review');
    cnb_eq([], CustomerNotifications::attention($other), 'the attention queue is scoped to the customer, not shared');
} finally {
    if ($runId > 0) { Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $runId]); }
    if ($paymentId > 0) { Database::run('DELETE FROM payments WHERE id = :id', [':id' => $paymentId]); }
    if ($orderId > 0) { Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]); }
    foreach ($notificationIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
    }
    foreach ($userIds as $id) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests customer notification database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
