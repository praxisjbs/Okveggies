<?php
/** M11 dashboard rendering, RBAC and deep-link checks over real HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8225';
$tests = 0;
$passed = 0;

function adh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function adh_eq($expected, $actual, string $label): void
{
    adh_ok($expected === $actual, $label . ($expected === $actual
        ? ''
        : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

function adh_request(string $base, string $jar, string $path): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function adh_login(string $base, string $jar, string $email, string $password): void
{
    [, $login] = adh_request($base, $jar, '/admin/login.php');
    $csrf = preg_match('/name="okv_csrf" value="([^"]+)"/', $login, $matches) ? (string) $matches[1] : '';
    $ch = curl_init($base . '/api/v1/auth.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'action' => 'login',
            'identifier' => $email,
            'password' => $password,
            'okv_csrf' => $csrf,
        ]),
        CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    adh_eq(200, $status, $email . ' signs in');
}

$serverLog = sys_get_temp_dir() . '/okv-admin-dashboard-http-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8225 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the dashboard test server.\n");
    exit(2);
}
$up = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8225, $errno, $error, 0.2);
    if ($socket) {
        fclose($socket);
        $up = true;
        break;
    }
    usleep(100000);
}
if (!$up) {
    proc_terminate($server);
    fwrite(STDERR, "The dashboard test server did not start.\n");
    exit(2);
}

$suffix = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
$password = 'dashboard-http-88';
$roles = [];
$users = [];
$jars = [];
$orders = [];
$paymentIds = [];
$transactionIds = [];
$orderItemIds = [];

try {
    $rolePermissions = [
        'full' => ['dashboard.view', 'dashboard.analytics.view', 'orders.view', 'payments.view', 'credit.view'],
        'orders' => ['dashboard.view', 'dashboard.analytics.view', 'orders.view'],
        'payments' => ['dashboard.view', 'dashboard.analytics.view', 'payments.view'],
        'orders_no_analytics' => ['dashboard.view', 'orders.view'],
        'analytics_only' => ['dashboard.view', 'dashboard.analytics.view'],
        'dashboard' => ['dashboard.view'],
        'users' => ['dashboard.view', 'users.view'],
        'blocked' => ['orders.view'],
    ];

    foreach ($rolePermissions as $key => $permissions) {
        Database::run(
            'INSERT INTO roles (name, description) VALUES (:name, :description)',
            [':name' => 'm11_' . $key . '_' . strtolower($suffix), ':description' => 'M11 dashboard HTTP fixture']
        );
        $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $roles[] = $roleId;
        foreach ($permissions as $permission) {
            Database::run(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :role, id FROM permissions WHERE `key` = :permission',
                [':role' => $roleId, ':permission' => $permission]
            );
        }

        $email = 'm11-' . $key . '-' . strtolower($suffix) . '@example.test';
        Database::run(
            'INSERT INTO users
                (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :password_hash, :user_type, :status)',
            [
                ':first' => ucfirst($key), ':last' => 'Dashboard', ':email' => $email,
                ':phone' => '+23470' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                ':user_type' => 'staff', ':status' => 'active',
            ]
        );
        $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $users[$key] = ['id' => $userId, 'email' => $email];
        Database::run(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
            [':user' => $userId, ':role' => $roleId]
        );
        $jars[$key] = tempnam(sys_get_temp_dir(), 'okv-m11-dashboard-');
    }

    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    foreach ([
        ['TODAY', $today . ' 08:00:00', 777700],
        ['YESTERDAY', date('Y-m-d', strtotime('-1 day')) . ' 08:00:00', 222200],
    ] as [$tag, $createdAt, $total]) {
        Database::run(
            'INSERT INTO orders
                (order_number, customer_type, order_status, payment_option, payment_status,
                 subtotal_subunit, order_total_subunit, balance_due_subunit,
                 preferred_delivery_date, created_at)
             VALUES (:number, :customer_type, :order_status, :payment_option, :payment_status,
                     :subtotal, :total, :balance, :delivery_date, :created_at)',
            [
                ':number' => 'M11-' . $tag . '-' . $suffix,
                ':customer_type' => 'household', ':order_status' => 'pending',
                ':payment_option' => 'pay_in_full', ':payment_status' => 'unpaid',
                ':subtotal' => $total, ':total' => $total, ':balance' => $total,
                ':delivery_date' => date('Y-m-d', strtotime('+2 days')), ':created_at' => $createdAt,
            ]
        );
        $orders[$tag] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    foreach ([
        [$orders['TODAY'], 'DUE', 1234500, $today . ' 18:00:00'],
        [$orders['YESTERDAY'], 'FUTURE', 222200, $tomorrow . ' 00:00:00'],
    ] as [$orderId, $tag, $amount, $dueAt]) {
        Database::run(
            'INSERT INTO payments
                (payment_number, order_id, provider, payment_type, expected_amount_subunit,
                 paid_amount_subunit, currency, status, due_at)
             VALUES (:number, :order_id, :provider, :payment_type, :expected,
                     :paid, :currency, :status, :due_at)',
            [
                ':number' => 'M11-PAY-' . $tag . '-' . $suffix, ':order_id' => $orderId,
                ':provider' => 'paystack', ':payment_type' => 'full_' . strtolower($tag),
                ':expected' => $amount, ':paid' => 0, ':currency' => Money::CODE,
                ':status' => Payments::STATUS_UNPAID, ':due_at' => $dueAt,
            ]
        );
        $paymentIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity,
             unit_price_subunit, line_total_subunit, created_at)
         VALUES (:order_id, :item_type, :item_name, :sku, :unit_name, :quantity,
                 :unit_price, :line_total, :created_at)',
        [
            ':order_id' => $orders['TODAY'], ':item_type' => 'combo',
            ':item_name' => 'M11 HTTP Harvest Basket ' . $suffix, ':sku' => 'M11-COMBO',
            ':unit_name' => 'basket', ':quantity' => '1.000', ':unit_price' => 777700,
            ':line_total' => 777700, ':created_at' => $today . ' 08:00:00',
        ]
    );
    $orderItemIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, provider, reference, domain, status, requested_amount_subunit,
             amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :provider, :reference, :domain, :status, :requested,
                 :amount, :currency, :email, :paid_at)',
        [
            ':payment_id' => $paymentIds[0], ':provider' => 'manual',
            ':reference' => 'M11-HTTP-TXN-' . $suffix, ':domain' => 'test', ':status' => 'success',
            ':requested' => 777700, ':amount' => 777700, ':currency' => Money::CODE,
            ':email' => 'm11-http-' . strtolower($suffix) . '@example.test',
            ':paid_at' => $today . ' 09:00:00',
        ]
    );
    $transactionIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    $guestJar = tempnam(sys_get_temp_dir(), 'okv-m11-guest-');
    $jars['guest'] = $guestJar;
    [$status] = adh_request($base, $guestJar, '/admin/');
    adh_eq(302, $status, 'a guest is redirected from the dashboard');

    foreach ($users as $key => $user) {
        adh_login($base, $jars[$key], $user['email'], $password);
    }

    [$status, $full] = adh_request($base, $jars['full'], '/admin/');
    adh_eq(200, $status, 'a fully permitted role opens the dashboard');
    $fullText = html_entity_decode($full, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (["Today's orders", "Today's revenue", 'Payments due', 'Credit outstanding'] as $label) {
        adh_ok(str_contains($fullText, $label), 'the full dashboard shows ' . $label);
    }
    adh_ok(str_contains($full, 'filter_created=' . $today), 'today orders links to the exact placed-date filter');
    adh_ok(str_contains($full, 'due=attention#payments-due'), 'payments due links to its attention view');
    adh_ok(str_contains($full, Money::format(1234500)), 'the due card formats its amount through Money');
    foreach (['Sales over time', 'Top products', 'Order share by category'] as $label) {
        adh_ok(str_contains($fullText, $label), 'the full dashboard shows ' . $label);
    }
    adh_ok(str_contains($full, 'id="okv-dashboard-data"'), 'a permitted non-empty chart response carries JSON data');
    adh_ok(str_contains($full, '/assets/js/admin-dashboard'), 'a permitted non-empty chart response loads the chart enhancer');
    adh_ok(str_contains($full, 'M11 HTTP Harvest Basket ' . $suffix), 'top products use the immutable order-item name');
    adh_ok(str_contains($full, 'View exact sales figures') && str_contains($full, 'View exact product figures')
        && str_contains($full, 'View exact category figures'), 'every chart has a keyboard-accessible exact table');
    adh_ok(str_contains($full, 'aria-label="Reporting period"') && str_contains($full, 'aria-current="page"'), 'the shared period control names and marks its selection');
    adh_ok(str_contains($full, 'id="okv-command-palette"')
        && str_contains($full, 'role="dialog"')
        && str_contains($full, 'aria-modal="true"'), 'the shared shell renders a named modal command palette');
    adh_ok(str_contains($full, 'aria-labelledby="okv-command-title"')
        && str_contains($full, 'aria-describedby="okv-command-description"'), 'the command dialog has an accessible name and description');
    adh_ok(str_contains($full, 'data-command-open') && str_contains($full, 'Ctrl/⌘ K'), 'the top bar exposes the palette and its shortcut');
    adh_eq(4, substr_count($full, 'data-command-item'), 'the full fixture receives only its 4 permitted commands');
    foreach (['/admin/', '/admin/orders.php', '/admin/payments.php', '/admin/credit.php'] as $commandUrl) {
        adh_ok(str_contains($full, 'href="' . $commandUrl . '"'), 'the command source includes permitted URL ' . $commandUrl);
    }
    adh_ok(!str_contains($full, 'href="/admin/users.php"'), 'a role without users.view receives no Users command or sidebar URL');
    adh_ok(str_contains($full, 'data-command-empty hidden>No permitted page matches that search.'), 'the palette carries a plain no-match state');
    adh_ok(str_contains($full, '/assets/js/admin-command-palette'), 'every admin response loads the shared command module');

    [, $sevenDays] = adh_request($base, $jars['full'], '/admin/?period=7');
    adh_ok(preg_match('/aria-current="page"[^>]*>\s*7 days\s*<\/a>/', $sevenDays) === 1, 'the 7 day GET preset is selected');
    [, $invalidPeriod] = adh_request($base, $jars['full'], '/admin/?period=not-a-period');
    adh_ok(preg_match('/aria-current="page"[^>]*>\s*30 days\s*<\/a>/', $invalidPeriod) === 1, 'an invalid period safely falls back to 30 days');

    [, $ordersOnly] = adh_request($base, $jars['orders'], '/admin/');
    $ordersOnlyText = html_entity_decode($ordersOnly, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    adh_ok(str_contains($ordersOnlyText, "Today's orders"), 'an orders role sees the order metric');
    adh_ok(!str_contains($ordersOnlyText, "Today's revenue"), 'an orders role receives no revenue card');
    adh_ok(!str_contains($ordersOnly, 'Payments due'), 'an orders role receives no due-payment card');
    adh_ok(!str_contains($ordersOnly, 'Credit outstanding'), 'an orders role receives no credit card');
    adh_ok(!str_contains($ordersOnly, Money::format(1234500)), 'a payment amount is absent from orders-only HTML');
    adh_ok(str_contains($ordersOnlyText, 'Top products') && str_contains($ordersOnlyText, 'Order share by category'), 'an analytics and orders role sees both order charts');
    adh_ok(!str_contains($ordersOnly, 'Sales over time') && !str_contains($ordersOnly, 'sales_over_time'), 'an orders-only response contains no sales chart or sales payload');
    adh_eq(2, substr_count($ordersOnly, 'data-command-item'), 'an Orders role receives only Dashboard and Orders commands');
    adh_ok(str_contains($ordersOnly, 'data-command-search-text="Orders Selling order basket checkout sales"'), 'approved Orders keywords are rendered only with its permitted command');
    adh_ok(!str_contains($ordersOnly, 'href="/admin/payments.php"')
        && !str_contains($ordersOnly, 'href="/admin/users.php"'), 'forbidden command URLs are absent from Orders-role source');

    [, $paymentsOnly] = adh_request($base, $jars['payments'], '/admin/');
    $paymentsOnlyText = html_entity_decode($paymentsOnly, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    adh_ok(!str_contains($paymentsOnlyText, "Today's orders"), 'a payments role receives no order metric');
    adh_ok(str_contains($paymentsOnlyText, "Today's revenue") && str_contains($paymentsOnlyText, 'Payments due'), 'a payments role sees both payment metrics');
    adh_ok(!str_contains($paymentsOnly, 'Credit outstanding'), 'a payments role receives no credit metric');
    adh_ok(str_contains($paymentsOnlyText, 'Sales over time'), 'an analytics and payments role sees the sales chart');
    adh_ok(!str_contains($paymentsOnly, 'Top products') && !str_contains($paymentsOnly, 'top_products')
        && !str_contains($paymentsOnly, 'Order share by category'), 'a payments-only response contains no order chart data');
    adh_eq(2, substr_count($paymentsOnly, 'data-command-item'), 'a Payments role receives only Dashboard and Payments commands');
    adh_ok(str_contains($paymentsOnly, 'payment pay paystack transactions refunds reconciliation'), 'searching pay can match the permitted Payments command');

    [, $ordersNoAnalytics] = adh_request($base, $jars['orders_no_analytics'], '/admin/');
    $ordersNoAnalyticsText = html_entity_decode($ordersNoAnalytics, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    adh_ok(str_contains($ordersNoAnalyticsText, "Today's orders"), 'orders permission still shows its operational card without analytics permission');
    adh_ok(!str_contains($ordersNoAnalytics, 'How the shop is moving')
        && !str_contains($ordersNoAnalytics, 'okv-dashboard-data')
        && !str_contains($ordersNoAnalytics, 'admin-dashboard'), 'missing dashboard.analytics.view removes chart HTML, JSON and JavaScript');

    [, $analyticsOnly] = adh_request($base, $jars['analytics_only'], '/admin/');
    adh_ok(!str_contains($analyticsOnly, 'How the shop is moving')
        && !str_contains($analyticsOnly, 'okv-dashboard-data'), 'analytics permission without a domain permission reveals no chart region or data');

    [, $dashboardOnly] = adh_request($base, $jars['dashboard'], '/admin/');
    $dashboardOnlyText = html_entity_decode($dashboardOnly, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    adh_ok(str_contains($dashboardOnlyText, "Your role does not include today's order or money figures."), 'dashboard-only staff get a useful permission empty state');
    adh_ok(!str_contains($dashboardOnlyText, "Today's orders") && !str_contains($dashboardOnlyText, "Today's revenue"), 'dashboard-only HTML contains no operational cards');
    adh_ok(!str_contains($dashboardOnly, Money::format(1234500)), 'dashboard-only HTML contains no payment amount');
    adh_eq(1, substr_count($dashboardOnly, 'data-command-item'), 'a dashboard-only role receives only the Dashboard command');

    [, $usersOnly] = adh_request($base, $jars['users'], '/admin/');
    adh_eq(2, substr_count($usersOnly, 'data-command-item'), 'a Users role receives only Dashboard and Users commands');
    adh_ok(str_contains($usersOnly, 'href="/admin/users.php"') && str_contains($usersOnly, 'roles staff permissions team'), 'the permitted Users command includes its approved role keywords');
    adh_ok(!str_contains($usersOnly, 'href="/admin/orders.php"'), 'the Users role receives no forbidden Orders URL');

    [$status, $blocked] = adh_request($base, $jars['blocked'], '/admin/');
    adh_eq(403, $status, 'dashboard.view is required even when orders.view is present');
    adh_ok(!str_contains($blocked, 'Orders and money at a glance'), 'the refused response contains no dashboard content');

    [, $filteredOrders] = adh_request($base, $jars['orders'], '/admin/orders.php?filter_created=' . rawurlencode($today));
    adh_ok(str_contains($filteredOrders, 'M11-TODAY-' . $suffix), 'the created-date filter includes an order placed that day');
    adh_ok(!str_contains($filteredOrders, 'M11-YESTERDAY-' . $suffix), 'the created-date filter excludes an earlier order');
    adh_ok(str_contains($filteredOrders, 'name="filter_created" value="' . $today . '"'), 'the Orders form preserves its placed-date filter');
    adh_ok(str_contains($filteredOrders, 'id="okv-command-palette"')
        && str_contains($filteredOrders, '/assets/js/admin-command-palette'), 'the shared palette is present beyond the dashboard page');

    [, $duePage] = adh_request($base, $jars['payments'], '/admin/payments.php?due=attention#payments-due');
    adh_ok(str_contains($duePage, 'M11-TODAY-' . $suffix), 'the due-attention view lists a payment due today');
    adh_ok(!str_contains($duePage, 'M11-YESTERDAY-' . $suffix), 'the due-attention view excludes a future payment');
    adh_ok(str_contains($duePage, Money::format(1234500)), 'the due-attention row shows the same formatted balance');
    adh_ok(str_contains($duePage, 'Due today'), 'the due-attention row names its time state');
} finally {
    foreach ($transactionIds as $transactionId) {
        Database::run('DELETE FROM payment_transactions WHERE id = :id', [':id' => $transactionId]);
    }
    foreach ($orderItemIds as $orderItemId) {
        Database::run('DELETE FROM order_items WHERE id = :id', [':id' => $orderItemId]);
    }
    foreach ($paymentIds as $paymentId) {
        Database::run('DELETE FROM payments WHERE id = :id', [':id' => $paymentId]);
    }
    foreach ($orders as $orderId) {
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    foreach ($users as $user) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $user['id']]);
    }
    foreach ($roles as $roleId) {
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
    foreach ($jars as $jar) {
        if (is_string($jar) && is_file($jar)) {
            unlink($jar);
        }
    }
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests admin dashboard HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
