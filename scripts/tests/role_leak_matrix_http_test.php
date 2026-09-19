<?php
/**
 * scripts/tests/role_leak_matrix_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The restricted-role leak matrix.
 *
 * Five narrow staff roles, each holding exactly one view permission:
 *
 *   orders.view    payments.view    credit.view    content.view    messages.view
 *
 * Each one is signed in on a real session over real HTTP and taken to all five
 * admin screens. The suite asserts two things every time:
 *
 *   1. a screen it has no permission for is refused outright, with no partial
 *      render and no data in the refusal; and
 *   2. the screen it is allowed to open carries no other module's records, not
 *      in the HTML, not in an inline script tag, and not in a JSON body.
 *
 * The bar the Owner set is the one this file checks: a role that can see orders
 * receives no payment or credit data, and a guest cannot read a customer's
 * order by guessing its id.
 *
 * A note on one shared value: an order number legitimately appears on the
 * payments and credit screens, because a payment and a credit charge both hang
 * off an order. The matrix allows that one reference only, and nothing else.
 *
 * Exit codes: 0 all assertions passed, 1 a failure, 2 the suite could not run.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8232';
$tests = 0;
$passed = 0;

function rlm_ok(bool $condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
    } else {
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function rlm_eq($expected, $actual, string $label): void
{
    rlm_ok(
        $expected === $actual,
        $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

/** One request, with a cookie jar so the session is the real session. */
function rlm_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function rlm_token(string $html): string
{
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : '';
}

/** Sign in through the real form endpoint and return the session's CSRF token. */
function rlm_login(string $base, string $jar, string $email, string $password): string
{
    [, $html] = rlm_req($base, $jar, 'GET', '/admin/login.php');
    $csrf = rlm_token($html);
    [$status] = rlm_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'identifier' => $email, 'password' => $password,
        'context' => 'admin', 'okv_csrf' => $csrf,
    ], true);
    rlm_eq(200, $status, $email . ' signs in');
    return $csrf;
}

/** The content of every inline script tag, which is where data leaks quietly. */
function rlm_inline_js(string $html): string
{
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $matches) === false) {
        return '';
    }
    return implode("\n", $matches[1] ?? []);
}

$serverLog = sys_get_temp_dir() . '/okv-role-leak-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8232 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the role leak matrix server.\n");
    exit(2);
}
$listening = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8232, $errno, $error, 0.2);
    if ($socket) {
        fclose($socket);
        $listening = true;
        break;
    }
    usleep(100000);
}
// Say why, rather than letting every assertion fail against a dead port. A port
// already in use reads exactly like a broken suite otherwise.
if (!$listening) {
    fwrite(STDERR, "Could not reach the role leak matrix server on 127.0.0.1:8232 after 5 seconds.\n");
    fwrite(STDERR, "Is something else holding that port? Server log: $serverLog\n");
    proc_terminate($server);
    proc_close($server);
    exit(2);
}

$suffix   = bin2hex(random_bytes(5));
$password = 'leak-matrix-77';

$orderNumber   = 'ZZ-LEAK-ORD-' . $suffix;
$paymentNumber = 'ZZ-LEAK-PAY-' . $suffix;
$reference     = 'ZZ-LEAK-TXN-' . $suffix;
$businessName  = 'Leak Business ' . $suffix;
$draftTitle    = 'Leak draft ' . $suffix;
$messageSubject = 'LEAK MESSAGE ' . $suffix;

$users        = [];
$roles        = [];
$jars         = [];
$roleUserIds  = [];
$customerId   = 0;
$businessId   = 0;
$orderId      = 0;
$applicationId = 0;
$paymentId    = 0;
$transactionId = 0;
$messageId    = 0;
$beforeDraft  = null;

$profiles = [
    'orders'   => 'orders.view',
    'payments' => 'payments.view',
    'credit'   => 'credit.view',
    'content'  => 'content.view',
    'messages' => 'messages.view',
];

$pages = [
    'orders'   => ['url' => '/admin/orders.php?filter_customer=' . rawurlencode($orderNumber),        'marker' => $orderNumber],
    'payments' => ['url' => '/admin/payments.php?q=' . rawurlencode($reference),                      'marker' => $paymentNumber],
    'credit'   => ['url' => '/admin/credit.php?search=' . rawurlencode($businessName),                'marker' => $businessName],
    'content'  => ['url' => '/admin/content.php?tab=page-copy&page=about',                            'marker' => $draftTitle],
    'messages' => ['url' => '/admin/content.php',                                                     'marker' => $messageSubject],
];

// Which page a one-permission role should be able to open, and what everything
// else must answer. A content-only account that lands on /admin/content.php is
// sent to its own tab rather than refused, so that one is a 200.
$expected = [
    'orders'   => ['orders' => 200, 'payments' => 403, 'credit' => 403, 'content' => 403, 'messages' => 403],
    'payments' => ['orders' => 403, 'payments' => 200, 'credit' => 403, 'content' => 403, 'messages' => 403],
    'credit'   => ['orders' => 403, 'payments' => 403, 'credit' => 200, 'content' => 403, 'messages' => 403],
    'content'  => ['orders' => 403, 'payments' => 403, 'credit' => 403, 'content' => 200, 'messages' => 200],
    'messages' => ['orders' => 403, 'payments' => 403, 'credit' => 403, 'content' => 403, 'messages' => 200],
];

// The only markers a role may ever receive: its own record, plus the order
// reference that the money screens share by design.
$allowed = [
    'orders'   => [$orderNumber],
    'payments' => [$paymentNumber, $orderNumber],
    'credit'   => [$businessName, $orderNumber],
    'content'  => [$draftTitle],
    'messages' => [$messageSubject],
];

$allMarkers = [
    'an order'          => $orderNumber,
    'a payment'         => $paymentNumber,
    'a credit facility' => $businessName,
    'a content draft'   => $draftTitle,
    'a customer message' => $messageSubject,
];

try {
    // ---- Fixtures -----------------------------------------------------------
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) '
        . 'VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Leak', ':last' => 'Customer', ':email' => "leak-customer-$suffix@example.test",
         ':phone' => '+23470' . random_int(10000000, 99999999),
         ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'household', ':status' => 'active']
    );
    $customerId = (int) Database::getInstance()->getConnection()->lastInsertId();

    foreach ($profiles as $name => $permission) {
        $email = "leak-$name-$suffix@example.test";
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) '
            . 'VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [':first' => ucfirst($name), ':last' => 'Leak', ':email' => $email,
             ':phone' => '+23471' . random_int(10000000, 99999999),
             ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'staff', ':status' => 'active']
        );
        $roleUserIds[$name] = (int) Database::getInstance()->getConnection()->lastInsertId();
        $users[$name] = ['id' => $roleUserIds[$name], 'email' => $email];

        Database::run(
            'INSERT INTO roles (name, description) VALUES (:name, :description)',
            [':name' => 'm13_' . $name . '_' . $suffix, ':description' => 'M13 leak matrix test role']
        );
        $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $roles[$name] = $roleId;
        Database::run(
            'INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission',
            [':role' => $roleId, ':permission' => $permission]
        );
        Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $roleUserIds[$name], ':role' => $roleId]);
        $jars[$name] = tempnam(sys_get_temp_dir(), 'okv-leak-' . $name . '-');
    }
    $jars['guest'] = tempnam(sys_get_temp_dir(), 'okv-leak-guest-');

    // The order, its payment and the transaction that makes both searchable.
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status, '
        . 'subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit, preferred_delivery_date, confirmed_at, source_regions_snapshot) '
        . "VALUES (:number, :user, 'household', 'confirmed', 'pay_in_full', 'paid', 2200000, 2200000, 2200000, 0, :date, NOW(), 'Lagos State')",
        [':number' => $orderNumber, ':user' => $customerId, ':date' => date('Y-m-d', strtotime('+3 days'))]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, currency, status, confirmed_at) '
        . "VALUES (:number, :user, :order, 'manual', 'deposit', 2200000, 2200000, :currency, 'paid', NOW())",
        [':number' => $paymentNumber, ':user' => $customerId, ':order' => $orderId, ':currency' => Money::CODE]
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, amount_subunit, currency, customer_email, paid_at) '
        . "VALUES (:payment, 1, 'manual', :reference, 'test', 'success', 2200000, 2200000, :currency, :email, NOW())",
        [':payment' => $paymentId, ':reference' => $reference, ':currency' => Money::CODE, ':email' => "leak-customer-$suffix@example.test"]
    );
    $transactionId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, business_type, credit_requested, credit_status) '
        . "VALUES (:user, :name, 'Leak Contact', 'restaurant', 1, 'pending')",
        [':user' => $customerId, ':name' => $businessName]
    );
    $businessId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO credit_applications (business_customer_id, requested_days, requested_limit_subunit, reason, status) '
        . "VALUES (:business, 14, 5000000, 'Leak matrix probe', 'pending')",
        [':business' => $businessId]
    );
    $applicationId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO contact_messages (name, email, subject, message, source, status) VALUES (:name, :email, :subject, :message, :source, :status)',
        [':name' => 'Leak Sender', ':email' => "leak-sender-$suffix@example.test", ':subject' => $messageSubject,
         ':message' => 'A message only messages.view should ever receive.', ':source' => 'contact_page', ':status' => 'new']
    );
    $messageId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $beforeDraft = Database::one('SELECT draft_title FROM content_pages WHERE slug = :slug', [':slug' => 'about']);
    Database::run('UPDATE content_pages SET draft_title = :title WHERE slug = :slug', [':title' => $draftTitle, ':slug' => 'about']);

    // ---- The guest ----------------------------------------------------------
    [$status] = rlm_req($base, $jars['guest'], 'GET', '/admin/orders.php');
    rlm_eq(302, $status, 'a guest cannot open the orders screen');

    [$status, $apiBody] = rlm_req($base, $jars['guest'], 'POST', '/api/v1/orders.php', ['action' => 'transition', 'order_id' => $orderId, 'target_status' => 'cancelled'], true);
    rlm_eq(401, $status, 'a guest cannot move an order through the API');
    rlm_ok(!str_contains($apiBody, $orderNumber), 'the unauthenticated JSON carries no order data');

    // The bar example: a guessed id, no session, no token.
    [$status, $guessed] = rlm_req($base, $jars['guest'], 'GET', '/public/order.php?order=' . $orderId);
    rlm_eq(404, $status, 'a guest guessing an order id gets the branded not-found response');
    rlm_ok(!str_contains($guessed, $orderNumber), 'the 404 for a guessed order id contains no order data');
    rlm_ok(!str_contains($guessed, $paymentNumber), 'the 404 for a guessed order id contains no payment data');

    [, $wrongToken] = rlm_req($base, $jars['guest'], 'GET', '/public/order.php?token=' . $suffix);
    rlm_ok(!str_contains($wrongToken, $orderNumber), 'a guessed trail token embeds no order data');

    // ---- The five narrow roles ---------------------------------------------
    foreach ($profiles as $role => $permission) {
        $csrf = rlm_login($base, $jars[$role], $users[$role]['email'], $password);

        foreach ($pages as $module => $page) {
            [$status, $body] = rlm_req($base, $jars[$role], 'GET', $page['url']);

            rlm_eq($expected[$role][$module], $status, "$role role: $module screen answers as expected");

            $leaked = [];
            foreach ($allMarkers as $what => $marker) {
                if (in_array($marker, $allowed[$role], true)) {
                    continue;
                }
                if (str_contains($body, $marker)) {
                    $leaked[] = $what;
                }
            }
            rlm_ok(
                $leaked === [],
                "$role role: $module screen receives no forbidden data" . ($leaked ? ' (received ' . implode(', ', $leaked) . ')' : '')
            );

            if ($module === $role && $status === 200) {
                rlm_ok(str_contains($body, $page['marker']), "$role role: its own $module screen shows its own record");
                $inline = rlm_inline_js($body);
                $scriptLeak = [];
                foreach ($allMarkers as $what => $marker) {
                    if (in_array($marker, $allowed[$role], true)) {
                        continue;
                    }
                    if (str_contains($inline, $marker)) {
                        $scriptLeak[] = $what;
                    }
                }
                rlm_ok($scriptLeak === [], "$role role: its own screen leaks nothing through inline JavaScript");
            }
        }

        [$status] = rlm_req($base, $jars[$role], 'GET', '/admin/settings.php');
        rlm_eq(403, $status, "$role role: settings stay closed");

        // Every write API for the modules this role cannot see.
        $probes = [
            'orders'   => ['/api/v1/orders.php',   ['action' => 'cancel_staff', 'order_id' => $orderId, 'confirmed' => '1']],
            'payments' => ['/api/v1/payments.php', ['action' => 'record_manual', 'order_id' => $orderId]],
            'credit'   => ['/api/v1/credit.php',   ['action' => 'approve', 'application_id' => $applicationId, 'credit_days' => 14, 'credit_limit' => 50000]],
            'content'  => ['/api/v1/content.php',  ['action' => 'save_draft', 'slug' => 'about', 'title' => 'Leak write ' . $suffix]],
        ];
        foreach ($probes as $module => $probe) {
            [$path, $fields] = $probe;
            $fields['okv_csrf'] = $csrf;
            [$status, $body] = rlm_req($base, $jars[$role], 'POST', $path, $fields, true);
            rlm_eq(403, $status, "$role role: the $module write API refuses it");
            $decoded = json_decode($body, true);
            rlm_ok(is_array($decoded) && ($decoded['status'] ?? '') === 'error', "$role role: the $module refusal is a JSON error");
            $jsonLeak = [];
            foreach ($allMarkers as $what => $marker) {
                if (in_array($marker, $allowed[$role], true)) {
                    continue;
                }
                if (str_contains($body, $marker)) {
                    $jsonLeak[] = $what;
                }
            }
            rlm_ok($jsonLeak === [], "$role role: the $module refusal JSON carries no forbidden data");
        }
    }
} finally {
    // The server goes first: a DELETE below that throws would otherwise leave
    // php -S holding port 8232 and the next run would not be able to start.
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
        $server = null;
    }
    if ($messageId > 0) {
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $messageId]);
    }
    if ($transactionId > 0) {
        Database::run('DELETE FROM payment_transactions WHERE id = :id', [':id' => $transactionId]);
    }
    if ($paymentId > 0) {
        Database::run('DELETE FROM payments WHERE id = :id', [':id' => $paymentId]);
    }
    if ($orderId > 0) {
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    if ($applicationId > 0) {
        Database::run('DELETE FROM credit_applications WHERE id = :id', [':id' => $applicationId]);
    }
    if ($businessId > 0) {
        Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $businessId]);
    }
    if ($beforeDraft !== null) {
        Database::run('UPDATE content_pages SET draft_title = :title WHERE slug = :slug', [':title' => $beforeDraft['draft_title'], ':slug' => 'about']);
    }
    foreach ($users as $user) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $user['id']]);
    }
    if ($customerId > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $customerId]);
    }
    foreach ($roles as $roleId) {
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
    foreach ($jars as $jar) {
        if (is_file($jar)) {
            unlink($jar);
        }
    }
}

fwrite(STDOUT, "\n$passed / $tests role leak matrix assertions passed.\n");
exit($passed === $tests ? 0 : 1);
