<?php
/**
 * scripts/tests/kitchen_runs_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run HTTP contract: the method gate, CSRF, RBAC,
 * ownership, and the two screens loading as a browser loads them.
 *
 *   php -S 127.0.0.1:8123 -t . &
 *   OKV_TEST_BASE=http://127.0.0.1:8123 php scripts/tests/kitchen_runs_http_test.php
 *
 * The screen loads at the bottom are not padding. M6 shipped an orders filter
 * that answered 500 to every search because it bound one named placeholder
 * twice, and M7's first attempt shipped a conversion that threw for the same
 * reason. Both were invisible to unit tests and both would have been a single
 * red line here. A route nobody requests is a route nobody has tried.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs PHP curl.\n");
    exit(2);
}

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0;
$passed = 0;

function krh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function krh_eq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    krh_ok($ok, $label . ($ok ? '' : " (expected $expected, got $actual)"));
}

function krh_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $headers = $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
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

function krh_csrf(string $jar, string $url): string
{
    [, $body] = krh_req($jar, $url, null, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

function krh_login(string $jar, string $base, string $page, string $email, string $password, string $after): string
{
    $token = krh_csrf($jar, $base . $page);
    [$code] = krh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token]);
    krh_eq(200, $code, "$email signs in");
    return krh_csrf($jar, $base . $after);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'kitchen-http-777';
$users = [];
$requestIds = [];
$jars = [
    tempnam(sys_get_temp_dir(), 'okv-krh-m-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-a-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-b-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-g-'),
];

try {
    foreach ([['staff', 'Manager'], ['household', 'Owner'], ['household', 'Stranger']] as $index => [$type, $last]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'Kitchen\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':last' => $last,
                ':email' => "kr-http-$index-$suffix@example.test",
                ':phone' => '+23476' . random_int(10000000, 99999999),
                ':hash' => password_hash($password, PASSWORD_BCRYPT),
                ':type' => $type,
            ]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users[0]]);
    Database::run('DELETE FROM rate_limits');

    $unitId = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];
    $zoneId = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $date = (string) Delivery::nextEligibleDates('household', 3)[0]['date'];

    // One request owned by the second user, so ownership can be tested.
    $owned = KitchenRunWorkflow::submit($users[1], 'household', [
        'recipient_name' => 'Kitchen Owner', 'recipient_phone' => '08031234567',
        'address_line_1' => '5 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId]],
    ]);
    $requestId = (int) $owned['id'];
    $requestIds[] = $requestId;
    $version = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];

    // --- 1. The gates that run before any action does ------------------------
    [$code] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php?action=submit');
    krh_eq(405, $code, 'a Kitchen Run write refuses GET');

    [$code, $body] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php', ['action' => 'submit', 'input_mode' => 'custom']);
    krh_eq(419, $code, 'a write without a CSRF token is refused before anything runs');
    krh_ok(!str_contains($body, 'Exception') && !str_contains($body, 'SQLSTATE'), 'a refusal never leaks an exception or a driver message');

    foreach (['quote', 'convert', 'decline', 'approve', 'cancel'] as $action) {
        [$code] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php', ['action' => $action, 'request_id' => $requestId]);
        krh_eq(419, $code, "a signed-out caller cannot $action without a CSRF token");
    }

    // --- 2. Signed in, and gated by who you are ------------------------------
    $managerCsrf = krh_login($jars[0], $base, '/admin/login.php', "kr-http-0-$suffix@example.test", $password, '/admin/kitchen_runs.php');
    $ownerCsrf = krh_login($jars[1], $base, '/account.php', "kr-http-1-$suffix@example.test", $password, '/kitchen-runs.php');
    $strangerCsrf = krh_login($jars[2], $base, '/account.php', "kr-http-2-$suffix@example.test", $password, '/kitchen-runs.php');

    krh_ok($managerCsrf !== '' && $ownerCsrf !== '' && $strangerCsrf !== '', 'every screen hands out a CSRF token');

    // A customer cannot do a staff job, whatever they post.
    foreach ([['quote', 403], ['convert', 403], ['decline', 403]] as [$action, $expected]) {
        [$code] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
            'action' => $action, 'request_id' => $requestId, 'state_version' => $version,
            'okv_csrf' => $ownerCsrf, 'payment_option' => 'deposit', 'admin_note' => 'no',
        ]);
        krh_eq($expected, $code, "a customer cannot $action a Kitchen Run");
    }

    // A customer cannot touch somebody else's request.
    [$code] = krh_req($jars[2], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'approve', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $strangerCsrf,
    ]);
    krh_eq(409, $code, 'another customer cannot approve a request that is not theirs');

    [$code] = krh_req($jars[2], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'cancel', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $strangerCsrf,
    ]);
    krh_eq(409, $code, 'another customer cannot withdraw a request that is not theirs');

    // Nor read its attachment route, which is a direct object reference.
    [$code] = krh_req($jars[2], $base . '/public/kitchen_run_attachment.php?request=' . $requestId, null, false);
    krh_eq(404, $code, 'another customer cannot reach somebody else\'s uploaded list');

    // --- 3. A staff member can do the staff job over the real route ----------
    [$code, $body] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'quote', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $managerCsrf,
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId, 'unit_price' => '4,000']],
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId, 'deposit' => '5,000',
    ]);
    krh_eq(200, $code, 'a manager quotes a Kitchen Run over the real route');
    $quoted = json_decode($body, true) ?: [];
    krh_eq(2000000, (int) ($quoted['total_subunit'] ?? 0), 'naira typed into the form become kobo exactly once, at the controller');

    // A second post carrying the version it already spent is refused, not
    // silently applied on top of somebody else's work.
    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'quote', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $managerCsrf,
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId, 'unit_price' => '9,000']],
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
    ]);
    krh_eq(409, $code, 'a stale quote post is refused rather than overwriting a colleague');

    $quotedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];
    [$code] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'approve', 'request_id' => $requestId, 'state_version' => $quotedVersion, 'okv_csrf' => $ownerCsrf,
    ]);
    krh_eq(200, $code, 'the customer who owns the request approves it');

    // Conversion over HTTP. This is the call the first attempt never once made.
    $approvedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];
    [$code, $body] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'convert', 'request_id' => $requestId, 'state_version' => $approvedVersion,
        'payment_option' => 'deposit', 'okv_csrf' => $managerCsrf,
    ]);
    krh_eq(200, $code, 'a manager converts an approved Kitchen Run into an order over the real route');
    $order = json_decode($body, true) ?: [];
    krh_ok(!empty($order['order_number']), 'the conversion answers with the order number it made');
    krh_ok(!isset($order['trail_token']), 'the order trail token is the customer\'s, and is never handed back over the API');

    // --- 4. The screens load. Both of them, with real data on them. ----------
    [$code, $body] = krh_req($jars[1], $base . '/kitchen-runs.php', null, false);
    krh_eq(200, $code, 'the customer Kitchen Runs screen loads');
    krh_ok(str_contains($body, 'Send us your list'), 'the customer screen renders its own copy');

    [$code, $body] = krh_req($jars[1], $base . '/kitchen-runs.php?request=' . $requestId, null, false);
    krh_eq(200, $code, 'a customer opens one of their own runs');
    krh_ok(str_contains($body, (string) $order['order_number']), 'a converted run links the customer to the order it became');

    foreach (KitchenRuns::MODES as $mode) {
        [$code] = krh_req($jars[1], $base . '/kitchen-runs.php?start=' . $mode, null, false);
        krh_eq(200, $code, "the $mode way of starting a list renders");
    }

    [$code, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php', null, false);
    krh_eq(200, $code, 'the staff queue loads');
    [$code] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?request=' . $requestId, null, false);
    krh_eq(200, $code, 'the staff screen opens one request');
    foreach (KitchenRuns::STATUSES as $status) {
        [$code] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?status=' . $status, null, false);
        krh_eq(200, $code, "the staff queue filters by $status without a server error");
    }

    // A signed-out visitor is sent to sign in, never shown the screen.
    [$code] = krh_req($jars[3], $base . '/kitchen-runs.php', null, false);
    krh_eq(302, $code, 'a signed-out visitor is sent to sign in rather than shown the form');
} finally {
    foreach ($requestIds as $id) {
        $orderRow = Database::one('SELECT converted_order_id FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
        $orderId = $orderRow && $orderRow['converted_order_id'] !== null ? (int) $orderRow['converted_order_id'] : 0;
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('UPDATE kitchen_run_requests SET converted_order_id = NULL WHERE id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
        if ($orderId > 0) {
            Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $orderId]);
            Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $orderId]);
            Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
        }
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($jars as $jar) {
        if (is_file($jar)) {
            unlink($jar);
        }
    }
}

fwrite(STDOUT, "\n$passed / $tests Kitchen Run HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
