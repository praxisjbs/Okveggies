<?php
/**
 * Stage emails over real HTTP: the controller path, the permissions and the
 * no-login link.
 *
 * Start a migrated local site with the mail sink first, then optionally set
 * OKV_TEST_BASE:
 *
 *   php -S 127.0.0.1:8123 -t /path/to/Okveggies &
 *   php scripts/tests/fake/smtp_sink.php &
 *   php scripts/migrate.php
 *   php scripts/tests/stage_notification_http_test.php
 *
 * SCRATCH DATABASE ONLY. It writes users, orders and notifications, and
 * removes them all in the finally block.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0; $passed = 0;
function sh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\\n"); }
}
function sh_eq($expected, $actual, string $label): void
{
    sh_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}
function sh_req(string $jar, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}
function sh_csrf(string $jar, string $url): string
{
    [, $body] = sh_req($jar, $url);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $match) ? $match[1] : '';
}
function sh_login(string $jar, string $base, string $page, string $email, string $password): string
{
    $token = sh_csrf($jar, $base . $page);
    [$code] = sh_req($jar, $base . '/api/v1/auth.php', [
        'action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token,
    ]);
    sh_eq(200, $code, $email . ' signs in');
    return sh_csrf($jar, $base . ($page === '/admin/login.php' ? '/admin/orders.php' : '/account.php'));
}
function sh_order(?int $userId, ?string $contactEmail, string $number): int
{
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit,
             preferred_delivery_date, contact_email)
         VALUES (:number, :user, \'household\', \'pending\', \'pay_on_delivery\', \'unpaid\',
                 500000, 500000, 500000, :delivery, :email)',
        [':number' => $number, ':user' => $userId, ':delivery' => date('Y-m-d', strtotime('+6 days')), ':email' => $contactEmail]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
}
function sh_events(int $orderId, string $event): array
{
    return Database::all(
        'SELECT id, title, body, cta_url, cta_label, status FROM notifications
          WHERE event_type = :event AND related_type = :type AND related_id = :id ORDER BY id',
        [':event' => $event, ':type' => 'order', ':id' => $orderId]
    );
}
function sh_email(int $notificationId): ?array
{
    $row = Database::one(
        'SELECT * FROM notification_deliveries WHERE notification_id = :id AND channel = :channel',
        [':id' => $notificationId, ':channel' => Notifications::CHANNEL_EMAIL]
    );
    return $row === null ? null : $row;
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'stage-http-777';
$hash = password_hash($password, PASSWORD_BCRYPT);
$users = []; $orders = [];
$managerJar = tempnam(sys_get_temp_dir(), 'okv-sh-m-');
$buyerJar = tempnam(sys_get_temp_dir(), 'okv-sh-b-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-sh-g-');

try {
    foreach ([
        ['Buyer', 'Ada', 'household', 'stage-http-buyer-' . $suffix . '@example.test', '+23471' . random_int(10000000, 99999999)],
        ['Manny', 'Manager', 'staff', 'stage-http-manager-' . $suffix . '@example.test', '+23472' . random_int(10000000, 99999999)],
    ] as $row) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first, :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [':first' => $row[0], ':last' => $row[1], ':email' => $row[3], ':phone' => $row[4], ':hash' => $hash, ':type' => $row[2]]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    [$buyerId, $managerId] = $users;
    Database::run(
        'INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'',
        [':user' => $managerId]
    );
    Database::run('DELETE FROM rate_limits');

    $order = sh_order($buyerId, 'stage-http-buyer-' . $suffix . '@example.test', 'ZZ-SHH-' . $suffix . '-1');
    $orders[] = $order;
    Database::run(
        'INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state)
         VALUES (:o, \'Buyer Ada\', \'+2348011122233\', \'12 Market Road\', \'Ikeja\', \'Lagos\')',
        [':o' => $order]
    );

    sh_login($managerJar, $base, '/admin/login.php', 'stage-http-manager-' . $suffix . '@example.test', $password);
    sh_login($buyerJar, $base, '/account.php', 'stage-http-buyer-' . $suffix . '@example.test', $password);

    $managerCsrf = function () use ($managerJar, $base, $order): string {
        return sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $order);
    };

    // --- 1. The journey announces every stage exactly once -------------------
    $stages = [
        ['pending', 'confirmed', 'order_confirmed'],
        ['confirmed', 'packed', 'order_packed'],
        ['packed', 'dispatched', 'order_dispatched'],
        ['dispatched', 'delivered', 'order_delivered'],
    ];
    foreach ($stages as [$expected, $target, $event]) {
        [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
            'action' => 'transition', 'order_id' => $order,
            'expected_status' => $expected, 'target_status' => $target,
            'okv_csrf' => $managerCsrf(),
        ]);
        $payload = json_decode($body, true) ?: [];
        sh_eq(200, $code, "moving to $target over HTTP answers 200");
        sh_eq('transitioned', (string) ($payload['code'] ?? ''), "the move to $target commits");
        sh_eq(1, count(sh_events($order, $event)), "the move to $target announces exactly one $event");
    }
    $dispatched = sh_events($order, 'order_dispatched');
    sh_eq('sent', (string) (($dispatched[0] ?? [])['status'] ?? ''), 'a dispatch whose email lands reads as sent');
    $dispatchedEmail = sh_email((int) ($dispatched[0]['id'] ?? 0));
    sh_eq('sent', (string) ($dispatchedEmail['status'] ?? ''), 'the dispatched email is handed to the mail server');
    sh_eq('stage-http-buyer-' . $suffix . '@example.test', (string) ($dispatchedEmail['recipient_address'] ?? ''), 'the dispatched email goes to the account address');

    // --- 2. The trail link in the email opens without an account -------------
    $trail = (string) (($dispatched[0] ?? [])['cta_url'] ?? '');
    sh_ok($trail !== '', 'the dispatched email carries a trail link');
    sh_ok(preg_match('/[?&]token=([A-Za-z0-9_-]{43})(?:$|&)/', $trail) === 1, 'the trail link carries a no-login token');
    if ($trail !== '') {
        // Requested through the test base, not the APP_URL the email was
        // rendered with, so this suite never touches a host it was not
        // pointed at.
        $parts = parse_url($trail);
        $local = $base . (string) ($parts['path'] ?? '/public/order.php') . '?' . (string) ($parts['query'] ?? '');
        [$trailCode, $trailBody] = sh_req($guestJar, $local);
        sh_eq(200, $trailCode, 'the no-login trail link opens for a signed-out visitor');
        $orderNumber = (string) Database::one('SELECT order_number FROM orders WHERE id = :id', [':id' => $order])['order_number'];
        sh_ok(str_contains($trailBody, $orderNumber), 'the trail page shows the order it was minted for');
    }

    // --- 3. Repeats and races stay silent ------------------------------------
    $token = $managerCsrf();
    [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $order,
        'expected_status' => 'dispatched', 'target_status' => 'delivered',
        'okv_csrf' => $token,
    ]);
    $payload = json_decode($body, true) ?: [];
    sh_eq(200, $code, 'repeating a transition over HTTP still answers 200');
    sh_eq('already_transitioned', (string) ($payload['code'] ?? ''), 'the repeat is reported as already done');
    sh_eq(1, count(sh_events($order, 'order_delivered')), 'refreshing a finished move announces nothing new');

    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $order,
        'expected_status' => 'packed', 'target_status' => 'dispatched',
        'okv_csrf' => $managerCsrf(),
    ]);
    sh_eq(409, $code, 'a stale form answers 409');
    sh_eq(1, count(sh_events($order, 'order_dispatched')), 'a stale form announces nothing new');

    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $order,
        'expected_status' => 'delivered', 'target_status' => 'packed',
        'okv_csrf' => $managerCsrf(),
    ]);
    sh_eq(422, $code, 'moving backwards answers 422');

    // --- 4. The guards hold ---------------------------------------------------
    $buyerCsrf = sh_csrf($buyerJar, $base . '/account.php');
    [$code] = sh_req($buyerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $order,
        'expected_status' => 'delivered', 'target_status' => 'delivered',
        'okv_csrf' => $buyerCsrf,
    ]);
    sh_eq(403, $code, 'a customer cannot move an order stage');
    sh_eq(1, count(sh_events($order, 'order_delivered')), 'a denied move announces nothing');

    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $order,
        'expected_status' => 'delivered', 'target_status' => 'delivered',
    ]);
    sh_eq(419, $code, 'a move without a CSRF token is refused');
    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php?action=transition&order_id=' . $order);
    sh_eq(405, $code, 'a move over GET is refused');

    // --- 5. A guest order is announced by its checkout address ---------------
    $guestOrder = sh_order(null, 'stage-http-guest-' . $suffix . '@example.test', 'ZZ-SHH-' . $suffix . '-2');
    $orders[] = $guestOrder;
    [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $guestOrder,
        'expected_status' => 'pending', 'target_status' => 'confirmed',
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $guestOrder),
    ]);
    sh_eq(200, $code, 'a guest order moves over HTTP like any other');
    $guestRows = sh_events($guestOrder, 'order_confirmed');
    sh_eq(1, count($guestRows), 'the guest move announces exactly one email');
    $guestEmail = sh_email((int) ($guestRows[0]['id'] ?? 0));
    sh_eq('stage-http-guest-' . $suffix . '@example.test', (string) ($guestEmail['recipient_address'] ?? ''), 'the guest email goes to the checkout address');
    sh_eq('sent', (string) ($guestEmail['status'] ?? ''), 'the guest email is handed to the mail server');

    // --- 6. No address means a recorded failure and a resend -----------------
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (\'Noaddress\', \'Ada\', :email, :phone, :hash, \'household\', \'active\', NOW())',
        [':email' => 'not-an-email-' . $suffix, ':phone' => '+23473' . random_int(10000000, 99999999), ':hash' => $hash]
    );
    $noAddressUser = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $noAddressUser;
    $badOrder = sh_order($noAddressUser, null, 'ZZ-SHH-' . $suffix . '-3');
    $orders[] = $badOrder;
    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'transition', 'order_id' => $badOrder,
        'expected_status' => 'pending', 'target_status' => 'confirmed',
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $badOrder),
    ]);
    sh_eq(200, $code, 'an order with no usable address still moves over HTTP');
    $badRows = sh_events($badOrder, 'order_confirmed');
    sh_eq(1, count($badRows), 'the unmailable move is still recorded once');
    $badEmail = sh_email((int) ($badRows[0]['id'] ?? 0));
    sh_eq('failed', (string) ($badEmail['status'] ?? ''), 'the unmailable email is recorded failed');
    $badEmailId = (int) ($badEmail['id'] ?? 0);
    sh_eq(1, count(sh_events($badOrder, 'admin_stage_email_failed')), 'the failure raises exactly one staff alert');

    // Order 360 shows the failure and offers the resend.
    [$pageCode, $pageBody] = sh_req($managerJar, $base . '/admin/orders.php?order=' . $badOrder);
    sh_eq(200, $pageCode, 'Order 360 opens for the failed order');
    sh_ok(str_contains($pageBody, 'Not sent'), 'Order 360 says the email did not go');
    sh_ok(str_contains($pageBody, 'resend_notification'), 'Order 360 offers the resend beside the failure');
    sh_ok(str_contains($pageBody, 'Send it again'), 'the resend is a button a person can press');

    // A resend with still no address says so plainly.
    [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'resend_notification', 'order_id' => $badOrder, 'delivery_id' => $badEmailId,
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $badOrder),
    ]);
    $payload = json_decode($body, true) ?: [];
    sh_eq(422, $code, 'resending with still no address answers 422');
    sh_eq('no_address', (string) ($payload['code'] ?? ''), 'the refusal names the missing address');

    // Correct the address, then send it again.
    Database::run('UPDATE users SET email = :email WHERE id = :id', [':email' => 'stage-http-fixed-' . $suffix . '@example.test', ':id' => $noAddressUser]);
    [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'resend_notification', 'order_id' => $badOrder, 'delivery_id' => $badEmailId,
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $badOrder),
    ]);
    $payload = json_decode($body, true) ?: [];
    sh_eq(200, $code, 'the corrected resend answers 200');
    sh_eq(true, (bool) ($payload['ok'] ?? false), 'the corrected resend goes out');
    $fixedRow = Database::one('SELECT status, recipient_address, attempt_count FROM notification_deliveries WHERE id = :id', [':id' => $badEmailId]);
    sh_eq('sent', (string) ($fixedRow['status'] ?? ''), 'the corrected resend lands');
    sh_eq('stage-http-fixed-' . $suffix . '@example.test', (string) ($fixedRow['recipient_address'] ?? ''), 'the resend goes to the corrected address');
    sh_eq(2, (int) ($fixedRow['attempt_count'] ?? 0), 'the attempt count counts both tries');

    // An email that went out is not sent a third time.
    [$code, $body] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'resend_notification', 'order_id' => $badOrder, 'delivery_id' => $badEmailId,
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $badOrder),
    ]);
    sh_eq(422, $code, 'resending a sent email is refused');

    // The resend is permission gated too.
    [$code] = sh_req($buyerJar, $base . '/api/v1/orders.php', [
        'action' => 'resend_notification', 'order_id' => $badOrder, 'delivery_id' => $badEmailId,
        'okv_csrf' => sh_csrf($buyerJar, $base . '/account.php'),
    ]);
    sh_eq(403, $code, 'a customer cannot resend a notification');
    [$code] = sh_req($managerJar, $base . '/api/v1/orders.php', [
        'action' => 'resend_notification', 'order_id' => $badOrder, 'delivery_id' => 999999999,
        'okv_csrf' => sh_csrf($managerJar, $base . '/admin/orders.php?order=' . $badOrder),
    ]);
    sh_eq(404, $code, 'resending a delivery that does not exist answers 404');

    // --- 7. Order 360 reads correctly once everything has landed -------------
    [$pageCode, $pageBody] = sh_req($managerJar, $base . '/admin/orders.php?order=' . $order);
    sh_eq(200, $pageCode, 'Order 360 opens for the finished journey');
    sh_ok(!str_contains($pageBody, 'Not sent'), 'a journey whose emails all landed shows no failure');
} finally {
    foreach ($orders as $id) {
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
    foreach ($users as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ([$managerJar, $buyerJar, $guestJar] as $jar) {
        if (is_string($jar) && $jar !== '' && file_exists($jar)) {
            unlink($jar);
        }
    }
}

fwrite(STDOUT, "\\n$passed / $tests stage notification HTTP assertions passed.\\n");
exit($passed === $tests ? 0 : 1);
