<?php
/**
 * scripts/tests/credit_checkout_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. M8 items 31 to 36, over the real routes.
 *
 * The domain tests prove the credit rules. This proves the thing a customer
 * actually does: a business signs in, fills a basket, chooses "on account" at
 * checkout, and the order is placed with a charge against its limit. Then the
 * same business tries to spend past the limit and is turned away before an
 * order row exists.
 *
 *   php -S 127.0.0.1:8123 -t .
 *   php scripts/tests/credit_checkout_http_test.php
 *
 * Creates its own business, orders and journal rows, then removes them.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$base   = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests  = 0;
$passed = 0;

function cc_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}

function cc_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    cc_ok($same, $label);
}

/** One request through the site, keeping the session in $jar. */
function cc_req(string $jar, string $url, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, json_decode($body, true)];
}

/**
 * The session's CSRF token. Read from the account screen, which carries it in a
 * meta tag; the token is per session, not per page, so one read serves every
 * post below.
 */
function cc_csrf(string $jar, string $base): string
{
    [, $body] = cc_req($jar, $base . '/account.php');
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}

$suffix     = substr(bin2hex(random_bytes(5)), 0, 10);
$password   = 'credit-checkout-777';
$email      = "credit-checkout-$suffix@example.test";
$userId     = 0;
$businessId = 0;
$jar        = tempnam(sys_get_temp_dir(), 'okv-cc-');
$orderIds   = [];

try {
    // ---- A business with a facility just big enough for one order ----------
    $product = Database::one(
        'SELECT id, current_price_subunit FROM products
          WHERE is_active = 1 AND current_price_subunit IS NOT NULL ORDER BY id LIMIT 1'
    );
    $unitPrice = (int) $product['current_price_subunit'];
    $limit     = $unitPrice * 2;

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (\'Credit\', \'Checkout\', :email, :phone, :hash, \'business\', \'active\', NOW())',
        [':email' => $email, ':phone' => '+23478' . random_int(10000000, 99999999),
         ':hash' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested,
                                         credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, :c, 1, \'approved\', 7, :lim)',
        [':u' => $userId, ':n' => "Credit Checkout $suffix", ':c' => 'Credit Checkout', ':lim' => $limit]
    );
    $businessId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('DELETE FROM rate_limits');

    $zone = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1');
    $date = Delivery::nextEligibleDates('business', 1)[0]['date'] ?? '';
    cc_ok($date !== '', 'the business queue offers a delivery day to order into');

    // ---- Sign in -----------------------------------------------------------
    $csrf = cc_csrf($jar, $base);
    [$code] = cc_req($jar, $base . '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront',
        'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ]);
    cc_eq(200, $code, 'the business signs in');

    /** Put $quantity of the product in the basket. */
    $fill = function (int $quantity) use ($jar, $base, $product): void {
        $csrf = cc_csrf($jar, $base);
        cc_req($jar, $base . '/api/v1/cart.php', [
            'action' => 'add_product', 'product_id' => (int) $product['id'],
            'quantity' => $quantity, 'okv_csrf' => $csrf,
        ]);
    };

    $fill(1);

    /**
     * Walk the real checkout: the three steps a customer fills in, then the
     * final submit. Driving it any other way would prove less than nothing.
     */
    $place = function () use ($jar, $base, $zone, $date, $email): array {
        $csrf = cc_csrf($jar, $base);
        cc_req($jar, $base . '/api/v1/checkout.php', [
            'action'          => 'save_step',
            'step'            => 'customer',
            'recipient_name'  => 'Credit Checkout',
            'recipient_phone' => '+2348012345670',
            'email'           => $email,
            'address_line_1'  => '9 Test Close',
            'city'            => 'Ikeja',
            'state'           => 'Lagos',
            'customer_type'   => 'business',
            'okv_csrf'        => $csrf,
        ]);
        cc_req($jar, $base . '/api/v1/checkout.php', [
            'action'           => 'save_step',
            'step'             => 'delivery',
            'delivery_date'    => $date,
            'delivery_zone_id' => (int) $zone['id'],
            'okv_csrf'         => $csrf,
        ]);
        return cc_req($jar, $base . '/api/v1/checkout.php', [
            'action'         => 'place_order',
            'payment_option' => 'on_account',
            'okv_csrf'       => $csrf,
        ]);
    };

    // ---- The payment step offers on account, and says what is available ----
    // The choice only renders once the earlier steps are filled in, which is
    // also the only point at which the screen knows what the order will cost.
    $csrf = cc_csrf($jar, $base);
    cc_req($jar, $base . '/api/v1/checkout.php', [
        'action' => 'save_step', 'step' => 'customer',
        'recipient_name' => 'Credit Checkout', 'recipient_phone' => '+2348012345670',
        'email' => $email, 'address_line_1' => '9 Test Close', 'city' => 'Ikeja',
        'state' => 'Lagos', 'customer_type' => 'business', 'okv_csrf' => $csrf,
    ]);
    cc_req($jar, $base . '/api/v1/checkout.php', [
        'action' => 'save_step', 'step' => 'delivery',
        'delivery_date' => $date, 'delivery_zone_id' => (int) $zone['id'], 'okv_csrf' => $csrf,
    ]);
    [, $paymentStep] = cc_req($jar, $base . '/checkout.php?step=4');
    cc_ok(str_contains($paymentStep, 'on_account'), 'the payment step offers on account to this business');
    cc_ok(str_contains($paymentStep, Money::format($limit)), 'and shows the credit actually available');

    // ---- One order inside the limit ----------------------------------------
    [$code, $body, $result] = $place();
    cc_eq(200, $code, 'an on-account order inside the limit is placed');
    if ($code !== 200) { fwrite(STDOUT, "    body: " . substr($body, 0, 400) . "\n"); }
    $orderId = (int) ($result['order_id'] ?? 0);
    cc_ok($orderId > 0, 'the order exists');
    if ($orderId > 0) { $orderIds[] = $orderId; }

    $charge = Database::one(
        'SELECT amount_subunit, due_date, transaction_type FROM credit_transactions
          WHERE order_id = :o AND transaction_type = :t',
        [':o' => $orderId, ':t' => 'charge']
    );
    cc_ok($charge !== null, 'placing on account writes a charge to the journal');
    cc_eq($unitPrice, (int) ($charge['amount_subunit'] ?? 0), 'the charge is the order balance');
    cc_eq(Credit::dueDateFor($date, 7), (string) ($charge['due_date'] ?? ''), 'the charge is due on the approved term');
    cc_eq($unitPrice, (int) Credit::facilityForUser($userId)['available_subunit'], 'the limit falls by the charge');

    // ---- An order past what is left is refused, and writes nothing ---------
    $ordersBefore = (int) Database::one('SELECT COUNT(*) AS c FROM orders WHERE user_id = :u', [':u' => $userId])['c'];
    $fill(2);
    [$code, , $result] = $place();
    cc_eq(422, $code, 'an on-account order past the limit is refused');
    cc_eq('credit_limit_exceeded', (string) ($result['code'] ?? ''), 'and it says why');
    cc_eq($ordersBefore, (int) Database::one('SELECT COUNT(*) AS c FROM orders WHERE user_id = :u', [':u' => $userId])['c'],
        'a refused credit order leaves no order behind');
    cc_eq($unitPrice, (int) Credit::facilityForUser($userId)['available_subunit'], 'and takes nothing off the limit');

    // ---- Money on the order frees the limit again --------------------------
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type,
                               expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
         VALUES (:n, :u, :o, \'manual\', \'balance\', :e, :a, \'paid\', NOW())',
        [':n' => "CC-P-$suffix", ':u' => $userId, ':o' => $orderId, ':e' => $unitPrice, ':a' => $unitPrice]
    );
    Payments::recomputeOrder($orderId);
    cc_eq($limit, (int) Credit::facilityForUser($userId)['available_subunit'],
        'settling the order gives the whole limit back');

    // ---- And the order the limit had refused now goes through --------------
    [$code, , $result] = $place();
    cc_eq(200, $code, 'the order refused a moment ago is accepted once the limit is free');
    if (!empty($result['order_id'])) { $orderIds[] = (int) $result['order_id']; }

    // ---- A suspended facility stops taking orders --------------------------
    Database::run('UPDATE business_customers SET credit_status = :s WHERE id = :id',
        [':s' => 'suspended', ':id' => $businessId]);
    $fill(1);
    [$code, , $result] = $place();
    cc_eq(422, $code, 'a suspended facility refuses a new on-account order');
    cc_eq('credit_not_approved', (string) ($result['code'] ?? ''), 'and says the facility is not approved');
} finally {
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM credit_transactions WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        foreach (['order_items', 'order_status_history', 'order_addresses', 'delivery_schedules'] as $table) {
            Database::run("DELETE FROM $table WHERE order_id = :id", [':id' => $id]);
        }
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN
                       (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    if ($userId) {
        Database::run('DELETE ci FROM cart_items ci JOIN shopping_carts c ON c.id = ci.cart_id WHERE c.user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $userId]);
    }
    if ($businessId) {
        Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id', [':id' => $businessId]);
        Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $businessId]);
    }
    if ($userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    if (is_string($jar) && is_file($jar)) { unlink($jar); }
}

fwrite(STDOUT, "\n$passed / $tests credit checkout HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
