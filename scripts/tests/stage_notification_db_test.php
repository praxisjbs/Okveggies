<?php
/**
 * scripts/tests/stage_notification_db_test.php
 * -----------------------------------------------------------------------------
 * Dispatched and delivered emails, against a migrated scratch database.
 *
 * A stage email that never arrived used to fail four quiet ways at once: no
 * idempotency in the dispatcher, a trail link that 404s for a guest, a missing
 * address that wrote nothing at all, and a parent row that read sent while the
 * email had failed. This suite proves the fix end to end: the full journey
 * announces every stage exactly once, a repeat or a stale form writes nothing
 * new, a guest gets a working no-login link, a missing address is a recorded
 * failure with a resend beside it, a dead mail host costs a delivery rather
 * than a transition, and the team hears about every failure on the bell.
 *
 * The release gate starts the mail sink and points .env at it before running
 * every *_db_test.php on disk, so email here really leaves the process and
 * really lands. Run it the same way by hand:
 *
 *   php scripts/tests/fake/smtp_sink.php &
 *   php scripts/migrate.php
 *   php scripts/tests/stage_notification_db_test.php
 *
 * SCRATCH DATABASE ONLY. It writes users, roles, orders, share links and
 * notifications, and removes them all in the finally block.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0;
function stg_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\\n"); }
}
function stg_eq($expected, $actual, string $label): void
{
    stg_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

/** Every message written about one order under one event. */
function stg_events(int $orderId, string $event): array
{
    return Database::all(
        'SELECT id, title, body, cta_url, cta_label, status, created_by
           FROM notifications
          WHERE event_type = :event AND related_type = :type AND related_id = :id
          ORDER BY id',
        [':event' => $event, ':type' => 'order', ':id' => $orderId]
    );
}

/** Every delivery row on one message. */
function stg_deliveries(int $notificationId): array
{
    return Database::all(
        'SELECT id, user_id, channel, recipient_address, status, attempt_count, sent_at, last_error
           FROM notification_deliveries
          WHERE notification_id = :id
          ORDER BY id',
        [':id' => $notificationId]
    );
}

/**
 * Move an order exactly the way api/v1/orders.php does: the transition first,
 * and the announcement only when the transition actually changed the stage.
 * The controller decides only whether the commit happened; everything about
 * who hears about it lives in Notifications, and this keeps the suite honest
 * about that split.
 */
function stg_transition(int $orderId, string $expected, string $target, int $actorId): array
{
    $result = OrderLifecycle::transition($orderId, $expected, $target, $actorId, '');
    if (!empty($result['ok']) && ($result['code'] ?? '') === 'transitioned') {
        Notifications::announceStage($orderId, (string) $result['status'], $actorId);
    }
    return $result;
}

function stg_order(?int $userId, ?string $contactEmail, string $suffix): int
{
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit,
             preferred_delivery_date, contact_email)
         VALUES (:number, :user, \'household\', \'pending\', \'pay_on_delivery\', \'unpaid\',
                 500000, 500000, 500000, :date, :email)',
        [
            ':number' => 'ZZ-STG-' . $suffix . '-' . random_int(100, 999),
            ':user'   => $userId,
            ':date'   => date('Y-m-d', strtotime('+6 days')),
            ':email'  => $contactEmail,
        ]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
}

function stg_address(int $orderId, string $name): void
{
    Database::run(
        'INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state)
         VALUES (:o, :name, \'+2348011122233\', \'12 Market Road\', \'Ikeja\', \'Lagos\')',
        [':o' => $orderId, ':name' => $name]
    );
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$pdo = Database::getInstance()->getConnection();
$roleIds = []; $staffIds = []; $orderIds = []; $customerIds = [];
$deliveryDate = date('Y-m-d', strtotime('+6 days'));
$deliveryDay = date('l jS F', strtotime($deliveryDate));
$appBase = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');

try {
    // --- The team ----------------------------------------------------------
    foreach (['orders' => ['orders.view'], 'payments' => ['payments.view']] as $key => $permissions) {
        Database::run(
            'INSERT INTO roles (name, description) VALUES (:name, :description)',
            [':name' => 'stg_' . $key . '_' . $suffix, ':description' => 'Stage email test']
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
             VALUES (:first, :last, :email, :phone, :hash, \'staff\', \'active\')',
            [
                ':first'  => ucfirst($key),
                ':last'   => 'Okoye',
                ':email'  => 'stg-' . $key . '-' . $suffix . '@example.test',
                ':phone'  => '+23471' . random_int(10000000, 99999999),
                ':hash'   => password_hash('test-only', PASSWORD_BCRYPT),
            ]
        );
        $staffIds[$key] = (int) $pdo->lastInsertId();
        Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $staffIds[$key], ':role' => $roleId]);
    }
    $actor = $staffIds['orders'];

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Adaeze\', \'Obi\', :email, :phone, :hash, \'household\', \'active\')',
        [':email' => 'stg-customer-' . $suffix . '@example.test', ':phone' => '+23472' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $customerId = (int) $pdo->lastInsertId();
    $customerIds[] = $customerId;

    // --- 1. The full journey announces every stage exactly once -------------
    $journey = stg_order($customerId, 'stg-customer-' . $suffix . '@example.test', $suffix . 'j');
    $orderIds[] = $journey;
    stg_address($journey, 'Adaeze Obi');
    $journeyNumber = (string) Database::one('SELECT order_number FROM orders WHERE id = :id', [':id' => $journey])['order_number'];

    $stages = [
        'confirmed'  => 'order_confirmed',
        'packed'     => 'order_packed',
        'dispatched' => 'order_dispatched',
        'delivered'  => 'order_delivered',
    ];
    $expected = 'pending';
    foreach ($stages as $target => $event) {
        $result = stg_transition($journey, $expected, $target, $actor);
        stg_eq('transitioned', (string) ($result['code'] ?? ''), "the journey reaches $target");
        $expected = $target;
    }
    stg_eq('delivered', (string) (Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $journey])['order_status'] ?? ''), 'the journey ends delivered');

    foreach ($stages as $target => $event) {
        $rows = stg_events($journey, $event);
        stg_eq(1, count($rows), "a successful transition to $target creates exactly one $event notification");
        if (count($rows) !== 1) {
            continue;
        }
        $id = (int) $rows[0]['id'];
        $body = (string) $rows[0]['body'];
        $title = (string) $rows[0]['title'];
        stg_ok(str_contains($title . $body, $journeyNumber), "the $target message carries the order number");
        stg_ok(str_contains($body, 'Adaeze Obi'), "the $target message carries the customer name");
        stg_ok(str_contains($body, $deliveryDay), "the $target message carries the delivery day");
        stg_ok(!str_contains($title . $body, '{{'), "the $target message renders with no placeholder left");
        stg_eq('Follow your order', (string) ($rows[0]['cta_label'] ?? ''), "the $target email offers the trail as its one action");
        stg_eq('sent', (string) ($rows[0]['status'] ?? ''), "the $target notification reads as sent when every channel lands");

        $deliveries = stg_deliveries($id);
        $byChannel = [];
        foreach ($deliveries as $delivery) {
            $byChannel[(string) $delivery['channel']][] = $delivery;
        }
        stg_eq(1, count($byChannel[Notifications::CHANNEL_EMAIL] ?? []), "the $target message has one email delivery");
        stg_eq(1, count($byChannel[Notifications::CHANNEL_IN_APP] ?? []), "the $target message has one in-app delivery");
        $email = ($byChannel[Notifications::CHANNEL_EMAIL] ?? [null])[0];
        stg_eq('stg-customer-' . $suffix . '@example.test', (string) ($email['recipient_address'] ?? ''), "the $target email goes to the account address");
        stg_eq($customerId, (int) ($email['user_id'] ?? 0), "the $target email records the customer id");
        stg_eq('sent', (string) ($email['status'] ?? ''), "the $target email is handed to the mail server");
        stg_eq('sent', (string) (($byChannel[Notifications::CHANNEL_IN_APP] ?? [null])[0]['status'] ?? ''), "the $target in-app copy lands");

        // The trail link is a fresh share token, minted at stage time, and it
        // opens this order without an account.
        $ctaUrl = (string) ($rows[0]['cta_url'] ?? '');
        stg_ok(preg_match('/[?&]token=([A-Za-z0-9_-]{43})(?:$|&)/', $ctaUrl, $match) === 1, "the $target email carries a no-login trail token");
        if (isset($match[1])) {
            $found = OrderTrail::findByToken($match[1]);
            stg_eq($journey, (int) ($found['id'] ?? 0), "the $target trail token opens this order");
        }
    }
    $shareLinks = (int) (Database::one(
        'SELECT COUNT(*) AS n FROM order_trail_share_links WHERE order_id = :id',
        [':id' => $journey]
    )['n'] ?? 0);
    stg_eq(4, $shareLinks, 'the journey mints one share link per stage email');

    // The history is intact: one row per stage, in order, attributed.
    $history = Database::all(
        'SELECT new_status, changed_by FROM order_status_history WHERE order_id = :id ORDER BY id',
        [':id' => $journey]
    );
    stg_eq(['confirmed', 'packed', 'dispatched', 'delivered'], array_column($history, 'new_status'), 'the status history holds every stage once');
    foreach ($history as $line) {
        stg_eq($actor, (int) $line['changed_by'], 'every history row names the colleague who moved it');
    }

    // --- 2. Skipped and invalid transitions announce nothing ----------------
    $skipped = stg_order($customerId, 'stg-customer-' . $suffix . '@example.test', $suffix . 's');
    $orderIds[] = $skipped;
    $result = stg_transition($skipped, 'pending', 'dispatched', $actor);
    stg_eq('invalid_transition', (string) ($result['code'] ?? ''), 'pending straight to dispatched is refused');
    stg_eq(0, count(stg_events($skipped, 'order_dispatched')), 'a refused transition announces nothing');
    $result = stg_transition($skipped, 'pending', 'no_such_stage', $actor);
    stg_eq('invalid_transition', (string) ($result['code'] ?? ''), 'an unknown stage is refused');
    stg_eq('pending', (string) (Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $skipped])['order_status'] ?? ''), 'refused transitions leave the order where it was');

    // --- 3. Repeats and concurrent attempts write nothing new ---------------
    $before = count(stg_events($journey, 'order_delivered'));
    $repeat = stg_transition($journey, 'dispatched', 'delivered', $actor);
    stg_eq('already_transitioned', (string) ($repeat['code'] ?? ''), 'a repeated transition is an idempotent success');
    stg_eq($before, count(stg_events($journey, 'order_delivered')), 'a repeated transition announces nothing new');
    Notifications::announceStage($journey, 'delivered', $actor);
    Notifications::announceStage($journey, 'delivered', $actor);
    stg_eq($before, count(stg_events($journey, 'order_delivered')), 'repeated announcements write no duplicate notification');

    // Two colleagues racing the same order: the second form is stale, and the
    // loser changes neither the order nor the outbox.
    $race = stg_order($customerId, 'stg-customer-' . $suffix . '@example.test', $suffix . 'r');
    $orderIds[] = $race;
    stg_transition($race, 'pending', 'confirmed', $actor);
    stg_transition($race, 'confirmed', 'packed', $actor);
    $first = stg_transition($race, 'packed', 'dispatched', $actor);
    stg_eq('transitioned', (string) ($first['code'] ?? ''), 'the first of two concurrent transitions wins');
    $second = stg_transition($race, 'confirmed', 'packed', $actor);
    stg_eq('stale', (string) ($second['code'] ?? ''), 'the second form, written against the old stage, is stale');
    stg_eq(1, count(stg_events($race, 'order_dispatched')), 'the race announces the stage once');
    stg_eq(1, count(stg_events($race, 'order_packed')), 'the stale loser announces nothing new');
    $raceHistory = (int) (Database::one(
        'SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id',
        [':id' => $race]
    )['n'] ?? 0);
    stg_eq(3, $raceHistory, 'the race writes one history row per real move, and no more');

    // --- 4. A guest order gets email with a working no-login link -----------
    $guest = stg_order(null, 'stg-guest-' . $suffix . '@example.test', $suffix . 'g');
    $orderIds[] = $guest;
    stg_address($guest, 'Guest Ada');
    $guestNumber = (string) Database::one('SELECT order_number FROM orders WHERE id = :id', [':id' => $guest])['order_number'];
    stg_transition($guest, 'pending', 'confirmed', $actor);
    stg_transition($guest, 'confirmed', 'packed', $actor);
    $result = stg_transition($guest, 'packed', 'dispatched', $actor);
    stg_eq('transitioned', (string) ($result['code'] ?? ''), 'a guest order dispatches like any other');
    $guestRows = stg_events($guest, 'order_dispatched');
    stg_eq(1, count($guestRows), 'a guest dispatch creates exactly one notification');
    $guestDeliveries = stg_deliveries((int) $guestRows[0]['id']);
    stg_eq(1, count($guestDeliveries), 'a guest has no account, so there is email and nothing else');
    stg_eq(Notifications::CHANNEL_EMAIL, (string) ($guestDeliveries[0]['channel'] ?? ''), 'the guest delivery is the email channel');
    stg_eq('stg-guest-' . $suffix . '@example.test', (string) ($guestDeliveries[0]['recipient_address'] ?? ''), 'the guest email goes to the checkout address');
    stg_eq(null, $guestDeliveries[0]['user_id'], 'the guest delivery records no user id');
    stg_eq('sent', (string) ($guestDeliveries[0]['status'] ?? ''), 'the guest email is handed to the mail server');
    stg_eq('sent', (string) ($guestRows[0]['status'] ?? ''), 'a single channel landing reads as sent');
    stg_ok(str_contains((string) $guestRows[0]['body'], $guestNumber), 'the guest message carries the order number');
    $guestCta = (string) ($guestRows[0]['cta_url'] ?? '');
    stg_ok(preg_match('/[?&]token=([A-Za-z0-9_-]{43})(?:$|&)/', $guestCta, $guestMatch) === 1, 'the guest email carries a no-login trail token');
    if (isset($guestMatch[1])) {
        $found = OrderTrail::findByToken($guestMatch[1]);
        stg_eq($guest, (int) ($found['id'] ?? 0), 'the guest trail token opens the guest order without an account');
    }

    // --- 5. Missing and invalid addresses are recorded failures -------------
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Noaddress\', \'Obi\', :email, :phone, :hash, \'household\', \'active\')',
        [':email' => 'not-an-email-' . $suffix, ':phone' => '+23475' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $noAddressUser = (int) $pdo->lastInsertId();
    $customerIds[] = $noAddressUser;
    $badEmail = stg_order($noAddressUser, null, $suffix . 'b');
    $orderIds[] = $badEmail;
    stg_transition($badEmail, 'pending', 'confirmed', $actor);
    stg_transition($badEmail, 'confirmed', 'packed', $actor);
    $result = stg_transition($badEmail, 'packed', 'dispatched', $actor);
    stg_eq('transitioned', (string) ($result['code'] ?? ''), 'an order with no usable address still dispatches');
    $badRows = stg_events($badEmail, 'order_dispatched');
    stg_eq(1, count($badRows), 'the unmailable stage is still recorded once');
    stg_eq(Notifications::STATUS_PARTIAL, (string) ($badRows[0]['status'] ?? ''), 'in-app sent with email failed reads as partial');
    $badDeliveries = stg_deliveries((int) $badRows[0]['id']);
    $badByChannel = [];
    foreach ($badDeliveries as $delivery) {
        $badByChannel[(string) $delivery['channel']] = $delivery;
    }
    stg_eq('sent', (string) ($badByChannel[Notifications::CHANNEL_IN_APP]['status'] ?? ''), 'the in-app copy still lands without an address');
    stg_eq('failed', (string) ($badByChannel[Notifications::CHANNEL_EMAIL]['status'] ?? ''), 'the email is recorded failed, not skipped');
    stg_eq(Notifications::FAIL_NO_ADDRESS, (string) ($badByChannel[Notifications::CHANNEL_EMAIL]['last_error'] ?? ''), 'the failure records the fixed sentence');
    stg_eq('not-an-email-' . $suffix, (string) ($badByChannel[Notifications::CHANNEL_EMAIL]['recipient_address'] ?? ''), 'the row keeps what was typed, so staff can see the typo');
    $badState = Notifications::deliveryState([
        'status' => (string) ($badRows[0]['status'] ?? ''),
        'delivery_status' => (string) ($badByChannel[Notifications::CHANNEL_EMAIL]['status'] ?? ''),
        'channel' => Notifications::CHANNEL_EMAIL,
        'scheduled_at' => null,
    ]);
    stg_eq('Not sent', (string) $badState['label'], 'Order 360 says the email did not go');
    stg_eq(true, (bool) $badState['may_resend'], 'the recorded failure can be sent again from Order 360');

    // A guest with nowhere to send to: no in-app copy exists, so the message
    // reads as failed rather than partial.
    $nowhere = stg_order(null, null, $suffix . 'n');
    $orderIds[] = $nowhere;
    stg_transition($nowhere, 'pending', 'confirmed', $actor);
    $nowhereRows = stg_events($nowhere, 'order_confirmed');
    stg_eq(1, count($nowhereRows), 'an order with no recipient at all still records its stage');
    stg_eq(Notifications::STATUS_FAILED, (string) ($nowhereRows[0]['status'] ?? ''), 'with no channel landing, the message reads as failed');
    $nowhereDeliveries = stg_deliveries((int) $nowhereRows[0]['id']);
    stg_eq(1, count($nowhereDeliveries), 'the unmailable guest has exactly one delivery row');
    stg_eq(Notifications::CHANNEL_EMAIL, (string) ($nowhereDeliveries[0]['channel'] ?? ''), 'and that row is the failed email');

    // The team hears about both, on the bell and by email. The bell carries one
    // alert per order, naming the first stage that failed: Order 360 lists
    // every failed email beside its own resend, so the bell points at the
    // order rather than repeating the ledger.
    foreach ([[$badEmail, 'Confirmed'], [$nowhere, 'Confirmed']] as [$failedOrder, $stage]) {
        $alerts = stg_events($failedOrder, 'admin_stage_email_failed');
        stg_eq(1, count($alerts), "the $stage failure raises exactly one staff alert");
        if (count($alerts) !== 1) {
            continue;
        }
        $alertBody = (string) $alerts[0]['body'];
        stg_ok(str_contains($alertBody, $stage), 'the alert names the stage that never reached the customer');
        stg_ok(str_contains($alertBody, Notifications::FAIL_NO_ADDRESS), 'the alert carries the reason in words');
        stg_ok(str_contains($alertBody, $deliveryDay), 'the alert carries the delivery day');
        stg_ok(!str_contains($alertBody, '/public/order.php'), 'the staff alert never carries the customer trail link');
        stg_eq('Open in admin', (string) ($alerts[0]['cta_label'] ?? ''), 'the alert offers the admin order as its one action');
        stg_eq($appBase . '/admin/orders.php?order=' . $failedOrder, (string) ($alerts[0]['cta_url'] ?? ''), 'the alert links to the order it is about');
    }
    $alertId = (int) (stg_events($badEmail, 'admin_stage_email_failed')[0]['id'] ?? 0);
    $alertDeliveries = stg_deliveries($alertId);
    $alertByUserChannel = [];
    foreach ($alertDeliveries as $delivery) {
        $alertByUserChannel[(int) $delivery['user_id'] . ':' . (string) $delivery['channel']] = $delivery;
    }
    stg_ok(isset($alertByUserChannel[$staffIds['orders'] . ':' . Notifications::CHANNEL_IN_APP]), 'a colleague with orders.view gets the failure on the bell');
    stg_ok(isset($alertByUserChannel[$staffIds['orders'] . ':' . Notifications::CHANNEL_EMAIL]), 'a colleague with orders.view gets the failure by email');
    stg_ok(!isset($alertByUserChannel[$staffIds['payments'] . ':' . Notifications::CHANNEL_IN_APP]), 'a colleague without orders.view gets no bell row');
    stg_ok(!isset($alertByUserChannel[$staffIds['payments'] . ':' . Notifications::CHANNEL_EMAIL]), 'a colleague without orders.view gets no email');

    Rbac::loadFromDb($staffIds['orders']);
    $feed = AdminNotifications::recent($staffIds['orders']);
    $seen = array_column($feed, 'event_type');
    stg_ok(in_array('admin_stage_email_failed', $seen, true), 'the bell recognises the failure alert');
    stg_ok(AdminNotifications::unreadCount($staffIds['orders']) > 0, 'the failure alert raises the unread count');

    // --- 6. A mail server that is down --------------------------------------
    // The transition is authoritative. The email is not, and must not be able
    // to undo it. The early stages go out while the mail server is up, so the
    // failure below belongs to the dispatch alone.
    $down = stg_order($customerId, 'stg-customer-' . $suffix . '@example.test', $suffix . 'd');
    $orderIds[] = $down;
    stg_address($down, 'Adaeze Obi');
    stg_transition($down, 'pending', 'confirmed', $actor);
    stg_transition($down, 'confirmed', 'packed', $actor);

    $realPort = $_ENV['SMTP_PORT'] ?? null;
    $realTimeout = $_ENV['SMTP_TIMEOUT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    $_ENV['SMTP_TIMEOUT'] = 2;

    $result = stg_transition($down, 'packed', 'dispatched', $actor);
    stg_eq('transitioned', (string) ($result['code'] ?? ''), 'the dispatch commits even with the mail server down');
    stg_eq('dispatched', (string) (Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $down])['order_status'] ?? ''), 'the order is still dispatched after the mail failed');

    $downRows = stg_events($down, 'order_dispatched');
    stg_eq(1, count($downRows), 'the stage was written even though the email could not go out');
    stg_eq(Notifications::STATUS_PARTIAL, (string) ($downRows[0]['status'] ?? ''), 'in-app sent with email failed reads as partial, never sent');
    $downDeliveries = stg_deliveries((int) $downRows[0]['id']);
    $downByChannel = [];
    foreach ($downDeliveries as $delivery) {
        $downByChannel[(string) $delivery['channel']] = $delivery;
    }
    stg_eq('failed', (string) ($downByChannel[Notifications::CHANNEL_EMAIL]['status'] ?? ''), 'the refused email is marked failed rather than sent');
    stg_eq('sent', (string) ($downByChannel[Notifications::CHANNEL_IN_APP]['status'] ?? ''), 'the in-app copy still lands when email is down');
    $downError = trim((string) ($downByChannel[Notifications::CHANNEL_EMAIL]['last_error'] ?? ''));
    stg_ok($downError !== '', 'the failure records why, so the person resending has something to go on');
    stg_ok(in_array($downError, [
        Mail::FAIL_UNKNOWN, Mail::FAIL_NO_TRANSPORT, Mail::FAIL_UNREACHABLE,
        Mail::FAIL_AUTH, Mail::FAIL_TIMEOUT, Mail::FAIL_RECIPIENT, Mail::FAIL_SENDER,
    ], true), 'the recorded reason is one of our own sentences, not the driver text: ' . $downError);

    $downAlerts = stg_events($down, 'admin_stage_email_failed');
    stg_eq(1, count($downAlerts), 'the failed dispatch raises exactly one staff alert');
    stg_ok(str_contains((string) ($downAlerts[0]['body'] ?? ''), 'Dispatched'), 'the alert names the stage that never reached the customer');
    stg_ok(str_contains((string) ($downAlerts[0]['body'] ?? ''), $downError), 'the alert carries the classified reason');
    // The bell works with the mail server down, because the bell is in-app.
    Rbac::loadFromDb($staffIds['orders']);
    $downFeed = AdminNotifications::recent($staffIds['orders']);
    $downSeen = [];
    foreach ($downFeed as $item) {
        if ((string) $item['event_type'] === 'admin_stage_email_failed' && (int) $item['related_id'] === $down) {
            $downSeen[] = $item;
        }
    }
    stg_eq(1, count($downSeen), 'the failure alert reaches the bell even with the mail server down');

    // Once the mail server is back, the recorded words go out on the same row,
    // and the notification reads as sent again.
    if ($realPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $realPort; }
    if ($realTimeout === null) { unset($_ENV['SMTP_TIMEOUT']); } else { $_ENV['SMTP_TIMEOUT'] = $realTimeout; }
    $downEmailId = (int) ($downByChannel[Notifications::CHANNEL_EMAIL]['id'] ?? 0);
    $resend = Notifications::resend($downEmailId, $actor);
    stg_eq(true, (bool) $resend['ok'], 'the failed dispatch email is sent again once the mail server is back');
    $resent = Database::one('SELECT status, attempt_count, last_error, sent_at FROM notification_deliveries WHERE id = :id', [':id' => $downEmailId]);
    stg_eq('sent', (string) ($resent['status'] ?? ''), 'the resend is recorded on the same delivery row');
    stg_eq(2, (int) ($resent['attempt_count'] ?? 0), 'the attempt count counts both tries');
    stg_eq(null, $resent['last_error'], 'a delivery that finally landed carries no error');
    stg_eq('sent', (string) (Database::one('SELECT status FROM notifications WHERE id = :id', [':id' => (int) $downRows[0]['id']])['status'] ?? ''), 'the notification reads as sent once every channel has landed');
    stg_eq(false, (bool) Notifications::resend($downEmailId, $actor)['ok'], 'an email that went out is not sent a third time');

    // A retry of the whole announcement after the mail failure still writes
    // nothing new: the message exists, so the resend button owns it now.
    Notifications::announceStage($down, 'dispatched', $actor);
    stg_eq(1, count(stg_events($down, 'order_dispatched')), 'a retry after a mail failure writes no duplicate message');
    stg_eq(1, count(stg_events($down, 'admin_stage_email_failed')), 'and no duplicate staff alert');

    // --- 7. Correct the address, then send it again --------------------------
    $badEmailId = (int) ($badByChannel[Notifications::CHANNEL_EMAIL]['id'] ?? 0);
    $early = Notifications::resend($badEmailId, $actor);
    stg_eq('no_address', (string) ($early['code'] ?? ''), 'a resend with still no address says so plainly');
    Database::run(
        'UPDATE users SET email = :email WHERE id = :id',
        [':email' => 'stg-fixed-' . $suffix . '@example.test', ':id' => $noAddressUser]
    );
    $fixed = Notifications::resend($badEmailId, $actor);
    stg_eq(true, (bool) $fixed['ok'], 'the recorded failure goes out once the address is corrected');
    $fixedRow = Database::one('SELECT status, recipient_address FROM notification_deliveries WHERE id = :id', [':id' => $badEmailId]);
    stg_eq('sent', (string) ($fixedRow['status'] ?? ''), 'the corrected resend lands');
    stg_eq('stg-fixed-' . $suffix . '@example.test', (string) ($fixedRow['recipient_address'] ?? ''), 'the resend goes to the corrected address and keeps it on the row');
    stg_eq('sent', (string) (Database::one('SELECT status FROM notifications WHERE id = :id', [':id' => (int) $badRows[0]['id']])['status'] ?? ''), 'the notification reads as sent once every channel has landed');

    // --- 8. An unknown event still sends nothing -----------------------------
    $unknown = Notifications::send('not_a_real_event', [], [['email' => 'x@example.test']], 'order', $journey);
    stg_eq(0, $unknown, 'an unknown event sends nothing rather than guessing');
} finally {
    foreach ($orderIds as $id) {
        Database::run(
            'DELETE FROM notification_deliveries WHERE notification_id IN
               (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)',
            [':id' => $id]
        );
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_trail_share_links WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach (array_merge(array_values($staffIds), $customerIds) as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($roleIds as $id) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $id]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\\n$passed / $tests stage notification database assertions passed.\\n");
exit($passed === $tests ? 0 : 1);
