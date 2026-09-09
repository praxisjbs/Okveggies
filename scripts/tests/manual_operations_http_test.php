<?php
/**
 * scripts/tests/manual_operations_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The HTTP contract for the work the back office can now start:
 * the method gate, CSRF, RBAC, and the three new screens loading as a browser
 * loads them.
 *
 *   php -S 127.0.0.1:8123 -t . &
 *   OKV_TEST_BASE=http://127.0.0.1:8123 php scripts/tests/manual_operations_http_test.php
 *
 * The screen loads at the bottom are not padding. M6 shipped an orders filter
 * that answered 500 to every search because it bound one named placeholder
 * twice, and M7's first attempt shipped a conversion that threw for the same
 * reason. Both were invisible to unit tests and both would have been a single
 * red line here. Every screen in this change carries a search, so every one of
 * them is requested with a real term.
 *
 * Everything this file creates, it removes in the finally block.
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

function moh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function moh_eq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    moh_ok($ok, $label . ($ok ? '' : " (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")"));
}

function moh_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $headers = $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
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

function moh_csrf(string $jar, string $url): string
{
    [, $body] = moh_req($jar, $url, null, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

function moh_login(string $jar, string $base, string $page, string $email, string $password, string $after): string
{
    $token = moh_csrf($jar, $base . $page);
    [$code] = moh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token]);
    moh_eq(200, $code, "$email signs in");
    return moh_csrf($jar, $base . $after);
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'manual-http-777';
$users    = [];
$madeUsers = [];
$orderIds = [];
$requestIds = [];
$zoneIds  = [];
$jars = [
    tempnam(sys_get_temp_dir(), 'okv-moh-m-'),
    tempnam(sys_get_temp_dir(), 'okv-moh-c-'),
    tempnam(sys_get_temp_dir(), 'okv-moh-g-'),
];

try {
    foreach ([['staff', 'Manager'], ['household', 'Caller']] as $index => [$type, $last]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'Manual\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':last'  => $last,
                ':email' => "mo-http-$index-$suffix@example.test",
                ':phone' => '+23477' . random_int(10000000, 99999999),
                ':hash'  => password_hash($password, PASSWORD_BCRYPT),
                ':type'  => $type,
            ]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users[0]]);
    Database::run('DELETE FROM rate_limits');

    $zoneId  = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $product = Database::one('SELECT id FROM products WHERE is_active = 1 AND current_price_subunit > 0 ORDER BY id LIMIT 1');
    $unitId  = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];
    $date    = (string) Delivery::nextEligibleDates('household', 5)[0]['date'];

    // --- 1. The gates that run before any action does ------------------------
    // Every new action, not one of them. A gate proved on one door says the gate
    // exists, not that it is on every door, and a later action added without one
    // would still pass that test.
    $writes = [
        ['/api/v1/orders.php',       'create'],
        ['/api/v1/customers.php',    'create'],
        ['/api/v1/kitchen_runs.php', 'staff_submit'],
        ['/api/v1/delivery.php',     'create_zone'],
        ['/api/v1/delivery.php',     'update_zone'],
    ];

    foreach ($writes as [$endpoint, $action]) {
        [$code, $body] = moh_req($jars[2], $base . $endpoint . '?action=' . $action);
        moh_eq(405, $code, "$action refuses GET, because a write is not a link somebody can be sent");
        moh_ok(!str_contains($body, 'SQLSTATE'), "the $action method refusal leaks no driver message");
    }

    foreach ($writes as [$endpoint, $action]) {
        [$code, $body] = moh_req($jars[2], $base . $endpoint, ['action' => $action]);
        moh_ok(in_array($code, [401, 403, 419], true), "a signed-out caller cannot $action");
        moh_ok(
            !str_contains($body, 'Exception') && !str_contains($body, 'SQLSTATE'),
            "the $action refusal never leaks an exception or a driver message"
        );
    }

    // --- 2. Signed in, and gated by who you are ------------------------------
    $managerCsrf  = moh_login($jars[0], $base, '/admin/login.php', "mo-http-0-$suffix@example.test", $password, '/admin/orders.php');
    $customerCsrf = moh_login($jars[1], $base, '/account.php', "mo-http-1-$suffix@example.test", $password, '/account.php');
    moh_ok($managerCsrf !== '' && $customerCsrf !== '', 'every screen hands out a CSRF token');

    // A customer cannot do a staff job, whatever they post. A real session and a
    // real token, so what refuses them is the permission and nothing else.
    foreach ($writes as [$endpoint, $action]) {
        [$code, $body] = moh_req($jars[1], $base . $endpoint, [
            'action' => $action, 'okv_csrf' => $customerCsrf,
            'user_id' => $users[1], 'payment_option' => 'pay_in_full',
            'delivery_date' => $date, 'delivery_zone_id' => $zoneId, 'zone_id' => $zoneId,
            'name' => 'Customer Zone', 'first_name' => 'Sneaky', 'last_name' => 'Person',
            'phone' => '08099999999',
            'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ]);
        moh_eq(403, $code, "a customer cannot $action from the storefront");
        moh_ok(!str_contains($body, 'SQLSTATE'), "the refusal to $action tells the caller nothing about the database");
    }
    moh_eq(
        0,
        (int) Database::one('SELECT COUNT(*) AS n FROM delivery_zones WHERE name = :n', [':n' => 'Customer Zone'])['n'],
        'and a refused zone write wrote nothing'
    );

    // --- 3. A manager does the job over the real route -----------------------
    // The customer picker first, because everything else needs somebody to be for.
    [$code, $body] = moh_req($jars[0], $base . '/api/v1/customers.php?action=search&q=Manual');
    moh_eq(200, $code, 'a manager searches customers');
    $found = json_decode($body, true) ?: [];
    moh_ok(isset($found['customers']) && is_array($found['customers']), 'the search answers with a list, whatever it found');
    moh_ok(
        !in_array($users[0], array_map(static fn(array $c): int => (int) $c['id'], $found['customers']), true),
        'and never offers a staff account as a customer to sell to'
    );

    [$code, $body] = moh_req($jars[0], $base . '/api/v1/customers.php', [
        'action' => 'create', 'okv_csrf' => $managerCsrf,
        'first_name' => 'Phone', 'last_name' => 'Caller' . $suffix,
        'phone' => '0806' . random_int(1000000, 9999999), 'email' => '',
    ]);
    moh_eq(201, $code, 'a manager makes the account a first-time caller needs');
    $newCustomer = json_decode($body, true)['customer'] ?? [];
    $madeUsers[] = (int) ($newCustomer['id'] ?? 0);
    moh_ok((int) ($newCustomer['id'] ?? 0) > 0, 'and gets the customer back to carry on with');
    moh_eq('', (string) ($newCustomer['email'] ?? 'x'), 'a placeholder address is never shown back as if it were a real one');

    // The order itself.
    [$code, $body] = moh_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'create', 'okv_csrf' => $managerCsrf,
        'user_id' => $madeUsers[0], 'payment_option' => 'pay_on_delivery', 'channel' => 'phone',
        'delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'recipient_name' => 'Phone Caller', 'recipient_phone' => '08031234567',
        'address_line_1' => '9 Awolowo Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'lines' => [
            ['item' => 'product:' . (int) $product['id'], 'quantity' => '2', 'unit_price' => ''],
            ['item' => 'custom', 'item_name' => 'Pomo', 'unit_name' => 'kg', 'quantity' => '3', 'unit_price' => '4,000'],
        ],
    ]);
    moh_eq(200, $code, 'a manager takes an order over the real route');
    $order = json_decode($body, true) ?: [];
    moh_ok((int) ($order['id'] ?? 0) > 0, 'and the order exists');
    if (!empty($order['id'])) {
        $orderIds[] = (int) $order['id'];
    }
    moh_ok(!isset($order['trail_token']) || (string) $order['trail_token'] !== '', 'the order comes back with what the screen needs');

    // Pay on delivery without an activated account is the whole point of the
    // screen: a colleague has spoken to the caller, which is the check the
    // storefront rule stands in for.
    $placed = Database::one('SELECT payment_option, order_status FROM orders WHERE id = :id', [':id' => (int) ($order['id'] ?? 0)]);
    moh_eq('pay_on_delivery', (string) ($placed['payment_option'] ?? ''), 'a colleague may take a pay-on-delivery order from an unactivated caller');

    // Naira typed into the form becomes kobo exactly once.
    $customLine = Database::one(
        'SELECT unit_price_subunit FROM order_items WHERE order_id = :id AND sku = :sku',
        [':id' => (int) ($order['id'] ?? 0), ':sku' => ManualOrder::CUSTOM_SKU]
    );
    moh_eq(400000, (int) ($customLine['unit_price_subunit'] ?? 0), 'naira with a comma become kobo exactly once, at the controller');

    // A refusal reaches the colleague as a sentence, never as an exception.
    [$code, $body] = moh_req($jars[0], $base . '/api/v1/orders.php', [
        'action' => 'create', 'okv_csrf' => $managerCsrf,
        'user_id' => $madeUsers[0], 'payment_option' => 'pay_in_full',
        'delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'recipient_name' => 'Phone Caller', 'recipient_phone' => '08031234567',
        'address_line_1' => '9 Awolowo Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'lines' => [],
    ]);
    moh_eq(422, $code, 'an order with no lines is refused');
    $refusal = json_decode($body, true) ?: [];
    moh_eq('no_lines', (string) ($refusal['code'] ?? ''), 'with the code the screen turns into words');
    moh_ok(
        !str_contains((string) ($refusal['message'] ?? ''), '_') && !str_contains($body, 'Exception'),
        'and the words are a sentence, never a code and never an exception'
    );

    // The typed-in kitchen list.
    [$code, $body] = moh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'staff_submit', 'okv_csrf' => $managerCsrf,
        'user_id' => $madeUsers[0], 'arrived_by' => 'whatsapp', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'recipient_name' => 'Phone Caller', 'recipient_phone' => '08031234567',
        'address_line_1' => '9 Awolowo Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'items' => [['item_name' => 'Pomo', 'quantity' => '6', 'unit_id' => $unitId]],
    ]);
    moh_eq(200, $code, 'a manager types in a list that arrived on WhatsApp');
    $run = json_decode($body, true) ?: [];
    if (!empty($run['id'])) {
        $requestIds[] = (int) $run['id'];
    }
    moh_ok((string) ($run['request_number'] ?? '') !== '', 'and it gets a request number the colleague can quote');
    $runRow = Database::one('SELECT status, created_by FROM kitchen_run_requests WHERE id = :id', [':id' => (int) ($run['id'] ?? 0)]);
    moh_eq('submitted', (string) ($runRow['status'] ?? ''), 'and it lands waiting for a price, exactly like a list a customer sends');
    moh_eq($users[0], (int) ($runRow['created_by'] ?? 0), 'with the colleague who typed it in on the record');

    // A list with nobody to belong to is refused, and the words say so.
    [$code, $body] = moh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'staff_submit', 'okv_csrf' => $managerCsrf, 'user_id' => 0,
        'items' => [['item_name' => 'Pomo', 'quantity' => '6', 'unit_id' => $unitId]],
    ]);
    moh_ok($code >= 400 && $code < 500, 'a list with no customer on it is refused');
    moh_ok(
        !str_contains((string) (json_decode($body, true)['message'] ?? ''), 'Sign in'),
        'and a signed-in colleague is never told to sign in'
    );

    // Zones.
    $zoneName = 'Test Zone ' . $suffix;
    [$code] = moh_req($jars[0], $base . '/api/v1/delivery.php', [
        'action' => 'create_zone', 'okv_csrf' => $managerCsrf,
        'name' => $zoneName, 'area_note' => 'Somewhere on the island', 'is_active' => '1',
    ]);
    moh_eq(200, $code, 'a manager adds a delivery zone');
    $zone = Database::one('SELECT id, slug, is_active FROM delivery_zones WHERE name = :n', [':n' => $zoneName]);
    moh_ok($zone !== null, 'and it is on the table');
    if ($zone) {
        $zoneIds[] = (int) $zone['id'];
        moh_eq(1, (int) $zone['is_active'], 'active, so it reaches the checkout picker');
        moh_ok((string) $zone['slug'] !== '', 'with a slug of its own');
    }

    [$code] = moh_req($jars[0], $base . '/api/v1/delivery.php', [
        'action' => 'create_zone', 'okv_csrf' => $managerCsrf, 'name' => $zoneName,
    ]);
    moh_eq(409, $code, 'the same zone name twice is refused rather than made twice');

    [$code] = moh_req($jars[0], $base . '/api/v1/delivery.php', [
        'action' => 'create_zone', 'okv_csrf' => $managerCsrf, 'name' => '   ',
    ]);
    moh_eq(422, $code, 'a zone with no name is refused');

    if ($zone) {
        [$code] = moh_req($jars[0], $base . '/api/v1/delivery.php', [
            'action' => 'update_zone', 'okv_csrf' => $managerCsrf, 'zone_id' => (int) $zone['id'],
            'name' => $zoneName . ' Renamed', 'area_note' => 'Renamed on the phone', 'sort_order' => '7',
        ]);
        moh_eq(200, $code, 'a manager renames a zone');
        $renamed = Database::one('SELECT name, slug, sort_order, is_active FROM delivery_zones WHERE id = :id', [':id' => (int) $zone['id']]);
        moh_eq($zoneName . ' Renamed', (string) $renamed['name'], 'and the new name is on the row');
        moh_ok((string) $renamed['slug'] !== (string) $zone['slug'], 'the slug follows a real rename');
        moh_eq(7, (int) $renamed['sort_order'], 'and where it sits in the list is a colleague\'s to set');
        moh_eq(0, (int) $renamed['is_active'], 'an unticked box switches a zone off, which is what takes it out of the picker');
    }

    [$code] = moh_req($jars[0], $base . '/api/v1/delivery.php', [
        'action' => 'update_zone', 'okv_csrf' => $managerCsrf, 'zone_id' => 999999999, 'name' => 'Nowhere',
    ]);
    moh_eq(422, $code, 'a zone that does not exist is refused');

    // --- 4. The screens load, with a search on them --------------------------
    // Every one of these carries a query that binds several placeholders. A
    // 500 here is the defect that shipped twice before.
    $screens = [
        ['/admin/order_new.php', 'the phone order screen'],
        ['/admin/order_new.php?customer_q=Manual', 'the phone order screen with a customer search'],
        ['/admin/order_new.php?user_id=' . $madeUsers[0], 'the phone order screen with a customer chosen'],
        ['/admin/kitchen_run_new.php', 'the typed-in list screen'],
        ['/admin/kitchen_run_new.php?customer_q=0806', 'the typed-in list screen searching by phone number'],
        ['/admin/kitchen_run_new.php?user_id=' . $madeUsers[0], 'the typed-in list screen with a customer chosen'],
        ['/admin/payments.php', 'the payments screen'],
        ['/admin/payments.php?q=Manual', 'the payments screen searching by customer name'],
        ['/admin/payments.php?q=OKV', 'the payments screen searching by order number'],
        ['/admin/payments.php?q=0803', 'the payments screen searching by phone number'],
        ['/admin/delivery.php', 'the delivery screen with its zone editor'],
        ['/admin/orders.php', 'the orders screen'],
        ['/admin/kitchen_runs.php', 'the kitchen run queue'],
    ];

    foreach ($screens as [$path, $label]) {
        [$code, $body] = moh_req($jars[0], $base . $path, null, false);
        moh_eq(200, $code, "$label loads");
        moh_ok(!str_contains($body, 'SQLSTATE') && !str_contains($body, 'Fatal error'), "$label leaks no database or PHP error");
    }

    // A colleague with no permission for a screen is sent to sign in rather than
    // shown it. The customer session is the nearest thing to a stranger who is
    // signed into something.
    foreach (['/admin/order_new.php', '/admin/kitchen_run_new.php'] as $path) {
        [$code] = moh_req($jars[1], $base . $path, null, false);
        moh_ok($code !== 200, 'a customer cannot open ' . $path);
    }
} finally {
    foreach ($requestIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
    }
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM manual_payment_proofs WHERE payment_transaction_id IN (SELECT t.id FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($zoneIds as $id) {
        Database::run('DELETE FROM delivery_zones WHERE id = :id', [':id' => $id]);
    }
    foreach (array_merge($madeUsers, $users) as $id) {
        if ($id < 1) {
            continue;
        }
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM business_customers WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id OR entity_id = :entity', [':id' => $id, ':entity' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($jars as $jar) {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\n$passed / $tests manual operations HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
