<?php
/** Lifecycle POST, CSRF, RBAC, stale and repeat behavior over local HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0; $passed = 0;
function lh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function lh_eq($expected, $actual, string $label): void { lh_ok($expected === $actual, $label . ($expected === $actual ? '' : " (expected $expected, got $actual)")); }
function lh_req(string $jar, string $url, ?array $post = null): array { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json']]); if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); } $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, $body]; }
function lh_csrf(string $jar, string $url): string { [, $body] = lh_req($jar, $url); return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : ''; }
function lh_login(string $jar, string $base, string $page, string $email, string $password): string { $token = lh_csrf($jar, $base . $page); [$code] = lh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token]); lh_eq(200, $code, "$email signs in"); return lh_csrf($jar, $base . ($page === '/admin/login.php' ? '/admin/orders.php' : '/account.php')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10); $password = 'lifecycle-http-777'; $users = []; $orderId = 0;
$jars = [tempnam(sys_get_temp_dir(), 'okv-lhm-'), tempnam(sys_get_temp_dir(), 'okv-lhc-')];
try {
    foreach ([['staff', 'Manager'], ['household', 'Customer']] as $i => [$type, $last]) {
        Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at) VALUES (\'Life\', :last, :email, :phone, :hash, :type, \'active\', NOW())', [':last' => $last, ':email' => "life-http-$i-$suffix@example.test", ':phone' => '+23476' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => $type]);
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users[0]]);
    Database::run('DELETE FROM rate_limits');
    Database::run('INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date) VALUES (:number, :user, \'household\', \'pending\', \'pay_on_delivery\', \'unpaid\', 100000, 100000, 100000, :date)', [':number' => "ZZ-LH-$suffix", ':user' => $users[1], ':date' => date('Y-m-d', strtotime('+6 days'))]);
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO order_status_history (order_id, new_status, source, changed_by) VALUES (:order, \'pending\', \'customer\', :user)', [':order' => $orderId, ':user' => $users[1]]);
    Database::run('INSERT INTO delivery_schedules (order_id, delivery_date, status) SELECT id, preferred_delivery_date, \'scheduled\' FROM orders WHERE id = :id', [':id' => $orderId]);

    $managerCsrf = lh_login($jars[0], $base, '/admin/login.php', "life-http-0-$suffix@example.test", $password);
    $customerCsrf = lh_login($jars[1], $base, '/account.php', "life-http-1-$suffix@example.test", $password);
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php?action=transition&order_id=' . $orderId); lh_eq(405, $code, 'GET transition is refused');
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'pending', 'target_status' => 'confirmed']); lh_eq(419, $code, 'transition without CSRF is refused');
    [$code] = lh_req($jars[1], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'pending', 'target_status' => 'confirmed', 'okv_csrf' => $customerCsrf]); lh_eq(403, $code, 'a customer cannot perform a staff transition');
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'pending', 'target_status' => 'delivered', 'okv_csrf' => $managerCsrf]); lh_eq(422, $code, 'an invalid skipped transition is refused');
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'pending', 'target_status' => 'confirmed', 'okv_csrf' => $managerCsrf]); lh_eq(200, $code, 'a permitted transition succeeds');
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'pending', 'target_status' => 'confirmed', 'okv_csrf' => $managerCsrf]); lh_eq(200, $code, 'a repeated transition is an idempotent success');
    [$code] = lh_req($jars[0], $base . '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'expected_status' => 'packed', 'target_status' => 'dispatched', 'okv_csrf' => $managerCsrf]); lh_eq(409, $code, 'a stale different transition is refused');

    // The orders list and its three filters, loaded as a browser loads them.
    // The customer filter reused one named placeholder twice, which MySQL
    // refuses on a native prepared statement, so the screen answered 500 to
    // every customer search and no test noticed. A filter that is never
    // requested over HTTP is a filter nobody has tried.
    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php');
    lh_eq(200, $code, 'the orders list loads with no filter');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the unfiltered list shows the order');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_status=confirmed');
    lh_eq(200, $code, 'the orders list filters by stage');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the stage filter finds the confirmed order');
    [, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_status=packed');
    lh_ok(!str_contains($page, "ZZ-LH-$suffix"), 'the stage filter excludes an order at another stage');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_date=' . date('Y-m-d', strtotime('+6 days')));
    lh_eq(200, $code, 'the orders list filters by delivery day');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the delivery day filter finds the order');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_customer=' . rawurlencode("ZZ-LH-$suffix"));
    lh_eq(200, $code, 'the orders list filters by order number without erroring');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the customer filter finds an order by its number');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_customer=' . rawurlencode("life-http-1-$suffix@example.test"));
    lh_eq(200, $code, 'the orders list filters by the account email without erroring');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the customer filter finds an order by its account email');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_customer=' . rawurlencode("Life Customer"));
    lh_eq(200, $code, 'the orders list filters by the account name without erroring');
    lh_ok(str_contains($page, "ZZ-LH-$suffix"), 'the customer filter finds an order by the account name');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_customer=' . rawurlencode("zzz-no-such-customer"));
    lh_eq(200, $code, 'a customer search that matches nothing is an empty list, not an error');
    lh_ok(!str_contains($page, "ZZ-LH-$suffix"), 'a customer search that matches nothing shows no orders');

    [$code, $page] = lh_req($jars[0], $base . '/admin/orders.php?filter_customer=' . rawurlencode("' OR 1=1 -- "));
    lh_eq(200, $code, 'a quote in the customer search is data, not SQL');
    lh_ok(!str_contains($page, "ZZ-LH-$suffix"), 'a quote in the customer search matches nothing');
} finally {
    if ($orderId) { Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]); Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId]); Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]); }
    foreach ($users as $id) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]); Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
    foreach ($jars as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
}
fwrite(STDOUT, "\n$passed / $tests lifecycle HTTP assertions passed.\n"); exit($passed === $tests ? 0 : 1);
