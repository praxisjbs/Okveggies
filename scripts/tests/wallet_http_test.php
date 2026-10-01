<?php
/**
 * scripts/tests/wallet_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR2 of the 23 Sep review, over the real routes.
 *
 * The database suite proves the ledger. This proves what a customer and a
 * colleague actually do: a customer with wallet credit checks out with the box
 * ticked and the order is paid or part paid before Paystack hears about it;
 * unticks it and pays in full by card; spends the wallet on an order that
 * already exists; opens the wallet and its credit note; and a stranger cannot
 * open either. The Owner gives goodwill credit once, however many times the
 * form is sent, and a colleague without the permission is turned away.
 *
 *   php -S 127.0.0.1:8123 -t .
 *   php -S 127.0.0.1:8124 scripts/tests/fake/paystack.php      (PAYSTACK_BASE_URL)
 *   php scripts/tests/wallet_http_test.php
 *
 * Creates its own customers, staff, orders and ledger rows, then removes them.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }

$base   = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests  = 0;
$passed = 0;

function wh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function wh_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    wh_ok($same, $label);
}

/** One request through the site, keeping the session in $jar. $json asks for JSON. */
function wh_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw    = (string) curl_exec($ch);
    $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    $body   = substr($raw, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);
    $location = preg_match('/^Location:\s*(\S+)/mi', $header, $m) ? $m[1] : '';
    return [$code, $body, json_decode($body, true), $location];
}

function wh_csrf(string $jar, string $base): string
{
    [, $body] = wh_req($jar, $base . '/account.php', null, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'wallet-http-9271';
$users = []; $roles = []; $orders = []; $carts = []; $businesses = [];
$jarA = tempnam(sys_get_temp_dir(), 'okv-wh-a-');   // the customer with a wallet
$jarB = tempnam(sys_get_temp_dir(), 'okv-wh-b-');   // another customer
$jarC = tempnam(sys_get_temp_dir(), 'okv-wh-c-');   // a business
$jarO = tempnam(sys_get_temp_dir(), 'okv-wh-o-');   // staff with wallet.credit
$jarS = tempnam(sys_get_temp_dir(), 'okv-wh-s-');   // staff without it
$jarG = tempnam(sys_get_temp_dir(), 'okv-wh-g-');   // nobody signed in

$makeUser = static function (string $first, string $type) use ($suffix, $password, &$users): array {
    $email = strtolower($first) . "-wh-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, \'Wallet\', :e, :p, :h, :t, \'active\', NOW())',
        [':f' => $first, ':e' => $email, ':p' => '+23479' . random_int(10000000, 99999999),
         ':h' => password_hash($password, PASSWORD_BCRYPT), ':t' => $type]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return [$id, $email];
};
$makeRole = static function (array $permissions) use ($suffix, &$roles): int {
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Wallet HTTP fixture\')', [':n' => 'wallet_http_' . $suffix . '_' . count($roles)]);
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roles[] = $id;
    foreach ($permissions as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE `key` = :p', [':r' => $id, ':p' => $permission]);
    }
    return $id;
};
$signIn = static function (string $jar, string $email, string $context) use ($base, $password): int {
    $csrf = wh_csrf($jar, $base);
    [$code] = wh_req($jar, $base . '/api/v1/auth.php', [
        'action' => 'login', 'context' => $context, 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ]);
    return $code;
};

try {
    $product = Database::one(
        'SELECT id, current_price_subunit FROM products WHERE is_active = 1 AND current_price_subunit IS NOT NULL ORDER BY id LIMIT 1'
    );
    $unit = (int) $product['current_price_subunit'];
    $zone = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1');
    $date = Delivery::nextEligibleDates('household', 1)[0]['date'] ?? '';
    wh_ok($date !== '' && $zone !== null && $unit > 0, 'the shop has a product, a zone and a delivery day to order into');
    Database::run('DELETE FROM rate_limits');

    [$adaId, $adaEmail]   = $makeUser('Ada', 'household');
    [$bolaId, $bolaEmail] = $makeUser('Bola', 'household');
    [$bisiId, $bisiEmail] = $makeUser('Bisi', 'business');
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, \'Bisi\', 1, \'approved\', 7, 900000000)',
        [':u' => $bisiId, ':n' => "Bisi Kitchen $suffix"]
    );
    $businesses[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    [$ownerId, $ownerEmail] = $makeUser('Owner', 'staff');
    [$viewerId, $viewerEmail] = $makeUser('Viewer', 'staff');
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $ownerId, ':r' => $makeRole(['dashboard.view', 'customers.view', 'wallet.view', 'wallet.credit'])]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $viewerId, ':r' => $makeRole(['dashboard.view', 'customers.view', 'wallet.view'])]);

    wh_eq(200, $signIn($jarA, $adaEmail, 'storefront'), 'Ada signs in');
    wh_eq(200, $signIn($jarB, $bolaEmail, 'storefront'), 'Bola signs in');
    wh_eq(200, $signIn($jarC, $bisiEmail, 'storefront'), 'Bisi (a business) signs in');

    // Ada's wallet: enough to cover a three unit basket outright.
    $credit = Wallet::creditNow($adaId, $unit * 3, 'goodwill', 'Wallet HTTP fixture credit', "wh:$suffix:ada1", null, null);
    wh_ok($credit['ok'], 'setup: Ada is credited');
    $noteId = (int) $credit['credit_note_id'];

    // ---- The wallet page and the credit note ------------------------------------------------
    [$code, $page] = wh_req($jarA, $base . '/wallet.php', null, false);
    wh_eq(200, $code, 'Ada opens her wallet');
    wh_ok(str_contains($page, Money::format($unit * 3)), 'it shows her balance');
    wh_ok(str_contains($page, (string) $credit['credit_note_number']), 'and the credit note for the credit');
    [$code, $doc] = wh_req($jarA, $base . '/public/documents/credit_note.php?id=' . $noteId, null, false);
    wh_eq(200, $code, 'Ada opens the credit note');
    wh_ok(str_contains($doc, (string) $credit['credit_note_number']) && str_contains($doc, Money::format($unit * 3, true)), 'it carries the number and the amount');
    [, $strangerDoc] = wh_req($jarB, $base . '/public/documents/credit_note.php?id=' . $noteId, null, false);
    wh_ok(!str_contains($strangerDoc, (string) $credit['credit_note_number']) && str_contains($strangerDoc, 'We could not open this credit note'), 'another customer cannot open it');
    [, $guestDoc] = wh_req($jarG, $base . '/public/documents/credit_note.php?id=' . $noteId, null, false);
    wh_ok(!str_contains($guestDoc, (string) $credit['credit_note_number']), 'nor can someone who is signed out');
    [$code, , , $where] = wh_req($jarG, $base . '/wallet.php', null, false);
    wh_ok($code === 302 && str_contains($where, '/account.php'), 'the wallet page sends a signed-out visitor to sign in');
    [$code, , , $where] = wh_req($jarC, $base . '/wallet.php', null, false);
    wh_ok($code === 302 && str_contains($where, '/pro/wallet.php'), 'a business is sent on to its own portal wallet');
    [$code, $pro] = wh_req($jarC, $base . '/pro/wallet.php', null, false);
    wh_ok($code === 200 && str_contains($pro, 'Wallet'), 'and the Pro wallet page opens');
    [, $account] = wh_req($jarA, $base . '/account.php', null, false);
    wh_ok(str_contains($account, 'data-wallet-card') && str_contains($account, Money::format($unit * 3)), 'the account home carries a wallet card with the balance');

    // ---- Checkout ---------------------------------------------------------------------------
    $fill = static function (string $jar, int $quantity) use ($base, $product): void {
        $csrf = wh_csrf($jar, $base);
        wh_req($jar, $base . '/api/v1/cart.php', ['action' => 'add_product', 'product_id' => (int) $product['id'], 'quantity' => $quantity, 'okv_csrf' => $csrf]);
    };
    $place = static function (string $jar, string $email, string $option, array $extra) use ($base, $zone, $date): array {
        $csrf = wh_csrf($jar, $base);
        wh_req($jar, $base . '/api/v1/checkout.php', [
            'action' => 'save_step', 'step' => 'customer', 'recipient_name' => 'Wallet Tester', 'recipient_phone' => '+2348012345671',
            'email' => $email, 'address_line_1' => '9 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos',
            'customer_type' => 'household', 'okv_csrf' => $csrf,
        ]);
        wh_req($jar, $base . '/api/v1/checkout.php', [
            'action' => 'save_step', 'step' => 'delivery', 'delivery_date' => $date, 'delivery_zone_id' => (int) $zone['id'], 'okv_csrf' => $csrf,
        ]);
        return wh_req($jar, $base . '/api/v1/checkout.php', ['action' => 'place_order', 'payment_option' => $option, 'okv_csrf' => $csrf] + $extra);
    };
    $orderRow = static function (int $id): array {
        $orders = Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
        $orders['rows'] = Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $id]);
        return $orders;
    };
    $track = static function (int $id) use (&$orders): void { if ($id > 0) { $orders[] = $id; } };

    // The payment step offers the box, ticked, with the balance.
    $fill($jarA, 1);
    wh_req($jarA, $base . '/api/v1/checkout.php', ['action' => 'save_step', 'step' => 'customer', 'recipient_name' => 'Wallet Tester', 'recipient_phone' => '+2348012345671', 'email' => $adaEmail, 'address_line_1' => '9 Test Close', 'city' => 'Ikeja', 'state' => 'Lagos', 'customer_type' => 'household', 'okv_csrf' => wh_csrf($jarA, $base)]);
    wh_req($jarA, $base . '/api/v1/checkout.php', ['action' => 'save_step', 'step' => 'delivery', 'delivery_date' => $date, 'delivery_zone_id' => (int) $zone['id'], 'okv_csrf' => wh_csrf($jarA, $base)]);
    [, $step] = wh_req($jarA, $base . '/checkout.php?step=4', null, false);
    wh_ok(str_contains($step, 'name="use_wallet"') && preg_match('/name="use_wallet"[^>]*checked/', $step) === 1, 'the payment step offers the wallet box, ticked');
    wh_ok(str_contains($step, 'data-wallet="' . ($unit * 3) . '"'), 'and carries the balance');
    [, $stepB] = wh_req($jarB, $base . '/checkout.php?step=4', null, false);
    wh_ok(!str_contains($stepB, 'name="use_wallet"'), 'a customer with an empty wallet is not offered the box');

    // 1. The wallet covers the whole order: paid, and no Paystack redirect.
    [$code, $body, $result] = $place($jarA, $adaEmail, 'pay_in_full', ['use_wallet' => '1']);
    wh_eq(200, $code, 'a pay in full order with the wallet ticked is placed');
    $o1 = (int) ($result['order_id'] ?? 0); $track($o1);
    wh_ok(array_key_exists('pay_url', $result) && $result['pay_url'] === null, 'and there is no card payment to send the customer to');
    wh_eq($unit, (int) ($result['wallet_paid_subunit'] ?? 0), 'the response says how much the wallet paid');
    $r = $orderRow($o1);
    wh_eq('paid', (string) $r['payment_status'], 'the order is paid');
    wh_eq($unit, (int) $r['amount_paid_subunit'], 'for the whole total');
    wh_eq($unit * 2, Wallet::balance($adaId), 'and the wallet dropped by that much');

    // 2. Ticked but not enough: the wallet pays what it has, the card pays the rest.
    Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $adaId]);
    $fill($jarA, 3);
    [$code, $body, $result] = $place($jarA, $adaEmail, 'pay_in_full', ['use_wallet' => '1']);
    wh_eq(200, $code, 'a bigger order with the wallet ticked is placed');
    $o2 = (int) ($result['order_id'] ?? 0); $track($o2);
    wh_eq($unit * 2, (int) ($result['wallet_paid_subunit'] ?? 0), 'the wallet paid everything it held');
    wh_ok(is_string($result['pay_url'] ?? null) && $result['pay_url'] !== '', 'and the customer is sent to Paystack for the rest');
    $r = $orderRow($o2);
    wh_eq('part_paid', (string) $r['payment_status'], 'the order reads as part paid');
    wh_eq($unit * 3, (int) $r['order_total_subunit'], 'setup: the order is three units');
    $card = array_values(array_filter($r['rows'], static fn($p) => $p['provider'] === 'paystack'))[0];
    wh_eq($unit, (int) $card['expected_amount_subunit'], 'the card row asks only for what is left');
    $attempt = Database::one('SELECT requested_amount_subunit FROM payment_transactions WHERE payment_id = :p', [':p' => $card['id']]);
    wh_eq($unit, (int) ($attempt['requested_amount_subunit'] ?? 0), 'and Paystack is asked for exactly that');
    wh_eq(0, Wallet::balance($adaId), 'the wallet is empty');

    // 3. Unticked: the whole amount goes to the card and the wallet is untouched.
    $credit2 = Wallet::creditNow($adaId, $unit * 2, 'goodwill', 'Second fixture credit', "wh:$suffix:ada2", null, null);
    Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $adaId]);
    $fill($jarA, 1);
    [$code, , $result] = $place($jarA, $adaEmail, 'pay_in_full', []);
    $o3 = (int) ($result['order_id'] ?? 0); $track($o3);
    wh_eq(200, $code, 'an order with the wallet unticked is placed');
    wh_eq(0, (int) ($result['wallet_paid_subunit'] ?? -1), 'the wallet paid nothing');
    wh_ok(is_string($result['pay_url'] ?? null) && $result['pay_url'] !== '', 'the customer goes to Paystack for the full amount');
    wh_eq($unit * 2, Wallet::balance($adaId), 'and the wallet is exactly as it was');

    // 4. A deposit order: the wallet pays the deposit and the customer is done online.
    Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $adaId]);
    $fill($jarA, 1);
    [$code, , $result] = $place($jarA, $adaEmail, 'deposit', ['use_wallet' => '1']);
    $o4 = (int) ($result['order_id'] ?? 0); $track($o4);
    wh_eq(200, $code, 'a deposit order with the wallet ticked is placed');
    $deposit = Money::deposit($unit, Settings::depositPercentage());
    wh_eq($deposit, (int) ($result['wallet_paid_subunit'] ?? 0), 'the wallet pays the deposit');
    wh_ok(array_key_exists('pay_url', $result) && $result['pay_url'] === null, 'so there is no card payment for the deposit');

    // 5. Pay an order that already exists, from the sheet. While the card attempt
    //    checkout opened is still unresolved, the wallet is withheld and refused.
    $csrf = wh_csrf($jarA, $base);
    [$code, , $result] = wh_req($jarA, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $o3, 'okv_csrf' => $csrf]);
    wh_eq(422, $code, 'while a card attempt is in flight the wallet is refused');
    wh_eq('payment_in_progress', (string) ($result['code'] ?? ''), 'for that reason');
    wh_eq(0, count(array_filter($orderRow($o3)['rows'], static fn($p) => $p['provider'] === 'wallet')), 'and nothing is written');
    // The customer comes back from Paystack without paying: the attempt is resolved.
    Database::run("UPDATE payment_transactions SET status = 'abandoned' WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)", [':o' => $o3]);
    [, $orderPage] = wh_req($jarA, $base . '/account.php', null, false);
    wh_ok(str_contains($orderPage, 'from wallet'), 'once it is resolved the account list offers the wallet on the order');
    wh_ok(str_contains($orderPage, 'by card'), 'and words the card button as the alternative');
    $walletBefore = Wallet::balance($adaId);
    [$code, , $result] = wh_req($jarA, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $o3, 'okv_csrf' => $csrf]);
    wh_eq(200, $code, 'the wallet button pays the existing order');
    wh_eq('paid', (string) ($result['code'] ?? ''), 'in full');
    wh_eq($walletBefore - $unit, Wallet::balance($adaId), 'and the balance falls by the order');
    wh_eq('paid', (string) $orderRow($o3)['payment_status'], 'the order is paid');
    [$code, , $result] = wh_req($jarA, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $o3, 'okv_csrf' => $csrf]);
    wh_eq(422, $code, 'a second tap is refused');
    wh_eq('nothing_due', (string) ($result['code'] ?? ''), 'because nothing is left to pay');
    wh_eq($walletBefore - $unit, Wallet::balance($adaId), 'and spends nothing more');

    // 6. Nobody else can spend it, or spend against someone else's order.
    [$code] = wh_req($jarG, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $o3, 'okv_csrf' => 'x']);
    wh_eq(401, $code, 'a signed-out caller is turned away');
    [$code] = wh_req($jarA, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $o3]);
    wh_eq(419, $code, 'a post without the CSRF token is refused');
    $fillB = static function () use ($jarB, $fill): void { $fill($jarB, 1); };
    $fillB();
    [$code, , $result] = $place($jarB, $bolaEmail, 'pay_in_full', []);
    $ob = (int) ($result['order_id'] ?? 0); $track($ob);
    $csrfA = wh_csrf($jarA, $base);
    [$code] = wh_req($jarA, $base . '/api/v1/payments.php', ['action' => 'use_wallet', 'order_id' => $ob, 'okv_csrf' => $csrfA]);
    wh_eq(404, $code, 'Ada cannot spend her wallet on Bola\'s order');
    wh_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM payments WHERE order_id = :o AND provider = \'wallet\'', [':o' => $ob])['c'], 'and nothing is written on it');

    // ---- Goodwill credit by the Owner ---------------------------------------------------------
    wh_eq(200, $signIn($jarO, $ownerEmail, 'admin'), 'the Owner signs in');
    wh_eq(200, $signIn($jarS, $viewerEmail, 'admin'), 'a colleague who may only view wallets signs in');
    [$code, $admin] = wh_req($jarO, $base . '/admin/customers.php?customer=' . $bolaId, null, false);
    wh_eq(200, $code, 'the Owner opens a customer');
    wh_ok(str_contains($admin, 'customer-wallet-heading') && str_contains($admin, 'give_wallet_credit'), 'the profile shows the wallet and the goodwill form');
    [, $adminView] = wh_req($jarS, $base . '/admin/customers.php?customer=' . $bolaId, null, false);
    wh_ok(str_contains($adminView, 'customer-wallet-heading'), 'a colleague with wallet.view sees the wallet');
    wh_ok(!str_contains($adminView, 'give_wallet_credit'), 'but is not offered the goodwill form');

    $token = bin2hex(random_bytes(8));
    $csrfO = wh_csrf($jarO, $base);
    $give = ['action' => 'give_wallet_credit', 'customer_id' => $bolaId, 'amount' => '1500', 'reason' => 'Late delivery on Tuesday', 'credit_token' => $token, 'okv_csrf' => $csrfO];
    [$code, , $result] = wh_req($jarO, $base . '/api/v1/customers.php', $give);
    wh_eq(200, $code, 'the Owner gives goodwill credit');
    wh_eq(150000, Wallet::balance($bolaId), 'and it lands in the wallet');
    wh_ok((bool) preg_match('/^CN\d{5,}$/', (string) ($result['credit_note_number'] ?? '')), 'with a credit note number');
    [$code, , $result] = wh_req($jarO, $base . '/api/v1/customers.php', $give);
    wh_eq(200, $code, 'sending the same form again is not an error');
    wh_eq('already_credited', (string) ($result['code'] ?? ''), 'but it says it was already done');
    wh_eq(150000, Wallet::balance($bolaId), 'and credits nothing twice');
    $csrfS = wh_csrf($jarS, $base);
    [$code] = wh_req($jarS, $base . '/api/v1/customers.php', ['credit_token' => bin2hex(random_bytes(8)), 'okv_csrf' => $csrfS] + $give);
    wh_eq(403, $code, 'a colleague without wallet.credit is refused');
    foreach ([['amount' => '0'], ['amount' => '2000000'], ['reason' => 'short']] as $bad) {
        [$code] = wh_req($jarO, $base . '/api/v1/customers.php', array_merge($give, ['credit_token' => bin2hex(random_bytes(8))], $bad));
        wh_eq(422, $code, 'a bad ' . array_key_first($bad) . ' is refused');
    }
    wh_eq(150000, Wallet::balance($bolaId), 'and none of the refused ones credited anything');
    $mail = Database::one("SELECT COUNT(*) AS c FROM notifications n JOIN notification_deliveries d ON d.notification_id = n.id WHERE n.event_type = 'wallet_credited' AND n.related_type = 'credit_note' AND d.user_id = :u", [':u' => $bolaId]);
    wh_ok((int) ($mail['c'] ?? 0) >= 1, 'the customer was told, in the app and by email');
    [$code, $bolaWallet] = wh_req($jarB, $base . '/wallet.php', null, false);
    wh_ok($code === 200 && str_contains($bolaWallet, 'Late delivery on Tuesday') === false && str_contains($bolaWallet, 'Goodwill credit'), 'Bola sees the credit in her wallet');

    // The ledger of everyone touched still reconciles.
    foreach ([$adaId, $bolaId] as $uid) {
        wh_ok(Wallet::reconcile($uid)['ok'], "wallet $uid: the ledger agrees with the cached balance");
    }
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allBiz    = $businesses ? implode(',', array_map('intval', $businesses)) : '0';
    Database::run("DELETE nd FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE nd.user_id IN ($allUsers) OR (n.related_type = 'order' AND n.related_id IN ($allOrders))");
    Database::run("DELETE FROM notifications WHERE id NOT IN (SELECT notification_id FROM notification_deliveries) AND ((related_type = 'order' AND related_id IN ($allOrders)) OR (related_type = 'credit_note' AND related_id IN (SELECT id FROM credit_notes WHERE user_id IN ($allUsers))))");
    Database::run("DELETE FROM order_trail_share_links WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'order' AND entity_id IN ($allOrders)) OR (entity_type = 'wallet_entry' AND entity_id IN (SELECT id FROM wallet_entries WHERE user_id IN ($allUsers))) OR actor_user_id IN ($allUsers)");
    Database::run("DELETE FROM credit_notes WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_entries WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_accounts WHERE user_id IN ($allUsers)");
    Database::run("DELETE h FROM payment_status_history h JOIN payments p ON p.id = h.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE t FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE FROM payments WHERE order_id IN ($allOrders)");
    foreach (['order_items', 'order_status_history', 'order_addresses', 'delivery_schedules'] as $table) {
        Database::run("DELETE FROM $table WHERE order_id IN ($allOrders)");
    }
    Database::run("DELETE FROM orders WHERE id IN ($allOrders)");
    Database::run("DELETE ci FROM cart_items ci JOIN shopping_carts c ON c.id = ci.cart_id WHERE c.user_id IN ($allUsers)");
    Database::run("DELETE FROM shopping_carts WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM customer_addresses WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM credit_transactions WHERE business_customer_id IN ($allBiz)");
    Database::run("DELETE FROM business_customers WHERE id IN ($allBiz)");
    Database::run("DELETE FROM user_roles WHERE user_id IN ($allUsers)");
    foreach ($roles as $role) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $role]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $role]);
    }
    Database::run("DELETE FROM users WHERE id IN ($allUsers)");
    foreach ([$jarA, $jarB, $jarC, $jarO, $jarS, $jarG] as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
}

fwrite(STDOUT, "\n$passed / $tests wallet HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
