<?php
/**
 * M6 notifications against a migrated scratch database and a real SMTP round
 * trip. Proves the three things the guide asks for: a row per send, a delivery
 * row per recipient and channel, and a failure that is recorded rather than
 * thrown at the caller.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function n_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function n_eq($expected, $actual, string $label): void { n_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userId = 0; $orderId = 0; $notificationIds = [];
try {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Notify\', \'Buyer\', :email, :phone, :hash, \'household\', \'active\')',
        [':email' => "notify-$suffix@example.test", ':phone' => '+23476' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $zone = Database::one('SELECT id FROM delivery_zones ORDER BY id LIMIT 1');
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
             preferred_delivery_date, delivery_zone_id)
         VALUES (:number, :user, \'household\', \'pending\', \'deposit\', \'unpaid\', 800000, 800000, 0, 800000, :date, :zone)',
        [':number' => "ZZ-NOT-$suffix", ':user' => $userId, ':date' => date('Y-m-d', strtotime('+6 days')), ':zone' => $zone ? (int) $zone['id'] : null]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state)
         VALUES (:o, \'Ada Obi\', \'+2348011122233\', \'12 Market Road\', \'Ikeja\', \'Lagos\')',
        [':o' => $orderId]
    );
    $token = OrderTrail::newToken();
    Database::run('UPDATE orders SET order_trail_token_hash = :t WHERE id = :o', [':t' => OrderTrail::hashToken($token), ':o' => $orderId]);

    // --- One send, two channels ------------------------------------------
    $id = Notifications::send('order_placed', Notifications::orderContext($orderId, $token)['vars'], [
        ['email' => "notify-$suffix@example.test", 'user_id' => $userId],
    ], 'order', $orderId);
    $notificationIds[] = $id;
    n_ok($id > 0, 'a send writes one notification row');

    $row = Database::one('SELECT event_type, title, body, related_type, related_id, status FROM notifications WHERE id = :id', [':id' => $id]);
    n_eq('order_placed', (string) $row['event_type'], 'the notification records the event it came from');
    n_eq('order', (string) $row['related_type'], 'the notification is tied to the order');
    n_eq($orderId, (int) $row['related_id'], 'the notification names the order');
    n_ok(!str_contains((string) $row['body'] . (string) $row['title'], '{{'), 'the rendered words leave no placeholder behind');
    n_ok(str_contains((string) $row['title'], "ZZ-NOT-$suffix"), 'the subject carries the order number');

    $deliveries = Database::all('SELECT channel, recipient_address, status, attempt_count, sent_at, last_error FROM notification_deliveries WHERE notification_id = :id ORDER BY channel', [':id' => $id]);
    n_eq(2, count($deliveries), 'one delivery row per recipient and channel');
    n_eq('email', (string) $deliveries[0]['channel'], 'the email channel is recorded');
    n_eq('sent', (string) $deliveries[0]['status'], 'a delivered email is marked sent');
    n_ok($deliveries[0]['sent_at'] !== null, 'a sent email records when it went');
    n_eq('in_app', (string) $deliveries[1]['channel'], 'the in-app channel is recorded');
    n_ok($deliveries[1]['read_at'] ?? null === null, 'a new in-app update starts unread');

    // --- The trail link is in the email, per PRD 14.2 ---------------------
    $vars = Notifications::orderContext($orderId, $token)['vars'];
    n_ok(str_contains((string) $vars['order_trail_url'], $token), 'the placed order email carries the no-login trail token');
    $cta = Mail::ctaFromVars($vars);
    n_eq('Follow your order', (string) ($cta['label'] ?? ''), 'the trail link becomes the button in the email');

    // --- The inbox ---------------------------------------------------------
    n_eq(1, Notifications::unreadCount($userId), 'the customer has one unread update');
    $inbox = Notifications::inboxFor($userId);
    n_eq($orderId, (int) $inbox[0]['related_id'], 'the in-app update links back to the order');
    Notifications::markInboxRead($userId);
    n_eq(0, Notifications::unreadCount($userId), 'reading the account page clears the unread count');

    // --- A failing send is recorded, never thrown -------------------------
    $realPort = $_ENV['SMTP_PORT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    $failedId = Notifications::send('order_dispatched', Notifications::orderContext($orderId)['vars'], [
        ['email' => "notify-$suffix@example.test", 'user_id' => $userId],
    ], 'order', $orderId);
    if ($realPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $realPort; }
    $notificationIds[] = $failedId;
    n_ok($failedId > 0, 'a send that cannot reach the mail server still records what it tried to say');

    $failed = Database::one('SELECT status, attempt_count, last_error, sent_at FROM notification_deliveries WHERE notification_id = :id AND channel = :c', [':id' => $failedId, ':c' => 'email']);
    n_eq('failed', (string) $failed['status'], 'a refused email is marked failed rather than sent');
    n_ok(trim((string) $failed['last_error']) !== '', 'the failure records why');
    n_ok($failed['sent_at'] === null, 'a failed email never claims a send time');
    $inApp = Database::one('SELECT status FROM notification_deliveries WHERE notification_id = :id AND channel = :c', [':id' => $failedId, ':c' => 'in_app']);
    n_eq('sent', (string) $inApp['status'], 'the in-app copy still lands when email is down');

    // --- Resending the failed one -----------------------------------------
    $deliveryId = (int) Database::one('SELECT id FROM notification_deliveries WHERE notification_id = :id AND channel = :c', [':id' => $failedId, ':c' => 'email'])['id'];
    $resend = Notifications::resend($deliveryId, $userId);
    n_ok($resend['ok'], 'a failed email can be sent again once the mail server is back');
    $after = Database::one('SELECT status, attempt_count FROM notification_deliveries WHERE id = :id', [':id' => $deliveryId]);
    n_eq('sent', (string) $after['status'], 'the resend is recorded on the same delivery row');
    n_eq(2, (int) $after['attempt_count'], 'the attempt count counts both tries');
    n_eq(false, Notifications::resend($deliveryId, $userId)['ok'], 'an email that already went is not sent a third time');

    // --- Every template in the matrix renders clean -----------------------
    foreach (Notifications::EVENTS as $event => $definition) {
        $tpl = Database::one('SELECT subject_template, body_template FROM notification_templates WHERE template_key = :k AND is_active = 1', [':k' => $definition['template']]);
        n_ok((bool) $tpl, $definition['template'] . ' has an active template');
        if (!$tpl) { continue; }
        $sample = SettingsEditor::sampleTokens($definition['template']);
        $subject = SettingsEditor::fillTemplate((string) $tpl['subject_template'], $sample);
        $body = SettingsEditor::fillTemplate((string) $tpl['body_template'], $sample);
        n_ok(!str_contains($subject . $body, '{{'), $definition['template'] . ' renders with no placeholder left');
        n_ok(trim($subject) !== '' && trim($body) !== '', $definition['template'] . ' renders real words');
        n_ok(!str_contains($subject . $body, "\u{2014}"), $definition['template'] . ' carries no em dash');
    }

    // --- Order 360 sees what was sent -------------------------------------
    $shown = Notifications::forOrder($orderId);
    n_ok(count($shown) >= 4, 'the order screen lists every message and channel');
    $unknown = Notifications::send('not_a_real_event', [], [['email' => 'x@example.test']], 'order', $orderId);
    n_eq(0, $unknown, 'an unknown event sends nothing rather than guessing');
    $nobody = Notifications::send('order_placed', [], [], 'order', $orderId);
    n_eq(0, $nobody, 'a send with nobody to send to writes nothing');
} finally {
    foreach ($notificationIds as $id) {
        if ($id > 0) {
            Database::run('DELETE FROM notification_deliveries WHERE notification_id = :id', [':id' => $id]);
            Database::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
        }
    }
    if ($orderId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :o)', [':o' => $orderId]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :o', [':o' => $orderId]);
    }
    if ($userId) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $userId]);
    }
}
fwrite(STDOUT, "\n$passed / $tests notification database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
