<?php
/**
 * Cancellation controller integration over real HTTP.
 * Start a migrated local site first and optionally set OKV_TEST_BASE.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0; $passed = 0;
function h_ok($condition, string $label): void {
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}
function h_eq($expected, $actual, string $label): void {
    h_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}
function h_req(string $jar, string $url, ?array $post = null): array {
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
function h_csrf(string $jar, string $url): string {
    [, $body] = h_req($jar, $url);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $match) ? $match[1] : '';
}
function h_login(string $jar, string $base, string $page, string $email, string $password): string {
    $token = h_csrf($jar, $base . $page);
    [$code] = h_req($jar, $base . '/api/v1/auth.php', [
        'action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token,
    ]);
    h_eq(200, $code, $email . ' signs in');
    return h_csrf($jar, $base . ($page === '/admin/login.php' ? '/admin/orders.php' : '/account.php'));
}
function h_order(int $userId, string $number, string $status = 'pending', string $payment = 'unpaid', int $paid = 0): int {
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_required_subunit, amount_paid_subunit,
             balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, \'household\', :status, \'deposit\', :payment,
                 1000000, 1000000, 300000, :paid, :balance, :delivery)',
        [':number' => $number, ':user' => $userId, ':status' => $status, ':payment' => $payment,
         ':paid' => $paid, ':balance' => max(0, 1000000 - $paid), ':delivery' => date('Y-m-d', strtotime('+5 days'))]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'cancel-http-777';
$hash = password_hash($password, PASSWORD_BCRYPT);
$users = []; $orders = [];
$jars = [tempnam(sys_get_temp_dir(), 'okv-c1-'), tempnam(sys_get_temp_dir(), 'okv-c2-'), tempnam(sys_get_temp_dir(), 'okv-m-')];
$oldAllowed = Settings::bool('cancellation_customer_allowed', true);
$oldCutoff = Settings::str('cancellation_cutoff_time', '18:00');

try {
    foreach ([
        ['Buyer', 'One', 'household', 'cancel-http-one-' . $suffix . '@example.test', '+23471' . random_int(10000000, 99999999)],
        ['Buyer', 'Two', 'household', 'cancel-http-two-' . $suffix . '@example.test', '+23472' . random_int(10000000, 99999999)],
        ['Manny', 'Manager', 'staff', 'cancel-http-manager-' . $suffix . '@example.test', '+23473' . random_int(10000000, 99999999)],
    ] as $row) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first, :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [':first' => $row[0], ':last' => $row[1], ':email' => $row[3], ':phone' => $row[4], ':hash' => $hash, ':type' => $row[2]]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run(
        'INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'',
        [':user' => $users[2]]
    );
    Database::run('DELETE FROM rate_limits');
    Settings::set('cancellation_customer_allowed', true, 'bool', null);
    Settings::set('cancellation_cutoff_time', '23:59', 'string', null);
    Settings::flushCache();

    $owned = h_order($users[0], 'ZZ-HC-' . $suffix . '-1'); $orders[] = $owned;
    $other = h_order($users[1], 'ZZ-HC-' . $suffix . '-2'); $orders[] = $other;
    $paid = h_order($users[0], 'ZZ-HC-' . $suffix . '-3', 'confirmed', 'part_paid', 300000); $orders[] = $paid;

    $csrfOne = h_login($jars[0], $base, '/account.php', 'cancel-http-one-' . $suffix . '@example.test', $password);
    $csrfTwo = h_login($jars[1], $base, '/account.php', 'cancel-http-two-' . $suffix . '@example.test', $password);
    $csrfManager = h_login($jars[2], $base, '/admin/login.php', 'cancel-http-manager-' . $suffix . '@example.test', $password);
    h_ok($csrfOne !== '' && $csrfTwo !== '' && $csrfManager !== '', 'all cancellation forms carry CSRF tokens');

    [$code] = h_req($jars[0], $base . '/api/v1/orders.php?action=cancel_customer&order_id=' . $owned);
    h_eq(405, $code, 'customer cancellation over GET is refused');
    [$code] = h_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'cancel_customer', 'order_id' => $owned, 'reason_code' => 'changed_mind', 'confirmed' => '1',
    ]);
    h_eq(419, $code, 'customer cancellation without CSRF is refused');
    [$code] = h_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'cancel_customer', 'order_id' => $other, 'reason_code' => 'changed_mind', 'confirmed' => '1', 'okv_csrf' => $csrfOne,
    ]);
    h_eq(404, $code, 'customer ownership is enforced by the server');
    [$code] = h_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'cancel_customer', 'order_id' => $owned, 'reason_code' => 'changed_mind', 'confirmed' => '1', 'okv_csrf' => $csrfOne,
    ]);
    h_eq(200, $code, 'an eligible customer cancellation succeeds');
    [$code] = h_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'cancel_customer', 'order_id' => $owned, 'reason_code' => 'changed_mind', 'confirmed' => '1', 'okv_csrf' => $csrfOne,
    ]);
    h_eq(200, $code, 'a repeated customer cancellation is an idempotent success');
    $count = Database::one('SELECT COUNT(*) AS n FROM order_cancellations WHERE order_id = :id', [':id' => $owned]);
    h_eq(1, (int) $count['n'], 'repeat clicks create one cancellation record');

    [$code] = h_req($jars[2], $base . '/api/v1/orders.php', [
        'action' => 'cancel_staff', 'order_id' => $paid, 'reason_code' => 'customer_requested', 'confirmed' => '1', 'okv_csrf' => $csrfManager,
    ]);
    h_eq(403, $code, 'a Manager cannot cancel a paid order without refund permission');
    $row = Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $paid]);
    h_eq('confirmed', (string) $row['order_status'], 'the refused staff request changes nothing');
} finally {
    Settings::set('cancellation_customer_allowed', $oldAllowed, 'bool', null);
    Settings::set('cancellation_cutoff_time', $oldCutoff, 'string', null);
    Settings::flushCache();
    foreach ($orders as $id) {
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_cancellations WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    if (isset($users[2])) {
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $users[2]]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($jars as $jar) { @unlink($jar); }
}

fwrite(STDOUT, "\n$passed / $tests cancellation HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
