<?php
/** Task C staff queue permissions and workflow through the real HTTP routes. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8213';
$tests = 0;
$passed = 0;
function iwhttp_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function iwhttp_eq($expected, $actual, string $label): void { iwhttp_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function iwhttp_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$status, $json ? (json_decode($body, true) ?? []) : $body, $location];
}
function iwhttp_csrf(string $html): string
{
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $match) ? (string) $match[1] : '';
}
function iwhttp_login(string $base, string $jar, string $email, string $password): string
{
    [, $login] = iwhttp_req($base, $jar, 'GET', '/admin/login.php');
    [$status] = iwhttp_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login',
        'identifier' => $email,
        'password' => $password,
        'okv_csrf' => iwhttp_csrf((string) $login),
    ], true);
    iwhttp_eq(200, $status, $email . ' signs in');
    [, $dashboard] = iwhttp_req($base, $jar, 'GET', '/admin/');
    return iwhttp_csrf((string) $dashboard);
}

$oldSmtpPort = $_ENV['SMTP_PORT'] ?? null;
$_ENV['SMTP_PORT'] = 1;
putenv('SMTP_PORT=1');
$serverLog = sys_get_temp_dir() . '/okv-issue-workflow-http-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8213 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the issue workflow test server.\n");
    exit(2);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8213, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'issue-workflow-88';
$userIds = [];
$roleIds = [];
$orderId = 0;
$issueId = 0;
$jars = [];

$makeUser = static function (string $first, string $type) use ($suffix, $password, &$userIds): array {
    $email = strtolower($first) . "-issue-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => $first, ':last' => 'Issue Test', ':email' => $email,
            ':phone' => '+23473' . random_int(10000000, 99999999),
            ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => $type, ':status' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return [$id, $email];
};
$giveRole = static function (int $userId, string $name, array $permissions) use ($suffix, &$roleIds): void {
    Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [
        ':name' => $name . '_' . $suffix,
        ':description' => 'Task C HTTP fixture',
    ]);
    $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roleIds[] = $roleId;
    foreach ($permissions as $permission) {
        Database::run(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT :role, id FROM permissions WHERE `key` = :permission',
            [':role' => $roleId, ':permission' => $permission]
        );
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $userId, ':role' => $roleId]);
};

try {
    [$customerId] = $makeUser('Customer', 'household');
    [$viewerId, $viewerEmail] = $makeUser('Viewer', 'staff');
    [$resolverOnlyId, $resolverOnlyEmail] = $makeUser('Resolveronly', 'staff');
    [$handlerId, $handlerEmail] = $makeUser('Handler', 'staff');
    [$colleagueId, $colleagueEmail] = $makeUser('Colleague', 'staff');
    $giveRole($viewerId, 'issue_viewer', ['dashboard.view', 'issues.view']);
    $giveRole($resolverOnlyId, 'issue_resolver_only', ['dashboard.view', 'issues.resolve']);
    $giveRole($handlerId, 'issue_handler', ['dashboard.view', 'issues.view', 'issues.resolve', 'orders.view']);
    $giveRole($colleagueId, 'issue_colleague', ['dashboard.view', 'issues.view', 'issues.resolve']);

    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :paid, :balance, :delivery_date)',
        [':number' => "ZZ-IWH-$suffix", ':user' => $customerId, ':customer_type' => 'household',
            ':order_status' => 'dispatched', ':payment_option' => 'deposit', ':payment_status' => 'part_paid',
            ':subtotal' => 850000, ':total' => 850000, ':paid' => 300000, ':balance' => 550000,
            ':delivery_date' => date('Y-m-d', strtotime('+1 day'))]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
         VALUES (:order, :old, :new, :source, :actor, :created)',
        [':order' => $orderId, ':old' => 'packed', ':new' => 'dispatched', ':source' => 'admin',
            ':actor' => $handlerId, ':created' => date('Y-m-d H:i:s', strtotime('-1 day'))]
    );
    Database::run(
        'INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state)
         VALUES (:order, :name, :phone, :address, :city, :state)',
        [':order' => $orderId, ':name' => 'Customer Issue Test', ':phone' => '08030000000',
            ':address' => '22 Fresh Market Road', ':city' => 'Ikeja', ':state' => 'Lagos']
    );
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
         VALUES (:order, :type, :name, :sku, :unit, :quantity, :price, :total)',
        [':order' => $orderId, ':type' => 'product', ':name' => 'Fresh peppers', ':sku' => 'TEST-PEPPER',
            ':unit' => 'kg', ':quantity' => 1, ':price' => 850000, ':total' => 850000]
    );
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    $created = IssueReports::submit(
        $orderId,
        $customerId,
        'damaged',
        '<script>alert("unsafe")</script> The pepper pack was crushed on arrival.',
        '192.0.2.91'
    );
    $issueId = (int) $created['issue_id'];

    $guestJar = tempnam(sys_get_temp_dir(), 'okv-iw-guest-'); $jars[] = $guestJar;
    [$status] = iwhttp_req($base, $guestJar, 'GET', '/admin/make_it_right.php');
    iwhttp_eq(302, $status, 'a guest cannot read the report queue');

    $viewerJar = tempnam(sys_get_temp_dir(), 'okv-iw-view-'); $jars[] = $viewerJar;
    $viewerCsrf = iwhttp_login($base, $viewerJar, $viewerEmail, $password);
    [$status, $viewerPage] = iwhttp_req($base, $viewerJar, 'GET', '/admin/make_it_right.php?report=' . $issueId);
    iwhttp_eq(200, $status, 'issues.view can open the report workspace');
    iwhttp_ok(str_contains((string) $viewerPage, "ZZ-IWH-$suffix"), 'the queue and detail use the existing order number');
    iwhttp_ok(str_contains((string) $viewerPage, '&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;'), 'the customer description is escaped');
    iwhttp_ok(!str_contains((string) $viewerPage, '<script>alert("unsafe")</script>'), 'stored report markup is never rendered');
    iwhttp_ok(str_contains((string) $viewerPage, 'Fresh peppers') && str_contains((string) $viewerPage, '22 Fresh Market Road'), 'the same screen includes order items and delivery context');
    iwhttp_ok(!str_contains((string) $viewerPage, 'Take this report'), 'view-only staff see no workflow control');
    [$status] = iwhttp_req($base, $viewerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open', 'okv_csrf' => $viewerCsrf,
    ], true);
    iwhttp_eq(403, $status, 'issues.view alone cannot take a report');

    $resolverOnlyJar = tempnam(sys_get_temp_dir(), 'okv-iw-resolve-'); $jars[] = $resolverOnlyJar;
    $resolverOnlyCsrf = iwhttp_login($base, $resolverOnlyJar, $resolverOnlyEmail, $password);
    [$status, $resolverOnlyPage] = iwhttp_req($base, $resolverOnlyJar, 'GET', '/admin/make_it_right.php?report=' . $issueId);
    iwhttp_eq(403, $status, 'issues.resolve without issues.view cannot read a report');
    iwhttp_ok(!str_contains((string) $resolverOnlyPage, 'Fresh peppers'), 'the refused read leaks no order item');
    [$status] = iwhttp_req($base, $resolverOnlyJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open', 'okv_csrf' => $resolverOnlyCsrf,
    ], true);
    iwhttp_eq(403, $status, 'a workflow action also requires issues.view');

    $handlerJar = tempnam(sys_get_temp_dir(), 'okv-iw-handle-'); $jars[] = $handlerJar;
    $handlerCsrf = iwhttp_login($base, $handlerJar, $handlerEmail, $password);
    [$status, $handlerPage] = iwhttp_req($base, $handlerJar, 'GET', '/admin/make_it_right.php?report=' . $issueId);
    iwhttp_eq(200, $status, 'a fully authorised colleague can read report detail');
    iwhttp_ok(str_contains((string) $handlerPage, 'Take this report'), 'an authorised colleague sees the take action');
    iwhttp_ok(str_contains((string) $handlerPage, '/admin/orders.php?order=' . $orderId), 'report detail links to its admin order');
    [$status, $orderPage] = iwhttp_req($base, $handlerJar, 'GET', '/admin/orders.php?order=' . $orderId);
    iwhttp_eq(200, $status, 'the linked admin order opens');
    iwhttp_ok(str_contains((string) $orderPage, '/admin/make_it_right.php?status=all&amp;report=' . $issueId), 'the admin order links back to its report');

    [$status] = iwhttp_req($base, $handlerJar, 'GET', '/api/v1/make_it_right.php?action=take&issue_id=' . $issueId, null, true);
    iwhttp_eq(405, $status, 'taking a report over GET is refused');
    [$status] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open',
    ], true);
    iwhttp_eq(419, $status, 'taking a report requires CSRF');
    [$status, $body] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open', 'okv_csrf' => $handlerCsrf,
    ], true);
    iwhttp_eq(200, $status, 'an authorised colleague can take an open report');
    iwhttp_eq('taken', (string) ($body['code'] ?? ''), 'the HTTP result names the taken transition');
    iwhttp_eq($handlerId, (int) Database::one('SELECT handled_by FROM issue_reports WHERE id = :id', [':id' => $issueId])['handled_by'], 'the HTTP transition records its actor');
    [$status, $body] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open', 'okv_csrf' => $handlerCsrf,
    ], true);
    iwhttp_eq(200, $status, 'same-handler take replay is harmless');
    iwhttp_eq('already_taken', (string) ($body['code'] ?? ''), 'the replay is explicitly idempotent');

    $colleagueJar = tempnam(sys_get_temp_dir(), 'okv-iw-colleague-'); $jars[] = $colleagueJar;
    $colleagueCsrf = iwhttp_login($base, $colleagueJar, $colleagueEmail, $password);
    [$status] = iwhttp_req($base, $colleagueJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'take', 'issue_id' => $issueId, 'expected_status' => 'open', 'okv_csrf' => $colleagueCsrf,
    ], true);
    iwhttp_eq(409, $status, 'another colleague cannot take over the report');
    [$status] = iwhttp_req($base, $colleagueJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'decline', 'issue_id' => $issueId, 'expected_status' => 'in_progress',
        'resolution_note' => 'This should not be accepted from another colleague.', 'okv_csrf' => $colleagueCsrf,
    ], true);
    iwhttp_eq(409, $status, 'a colleague who is not handling the report cannot decline it');
    [$status] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'decline', 'issue_id' => $issueId, 'expected_status' => 'in_progress',
        'resolution_note' => 'Too short', 'okv_csrf' => $handlerCsrf,
    ], true);
    iwhttp_eq(422, $status, 'a short customer-facing decline reason is refused');

    [$status, $body] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'decline', 'issue_id' => $issueId, 'expected_status' => 'in_progress',
        'resolution_note' => 'We could not verify the reported damage from the order record.', 'okv_csrf' => $handlerCsrf,
    ], true);
    iwhttp_eq(200, $status, 'the handler can decline with a customer-facing reason');
    iwhttp_eq('declined', (string) ($body['code'] ?? ''), 'the HTTP result names the terminal outcome');
    $terminal = Database::one('SELECT status, resolution_note, resolved_at FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    iwhttp_eq('declined', (string) $terminal['status'], 'the terminal status is durable');
    iwhttp_ok($terminal['resolved_at'] !== null, 'the terminal transition records its time');
    iwhttp_eq(3, (int) Database::one('SELECT COUNT(*) AS n FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId])['n'], 'report, take and decline each have one history entry');
    iwhttp_eq(1, (int) Database::one(
        'SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'issue_report_resolved']
    )['n'], 'the committed decline creates one customer notification');
    [$status] = iwhttp_req($base, $handlerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'decline', 'issue_id' => $issueId, 'expected_status' => 'in_progress',
        'resolution_note' => 'A replay must not replace the original customer reason.', 'okv_csrf' => $handlerCsrf,
    ], true);
    iwhttp_eq(409, $status, 'a terminal report cannot be declined twice');
    iwhttp_eq(1, (int) Database::one(
        'SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'issue_report_resolved']
    )['n'], 'terminal replay creates no duplicate notification');
} finally {
    if ($issueId > 0) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_report_photos WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    }
    if ($orderId > 0) {
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%' OR bucket LIKE 'login:%'");
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]);
    }
    foreach ($roleIds as $roleId) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $roleId]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
    foreach ($jars as $jar) { if (is_file($jar)) { unlink($jar); } }
    proc_terminate($server);
    proc_close($server);
    if ($oldSmtpPort === null) {
        unset($_ENV['SMTP_PORT']);
        putenv('SMTP_PORT');
    } else {
        $_ENV['SMTP_PORT'] = $oldSmtpPort;
        putenv('SMTP_PORT=' . $oldSmtpPort);
    }
}

fwrite(STDOUT, "\n$passed / $tests issue workflow HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
