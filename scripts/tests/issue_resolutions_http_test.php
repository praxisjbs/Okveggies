<?php
/** M10 Task D permission and replacement resolution through the real endpoint. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }

$root = dirname(__DIR__, 2); $base = 'http://127.0.0.1:8214'; $tests = 0; $passed = 0;
function irhttp_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function irhttp_eq($expected, $actual, string $label): void { irhttp_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function irhttp_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($json) { curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? [])); }
    $body = (string) curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, $json ? (json_decode($body, true) ?? []) : $body];
}
function irhttp_csrf(string $html): string { return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }

$oldSmtpPort = $_ENV['SMTP_PORT'] ?? null; $_ENV['SMTP_PORT'] = 1; putenv('SMTP_PORT=1');
$log = sys_get_temp_dir() . '/okv-issue-resolution-http-server.log';
$server = proc_open('exec php -d display_errors=0 -S 127.0.0.1:8214 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the Task D HTTP server.\n"); exit(2); }
for ($i = 0; $i < 50; $i++) { $socket = @fsockopen('127.0.0.1', 8214, $errno, $error, 0.2); if ($socket) { fclose($socket); break; } usleep(100000); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10); $password = 'issue-resolution-88';
$userIds = []; $roleId = 0; $orderIds = []; $issueId = 0; $jar = tempnam(sys_get_temp_dir(), 'okv-ird-http-');
try {
    $makeUser = static function (string $first, string $type) use ($suffix, $password, &$userIds): array {
        $email = strtolower($first) . "-$suffix@example.test";
        Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
            VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
            [':first' => $first, ':last' => 'Task D', ':email' => $email, ':phone' => '+23474' . random_int(10000000, 99999999),
                ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => $type, ':status' => 'active']);
        $id = (int) Database::getInstance()->getConnection()->lastInsertId(); $userIds[] = $id; return [$id, $email];
    };
    [$customerId] = $makeUser('Customer', 'household'); [$staffId, $staffEmail] = $makeUser('Handler', 'staff');
    Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => "issue_task_d_$suffix", ':description' => 'Task D HTTP fixture']);
    $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    foreach (['dashboard.view', 'issues.view', 'issues.resolve', 'orders.view'] as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission', [':role' => $roleId, ':permission' => $permission]);
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $staffId, ':role' => $roleId]);

    $makeOrder = static function (int $sequence, ?int $createdBy) use ($suffix, $customerId, &$orderIds): array {
        $number = "ZZ-IRH-$sequence-$suffix";
        Database::run('INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
             preferred_delivery_date, created_by)
            VALUES (:number, :user, :type, :order_status, :payment_option, :payment_status,
             :subtotal, :total, :paid, :balance, :delivery_date, :created_by)',
            [':number' => $number, ':user' => $customerId, ':type' => 'household', ':order_status' => 'delivered',
                ':payment_option' => 'full', ':payment_status' => 'paid', ':subtotal' => 500000, ':total' => 500000,
                ':paid' => 500000, ':balance' => 0, ':delivery_date' => date('Y-m-d'), ':created_by' => $createdBy]);
        $id = (int) Database::getInstance()->getConnection()->lastInsertId(); $orderIds[] = $id;
        Database::run('INSERT INTO order_items (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
            VALUES (:order, :type, :name, :sku, :unit, :quantity, :price, :total)',
            [':order' => $id, ':type' => 'product', ':name' => 'Fresh Tomatoes', ':sku' => "IRH-$sequence", ':unit' => 'kg',
                ':quantity' => 1, ':price' => 500000, ':total' => 500000]);
        return ['id' => $id, 'number' => $number, 'item_id' => (int) Database::getInstance()->getConnection()->lastInsertId()];
    };
    $original = $makeOrder(1, null); $replacement = $makeOrder(2, $staffId);
    Database::run('INSERT INTO issue_reports (order_id, user_id, category, description, status, active_slot, handled_by, handled_at)
        VALUES (:order, :user, :category, :description, :status, :slot, :handler, NOW())',
        [':order' => $original['id'], ':user' => $customerId, ':category' => 'damaged', ':description' => 'The tomatoes arrived damaged.',
            ':status' => 'in_progress', ':slot' => 1, ':handler' => $staffId]);
    $issueId = (int) Database::getInstance()->getConnection()->lastInsertId();

    [, $login] = irhttp_req($base, $jar, 'GET', '/admin/login.php');
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/auth.php', ['action' => 'login', 'identifier' => $staffEmail,
        'password' => $password, 'okv_csrf' => irhttp_csrf((string) $login)], true);
    irhttp_eq(200, $status, 'the Task D handler signs in');
    [, $page] = irhttp_req($base, $jar, 'GET', '/admin/make_it_right.php?report=' . $issueId);
    $csrf = irhttp_csrf((string) $page);
    irhttp_ok(str_contains((string) $page, 'Record final outcome'), 'the assigned handler sees the resolution form');
    irhttp_ok(str_contains((string) $page, 'Account credit (Owner permission required)'), 'credit stays visible but disabled without its finance permission');

    $common = ['action' => 'resolve', 'issue_id' => $issueId, 'expected_status' => 'in_progress',
        'item_ids' => [$original['item_id']], 'resolution_note' => 'We linked a replacement order for these tomatoes.',
        'resolution_type' => 'replacement', 'replacement_order_number' => $replacement['number']];
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', $common, true);
    irhttp_eq(419, $status, 'resolution requires CSRF');
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', array_merge($common, ['okv_csrf' => $csrf]), true);
    irhttp_eq(422, $status, 'resolution requires server-side confirmation');
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', array_merge($common, ['okv_csrf' => $csrf, 'confirmed' => 1,
        'resolution_type' => 'refund', 'transaction_id' => 1, 'amount' => '1000']), true);
    irhttp_eq(403, $status, 'issues.resolve alone cannot raise a refund');
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', array_merge($common, ['okv_csrf' => $csrf, 'confirmed' => 1,
        'resolution_type' => 'credit', 'amount' => '1000']), true);
    irhttp_eq(403, $status, 'issues.resolve alone cannot grant account credit');
    [$status, $body] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', array_merge($common, ['okv_csrf' => $csrf, 'confirmed' => 1]), true);
    irhttp_eq(200, $status, 'the handler can link a valid manual replacement order');
    irhttp_eq('resolved', (string) ($body['code'] ?? ''), 'the endpoint returns the terminal resolved state');
    irhttp_eq($replacement['id'], (int) Database::one('SELECT replacement_order_id FROM issue_reports WHERE id = :id', [':id' => $issueId])['replacement_order_id'], 'HTTP resolution persists the replacement link');
    [$status] = irhttp_req($base, $jar, 'POST', '/api/v1/make_it_right.php', array_merge($common, ['okv_csrf' => $csrf, 'confirmed' => 1]), true);
    irhttp_eq(409, $status, 'a replay cannot resolve the report twice');
    irhttp_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'issue_report_resolved'])['n'], 'the committed outcome queues 1 customer notice');
} finally {
    if ($issueId > 0) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_report_resolution_items WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    }
    foreach ($orderIds as $orderId) { Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]); Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]); }
    foreach ($userIds as $userId) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]); }
    if ($roleId > 0) { Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $roleId]); Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]); }
    foreach ($userIds as $userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    if (is_file($jar)) { unlink($jar); } proc_terminate($server); proc_close($server);
    if ($oldSmtpPort === null) { unset($_ENV['SMTP_PORT']); putenv('SMTP_PORT'); } else { $_ENV['SMTP_PORT'] = $oldSmtpPort; putenv('SMTP_PORT=' . $oldSmtpPort); }
}
fwrite(STDOUT, "\n$passed / $tests issue resolution HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
