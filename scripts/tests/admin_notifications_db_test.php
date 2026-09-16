<?php
/** Permission, unread and attention behaviour against a migrated MySQL 8 database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function anb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function anb_eq($expected, $actual, string $label): void { anb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$roleIds = []; $userIds = []; $notificationIds = []; $orderId = 0;
$pdo = Database::getInstance()->getConnection();

try {
    foreach (['orders' => ['dashboard.view', 'orders.view'], 'blocked' => ['dashboard.view']] as $key => $permissions) {
        Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => 'bell_' . $key . '_' . $suffix, ':description' => 'Admin bell test']);
        $roleId = (int) $pdo->lastInsertId();
        $roleIds[$key] = $roleId;
        foreach ($permissions as $permission) {
            Database::run(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :role, id FROM permissions WHERE `key` = :permission',
                [':role' => $roleId, ':permission' => $permission]
            );
        }
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [
                ':first' => ucfirst($key), ':last' => 'Bell', ':email' => 'bell-' . $key . '-' . $suffix . '@example.test',
                ':phone' => '+23472' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
                ':type' => 'staff', ':status' => 'active',
            ]
        );
        $userId = (int) $pdo->lastInsertId();
        $userIds[$key] = $userId;
        Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $userId, ':role' => $roleId]);
    }

    $recipients = Notifications::staffRecipients('orders.view');
    $recipientIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $recipients);
    anb_ok(in_array($userIds['orders'], $recipientIds, true), 'a custom staff member with orders.view receives order alerts');
    anb_ok(!in_array($userIds['blocked'], $recipientIds, true), 'a staff member without orders.view receives no order alert');

    foreach ([
        ['admin_new_order', 'order', 77, 'New order'],
        ['admin_manual_payment_proof', 'payment_proof', 88, 'Payment proof'],
    ] as [$event, $type, $related, $title]) {
        Database::run(
            'INSERT INTO notifications (event_type, related_type, related_id, title, body, status)
             VALUES (:event, :type, :related, :title, :body, :status)',
            [':event' => $event, ':type' => $type, ':related' => $related, ':title' => $title, ':body' => 'Bell test body.', ':status' => 'sent']
        );
        $notificationId = (int) $pdo->lastInsertId();
        $notificationIds[] = $notificationId;
        Database::run(
            'INSERT INTO notification_deliveries
                (notification_id, user_id, channel, recipient_address, status, attempt_count, sent_at)
             VALUES (:notification, :user, :channel, :address, :status, :attempts, NOW())',
            [
                ':notification' => $notificationId, ':user' => $userIds['orders'],
                ':channel' => Notifications::CHANNEL_IN_APP, ':address' => 'in-app',
                ':status' => Notifications::STATUS_SENT, ':attempts' => 1,
            ]
        );
    }

    Rbac::loadFromDb($userIds['orders']);
    $recent = AdminNotifications::recent($userIds['orders']);
    anb_eq(1, count($recent), 'the feed removes a previously delivered alert after permission filtering');
    anb_eq('admin_new_order', (string) $recent[0]['event_type'], 'the permitted order alert remains');
    anb_eq('/admin/orders.php?order=77', (string) $recent[0]['href'], 'the permitted alert receives its safe deep link');
    anb_eq(1, AdminNotifications::unreadCount($userIds['orders']), 'the badge counts only permitted unread alerts');

    $forbiddenDelivery = (int) Database::one(
        'SELECT d.id FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id
          WHERE d.user_id = :user AND n.event_type = :event',
        [':user' => $userIds['orders'], ':event' => 'admin_manual_payment_proof']
    )['id'];
    anb_eq(false, AdminNotifications::markRead($userIds['orders'], $forbiddenDelivery), 'a hidden alert cannot be marked through a guessed delivery id');

    $allowedDelivery = (int) $recent[0]['delivery_id'];
    anb_eq(true, AdminNotifications::markRead($userIds['orders'], $allowedDelivery), 'a permitted alert can be marked read');
    anb_eq(0, AdminNotifications::unreadCount($userIds['orders']), 'the unread count falls after an individual read');
    Database::run('UPDATE notification_deliveries SET read_at = NULL WHERE id = :id', [':id' => $allowedDelivery]);
    anb_eq(1, AdminNotifications::markAllRead($userIds['orders']), 'mark all changes only the permitted unread row');
    anb_eq(0, AdminNotifications::unreadCount($userIds['orders']), 'mark all clears the permitted unread count');

    Database::run(
        'INSERT INTO orders
            (order_number, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date)',
        [
            ':number' => 'BELL-' . $suffix, ':customer_type' => 'household', ':order_status' => 'pending',
            ':payment_option' => 'pay_on_delivery', ':payment_status' => 'unpaid',
            ':subtotal' => 1000, ':total' => 1000, ':balance' => 1000,
            ':delivery_date' => date('Y-m-d', strtotime('+2 days')),
        ]
    );
    $orderId = (int) $pdo->lastInsertId();
    $attention = AdminNotifications::attention();
    anb_ok(in_array('Orders to confirm', array_column($attention, 'label'), true), 'an Orders role receives its live pending-order attention queue');
    anb_ok(!in_array('Payment proofs to review', array_column($attention, 'label'), true), 'an Orders role receives no payment attention count');

    Rbac::loadFromDb($userIds['blocked']);
    anb_eq([], AdminNotifications::attention(), 'a dashboard-only role receives no domain attention data');
    anb_eq([], AdminNotifications::recent($userIds['blocked']), 'a role with no alert permissions receives an empty feed');
} finally {
    if ($orderId > 0) { Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]); }
    foreach ($notificationIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
    }
    foreach ($userIds as $id) {
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($roleIds as $id) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $id]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests admin notification database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
