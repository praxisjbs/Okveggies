<?php
/** Task A behaviour through the real owner and public-token routes. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8210';
$tests = 0; $passed = 0;
function irh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function irh_eq($expected, $actual, string $label): void { irh_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function irh_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = true): array {
    $ch = curl_init($base . $path);
    $headers = [$json ? 'X-Requested-With: fetch' : 'Accept: text/html'];
    if ($json) { $headers[] = 'Accept: application/json'; }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => !$json,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$json) {
        preg_match('/^Location:\s*(\S+)/mi', $raw, $location);
        return [$status, $raw, (string) ($location[1] ?? '')];
    }
    return [$status, json_decode($raw, true) ?? $raw];
}
function irh_page(string $base, string $jar, string $path): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $html = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $csrf);
    return [$status, $html, (string) ($csrf[1] ?? '')];
}

$previousPort = getenv('SMTP_PORT');
$serverLog = sys_get_temp_dir() . '/okv-issue-http-server.log';
$server = proc_open(
    'exec env SMTP_PORT=1 php -d display_errors=0 -S 127.0.0.1:8210 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the issue report test server.\n");
    exit(2);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8210, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$jar = tempnam(sys_get_temp_dir(), 'okv-issue-owner-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-issue-guest-');
$userIds = []; $orderIds = []; $issueIds = [];

$makeUser = static function (string $name) use ($suffix, &$userIds): array {
    $email = strtolower($name) . "-http-$suffix@example.test";
    $password = 'issue-http-88';
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => $name, ':last' => 'Issue HTTP', ':email' => $email,
            ':phone' => '+23471' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT),
            ':type' => 'household', ':status' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return [$id, $email, $password];
};
$makeOrder = static function (int $userId, string $status, string $eventAt, bool $withEvent = true) use ($suffix, &$orderIds): array {
    $token = OrderTrail::newToken();
    $number = 'ZZ-IRH-' . count($orderIds) . '-' . $suffix;
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date,
             delivered_at, order_trail_token_hash)
         VALUES (:number, :user, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date, :delivered_at, :token_hash)',
        [':number' => $number, ':user' => $userId, ':customer_type' => 'household', ':order_status' => $status,
            ':payment_option' => 'pay_on_delivery', ':payment_status' => 'unpaid', ':subtotal' => 500000,
            ':total' => 500000, ':balance' => 500000, ':delivery_date' => date('Y-m-d'),
            ':delivered_at' => $status === 'delivered' && $withEvent ? $eventAt : null,
            ':token_hash' => OrderTrail::hashToken($token)]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orderIds[] = $id;
    Database::run(
        'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
         VALUES (:order_id, NULL, :new_status, :source, :actor, :created_at)',
        [':order_id' => $id, ':new_status' => 'pending', ':source' => 'customer', ':actor' => $userId,
            ':created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]
    );
    if ($withEvent && $status === 'dispatched') {
        Database::run(
            'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
             VALUES (:order_id, :old_status, :new_status, :source, :actor, :created_at)',
            [':order_id' => $id, ':old_status' => 'packed', ':new_status' => 'dispatched',
                ':source' => 'admin', ':actor' => $userId, ':created_at' => $eventAt]
        );
    }
    return [$id, $number, $token];
};

try {
    [$ownerId, $ownerEmail, $password] = $makeUser('Ada');
    [$otherId] = $makeUser('Bola');
    [$eligibleId, $eligibleNumber, $trailToken] = $makeOrder($ownerId, 'dispatched', date('Y-m-d H:i:s', strtotime('-1 day')));
    [$otherOrderId] = $makeOrder($otherId, 'dispatched', date('Y-m-d H:i:s', strtotime('-1 day')));
    [$packedId] = $makeOrder($ownerId, 'packed', date('Y-m-d H:i:s'));
    [$expiredId] = $makeOrder($ownerId, 'dispatched', date('Y-m-d H:i:s', strtotime('-8 days')));

    [$status] = irh_req($base, $guestJar, 'GET', '/api/v1/make_it_right.php');
    irh_eq(405, $status, 'GET is refused by the reporting endpoint');
    [$status, $plainGet] = irh_req($base, $guestJar, 'GET', '/api/v1/make_it_right.php', null, false);
    irh_eq(405, $status, 'a browser-style GET is also refused without a redirect');
    irh_ok(str_contains($plainGet, 'method_not_allowed'), 'the browser-style GET receives the same safe API error');
    [$status] = irh_req($base, $guestJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'order_id' => $eligibleId, 'category' => 'damaged',
        'description' => 'Two packs arrived crushed.',
    ]);
    irh_eq(401, $status, 'a signed-out submission is refused');

    [, $accountHtml, $loginCsrf] = irh_page($base, $jar, '/account.php');
    irh_ok($loginCsrf !== '', 'the sign-in page supplies CSRF');
    [$status] = irh_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'identifier' => $ownerEmail,
        'password' => $password, 'okv_csrf' => $loginCsrf,
    ]);
    irh_eq(200, $status, 'the fixture customer signs in');

    [$status, $ownerPage, $csrf] = irh_page($base, $jar, '/public/order.php?order=' . $eligibleId);
    irh_eq(200, $status, 'the authenticated owner can open the order');
    irh_ok(str_contains($ownerPage, 'Something is not right'), 'the eligible owner sees the reporting action');
    irh_ok(str_contains($ownerPage, 'Send report for order ' . $eligibleNumber), 'the form repeats the real order number');
    irh_ok(str_contains($ownerPage, 'Use 10 to 1,000 characters'), 'the description limit is stated before submission');

    [$status, $publicPage] = irh_page($base, $guestJar, '/public/order.php?token=' . rawurlencode($trailToken));
    irh_eq(200, $status, 'the public token still opens the Order Trail');
    irh_ok(!str_contains($publicPage, 'Something is not right'), 'the public token reveals no report action');
    irh_ok(!str_contains($publicPage, 'Make It Right'), 'the public token reveals no issue section');
    irh_ok(!str_contains($publicPage, '/api/v1/make_it_right.php'), 'the public token carries no issue endpoint');

    [$status] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'order_id' => $eligibleId, 'category' => 'damaged',
        'description' => 'Two packs arrived crushed.',
    ]);
    irh_eq(419, $status, 'missing CSRF is refused');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $wrongOwner] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $otherOrderId,
        'category' => 'missing_item', 'description' => 'One spinach bunch was missing.',
    ]);
    irh_eq(404, $status, 'another customer\'s order is refused as not found');
    irh_eq('not_found', (string) ($wrongOwner['code'] ?? ''), 'the ownership response gives no order details');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $packed] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $packedId,
        'category' => 'late', 'description' => 'This order has not left yet.',
    ]);
    irh_eq(422, $status, 'an order before dispatch is refused over HTTP');
    irh_eq('ineligible_status', (string) ($packed['code'] ?? ''), 'the pre-dispatch refusal is explicit to its owner');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $expired] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $expiredId,
        'category' => 'quality', 'description' => 'The leaves were already brown.',
    ]);
    irh_eq(422, $status, 'an expired reporting window is refused over HTTP');
    irh_eq('expired', (string) ($expired['code'] ?? ''), 'the expired owner receives the safe window state');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $badCategory] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $eligibleId,
        'category' => 'refund_now', 'description' => 'Please inspect the produce.',
    ]);
    irh_eq(422, $status, 'an invented category is refused over HTTP');
    irh_eq('category_required', (string) ($badCategory['code'] ?? ''), 'the invalid category identifies its field safely');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $tooShort] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $eligibleId,
        'category' => 'damaged', 'description' => 'Crushed',
    ]);
    irh_eq(422, $status, 'a short description is refused over HTTP');
    irh_eq('description_too_short', (string) ($tooShort['code'] ?? ''), 'the short description identifies its field safely');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    $customerText = '<b>Two tomato packs arrived crushed.</b>';
    [$status, $saved] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $eligibleId,
        'category' => 'damaged', 'description' => $customerText,
    ]);
    irh_eq(201, $status, 'a valid owner report is created over HTTP');
    irh_eq('reported', (string) ($saved['code'] ?? ''), 'the success state is explicit');
    irh_eq($eligibleNumber, (string) ($saved['order_number'] ?? ''), 'success copy uses the existing order number');
    $issueId = (int) Database::one('SELECT id FROM issue_reports WHERE order_id = :order_id', [':order_id' => $eligibleId])['id'];
    $issueIds[] = $issueId;
    irh_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :order_id', [':order_id' => $eligibleId])['n'], 'one HTTP submission creates one report');
    $events = array_column(Database::all('SELECT event_type FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]), 'event_type');
    irh_ok(in_array('issue_report_received', $events, true), 'the customer notification is recorded after the HTTP write');
    irh_ok(in_array('admin_new_issue_report', $events, true), 'the staff alert is recorded after the HTTP write');

    [$status, $afterPage] = irh_page($base, $jar, '/public/order.php?order=' . $eligibleId . '&issue=reported');
    irh_eq(200, $status, 'the owner returns to an accessible success state');
    irh_ok(str_contains($afterPage, 'We received your report for order ' . $eligibleNumber), 'the success state repeats the order number');
    irh_ok(str_contains($afterPage, '&lt;b&gt;Two tomato packs arrived crushed.&lt;/b&gt;'), 'stored customer text is escaped on output');
    irh_ok(!str_contains($afterPage, $customerText), 'stored customer text never renders as markup');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [$status, $again] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $eligibleId,
        'category' => 'quality', 'description' => 'A repeated browser submission.',
    ]);
    irh_eq(200, $status, 'a replay receives an idempotent response');
    irh_eq('already_open', (string) ($again['code'] ?? ''), 'the replay is identified as already open');
    irh_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :order_id', [':order_id' => $eligibleId])['n'], 'the replay creates no second report');

    [$plainId, $plainNumber] = $makeOrder($ownerId, 'delivered', date('Y-m-d H:i:s', strtotime('-1 day')));
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    [, , $plainCsrf] = irh_page($base, $jar, '/public/order.php?order=' . $plainId);
    [$status, , $location] = irh_req($base, $jar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $plainCsrf, 'order_id' => $plainId,
        'category' => 'wrong_item', 'description' => 'The basket contained the wrong pepper.',
    ], false);
    irh_eq(303, $status, 'the form works without JavaScript');
    irh_ok(str_contains($location, '/public/order.php?order=' . $plainId . '&issue=reported'), 'the plain POST returns to the same owned order');
    $plainIssue = Database::one('SELECT id FROM issue_reports WHERE order_id = :id', [':id' => $plainId]);
    if ($plainIssue) { $issueIds[] = (int) $plainIssue['id']; }
    irh_ok($plainIssue !== null, 'the plain POST stores the same report');
} finally {
    foreach ($issueIds as $issueId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM issue_reports WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
    if (is_file($jar)) { unlink($jar); }
    if (is_file($guestJar)) { unlink($guestJar); }
    proc_terminate($server);
    proc_close($server);
    if ($previousPort === false) { putenv('SMTP_PORT'); } else { putenv('SMTP_PORT=' . $previousPort); }
}

fwrite(STDOUT, "\n$passed / $tests issue report HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
