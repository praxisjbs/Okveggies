<?php
/**
 * scripts/tests/transfer_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Direct bank transfer end to end over real HTTP (PRD Section 9.3a).
 *
 * A customer chooses Direct bank transfer at checkout and attaches a receipt;
 * the order is placed pending verification; a colleague verifies or declines it
 * in Payments; and the customer lands on the green "Payment received" screen.
 * Also proves the doors: a bad file never leaves an order behind, another
 * account cannot act, the shareable trail shows no money, and the private
 * receipt link opens only the receipt.
 *
 *   php scripts/tests/transfer_http_test.php
 *
 * Starts its own throwaway server on the scratch database, creates fixtures and
 * removes everything it made.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$port = 8214;
$base = 'http://127.0.0.1:' . $port;
$tests = 0; $passed = 0;
function th_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function th_eq($expected, $actual, string $label): void { th_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

/** One request. $json asks for the fetch flavour of an answer; $fields may carry CURLFile. */
function th_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $multipart = false;
        foreach ($fields ?? [] as $value) {
            if ($value instanceof CURLFile) { $multipart = true; break; }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? ($fields ?? []) : http_build_query($fields ?? []));
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw     = $raw === false ? '' : (string) $raw;
    $headers = substr($raw, 0, $size);
    $body    = substr($raw, $size);
    $location = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '';
    return [$status, $json ? (json_decode($body, true) ?? []) : $body, $location, $headers];
}
function th_csrf(string $html): string { return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : ''; }
function th_login(string $base, string $jar, string $page, string $email, string $password, bool $storefront): string
{
    [, $html] = th_req($base, $jar, 'GET', $page);
    $csrf = th_csrf((string) $html);
    [$status] = th_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => $storefront ? 'storefront' : '',
        'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ], true);
    th_eq(200, $status, $email . ' signs in');
    return $csrf;
}

// --- A throwaway server on the scratch database ------------------------------
$serverLog = sys_get_temp_dir() . '/okv-transfer-http-server.log';
$server = proc_open(
    'exec env SMTP_PORT=1 php -d display_errors=0 -d upload_max_filesize=12M -d post_max_size=48M -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the transfer test server.\n");
    exit(2);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'transfer-http-88';
$userIds  = []; $orderIds = []; $storedPaths = [];
$productId = null; $categoryId = null;
$bankKeys = ['bank_transfer_enabled', 'bank_transfer_bank_name', 'bank_transfer_account_name', 'bank_transfer_account_number'];
$savedSettings = [];
$jars = [];
foreach (['buyer', 'stranger', 'staff', 'owner', 'guest', 'anon'] as $name) { $jars[$name] = tempnam(sys_get_temp_dir(), 'okv-th-' . $name . '-'); }
$fixtureDir = sys_get_temp_dir() . '/okv-transfer-' . $suffix;
mkdir($fixtureDir, 0700);

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$validPng = $fixtureDir . '/receipt.png';
$validPdf = $fixtureDir . '/receipt.pdf';
$wrongTxt = $fixtureDir . '/receipt.txt';
$fakeJpg  = $fixtureDir . '/receipt.jpg';
$bigPng   = $fixtureDir . '/big.png';
file_put_contents($validPng, $pngBytes);
file_put_contents($validPdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
file_put_contents($wrongTxt, $pngBytes);
file_put_contents($fakeJpg, 'This is not an image at all.');
file_put_contents($bigPng, $pngBytes . str_repeat("\0", 6 * 1024 * 1024));
function th_file(string $path, string $mime, string $name): CURLFile { return new CURLFile($path, $mime, $name); }

/** Put one product in this jar's basket and walk the checkout details steps. */
function th_prepare_checkout(string $base, string $jar, string $csrfPage, int $productId, string $email, string $name): string
{
    [, $html] = th_req($base, $jar, 'GET', $csrfPage);
    $csrf = th_csrf((string) $html);
    [$code] = th_req($base, $jar, 'POST', '/api/v1/cart.php', ['action' => 'add_product', 'product_id' => $productId, 'okv_csrf' => $csrf], true);
    th_eq(200, $code, 'a product goes in the basket');
    $dates = Delivery::nextEligibleDates('household', 1);
    $zone  = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order LIMIT 1');
    [$code, $res] = th_req($base, $jar, 'POST', '/api/v1/checkout.php', [
        'action' => 'save_step', 'step' => 'customer', 'okv_csrf' => $csrf,
        'recipient_name' => $name, 'recipient_phone' => '08090000' . random_int(100, 999), 'email' => $email,
        'address_line_1' => '4 Trail Close', 'address_line_2' => '', 'city' => 'Lagos', 'state' => 'Lagos',
        'landmark' => 'Blue gate', 'customer_type' => 'household',
    ], true);
    th_eq(200, $code, 'the customer step saves');
    [$code] = th_req($base, $jar, 'POST', '/api/v1/checkout.php', [
        'action' => 'save_step', 'step' => 'delivery', 'okv_csrf' => $csrf,
        'delivery_date' => (string) $dates[0]['date'], 'delivery_zone_id' => (int) $zone['id'],
    ], true);
    th_eq(200, $code, 'the delivery step saves');
    return $csrf;
}

function th_payment(int $orderId, string $type): array
{
    return Database::one('SELECT * FROM payments WHERE order_id = :o AND payment_type = :t', [':o' => $orderId, ':t' => $type]) ?? [];
}

try {
    // --- Fixtures ------------------------------------------------------------
    foreach ($bankKeys as $key) {
        $row = Database::one('SELECT setting_value FROM site_settings WHERE setting_key = :k', [':k' => $key]);
        $savedSettings[$key] = $row === null ? null : (string) $row['setting_value'];
    }
    Settings::set('bank_transfer_enabled', false, 'bool');
    Settings::set('bank_transfer_bank_name', 'Test Bank', 'string');
    Settings::set('bank_transfer_account_name', 'OK Veggies Test', 'string');
    Settings::set('bank_transfer_account_number', '0123456789', 'string');
    Settings::flushCache();

    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active) VALUES (:n, :s, :d, 906, 1)',
        [':n' => 'ZZ Http Transfer ' . $suffix, ':s' => 'zz-http-transfer-' . strtolower($suffix), ':d' => 'Throwaway.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $unit = Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active)
         VALUES (:cat, :unit, :name, :slug, :sku, :desc, 1000000, 1.000, 1.000, 1)',
        [':cat' => $categoryId, ':unit' => (int) $unit['id'], ':name' => 'ZZ Http Yam ' . $suffix,
         ':slug' => 'zz-http-yam-' . strtolower($suffix), ':sku' => 'ZH-' . $suffix, ':desc' => 'Throwaway.']
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();

    foreach ([['buyer', 'household'], ['stranger', 'household'], ['staff', 'staff'], ['owner', 'staff']] as $i => [$who, $type]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'ZZ\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [':last' => ucfirst($who), ':email' => "th-$who-$suffix@example.test",
             ':phone' => '+23478' . $i . random_int(1000000, 9999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => $type]
        );
        $userIds[$who] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $userIds['staff']]);
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'owner\'', [':user' => $userIds['owner']]);
    Database::run('DELETE FROM rate_limits');

    $buyerEmail = "th-buyer-$suffix@example.test";
    th_login($base, $jars['buyer'], '/account.php', $buyerEmail, $password, true);
    th_login($base, $jars['stranger'], '/account.php', "th-stranger-$suffix@example.test", $password, true);
    th_login($base, $jars['staff'], '/admin/login.php', "th-staff-$suffix@example.test", $password, false);
    th_login($base, $jars['owner'], '/admin/login.php', "th-owner-$suffix@example.test", $password, false);

    // =========================================================================
    // 1. The card is only there when the Owner has switched it on.
    // =========================================================================
    th_prepare_checkout($base, $jars['buyer'], '/account.php', $productId, $buyerEmail, 'ZZ Buyer');
    [$code, $html] = th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4');
    th_eq(200, $code, 'the payment step renders');
    th_ok(!str_contains((string) $html, 'Direct bank transfer'), 'with the option off, customers see no transfer card');
    th_ok(!str_contains((string) $html, '0123456789'), 'and no account number');

    Settings::set('bank_transfer_enabled', true, 'bool');
    Settings::flushCache();
    [$code, $html] = th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4');
    th_ok(str_contains((string) $html, 'Direct bank transfer'), 'switched on, the Direct bank transfer card is there');
    th_ok(str_contains((string) $html, 'Paystack: card, transfer or USSD'), 'beside the Paystack card');
    th_ok(str_contains((string) $html, 'Test Bank') && str_contains((string) $html, 'OK Veggies Test'), 'it shows the bank and the account name');
    th_ok(str_contains((string) $html, '0123456789'), 'and the account number');
    th_ok(str_contains((string) $html, 'enctype="multipart/form-data"'), 'the form can carry a receipt');
    th_ok(str_contains((string) $html, 'name="receipt"') && str_contains((string) $html, 'name="payment_method"'), 'with a receipt box and a method choice');
    th_ok(str_contains((string) $html, 'data-transfer-amount="pay_in_full"') && str_contains((string) $html, 'data-transfer-amount="deposit"'), 'and the exact amount for each amount choice');
    th_ok(str_contains((string) $html, '₦10,000'), 'the full amount is shown with the naira sign and comma');
    th_ok(str_contains((string) $html, 'bank-transfer.min.js'), 'the helper script is loaded');

    // =========================================================================
    // 2. A receipt is required, judged before any order is written.
    // =========================================================================
    $countOrders = static function (int $userId): int {
        return (int) Database::one('SELECT COUNT(*) AS n FROM orders WHERE user_id = :u', [':u' => $userId])['n'];
    };
    $before = $countOrders($userIds['buyer']);
    $csrf = th_csrf((string) th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4')[1]);

    [$code, $res] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
    ], true);
    th_eq(422, $code, 'a transfer order with no receipt is refused');
    th_eq('receipt_missing', $res['code'] ?? '', 'and says the receipt is missing');
    th_ok(str_contains((string) ($res['message'] ?? ''), 'Attach the receipt'), 'in plain words');

    foreach ([
        ['a text file', $wrongTxt, 'text/plain', 'receipt.txt', 'receipt_unsupported_extension'],
        ['a page dressed as a photo', $fakeJpg, 'image/jpeg', 'receipt.jpg', 'receipt_unsupported_type'],
        ['a file over the size cap', $bigPng, 'image/png', 'big.png', 'receipt_too_large'],
    ] as [$what, $path, $mime, $name, $expectedCode]) {
        [$code, $res] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
            'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
            'receipt' => th_file($path, $mime, $name),
        ], true);
        th_eq(422, $code, "$what is refused");
        th_eq($expectedCode, $res['code'] ?? '', "$what is refused for the right reason");
    }
    th_eq($before, $countOrders($userIds['buyer']), 'no refused receipt left an order behind');

    // A plain form post is sent back to the payment step with the reason.
    [$code, , $location] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
    ], false);
    th_eq(303, $code, 'a plain form post with no receipt is turned back, not shown a wall of JSON');
    th_ok(str_contains($location, '/checkout.php?step=4') && str_contains($location, 'receipt_error=missing'), 'to the payment step, with the reason');
    [, $html] = th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4&receipt_error=missing');
    th_ok(str_contains((string) $html, 'Attach the receipt from your bank'), 'and the page says it in words');
    [, $tooBig] = th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4&receipt_error=nonsense');
    th_ok(!str_contains((string) $tooBig, 'nonsense'), 'an unknown reason code is never echoed onto the page');

    // A method on a choice that cannot have one is not honoured.
    [$code, $res] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_on_delivery', 'payment_method' => 'bank_transfer',
        'receipt' => th_file($validPng, 'image/png', 'receipt.png'),
    ], true);
    th_ok(in_array($code, [200, 422], true), 'pay on delivery with a stale transfer method is either placed the ordinary way or refused');
    if ($code === 200) {
        $orderIds[] = (int) $res['order_id'];
        th_eq('manual', (string) th_payment((int) $res['order_id'], 'pay_on_delivery')['provider'], 'a stale method on pay on delivery changed nothing');
        th_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :o', [':o' => (int) $res['order_id']])['n'], 'and no receipt was taken for it');
        th_prepare_checkout($base, $jars['buyer'], '/account.php', $productId, $buyerEmail, 'ZZ Buyer');
        $csrf = th_csrf((string) th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4')[1]);
    }

    // =========================================================================
    // 3. A good receipt places the order, pending verification.
    // =========================================================================
    [$code, $res] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
        'payer_name' => 'ZZ Sender', 'bank_reference' => 'FT-HTTP-1',
        'receipt' => th_file($validPng, 'image/png', 'my receipt.png'),
    ], true);
    th_eq(200, $code, 'a transfer order with a good receipt is placed');
    $orderId = (int) ($res['order_id'] ?? 0);
    $orderIds[] = $orderId;
    th_ok($orderId > 0, 'and answers with the order');
    th_ok(empty($res['pay_url']), 'there is no Paystack page to go to');

    $pay = th_payment($orderId, 'pay_in_full');
    th_eq('manual', (string) ($pay['provider'] ?? ''), 'the order is recorded as a manual transfer');
    th_eq(0, (int) ($pay['paid_amount_subunit'] ?? -1), 'nothing is credited by handing in a receipt');
    $proof = Database::one(
        'SELECT mp.* FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p',
        [':p' => (int) $pay['id']]
    );
    th_eq('submitted', (string) ($proof['status'] ?? ''), 'the receipt waits as submitted');
    th_eq('ZZ Sender', (string) ($proof['payer_name'] ?? ''), 'the optional payer name is kept');
    th_eq('FT-HTTP-1', (string) ($proof['bank_reference'] ?? ''), 'and the optional reference');
    $storedUrl = (string) ($proof['proof_url'] ?? '');
    $storedPaths[] = $storedUrl;
    th_ok(preg_match('#^uploads/payment_proofs/[a-f0-9]{32}\.png$#', $storedUrl) === 1, 'the file is stored under uploads with a random name, not the customer\'s');
    th_ok(is_file($root . '/' . $storedUrl), 'and really is on disk');
    th_ok(!str_contains($storedUrl, 'my receipt'), 'the customer\'s file name is not kept');
    th_ok((int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE event_type = \'transfer_receipt_received\' AND related_id = :o', [':o' => $orderId])['n'] === 1, 'the customer is told it is pending verification');
    th_ok((int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE event_type = \'admin_transfer_receipt\' AND related_type = \'payment_proof\' AND related_id = :p', [':p' => (int) $proof['id']])['n'] === 1, 'staff are told there is a receipt to verify');
    th_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE event_type IN (\'order_placed\', \'payment_confirmed\') AND related_id = :o', [':o' => $orderId])['n'], 'and the customer is not sent a second, contradicting email');

    // A double submit of the same basket is not placed twice and stores no second file.
    [$code, $res2] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
        'receipt' => th_file($validPng, 'image/png', 'again.png'),
    ], true);
    th_eq($orderId, (int) ($res2['order_id'] ?? 0), 'a second press returns the same order');
    th_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p', [':p' => (int) $pay['id']])['n'], 'and stores no second receipt');

    // =========================================================================
    // 4. What the customer sees while it waits.
    // =========================================================================
    [$code, $html] = th_req($base, $jars['buyer'], 'GET', '/public/order.php?order=' . $orderId . '&payment=awaiting');
    th_eq(200, $code, 'the owner opens the order');
    th_ok(str_contains((string) $html, 'Payment pending verification'), 'it says payment is pending verification');
    th_ok(str_contains((string) $html, 'data-payment-panel="awaiting"'), 'the Payments panel is there, in the pending state');
    th_ok(str_contains((string) $html, 'Still due') && str_contains((string) $html, '₦10,000'), 'it shows what is still due');
    th_ok(str_contains((string) $html, 'by bank transfer'), 'and how it is being paid');
    th_ok(!str_contains((string) $html, 'value="initialise"'), 'there is no Pay with Paystack button on a transfer order');
    th_ok(!str_contains((string) $html, 'data-transfer-form'), 'and no second upload box while one receipt is being checked');

    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/order.php?token=' . rawurlencode('x'));
    th_eq(404, $code, 'a made-up trail link is a not-found');

    $trailToken = OrderTrail::newToken();
    Database::run('UPDATE orders SET order_trail_token_hash = :h WHERE id = :o', [':h' => OrderTrail::hashToken($trailToken), ':o' => $orderId]);
    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/order.php?token=' . rawurlencode($trailToken));
    th_eq(200, $code, 'the shareable trail opens without signing in');
    th_ok(!str_contains((string) $html, '₦'), 'and shows no money');
    th_ok(!str_contains((string) $html, 'data-payment-panel'), 'no Payments panel');
    th_ok(str_contains((string) $html, 'pending verification'), 'though it does say the payment is pending verification');

    [$code] = th_req($base, $jars['anon'], 'GET', '/public/payment/receipt.php?token=' . rawurlencode($trailToken));
    th_eq(404, $code, 'the shareable trail token does not open the money screen');
    [$code] = th_req($base, $jars['anon'], 'GET', '/public/payment/receipt.php?order=' . $orderId);
    th_eq(404, $code, 'nobody signed out can open it by order number');
    [$code] = th_req($base, $jars['stranger'], 'GET', '/public/payment/receipt.php?order=' . $orderId);
    th_eq(404, $code, 'another signed-in account cannot open it');
    [$code, $html, , $headers] = th_req($base, $jars['buyer'], 'GET', '/public/payment/receipt.php?order=' . $orderId);
    th_eq(200, $code, 'the owner can open it');
    th_ok(str_contains((string) $html, 'data-payment-hero="awaiting"'), 'and it says pending, not received');
    th_ok(!str_contains((string) $html, 'Payment received</h1>'), 'it does not claim money has arrived');
    th_ok(stripos($headers, 'Cache-Control: private, no-store') !== false, 'the money screen is never cached');
    th_ok(stripos($headers, 'X-Robots-Tag: noindex') !== false, 'and never indexed');

    // =========================================================================
    // 5. Staff verify it.
    // =========================================================================
    [$code, $html] = th_req($base, $jars['staff'], 'GET', '/admin/payments.php');
    th_eq(200, $code, 'staff open Payments');
    th_ok(str_contains((string) $html, 'Transfers to verify'), 'the queue has a Transfers to verify list');
    th_ok(str_contains((string) $html, (string) $res['order_number']), 'with this order in it');
    th_ok(str_contains((string) $html, '/' . $storedUrl), 'and a link to view the receipt');
    th_ok(str_contains((string) $html, 'I checked this against the bank'), 'and the confirmation a colleague has to tick');
    $staffCsrf = th_csrf((string) $html);

    [$code, $res3] = th_req($base, $jars['stranger'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10000', 'confirmed' => '1', 'okv_csrf' => $staffCsrf], true);
    th_ok(in_array($code, [401, 403], true), 'a customer cannot verify a payment');
    [$code] = th_req($base, $jars['anon'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10000', 'confirmed' => '1'], true);
    th_ok(in_array($code, [401, 403, 419], true), 'nor can somebody signed out');
    [$code, $res3] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10000', 'confirmed' => '1'], true);
    th_eq(419, $code, 'verifying needs its CSRF token');
    [$code, $res3] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10000', 'okv_csrf' => $staffCsrf], true);
    th_eq('not_confirmed', $res3['code'] ?? '', 'and the confirmation tick, enforced on the server');
    th_eq(0, (int) th_payment($orderId, 'pay_in_full')['paid_amount_subunit'], 'a refused verification credits nothing');
    [$code, $res3] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10,000', 'note' => 'Seen in the bank', 'confirmed' => '1', 'okv_csrf' => $staffCsrf], true);
    th_eq(200, $code, 'a colleague can verify it');
    th_eq(1000000, (int) th_payment($orderId, 'pay_in_full')['paid_amount_subunit'], 'the figure they typed is credited, in kobo');
    th_eq('paid', (string) Database::one('SELECT payment_status FROM orders WHERE id = :o', [':o' => $orderId])['payment_status'], 'the order is paid');
    [$code, $res3] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $proof['id'], 'amount' => '10000', 'confirmed' => '1', 'okv_csrf' => $staffCsrf], true);
    th_eq('already_reviewed', $res3['code'] ?? '', 'a second press does not credit twice');
    th_eq(1000000, (int) th_payment($orderId, 'pay_in_full')['paid_amount_subunit'], 'and the order still holds one payment');

    // =========================================================================
    // 6. The green screen, and the doors to it.
    // =========================================================================
    [$code, $html] = th_req($base, $jars['buyer'], 'GET', '/public/payment/receipt.php?order=' . $orderId);
    th_eq(200, $code, 'the owner opens the receipt screen');
    th_ok(str_contains((string) $html, 'data-payment-hero="paid"'), 'it is the green paid screen');
    th_ok(str_contains((string) $html, 'Payment received'), 'it says Payment received');
    th_ok(str_contains((string) $html, '₦10,000'), 'with the amount received');
    th_ok(str_contains((string) $html, 'Nothing is left to pay on this order.'), 'and that nothing is left to pay');
    th_ok(str_contains((string) $html, 'Bank transfer'), 'and how it was paid');
    th_ok(str_contains((string) $html, 'Order ' . $res['order_number']), 'with the order details');
    th_ok(str_contains((string) $html, 'aria-label="Breadcrumb"') && str_contains((string) $html, '/public/order.php?order=' . $orderId), 'and a way back to the order');

    [$code, $html] = th_req($base, $jars['buyer'], 'GET', '/public/order.php?order=' . $orderId);
    th_ok(str_contains((string) $html, 'data-payment-panel="paid"'), 'reopened later, the order says paid');
    th_ok(str_contains((string) $html, 'Nothing is left to pay on this order.'), 'and shows nothing left to pay');
    th_ok(str_contains((string) $html, 'Open payment receipt'), 'with a link to the receipt');

    $verifiedNote = Database::one('SELECT n.id, n.cta_url, n.body FROM notifications n WHERE n.event_type = \'transfer_verified\' AND n.related_id = :o', [':o' => $orderId]);
    th_ok($verifiedNote !== null, 'the customer is told it was verified');
    th_ok(str_contains((string) $verifiedNote['cta_url'], '/public/payment/receipt.php?token='), 'the email button opens the green screen');
    th_ok(!str_contains((string) $verifiedNote['body'], (string) $trailToken), 'and never carries the shareable trail token');
    preg_match('/token=([A-Za-z0-9_-]{43})/', (string) $verifiedNote['cta_url'], $tm);
    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/payment/receipt.php?token=' . rawurlencode($tm[1] ?? ''));
    th_eq(200, $code, 'the emailed link opens for somebody who is not signed in');
    th_ok(str_contains((string) $html, 'data-payment-hero="paid"') && str_contains((string) $html, 'Payment received'), 'on the green screen');
    th_ok(!str_contains((string) $html, 'ZZ Buyer') && !str_contains((string) $html, '4 Trail Close') && !str_contains((string) $html, '08090000'), 'without the name, address or phone');
    $appHref = CustomerNotifications::recent($userIds['buyer']);
    $verifiedRow = null;
    foreach ($appHref as $row) { if ($row['event_type'] === 'transfer_verified') { $verifiedRow = $row; } }
    th_ok($verifiedRow !== null, 'the same news is in the customer\'s notification list');
    th_eq('/public/payment/receipt.php?order=' . $orderId, (string) ($verifiedRow['href'] ?? ''), 'and opening it lands on the green screen');

    // =========================================================================
    // 7. Declined, and the customer tries again.
    // =========================================================================
    th_prepare_checkout($base, $jars['buyer'], '/account.php', $productId, $buyerEmail, 'ZZ Buyer');
    $csrf = th_csrf((string) th_req($base, $jars['buyer'], 'GET', '/checkout.php?step=4')[1]);
    [$code, $d] = th_req($base, $jars['buyer'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $csrf, 'payment_option' => 'deposit', 'payment_method' => 'bank_transfer',
        'receipt' => th_file($validPdf, 'application/pdf', 'receipt.pdf'),
    ], true);
    th_eq(200, $code, 'a deposit can be paid by transfer with a PDF receipt');
    $depOrder = (int) ($d['order_id'] ?? 0);
    $orderIds[] = $depOrder;
    $depPay = th_payment($depOrder, 'deposit');
    th_eq('manual', (string) ($depPay['provider'] ?? ''), 'the deposit is a manual payment');
    th_eq(300000 === 0 ? 0 : (int) round(1000000 * Settings::depositPercentage() / 100), (int) ($depPay['expected_amount_subunit'] ?? 0), 'for the configured deposit');
    $depProof = Database::one('SELECT mp.* FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p', [':p' => (int) $depPay['id']]);
    $storedPaths[] = (string) $depProof['proof_url'];
    th_ok(str_ends_with((string) $depProof['proof_url'], '.pdf'), 'the PDF is kept as a PDF');

    [, $html] = th_req($base, $jars['staff'], 'GET', '/admin/payments.php');
    $staffCsrf = th_csrf((string) $html);
    [$code, $r] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'decline_transfer', 'proof_id' => (int) $depProof['id'], 'reason' => '  ', 'okv_csrf' => $staffCsrf], true);
    th_eq('reason_required', $r['code'] ?? '', 'a decline needs a reason');
    [$code, $r] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'decline_transfer', 'proof_id' => (int) $depProof['id'], 'reason' => 'We cannot see this transfer in our account yet.', 'okv_csrf' => $staffCsrf], true);
    th_eq(200, $code, 'a colleague can decline it');
    th_eq(0, (int) th_payment($depOrder, 'deposit')['paid_amount_subunit'], 'a decline credits nothing');

    [, $html] = th_req($base, $jars['buyer'], 'GET', '/public/order.php?order=' . $depOrder);
    th_ok(str_contains((string) $html, 'We could not confirm your last receipt'), 'the customer is told it was not confirmed');
    th_ok(str_contains((string) $html, 'We cannot see this transfer in our account yet.'), 'with the reason');
    th_ok(str_contains((string) $html, 'data-transfer-form') && str_contains((string) $html, 'name="receipt"'), 'and can upload another');
    th_ok(str_contains((string) $html, 'OKV') && str_contains((string) $html, 'Narration'), 'with the order number to use as the narration');
    $depCsrf = th_csrf((string) $html);

    [$code, $r] = th_req($base, $jars['buyer'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'receipt' => th_file($validPng, 'image/png', 'new.png')], true);
    th_eq(419, $code, 'uploading needs its CSRF token');
    [$code, $r] = th_req($base, $jars['stranger'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf, 'receipt' => th_file($validPng, 'image/png', 'new.png')], true);
    th_ok(in_array($code, [403, 404, 419], true), 'another account cannot upload against this order');
    [$code, $r] = th_req($base, $jars['anon'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf, 'receipt' => th_file($validPng, 'image/png', 'new.png')], true);
    th_ok(in_array($code, [401, 419], true), 'nor can somebody signed out');
    [$code, $r] = th_req($base, $jars['buyer'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf, 'receipt' => th_file($wrongTxt, 'text/plain', 'new.txt')], true);
    th_eq('receipt_unsupported_extension', $r['code'] ?? '', 'a wrong file is refused on the order page too');
    [$code, $r] = th_req($base, $jars['buyer'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf], true);
    th_eq('receipt_missing', $r['code'] ?? '', 'and so is no file at all');
    [$code, $r, $location] = th_req($base, $jars['buyer'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf, 'receipt' => th_file($validPng, 'image/png', 'new.png')], false);
    th_eq(303, $code, 'a good receipt goes through');
    th_ok(str_contains($location, '/public/order.php?order=' . $depOrder) && str_contains($location, 'payment=awaiting'), 'and lands back on the order, pending verification');
    $newProof = Database::one('SELECT mp.* FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p AND mp.status = \'submitted\'', [':p' => (int) $depPay['id']]);
    th_ok($newProof !== null, 'it waits as a fresh submitted receipt');
    $storedPaths[] = (string) ($newProof['proof_url'] ?? '');
    th_eq(2, (int) Database::one('SELECT COUNT(*) AS n FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p', [':p' => (int) $depPay['id']])['n'], 'and the declined one is still on file');
    [$code, $r] = th_req($base, $jars['buyer'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $depOrder, 'okv_csrf' => $depCsrf, 'receipt' => th_file($validPng, 'image/png', 'again.png')], true);
    th_eq('nothing_to_submit', $r['code'] ?? '', 'a second receipt while one is being checked is refused');

    // The deposit is verified for less than was sent: a part payment, balance still due.
    $expectedDeposit = (int) $depPay['expected_amount_subunit'];
    $seen = intdiv($expectedDeposit, 100) - 500;   // naira, a little short
    [, $html] = th_req($base, $jars['staff'], 'GET', '/admin/payments.php');
    $staffCsrf = th_csrf((string) $html);
    [$code, $r] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $newProof['id'], 'amount' => (string) $seen, 'confirmed' => '1', 'okv_csrf' => $staffCsrf], true);
    th_eq(200, $code, 'the deposit can be verified for what was seen');
    $depAfter = th_payment($depOrder, 'deposit');
    th_eq('part_paid', (string) $depAfter['status'], 'a short deposit is part paid');
    th_eq($seen * 100, (int) $depAfter['paid_amount_subunit'], 'the credited figure is the one typed');
    [, $html] = th_req($base, $jars['buyer'], 'GET', '/public/payment/receipt.php?order=' . $depOrder);
    th_ok(str_contains((string) $html, 'data-payment-hero="part_paid"'), 'the green screen still says money arrived');
    th_ok(str_contains((string) $html, 'Still due'), 'and what is still due');
    th_ok(str_contains((string) $html, 'Deposit') && str_contains((string) $html, 'Balance'), 'with a line for the deposit and one for the balance');
    th_ok(str_contains((string) $html, 'Due on delivery'), 'the balance says when it is due');

    // =========================================================================
    // 8. A guest, with no account, by the trail link they hold.
    // =========================================================================
    $guestEmail = "th-guest-$suffix@example.test";
    $guestCsrf = th_prepare_checkout($base, $jars['guest'], '/account.php', $productId, $guestEmail, 'ZZ Guest');
    [$code, $g] = th_req($base, $jars['guest'], 'POST', '/api/v1/checkout.php', [
        'action' => 'place_order', 'okv_csrf' => $guestCsrf, 'payment_option' => 'pay_in_full', 'payment_method' => 'bank_transfer',
        'receipt' => th_file($validPng, 'image/png', 'guest.png'),
    ], true);
    th_eq(200, $code, 'a guest can pay by transfer without an account');
    $guestOrder = (int) ($g['order_id'] ?? 0);
    $orderIds[] = $guestOrder;
    $guestToken = (string) ($g['trail_token'] ?? '');
    $guestPay = th_payment($guestOrder, 'pay_in_full');
    $guestProof = Database::one('SELECT mp.* FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p', [':p' => (int) $guestPay['id']]);
    $storedPaths[] = (string) ($guestProof['proof_url'] ?? '');
    th_eq('submitted', (string) ($guestProof['status'] ?? ''), 'the guest receipt is waiting');
    th_ok(str_contains((string) Database::one('SELECT body FROM notifications WHERE event_type = \'transfer_receipt_received\' AND related_id = :o', [':o' => $guestOrder])['body'], $guestToken), 'the guest\'s acknowledgement carries their working trail link');
    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/order.php?token=' . rawurlencode($guestToken));
    th_eq(200, $code, 'the guest follows the order by that link');
    th_ok(!str_contains((string) $html, '₦'), 'which shows no money');
    th_ok(str_contains((string) $html, 'pending verification'), 'but does say it is pending verification');

    [, $html] = th_req($base, $jars['staff'], 'GET', '/admin/payments.php');
    $staffCsrf = th_csrf((string) $html);
    [$code] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'decline_transfer', 'proof_id' => (int) $guestProof['id'], 'reason' => 'Amount does not match.', 'okv_csrf' => $staffCsrf], true);
    th_eq(200, $code, 'the guest receipt is declined');
    $declinedMail = Database::one('SELECT cta_url FROM notifications WHERE event_type = \'transfer_declined\' AND related_id = :o', [':o' => $guestOrder]);
    th_ok(str_contains((string) ($declinedMail['cta_url'] ?? ''), '/public/order.php?token='), 'the guest is sent a working link to upload again, not an account-only one');
    preg_match('/token=([A-Za-z0-9_-]{43})/', (string) ($declinedMail['cta_url'] ?? ''), $gt);
    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/order.php?token=' . rawurlencode($gt[1] ?? ''));
    th_eq(200, $code, 'that link opens for a guest with no session');
    th_ok(str_contains((string) $html, 'data-transfer-form') && str_contains((string) $html, 'type="hidden" name="token"'), 'and offers the upload box, carrying the token as the credential');
    $anonCsrf = th_csrf((string) $html);
    [$code, $r] = th_req($base, $jars['anon'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $guestOrder, 'token' => $gt[1] ?? '', 'okv_csrf' => $anonCsrf, 'receipt' => th_file($validPng, 'image/png', 'guest2.png')], true);
    th_eq(200, $code, 'the guest uploads a new receipt by token');
    $guestNew = Database::one('SELECT mp.* FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p AND mp.status = \'submitted\'', [':p' => (int) $guestPay['id']]);
    th_ok($guestNew !== null, 'it is waiting');
    $storedPaths[] = (string) ($guestNew['proof_url'] ?? '');
    [$code, $r] = th_req($base, $jars['anon'], 'POST', '/api/v1/payments.php', ['action' => 'submit_transfer', 'order_id' => $orderId, 'token' => $gt[1] ?? '', 'okv_csrf' => $anonCsrf, 'receipt' => th_file($validPng, 'image/png', 'x.png')], true);
    th_eq(404, $code, 'a guest token opens its own order and no other');

    [, $html] = th_req($base, $jars['staff'], 'GET', '/admin/payments.php');
    $staffCsrf = th_csrf((string) $html);
    [$code] = th_req($base, $jars['staff'], 'POST', '/api/v1/payments.php', ['action' => 'verify_transfer', 'proof_id' => (int) $guestNew['id'], 'amount' => '10000', 'confirmed' => '1', 'okv_csrf' => $staffCsrf], true);
    th_eq(200, $code, 'the guest receipt is verified');
    $guestMail = Database::one('SELECT cta_url FROM notifications WHERE event_type = \'transfer_verified\' AND related_id = :o', [':o' => $guestOrder]);
    preg_match('/token=([A-Za-z0-9_-]{43})/', (string) ($guestMail['cta_url'] ?? ''), $rt);
    [$code, $html] = th_req($base, $jars['anon'], 'GET', '/public/payment/receipt.php?token=' . rawurlencode($rt[1] ?? ''));
    th_eq(200, $code, 'the guest\'s emailed link opens the green screen');
    th_ok(str_contains((string) $html, 'data-payment-hero="paid"'), 'showing the payment received');
    [$code] = th_req($base, $jars['anon'], 'GET', '/public/payment/receipt.php?token=' . rawurlencode($guestToken));
    th_eq(404, $code, 'while the guest\'s trail link still cannot open it');

    // =========================================================================
    // 9. The Order settings side: the account is validated, and enabling needs it whole.
    // =========================================================================
    [$code, $html] = th_req($base, $jars['owner'], 'GET', '/admin/settings.php');
    th_eq(200, $code, 'the settings screen opens for the Owner');
    th_ok(str_contains((string) $html, 'Direct bank transfer') && str_contains((string) $html, 'bank_transfer_account_number'), 'the Payments tab carries the Direct bank transfer section');
    th_ok(str_contains((string) $html, 'inputmode="numeric"'), 'the account number asks for a number pad on a phone');
    $ownerCsrf = th_csrf((string) $html);
    $paymentKeys = 'payment_channels,payment_verify_sweep_minutes,payment_reminder_minutes,bank_transfer_enabled,bank_transfer_bank_name,bank_transfer_account_name,bank_transfer_account_number';
    $bankPost = static fn(array $over) => array_merge([
        'action' => 'save_payment_settings', 'okv_csrf' => $ownerCsrf, 'rendered_fields' => $paymentKeys,
        'bank_transfer_enabled' => '1', 'bank_transfer_bank_name' => 'Zenith Bank', 'bank_transfer_account_name' => 'OK Veggies Limited',
        'bank_transfer_account_number' => '0987 654 321',
    ], $over);

    [$code, $r] = th_req($base, $jars['staff'], 'POST', '/api/v1/settings.php', $bankPost(['okv_csrf' => $staffCsrf]), true);
    th_eq(403, $code, 'a Manager cannot change where customer money goes');
    [$code, $r] = th_req($base, $jars['anon'], 'POST', '/api/v1/settings.php', $bankPost([]), true);
    th_ok(in_array($code, [401, 403], true), 'nor can somebody signed out');
    [$code, $r] = th_req($base, $jars['owner'], 'POST', '/api/v1/settings.php', $bankPost(['bank_transfer_account_number' => '12345']), true);
    th_eq(422, $code, 'a short account number is refused');
    th_ok(isset($r['errors']['bank_transfer_account_number']), 'against the account number field');
    [$code, $r] = th_req($base, $jars['owner'], 'POST', '/api/v1/settings.php', $bankPost(['bank_transfer_bank_name' => '']), true);
    th_eq(422, $code, 'switching it on with no bank name is refused');
    th_ok(isset($r['errors']['bank_transfer_enabled']), 'against the switch');
    th_eq('Test Bank', Settings::str('bank_transfer_bank_name', ''), 'a refused save changed nothing');

    [$code, $r] = th_req($base, $jars['owner'], 'POST', '/api/v1/settings.php', $bankPost(['action' => 'preview', 'group' => 'payment']), true);
    th_eq(200, $code, 'the confirmation step can be previewed');
    th_ok(!empty($r['changed']['bank_transfer_account_number']['confirm']) && !empty($r['needs_confirm']), 'and asks for the account number to be confirmed');
    th_eq('0987654321', (string) ($r['changed']['bank_transfer_account_number']['to'] ?? ''), 'showing the number with the spaces gone');

    [$code, $r] = th_req($base, $jars['owner'], 'POST', '/api/v1/settings.php', $bankPost([]), true);
    th_eq(200, $code, 'the Owner can save a new account');
    Settings::flushCache();
    th_eq('Zenith Bank', Settings::str('bank_transfer_bank_name', ''), 'the bank is saved');
    th_eq('0987654321', Settings::str('bank_transfer_account_number', ''), 'and the number, cleaned');
    th_ok(TransferProofs::isEnabled(), 'direct transfer is offered');
    th_ok((int) Database::one('SELECT COUNT(*) AS n FROM audit_logs WHERE actor_user_id = :u AND action = \'settings.update\'', [':u' => $userIds['owner']])['n'] >= 1, 'and the change is in the audit log');

} catch (Throwable $e) {
    fwrite(STDERR, '  FAIL: threw ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n");
    $tests++;
} finally {
    // --- Clean up ------------------------------------------------------------
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type IN (\'order\', \'payment_receipt\') AND related_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type IN (\'order\', \'payment_receipt\') AND related_id = :o', [':o' => $id]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT n.id FROM notifications n JOIN manual_payment_proofs mp ON mp.id = n.related_id AND n.related_type = \'payment_proof\' JOIN payment_transactions t ON t.id = mp.payment_transaction_id JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'payment_proof\' AND related_id IN (SELECT mp.id FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM order_receipt_links WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM order_trail_share_links WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM payment_reversals WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM manual_payment_proofs WHERE payment_transaction_id IN (SELECT t.id FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :o', [':o' => $id]);
        Database::run('DELETE FROM orders WHERE id = :o', [':o' => $id]);
    }
    foreach ($storedPaths as $path) {
        if ($path !== '' && preg_match('#^uploads/payment_proofs/[a-f0-9]{32}\.(?:png|pdf|jpg|webp)$#', $path) === 1) {
            @unlink($root . '/' . $path);
        }
    }
    foreach ($userIds as $uid) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM user_roles WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $uid]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $uid]);
    }
    Database::run('DELETE FROM cart_items WHERE product_id = :p', [':p' => (int) $productId]);
    if ($productId)  { Database::run('DELETE FROM products WHERE id = :p', [':p' => $productId]); }
    if ($categoryId) { Database::run('DELETE FROM product_categories WHERE id = :c', [':c' => $categoryId]); }
    foreach ($savedSettings as $key => $value) {
        if ($value !== null) {
            Database::run('UPDATE site_settings SET setting_value = :v WHERE setting_key = :k', [':v' => $value, ':k' => $key]);
        }
    }
    Settings::flushCache();
    foreach ($jars as $jar) { @unlink($jar); }
    foreach (glob($fixtureDir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($fixtureDir);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
}

fwrite(STDOUT, "\n$passed / $tests transfer HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
