<?php
/**
 * scripts/tests/cancellation_notification_db_test.php
 * -----------------------------------------------------------------------------
 * The staff half of a cancellation, against a migrated scratch database.
 *
 * A cancellation used to write one notification and send one email, both to the
 * customer. This suite proves the fix end to end: that a committed cancellation
 * reaches every active staff member who may open the order, that it reaches
 * nobody who may not, that it says the seven facts it has to say, that it says
 * them once however many times it is asked, and that a mail server which is down
 * costs a recorded delivery rather than a rolled back cancellation.
 *
 * The release gate starts the mail sink and points .env at it before running
 * every *_db_test.php on disk, so email here really leaves the process and
 * really lands. Run it the same way by hand:
 *
 *   php scripts/tests/fake/smtp_sink.php &
 *   php scripts/migrate.php
 *   php scripts/tests/cancellation_notification_db_test.php
 *
 * SCRATCH DATABASE ONLY. It writes users, roles, orders, cancellations,
 * payments and notifications, and removes them all in the finally block.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0;
function cn_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}
function cn_eq($expected, $actual, string $label): void
{
    cn_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

/** The one delivery row for one person and one channel on one message. */
function cn_delivery(int $notificationId, int $userId, string $channel): ?array
{
    $row = Database::one(
        'SELECT id, recipient_address, status, attempt_count, sent_at, last_error
           FROM notification_deliveries
          WHERE notification_id = :notification AND channel = :channel AND user_id = :user
          ORDER BY id LIMIT 1',
        [':notification' => $notificationId, ':channel' => $channel, ':user' => $userId]
    );
    return $row === null ? null : $row;
}

/** Every message written about one order under one event. */
function cn_events(int $orderId, string $event): array
{
    return Database::all(
        'SELECT id, title, body, cta_url, cta_label, status, created_by
           FROM notifications
          WHERE event_type = :event AND related_type = :type AND related_id = :id
          ORDER BY id',
        [':event' => $event, ':type' => 'order', ':id' => $orderId]
    );
}

function cn_count(int $orderId): array
{
    return [
        'staff'    => count(cn_events($orderId, 'admin_order_cancelled')),
        'customer' => count(cn_events($orderId, 'order_cancelled')),
        'rows'     => (int) (Database::one(
            'SELECT COUNT(*) AS n FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE n.related_type = :type AND n.related_id = :id',
            [':type' => 'order', ':id' => $orderId]
        )['n'] ?? 0),
    ];
}

/**
 * Announce exactly the way api/v1/orders.php does, on both paths. The controller
 * decides only whether the cancellation committed; everything about who hears
 * about it and what they are told lives in Notifications, and this keeps the
 * suite honest about that split.
 */
function cn_announce(int $orderId, array $result, ?int $actorId): void
{
    $refundEvents = is_array($result['refund_events'] ?? null) ? $result['refund_events'] : [];
    unset($result['refund_events']);
    if ($result['code'] === 'cancelled') {
        Notifications::announceCancellation($orderId, $result, $actorId);
        foreach ($refundEvents as $refundEvent) {
            Notifications::announceRefund($refundEvent);
        }
    }
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$pdo = Database::getInstance()->getConnection();
$roleIds = []; $staffIds = []; $orderIds = []; $customerId = 0;

$oldAllowed = Settings::bool('cancellation_customer_allowed', true);
$oldCutoff = Settings::str('cancellation_cutoff_time', '18:00');

/** An unpaid order a customer is allowed to cancel themselves. */
function cn_order(int $userId, string $status, string $paymentStatus, int $paid, string $date, string $suffix): int
{
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_required_subunit, amount_paid_subunit,
             balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, \'household\', :status, :option, :payment,
                 1000000, 1000000, 300000, :paid, :balance, :delivery)',
        [
            ':number'   => 'ZZ-CNN-' . $suffix . '-' . random_int(100, 999),
            ':user'     => $userId,
            ':status'   => $status,
            ':option'   => $paid > 0 ? 'deposit' : 'pay_on_delivery',
            ':payment'  => $paymentStatus,
            ':paid'     => $paid,
            ':balance'  => max(0, 1000000 - $paid),
            ':delivery' => $date,
        ]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
}

/** Cash or a transfer recorded by hand, so the refund is a staff job. */
function cn_manual_payment(int $orderId, int $userId, int $amount, string $suffix): void
{
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status)
         VALUES (:number, :user, :order, \'manual\', \'deposit\', :amount, :amount, \'paid\')',
        [':number' => 'ZZ-CNP-' . $suffix . '-' . random_int(100, 999), ':user' => $userId, ':order' => $orderId, ':amount' => $amount]
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, provider, reference, domain, status, requested_amount_subunit, amount_subunit, customer_email, paid_at)
         VALUES (:payment, \'manual\', :reference, \'test\', \'success\', :amount, :amount, :email, NOW())',
        [':payment' => $paymentId, ':reference' => 'ZZ-CNN-MAN-' . $suffix . '-' . random_int(100, 999), ':amount' => $amount, ':email' => 'cnn-' . $suffix . '@example.test']
    );
}

$adminBase = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');

try {
    // --- The team ----------------------------------------------------------
    // Three shapes of staff member and one who has left. Recipients are read
    // from active users and the permission, so the fixture has to contain both
    // a person who qualifies and two who must not.
    $shapes = [
        'orders'   => ['dashboard.view', 'orders.view'],
        'payments' => ['dashboard.view', 'payments.view'],
        'actor'    => ['dashboard.view', 'orders.view', 'orders.cancel', 'payments.refund'],
        'away'     => ['dashboard.view', 'orders.view'],
    ];
    foreach ($shapes as $key => $permissions) {
        Database::run(
            'INSERT INTO roles (name, description) VALUES (:name, :description)',
            [':name' => 'cnn_' . $key . '_' . $suffix, ':description' => 'Cancellation alert test']
        );
        $roleId = (int) $pdo->lastInsertId();
        $roleIds[] = $roleId;
        foreach ($permissions as $permission) {
            Database::run(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :role, id FROM permissions WHERE `key` = :permission',
                [':role' => $roleId, ':permission' => $permission]
            );
        }
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :hash, \'staff\', :status)',
            [
                ':first'  => ucfirst($key),
                ':last'   => 'Okoye',
                ':email'  => 'cnn-' . $key . '-' . $suffix . '@example.test',
                ':phone'  => '+23473' . random_int(10000000, 99999999),
                ':hash'   => password_hash('test-only', PASSWORD_BCRYPT),
                // The person who has left keeps the role, which is the only way
                // to prove the exclusion is the account status and not the role.
                ':status' => $key === 'away' ? 'inactive' : 'active',
            ]
        );
        $staffIds[$key] = (int) $pdo->lastInsertId();
        Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $staffIds[$key], ':role' => $roleId]);
    }

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Ada\', \'Obi\', :email, :phone, :hash, \'household\', \'active\')',
        [':email' => 'cnn-customer-' . $suffix . '@example.test', ':phone' => '+23474' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $customerId = (int) $pdo->lastInsertId();

    // The recipient list is read at send time. This is the assertion that the
    // rule is dynamic: nobody is named in code, and the person who qualifies is
    // the person the database says qualifies.
    $recipients = Notifications::staffRecipients('orders.view');
    $recipientIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $recipients);
    cn_ok(in_array($staffIds['orders'], $recipientIds, true), 'an active colleague with orders.view is a recipient');
    cn_ok(in_array($staffIds['actor'], $recipientIds, true), 'the colleague who cancels an order is a recipient of their own cancellation');
    cn_ok(!in_array($staffIds['payments'], $recipientIds, true), 'a colleague without orders.view is not a recipient');
    cn_ok(!in_array($staffIds['away'], $recipientIds, true), 'a colleague whose account is switched off is not a recipient, whatever their role says');

    Settings::set('cancellation_customer_allowed', true, 'bool', null);
    Settings::set('cancellation_cutoff_time', '23:59', 'string', null);
    Settings::flushCache();
    $deliveryDate = date('Y-m-d', strtotime('+5 days'));

    // --- 1. A customer cancels their own order -----------------------------
    $customerOrder = cn_order($customerId, 'pending', 'unpaid', 0, $deliveryDate, $suffix . '-a');
    $orderIds[] = $customerOrder;
    $orderNumber = (string) Database::one('SELECT order_number FROM orders WHERE id = :id', [':id' => $customerOrder])['order_number'];

    $result = OrderCancellation::cancelForCustomer($customerOrder, $customerId, 'delivery_date', 'The market day moved.');
    cn_eq('cancelled', (string) $result['code'], 'the customer cancellation commits');
    cn_eq('not_required', (string) $result['refund_status'], 'nothing had been paid, so nothing is owed back');
    cn_announce($customerOrder, $result, $customerId);

    $staffRows = cn_events($customerOrder, 'admin_order_cancelled');
    cn_eq(1, count($staffRows), 'a committed cancellation writes exactly one staff alert');
    $alertId = (int) ($staffRows[0]['id'] ?? 0);
    $body = (string) ($staffRows[0]['body'] ?? '');
    $title = (string) ($staffRows[0]['title'] ?? '');

    cn_ok(str_contains($title, $orderNumber), 'the subject carries the order number');
    cn_ok(str_contains($body, $orderNumber), 'the alert names the order number');
    cn_ok(str_contains($body, 'Ada Obi'), 'the alert names the customer');
    cn_ok(str_contains($body, 'Cancelled by the customer.'), 'the alert says where the cancellation came from');
    cn_ok(str_contains($body, 'The delivery day no longer works. Note: The market day moved.'), 'the alert carries the recorded reason and the note behind it');
    cn_ok(str_contains($body, date('l jS F', strtotime($deliveryDate))), 'the alert carries the delivery date');
    cn_ok(str_contains($body, 'Nothing had been paid on this order'), 'the alert says where the money stands');
    cn_ok(!str_contains($body . $title, '{{'), 'the alert renders with no placeholder left');
    cn_ok(!str_contains($body . $title, "\u{2014}"), 'the alert carries no em dash');
    cn_ok(!str_contains($body, "\u{20A6}"), 'the staff alert carries no naira figure');
    cn_eq($customerId, (int) ($staffRows[0]['created_by'] ?? 0), 'the alert records who the cancellation came from');

    // The link is built from APP_URL and the row id at send time, never typed.
    cn_eq('Open in admin', (string) ($staffRows[0]['cta_label'] ?? ''), 'the alert offers one action, and it is the admin order');
    cn_eq($adminBase . '/admin/orders.php?order=' . $customerOrder, (string) ($staffRows[0]['cta_url'] ?? ''), 'the alert links to the admin order it is about');
    cn_ok(!str_contains($body, '/public/order.php'), 'the alert never carries the customer trail link');

    // In the app, for the people who may open the order.
    cn_ok(cn_delivery($alertId, $staffIds['orders'], Notifications::CHANNEL_IN_APP) !== null, 'a colleague with orders.view gets the alert in the app');
    cn_ok(cn_delivery($alertId, $staffIds['actor'], Notifications::CHANNEL_IN_APP) !== null, 'the colleague who may cancel gets the alert in the app');
    cn_eq(null, cn_delivery($alertId, $staffIds['payments'], Notifications::CHANNEL_IN_APP), 'a colleague without orders.view gets no in-app row');
    cn_eq(null, cn_delivery($alertId, $staffIds['away'], Notifications::CHANNEL_IN_APP), 'a colleague whose account is switched off gets no in-app row');

    // And by email, to the same people and nobody else.
    $ordersEmail = cn_delivery($alertId, $staffIds['orders'], Notifications::CHANNEL_EMAIL);
    cn_ok($ordersEmail !== null, 'a colleague with orders.view gets the alert by email');
    cn_eq('cnn-orders-' . $suffix . '@example.test', (string) ($ordersEmail['recipient_address'] ?? ''), 'the email goes to the address the account holds, read from the database');
    cn_eq('sent', (string) ($ordersEmail['status'] ?? ''), 'the staff email is handed to the mail server');
    cn_ok(($ordersEmail['sent_at'] ?? null) !== null, 'a sent staff email records when it went');
    cn_eq(null, $ordersEmail['last_error'] ?? null, 'a sent staff email records no error');
    cn_eq(1, (int) ($ordersEmail['attempt_count'] ?? 0), 'a sent staff email records one attempt');
    cn_eq(null, cn_delivery($alertId, $staffIds['payments'], Notifications::CHANNEL_EMAIL), 'a colleague without orders.view gets no email');
    cn_eq(null, cn_delivery($alertId, $staffIds['away'], Notifications::CHANNEL_EMAIL), 'a colleague whose account is switched off gets no email');

    // The customer half still works, unchanged (requirement 7).
    $customerRows = cn_events($customerOrder, 'order_cancelled');
    cn_eq(1, count($customerRows), 'the customer still gets exactly one cancellation message');
    $customerDelivery = cn_delivery((int) $customerRows[0]['id'], $customerId, Notifications::CHANNEL_EMAIL);
    cn_ok($customerDelivery !== null, 'the customer cancellation email is still addressed to the customer');
    cn_eq('sent', (string) ($customerDelivery['status'] ?? ''), 'the customer cancellation email still goes out');
    cn_ok(cn_delivery((int) $customerRows[0]['id'], $customerId, Notifications::CHANNEL_IN_APP) !== null, 'the customer in-app copy still lands');
    cn_eq(null, cn_delivery((int) $customerRows[0]['id'], $staffIds['orders'], Notifications::CHANNEL_EMAIL), 'the customer message is not copied to the team');

    // --- 2. The bell and the history page recognise it ---------------------
    Rbac::loadFromDb($staffIds['orders']);
    $feed = AdminNotifications::recent($staffIds['orders']);
    $seen = array_column($feed, 'event_type');
    cn_ok(in_array('admin_order_cancelled', $seen, true), 'the bell recognises the new event');
    $row = null;
    foreach ($feed as $item) {
        if ((string) $item['event_type'] === 'admin_order_cancelled') { $row = $item; break; }
    }
    cn_eq('/admin/orders.php?order=' . $customerOrder, (string) ($row['href'] ?? ''), 'the bell row deep-links to Order 360');
    cn_eq(false, (bool) ($row['is_read'] ?? true), 'a new cancellation alert starts unread');
    cn_ok(AdminNotifications::unreadCount($staffIds['orders']) >= 1, 'the badge counts the cancellation alert');
    cn_eq(true, AdminNotifications::markRead($staffIds['orders'], (int) ($row['delivery_id'] ?? 0)), 'the cancellation alert can be marked read');

    Rbac::loadFromDb($staffIds['payments']);
    $blindFeed = AdminNotifications::recent($staffIds['payments']);
    cn_ok(!in_array('admin_order_cancelled', array_column($blindFeed, 'event_type'), true), 'a colleague without orders.view never sees the alert, even with the delivery id guessed');
    cn_eq(false, AdminNotifications::markRead($staffIds['payments'], (int) ($row['delivery_id'] ?? 0)), 'a colleague without orders.view cannot mark somebody else alert read');

    // --- 3. Idempotency: a retry, a refresh, a second ask ------------------
    Rbac::loadFromDb($staffIds['orders']);
    $before = cn_count($customerOrder);
    cn_eq(true, Notifications::alreadyAnnounced('admin_order_cancelled', 'order', $customerOrder), 'the dispatcher knows it has already announced this cancellation');
    cn_eq(true, Notifications::alreadyAnnounced('order_cancelled', 'order', $customerOrder), 'and it knows it has already told the customer');

    // The customer presses the button again, or reloads and posts again.
    $again = OrderCancellation::cancelForCustomer($customerOrder, $customerId, 'delivery_date', '');
    cn_eq('already_cancelled', (string) $again['code'], 'a repeat submission is answered without cancelling twice');
    cn_announce($customerOrder, $again, $customerId);

    // And the announcement is called a second time directly, which is what a
    // retried request or a future caller would do.
    Notifications::announceCancellation($customerOrder, $result, $customerId);
    Notifications::announceCancellation($customerOrder, $result, $customerId);

    $after = cn_count($customerOrder);
    cn_eq($before['staff'], $after['staff'], 'no second staff alert is written on a retry');
    cn_eq($before['customer'], $after['customer'], 'no second customer message is written on a retry');
    cn_eq($before['rows'], $after['rows'], 'no second delivery row, so no second email, on a retry');
    cn_eq(1, (int) (Database::one('SELECT COUNT(*) AS n FROM order_cancellations WHERE order_id = :id', [':id' => $customerOrder])['n'] ?? 0), 'still one cancellation row');
    cn_eq(1, (int) (Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id AND new_status = \'cancelled\'', [':id' => $customerOrder])['n'] ?? 0), 'still one cancellation history event');

    // --- 4. A colleague cancels an order -----------------------------------
    $staffOrder = cn_order($customerId, 'confirmed', 'unpaid', 0, $deliveryDate, $suffix . '-b');
    $orderIds[] = $staffOrder;
    $staffResult = OrderCancellation::cancelForStaff($staffOrder, $staffIds['actor'], 'stock_unavailable', 'The farm in Jos could not load it.', true);
    cn_eq('cancelled', (string) $staffResult['code'], 'the staff cancellation commits');
    cn_announce($staffOrder, $staffResult, $staffIds['actor']);

    $staffAlerts = cn_events($staffOrder, 'admin_order_cancelled');
    cn_eq(1, count($staffAlerts), 'a staff cancellation also writes exactly one staff alert');
    $staffAlertId = (int) ($staffAlerts[0]['id'] ?? 0);
    $staffBody = (string) ($staffAlerts[0]['body'] ?? '');
    cn_ok(str_contains($staffBody, 'Cancelled by Actor Okoye on our team.'), 'the alert names the colleague who pressed the button');
    cn_ok(str_contains($staffBody, 'Produce is unavailable. Note: The farm in Jos could not load it.'), 'the alert carries the staff reason from the staff reason list');
    cn_ok(str_contains($staffBody, 'Ada Obi'), 'a staff cancellation still names the customer it happened to');
    cn_eq($adminBase . '/admin/orders.php?order=' . $staffOrder, (string) ($staffAlerts[0]['cta_url'] ?? ''), 'the staff alert links to the order the colleague cancelled');
    cn_eq($staffIds['actor'], (int) ($staffAlerts[0]['created_by'] ?? 0), 'the alert records the colleague who cancelled it');

    // Question 1 was answered A: one rule for both paths, the actor included.
    cn_ok(cn_delivery($staffAlertId, $staffIds['actor'], Notifications::CHANNEL_IN_APP) !== null, 'the colleague who cancelled it is told too, so the bell is a complete record');
    cn_ok(cn_delivery($staffAlertId, $staffIds['orders'], Notifications::CHANNEL_IN_APP) !== null, 'their colleagues are told');
    cn_eq(null, cn_delivery($staffAlertId, $staffIds['payments'], Notifications::CHANNEL_IN_APP), 'a colleague without orders.view is still not told');
    cn_eq(null, cn_delivery($staffAlertId, $staffIds['away'], Notifications::CHANNEL_IN_APP), 'a colleague who has left is still not told');

    // The customer of a staff-cancelled order still hears about it.
    cn_eq(1, count(cn_events($staffOrder, 'order_cancelled')), 'a staff cancellation still tells the customer');

    // --- 5. Refund outcomes, and who is allowed to see a figure ------------
    $paidOrder = cn_order($customerId, 'confirmed', 'part_paid', 300000, $deliveryDate, $suffix . '-c');
    $orderIds[] = $paidOrder;
    cn_manual_payment($paidOrder, $customerId, 300000, $suffix . '-c');
    $paidResult = OrderCancellation::cancelForStaff($paidOrder, $staffIds['actor'], 'customer_requested', '', true);
    cn_eq('cancelled', (string) $paidResult['code'], 'a paid order is cancelled once the colleague may also refund it');
    cn_eq('manual_required', (string) $paidResult['refund_status'], 'money recorded by hand is left visibly requiring return');
    cn_announce($paidOrder, $paidResult, $staffIds['actor']);

    $paidAlerts = cn_events($paidOrder, 'admin_order_cancelled');
    cn_eq(1, count($paidAlerts), 'a cancellation that owes money writes one staff alert');
    $paidBody = (string) ($paidAlerts[0]['body'] ?? '');
    cn_ok(str_contains($paidBody, 'Money our team recorded still has to go back to the customer by hand.'), 'the staff alert says the money position in words');
    cn_ok(!str_contains($paidBody, "\u{20A6}"), 'the staff alert still carries no figure');
    cn_ok(!str_contains($paidBody, Money::format(300000)), 'the staff alert carries no amount, not even as digits');
    $paidCustomerBody = (string) (cn_events($paidOrder, 'order_cancelled')[0]['body'] ?? '');
    cn_ok(str_contains($paidCustomerBody, Money::format(300000)), 'the customer message still carries the exact figure');
    cn_ok(str_contains($paidCustomerBody, 'by hand'), 'and still says part of it needs a person');

    // A refusal writes nothing at all, so there is nothing to announce.
    $refusedOrder = cn_order($customerId, 'delivered', 'paid', 1000000, $deliveryDate, $suffix . '-d');
    $orderIds[] = $refusedOrder;
    $refused = OrderCancellation::cancelForStaff($refusedOrder, $staffIds['actor'], 'customer_requested', '', true);
    cn_eq('not_eligible', (string) $refused['code'], 'a delivered order is refused');
    cn_announce($refusedOrder, $refused, $staffIds['actor']);
    cn_eq(0, count(cn_events($refusedOrder, 'admin_order_cancelled')), 'a refused cancellation alerts nobody');
    cn_eq(0, count(cn_events($refusedOrder, 'order_cancelled')), 'and tells the customer nothing');
    cn_announce($refusedOrder, ['code' => 'cancelled', 'refund_status' => 'not_required'], $staffIds['actor']);
    cn_eq(0, count(cn_events($refusedOrder, 'admin_order_cancelled')), 'an announcement with no committed cancellation row writes nothing');

    // --- 6. A mail server that is down -------------------------------------
    // The cancellation is authoritative. The email is not, and must not be able
    // to undo it.
    $realPort = $_ENV['SMTP_PORT'] ?? null;
    $realTimeout = $_ENV['SMTP_TIMEOUT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    $_ENV['SMTP_TIMEOUT'] = 2;

    $downOrder = cn_order($customerId, 'pending', 'unpaid', 0, $deliveryDate, $suffix . '-e');
    $orderIds[] = $downOrder;
    $downResult = OrderCancellation::cancelForCustomer($downOrder, $customerId, 'changed_mind', 'Plans changed.');
    cn_eq('cancelled', (string) $downResult['code'], 'the cancellation commits even with the mail server down');
    cn_announce($downOrder, $downResult, $customerId);

    cn_eq('cancelled', (string) (Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $downOrder])['order_status'] ?? ''), 'the order is still cancelled after the mail failed');
    cn_eq(1, (int) (Database::one('SELECT COUNT(*) AS n FROM order_cancellations WHERE order_id = :id', [':id' => $downOrder])['n'] ?? 0), 'the cancellation row survived the mail failure');

    $downAlerts = cn_events($downOrder, 'admin_order_cancelled');
    cn_eq(1, count($downAlerts), 'the alert was written even though the email could not go out');
    $downAlertId = (int) ($downAlerts[0]['id'] ?? 0);
    $downEmail = cn_delivery($downAlertId, $staffIds['orders'], Notifications::CHANNEL_EMAIL);
    cn_ok($downEmail !== null, 'the failed email still has its delivery row');
    cn_eq('failed', (string) ($downEmail['status'] ?? ''), 'the failed email is marked failed, not sent');
    cn_eq(1, (int) ($downEmail['attempt_count'] ?? 0), 'the failed email records the attempt it made');
    cn_eq(null, $downEmail['sent_at'] ?? null, 'the failed email never claims a send time');
    $lastError = trim((string) ($downEmail['last_error'] ?? ''));
    cn_ok($lastError !== '', 'the failure records why, so the person resending has something to go on');
    cn_ok(in_array($lastError, [
        Mail::FAIL_UNKNOWN, Mail::FAIL_NO_TRANSPORT, Mail::FAIL_UNREACHABLE,
        Mail::FAIL_AUTH, Mail::FAIL_TIMEOUT, Mail::FAIL_RECIPIENT, Mail::FAIL_SENDER,
    ], true), 'the recorded reason is one of our own sentences, not the driver text: ' . $lastError);
    cn_ok(!str_contains($lastError, 'SMTP'), 'the recorded reason names no driver vocabulary');
    cn_eq('sent', (string) (cn_delivery($downAlertId, $staffIds['orders'], Notifications::CHANNEL_IN_APP)['status'] ?? ''), 'the in-app copy lands even with the mail server down');

    // Still eligible for the existing resend workflow on Order 360.
    $state = Notifications::deliveryState([
        'status' => (string) ($downAlerts[0]['status'] ?? ''),
        'delivery_status' => (string) ($downEmail['status'] ?? ''),
        'channel' => Notifications::CHANNEL_EMAIL,
        'scheduled_at' => null,
    ]);
    cn_eq('Not sent', (string) $state['label'], 'Order 360 says the staff email did not go');
    cn_eq('bad', (string) $state['tone'], 'and says it in the tone that means something is wrong');
    cn_eq(true, (bool) $state['may_resend'], 'the failed staff email can be sent again from Order 360');
    // Order 360 reads Notifications::forOrder(), so this is the list the person
    // with the resend button actually sees.
    $listed = Notifications::forOrder($downOrder);
    $listedRow = null;
    foreach ($listed as $message) {
        if ((int) $message['id'] === $downAlertId && (string) $message['channel'] === Notifications::CHANNEL_EMAIL) {
            $listedRow = $message;
        }
    }
    cn_ok($listedRow !== null, 'Order 360 lists the failed staff alert beside every other message on the order');
    cn_eq('failed', (string) ($listedRow['delivery_status'] ?? ''), 'Order 360 shows the staff email as not sent');
    cn_eq($lastError, trim((string) ($listedRow['last_error'] ?? '')), 'Order 360 shows the reason it failed');
    cn_eq('Order cancelled, for staff', Notifications::EVENTS['admin_order_cancelled']['label'], 'Order 360 names the message in words');

    // Once the mail server is back, the recorded words go out on the same row.
    if ($realPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $realPort; }
    if ($realTimeout === null) { unset($_ENV['SMTP_TIMEOUT']); } else { $_ENV['SMTP_TIMEOUT'] = $realTimeout; }
    $resend = Notifications::resend((int) $downEmail['id'], $staffIds['orders']);
    cn_eq(true, (bool) $resend['ok'], 'the failed staff email is sent again once the mail server is back');
    $resent = Database::one('SELECT status, attempt_count, last_error, sent_at FROM notification_deliveries WHERE id = :id', [':id' => (int) $downEmail['id']]);
    cn_eq('sent', (string) $resent['status'], 'the resend is recorded on the same delivery row');
    cn_eq(2, (int) $resent['attempt_count'], 'the attempt count counts both tries');
    cn_eq(null, $resent['last_error'], 'a delivery that finally landed carries no error');
    cn_eq(false, (bool) Notifications::resend((int) $downEmail['id'], $staffIds['orders'])['ok'], 'an email that went out is not sent a third time');

    // A retry of the whole cancellation after the mail failure still writes
    // nothing new: the alert exists, so it is the resend button that owns it.
    $downCounts = cn_count($downOrder);
    Notifications::announceCancellation($downOrder, $downResult, $customerId);
    cn_eq($downCounts, cn_count($downOrder), 'a retry after a mail failure writes no duplicate alert and sends no duplicate email');
} finally {
    Settings::set('cancellation_customer_allowed', $oldAllowed, 'bool', null);
    Settings::set('cancellation_cutoff_time', $oldCutoff, 'string', null);
    Settings::flushCache();

    foreach ($orderIds as $id) {
        Database::run(
            'DELETE FROM notification_deliveries WHERE notification_id IN
               (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)',
            [':id' => $id]
        );
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM refund_status_history WHERE refund_id IN (SELECT id FROM refunds WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM refunds WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_cancellations WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($staffIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    if ($customerId > 0) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :id', [':id' => $customerId]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $customerId]);
    }
    foreach ($roleIds as $id) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $id]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests cancellation notification database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
