<?php
/**
 * scripts/tests/shortages_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR2b of the 23 Sep review, over the real routes.
 *
 * The database suite proves the money. This proves what people actually do: a
 * colleague at the sourcing bench marks a line out of stock (and a colleague
 * without the permission cannot); the customer opens the emailed link with no
 * account, chooses the wallet or their bank once however often they tap, and
 * cannot open someone else's; a guest is never offered a wallet; a colleague
 * who may pay refunds sees the whole account number, one who may not sees it
 * masked; a refund is paid once and the customer is told; and a customer asks
 * for their wallet back and a colleague cancels it.
 *
 *   php -S 127.0.0.1:8123 -t .
 *   php scripts/tests/shortages_http_test.php
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

function sht_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function sht_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    sht_ok($same, $label);
}

/** One request through the site, keeping the session in $jar. $json asks for JSON. */
function sht_req(string $jar, string $url, ?array $post = null, bool $json = true): array
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

function sht_csrf(string $jar, string $base): string
{
    [, $body] = sht_req($jar, $base . '/account.php', null, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'shortage-http-4417';
$users = []; $roles = []; $orders = []; $carts = [];
$jarA = tempnam(sys_get_temp_dir(), 'okv-sh-a-');   // Ada, who owns the orders
$jarB = tempnam(sys_get_temp_dir(), 'okv-sh-b-');   // Bola, another customer
$jarG = tempnam(sys_get_temp_dir(), 'okv-sh-g-');   // nobody signed in (the email link)
$jarS = tempnam(sys_get_temp_dir(), 'okv-sh-s-');   // staff who may mark an item short
$jarP = tempnam(sys_get_temp_dir(), 'okv-sh-p-');   // staff who may pay refunds
$jarV = tempnam(sys_get_temp_dir(), 'okv-sh-v-');   // staff who may only look

$makeUser = static function (string $first, string $type) use ($suffix, $password, &$users): array {
    $email = strtolower($first) . "-sh-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, \'Shortage\', :e, :p, :h, :t, \'active\', NOW())',
        [':f' => $first, ':e' => $email, ':p' => '+23478' . random_int(10000000, 99999999),
         ':h' => password_hash($password, PASSWORD_BCRYPT), ':t' => $type]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return [$id, $email];
};
$makeRole = static function (array $permissions) use ($suffix, &$roles): int {
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Shortage HTTP fixture\')', [':n' => 'shortage_http_' . $suffix . '_' . count($roles)]);
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roles[] = $id;
    foreach ($permissions as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE `key` = :p', [':r' => $id, ':p' => $permission]);
    }
    return $id;
};
$signIn = static function (string $jar, string $email, string $context) use ($base, $password): int {
    $csrf = sht_csrf($jar, $base);
    [$code] = sht_req($jar, $base . '/api/v1/auth.php', [
        'action' => 'login', 'context' => $context, 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ]);
    return $code;
};
$delivery = date('Y-m-d', strtotime('+4 days'));
/** An order written the way checkout writes it. $lines is a list of [name, unit, quantity, unit price in kobo]. */
$makeOrder = static function (?int $userId, array $lines, string $status = 'confirmed') use ($suffix, $delivery, &$orders, &$carts): array {
    $total = 0;
    foreach ($lines as $l) { $total += Money::lineTotal($l[2], $l[3]); }
    $cartId = null;
    if ($userId !== null) {
        Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:u, \'converted\')', [':u' => $userId]);
        $cartId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $carts[] = $cartId;
    }
    $number = 'SHH-' . strtoupper($suffix) . '-' . random_int(1000, 9999);
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, shopping_cart_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, created_by, contact_email)
         VALUES (:n, :u, :cart, \'household\', :st, \'pay_in_full\', \'unpaid\', :t, :t2, :t3, :dd, :u2, :em)',
        [':n' => $number, ':u' => $userId, ':cart' => $cartId, ':st' => $status, ':t' => $total, ':t2' => $total, ':t3' => $total,
         ':dd' => $delivery, ':u2' => $userId, ':em' => "guest-$suffix@example.test"]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[] = $id;
    $itemIds = [];
    foreach ($lines as $i => $l) {
        Database::run(
            'INSERT INTO order_items (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
             VALUES (:o, \'product\', :n, :sku, :u, :q, :p, :t)',
            [':o' => $id, ':n' => $l[0], ':sku' => "SHH-$suffix-$i", ':u' => $l[1], ':q' => $l[2], ':p' => $l[3], ':t' => Money::lineTotal($l[2], $l[3])]
        );
        $itemIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Checkout::writePayments($id, $userId, $number, 'pay_in_full', $total, $total, $delivery);
    $row = Database::one('SELECT id, expected_amount_subunit FROM payments WHERE order_id = :o ORDER BY id LIMIT 1', [':o' => $id]);
    Database::run('UPDATE payments SET paid_amount_subunit = expected_amount_subunit, status = \'paid\', confirmed_at = NOW() WHERE id = :id', [':id' => $row['id']]);
    Payments::recomputeOrder($id);
    return ['id' => $id, 'number' => $number, 'total' => $total, 'items' => $itemIds];
};
/** The link a customer was emailed for a shortage: the token is only ever in the message. */
$emailedToken = static function (int $shortageId): string {
    $row = Database::one("SELECT body, cta_url FROM notifications WHERE event_type = 'shortage_choose' AND related_type = 'order_shortage' AND related_id = :id ORDER BY id DESC LIMIT 1", [':id' => $shortageId]);
    return $row !== null && preg_match('/shortage\.php\?t=([0-9a-f]{48})/', (string) $row['body'] . ' ' . (string) $row['cta_url'], $m) ? $m[1] : '';
};

try {
    Database::run('DELETE FROM rate_limits');
    [$adaId, $adaEmail]       = $makeUser('Ada', 'household');
    [$bolaId, $bolaEmail]     = $makeUser('Bola', 'household');
    [$sourcerId, $sourcerEmail] = $makeUser('Sourcer', 'staff');
    [$payerId, $payerEmail]   = $makeUser('Payer', 'staff');
    [$viewerId, $viewerEmail] = $makeUser('Viewer', 'staff');
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $sourcerId, ':r' => $makeRole(['dashboard.view', 'orders.view', 'orders.shortage.record'])]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $payerId, ':r' => $makeRole(['dashboard.view', 'payments.view', 'payments.refund'])]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $viewerId, ':r' => $makeRole(['dashboard.view', 'orders.view', 'payments.view'])]);

    sht_eq(200, $signIn($jarA, $adaEmail, 'storefront'), 'Ada signs in');
    sht_eq(200, $signIn($jarB, $bolaEmail, 'storefront'), 'Bola signs in');
    sht_eq(200, $signIn($jarS, $sourcerEmail, 'admin'), 'a colleague who may mark items short signs in');
    sht_eq(200, $signIn($jarP, $payerEmail, 'admin'), 'a colleague who may pay refunds signs in');
    sht_eq(200, $signIn($jarV, $viewerEmail, 'admin'), 'a colleague who may only look signs in');

    $adaOrder   = $makeOrder($adaId, [['Tomatoes', 'kg', '4.000', 200000], ['Onions', 'kg', '2.000', 150000], ['Peppers', 'kg', '1.000', 90000]]);
    $guestOrder = $makeOrder(null, [['Plantain', 'bunch', '3.000', 100000]]);
    $packed     = $makeOrder($adaId, [['Yams', 'tuber', '2.000', 100000]], 'packed');
    sht_ok($adaOrder['id'] > 0 && $guestOrder['id'] > 0, 'setup: a signed-in order and a guest order, both paid');

    // ---- 1. Marking a line short ------------------------------------------------------------------
    $csrfS = sht_csrf($jarS, $base);
    $record = static fn(int $order, int $item, string $quantity, array $more = []) => ['action' => 'record', 'order_id' => $order, 'item_id' => $item, 'quantity' => $quantity, 'reason' => 'Short at the market'] + $more;

    [$code] = sht_req($jarS, $base . '/api/v1/shortages.php?action=record');
    sht_eq(405, $code, 'a GET is refused');
    [$code] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $adaOrder['items'][0], '1'));
    sht_eq(419, $code, 'a post without the CSRF token is refused');
    $csrfV = sht_csrf($jarV, $base);
    [$code] = sht_req($jarV, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $adaOrder['items'][0], '1') + ['okv_csrf' => $csrfV]);
    sht_eq(403, $code, 'a colleague without orders.shortage.record is refused');
    [$code] = sht_req($jarG, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $adaOrder['items'][0], '1') + ['okv_csrf' => sht_csrf($jarG, $base)]);
    sht_ok(in_array($code, [401, 403], true), 'someone signed out is refused');
    sht_eq(3, (int) Database::one('SELECT COUNT(*) AS c FROM order_items WHERE order_id = :o AND quantity > 0 AND line_total_subunit > 0', [':o' => $adaOrder['id']])['c'], 'and none of those refusals touched the order');
    sht_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM order_shortages WHERE order_id = :o', [':o' => $adaOrder['id']])['c'], 'nor wrote a shortage');

    [$code, , $result] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($packed['id'], $packed['items'][0], '1') + ['okv_csrf' => $csrfS]);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'not_sourcing', 'a packed order cannot be marked short');
    [$code, , $result] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $adaOrder['items'][0], '9') + ['okv_csrf' => $csrfS]);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'bad_quantity', 'more than the line holds is refused');
    [$code, , $result] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $guestOrder['items'][0], '1') + ['okv_csrf' => $csrfS]);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'not_found', 'a line of another order is not found');

    [$code, , $result] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($adaOrder['id'], $adaOrder['items'][0], '1') + ['okv_csrf' => $csrfS]);
    sht_eq(200, $code, 'a colleague marks one kg of tomatoes short');
    sht_eq('recorded_choice', (string) ($result['code'] ?? ''), 'a paid order now waits for the customer to choose');
    sht_eq(200000, (int) ($result['refund_due_subunit'] ?? 0), 'one kg is worth 2,000 naira');
    sht_ok(!array_key_exists('token', $result), 'the response never carries the customer\'s link token');
    $s1 = (int) $result['shortage_id'];
    $token1 = $emailedToken($s1);
    sht_ok($token1 !== '', 'the customer was emailed a link carrying the token');
    $channels = array_column(Database::all("SELECT d.channel FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.event_type = 'shortage_choose' AND n.related_id = :s AND d.user_id = :u", [':s' => $s1, ':u' => $adaId]), 'channel');
    sht_ok(in_array('email', $channels, true) && count($channels) === count(array_unique($channels)), 'and it is in Ada\'s notifications, once on each channel, email included');

    [, $adminOrder] = sht_req($jarS, $base . '/admin/orders.php?order=' . $adaOrder['id'], null, false);
    sht_ok(str_contains($adminOrder, 'id="shortages"') && str_contains($adminOrder, 'Add to their wallet'), 'the order screen shows the shortage and offers to choose for the customer');
    sht_ok(str_contains($adminOrder, 'Mark out of stock'), 'and the sourcing bench has the Mark out of stock form on each line');
    [, $viewerOrder] = sht_req($jarV, $base . '/admin/orders.php?order=' . $adaOrder['id'], null, false);
    sht_ok(!str_contains($viewerOrder, 'name="action" value="record"') && !str_contains($viewerOrder, 'Add to their wallet'), 'a colleague without the permission is offered neither');

    // ---- 2. The customer's page, from the emailed link ----------------------------------------------
    [$code, $page] = sht_req($jarG, $base . '/public/shortage.php?t=' . $token1, null, false);
    sht_eq(200, $code, 'the link opens with no account');
    sht_ok(str_contains($page, '1 kg Tomatoes') && str_contains($page, Money::format(200000)), 'it names the item and the amount');
    sht_ok(str_contains($page, 'value="wallet"') && str_contains($page, 'value="bank"'), 'it offers the wallet and the bank');
    sht_ok(str_contains($page, 'Nothing happens until you choose'), 'and says nothing is decided for them');
    sht_ok(str_contains($page, 'noindex'), 'and is kept out of search');
    [$code, $wrong] = sht_req($jarG, $base . '/public/shortage.php?t=' . str_repeat('0', 48), null, false);
    sht_ok($code === 200 && str_contains($wrong, 'We could not open this link') && !str_contains($wrong, 'Tomatoes'), 'a wrong token opens nothing');
    [$code, , , $where] = sht_req($jarG, $base . '/public/shortage.php?id=' . $s1, null, false);
    sht_ok($code === 302 && str_contains($where, '/account.php'), 'the owner route sends someone signed out to sign in');
    [, $bolaPage] = sht_req($jarB, $base . '/public/shortage.php?id=' . $s1, null, false);
    sht_ok(str_contains($bolaPage, 'We could not open this link') && !str_contains($bolaPage, 'Tomatoes'), 'another customer cannot open it by id');

    $decide = static fn(string $key, string $value, array $more) => ['action' => 'decide', $key => $value] + $more;
    $csrfG = sht_csrf($jarG, $base);
    [$code] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'wallet']));
    sht_eq(419, $code, 'a choice without the CSRF token is refused');
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'cash', 'okv_csrf' => $csrfG]));
    sht_ok($code === 422 && ($result['code'] ?? '') === 'bad_choice', 'only the wallet or the bank can be chosen');
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'bank', 'bank_name' => 'GTBank', 'account_number' => '12', 'account_name' => 'Ada Obi', 'okv_csrf' => $csrfG]));
    sht_ok($code === 422 && ($result['code'] ?? '') === 'bad_bank', 'a bank refund with a bad account number is refused');
    sht_eq('awaiting_choice', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $s1])['status'], 'and the shortage still waits for a good choice');
    [$code, $html] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'bank', 'bank_name' => 'GTBank', 'account_number' => '12', 'account_name' => 'Ada Obi', 'okv_csrf' => $csrfG]), false);
    sht_eq(303, $code, 'without JavaScript the same mistake redirects back to the page');
    [$code, $retry] = sht_req($jarG, $base . '/public/shortage.php?t=' . $token1 . '&error=bad_bank&choice=bank', null, false);
    sht_ok($code === 200 && str_contains($retry, 'Check your bank details') && preg_match('/<details[^>]*open/', $retry) === 1, 'the page says what was wrong and leaves the bank form open');
    [$code] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', 'garbage', ['choice' => 'wallet', 'okv_csrf' => $csrfG]));
    sht_eq(404, $code, 'a made up token is not found');

    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'wallet', 'okv_csrf' => $csrfG]));
    sht_eq(200, $code, 'the customer chooses the wallet from the email link');
    sht_eq('decided', (string) ($result['code'] ?? ''), 'it is decided');
    sht_eq(200000, Wallet::balance($adaId), 'and the money is in Ada\'s wallet');
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'wallet', 'okv_csrf' => $csrfG]));
    sht_ok($code === 200 && ($result['code'] ?? '') === 'already_decided', 'tapping again is recognised');
    sht_eq(200000, Wallet::balance($adaId), 'and credits nothing twice');
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $token1, ['choice' => 'bank', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi', 'okv_csrf' => $csrfG]));
    sht_ok($code === 200 && ($result['code'] ?? '') === 'already_decided', 'a different choice afterwards changes nothing');
    sht_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM manual_refunds WHERE order_id = :o', [':o' => $adaOrder['id']])['c'], 'and queues no refund');
    sht_eq(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'shortage_choice_received' AND related_id = :s", [':s' => $s1])['c'], 'the customer was told once that their choice is in');
    [, $done] = sht_req($jarG, $base . '/public/shortage.php?t=' . $token1, null, false);
    sht_ok(str_contains($done, 'Added to your wallet') && !str_contains($done, 'name="choice"'), 'the page now says so and offers no buttons');

    // ---- 3. The customer's order page ------------------------------------------------------------------
    [, $orderPage] = sht_req($jarA, $base . '/public/order.php?order=' . $adaOrder['id'], null, false);
    sht_ok(str_contains($orderPage, '1 kg Tomatoes') && str_contains($orderPage, 'The money is in your wallet'), 'the order page says the tomatoes were out of stock and where the money went');
    $rOnions = Shortages::record($adaOrder['id'], $adaOrder['items'][1], 'all', '', $sourcerId);
    sht_ok($rOnions['ok'], 'setup: the onions go short too');
    [, $orderPage] = sht_req($jarA, $base . '/public/order.php?order=' . $adaOrder['id'], null, false);
    sht_ok(str_contains($orderPage, '/public/shortage.php?id=' . $rOnions['shortage_id']) && str_contains($orderPage, 'Choose where it goes'), 'a shortage waiting for a choice has a Choose button on the order page');
    [, $invoice] = sht_req($jarA, $base . '/public/documents/invoice.php?order=' . $adaOrder['id'], null, false);
    sht_ok(str_contains($invoice, 'Tomatoes') && !str_contains($invoice, 'Onions'), 'the invoice leaves out a line that is wholly out of stock');
    [, $receipt] = sht_req($jarA, $base . '/public/documents/receipt.php?order=' . $adaOrder['id'], null, false);
    sht_ok(str_contains($receipt, 'Refunded') && str_contains($receipt, Money::format(200000, true)), 'and the receipt shows what went back to the wallet');

    // The signed-in owner chooses by id, with no token at all.
    $csrfA = sht_csrf($jarA, $base);
    [$code, , $result] = sht_req($jarA, $base . '/api/v1/shortages.php', ['action' => 'decide', 'id' => $rOnions['shortage_id'], 'choice' => 'bank', 'bank_name' => 'First Bank', 'account_number' => '0123 456 789', 'account_name' => 'Ada Obi', 'okv_csrf' => $csrfA]);
    sht_eq(200, $code, 'the signed-in owner chooses a bank refund from her order');
    $refund = Database::one('SELECT * FROM manual_refunds WHERE shortage_id = :s', [':s' => $rOnions['shortage_id']]);
    sht_ok($refund !== null && (string) $refund['status'] === 'requested', 'a refund is waiting to be sent');
    sht_eq('0123456789', (string) ($refund['account_number'] ?? ''), 'with the account number cleaned to ten digits');
    sht_eq(300000, (int) ($refund['amount_subunit'] ?? 0), 'for what the onions were worth');
    [$code] = sht_req($jarB, $base . '/api/v1/shortages.php', ['action' => 'decide', 'id' => $rOnions['shortage_id'], 'choice' => 'wallet', 'okv_csrf' => sht_csrf($jarB, $base)]);
    sht_eq(404, $code, 'Bola cannot decide for Ada');

    // ---- 4. A guest has no wallet to put it in --------------------------------------------------------------
    [$code, , $result] = sht_req($jarS, $base . '/api/v1/shortages.php', $record($guestOrder['id'], $guestOrder['items'][0], '1') + ['okv_csrf' => $csrfS]);
    sht_eq(200, $code, 'a colleague marks a guest order\'s plantain short');
    $sg = (int) $result['shortage_id'];
    $tokenG = $emailedToken($sg);
    sht_ok($tokenG !== '', 'the guest is emailed the link');
    [, $guestPage] = sht_req($jarG, $base . '/public/shortage.php?t=' . $tokenG, null, false);
    sht_ok(!str_contains($guestPage, 'value="wallet"') && str_contains($guestPage, 'value="bank"'), 'the guest page offers only the bank');
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $tokenG, ['choice' => 'wallet', 'okv_csrf' => $csrfG]));
    sht_ok($code === 422 && ($result['code'] ?? '') === 'no_account', 'and a wallet choice is refused with a plain reason');

    // ---- 5. The refund queue ---------------------------------------------------------------------------------
    [$code, , $result] = sht_req($jarG, $base . '/api/v1/shortages.php', $decide('t', $tokenG, ['choice' => 'bank', 'bank_name' => 'Zenith Bank', 'account_number' => '2233445566', 'account_name' => 'Guest Person', 'okv_csrf' => $csrfG]));
    sht_eq(200, $code, 'the guest chooses a bank refund');
    $guestRefund = Database::one('SELECT * FROM manual_refunds WHERE shortage_id = :s', [':s' => $sg]);
    sht_ok($guestRefund !== null, 'and a refund is queued');
    $payerChannels = array_column(Database::all("SELECT d.channel FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.event_type = 'admin_manual_refund_requested' AND n.related_id = :r AND d.user_id = :u", [':r' => $guestRefund['id'], ':u' => $payerId]), 'channel');
    sht_ok($payerChannels !== [] && count($payerChannels) === count(array_unique($payerChannels)), 'a colleague who may pay refunds is told there is one to pay, once per channel');

    [$code, $payPage] = sht_req($jarP, $base . '/admin/payments.php', null, false);
    sht_eq(200, $code, 'the payer opens the Payments screen');
    sht_ok(str_contains($payPage, 'id="refunds-heading"') && str_contains($payPage, 'Refunds to pay by hand'), 'it has the Refunds to pay panel');
    sht_ok(str_contains($payPage, '2233445566') && str_contains($payPage, 'Zenith Bank') && str_contains($payPage, 'Guest Person'), 'the payer sees the whole account');
    sht_ok(str_contains($payPage, 'name="action" value="mark_refund_paid"') && str_contains($payPage, 'name="action" value="cancel_manual_refund"'), 'and can mark it paid or cancel it');
    [, $viewPage] = sht_req($jarV, $base . '/admin/payments.php', null, false);
    sht_ok(str_contains($viewPage, 'Refunds to pay by hand') && !str_contains($viewPage, '2233445566') && str_contains($viewPage, '******5566'), 'a colleague who may only look sees the queue with the number masked');
    sht_ok(!str_contains($viewPage, 'name="action" value="mark_refund_paid"'), 'and cannot pay');

    $csrfP = sht_csrf($jarP, $base);
    $pay = ['action' => 'mark_refund_paid', 'refund_id' => $guestRefund['id'], 'reference' => 'TRF-770011', 'confirmed' => '1', 'okv_csrf' => $csrfP];
    [$code] = sht_req($jarV, $base . '/api/v1/payments.php', ['okv_csrf' => sht_csrf($jarV, $base)] + $pay);
    sht_eq(403, $code, 'a colleague without payments.refund cannot mark it paid');
    [$code] = sht_req($jarP, $base . '/api/v1/payments.php', array_diff_key($pay, ['okv_csrf' => 1]));
    sht_eq(419, $code, 'a post without the CSRF token is refused');
    [$code, , $result] = sht_req($jarP, $base . '/api/v1/payments.php', array_diff_key($pay, ['confirmed' => 1]));
    sht_ok($code === 422 && ($result['code'] ?? '') === 'not_confirmed', 'it needs the confirmation tick, enforced on the server');
    [$code, , $result] = sht_req($jarP, $base . '/api/v1/payments.php', ['reference' => 'x'] + $pay);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'reference_required', 'it needs a real bank reference');
    sht_eq('requested', (string) Database::one('SELECT status FROM manual_refunds WHERE id = :id', [':id' => $guestRefund['id']])['status'], 'and none of those refusals paid it');
    [$code] = sht_req($jarP, $base . '/api/v1/payments.php', $pay);
    sht_eq(200, $code, 'the payer marks it paid');
    $paid = Database::one('SELECT * FROM manual_refunds WHERE id = :id', [':id' => $guestRefund['id']]);
    sht_eq('paid', (string) $paid['status'], 'it is paid');
    sht_eq('TRF-770011', (string) $paid['payment_reference'], 'with the bank reference');
    sht_eq($payerId, (int) $paid['paid_by'], 'and who paid it');
    sht_eq('settled', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $sg])['status'], 'the shortage is settled');
    sht_eq(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'manual_refund_paid' AND related_id = :r", [':r' => $guestRefund['id']])['c'], 'the customer was told it has been sent');
    [$code, , $result] = sht_req($jarP, $base . '/api/v1/payments.php', $pay);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'not_requested', 'paying it a second time is refused');
    sht_eq(1, (int) Database::one("SELECT COUNT(*) AS c FROM notifications WHERE event_type = 'manual_refund_paid' AND related_id = :r", [':r' => $guestRefund['id']])['c'], 'and says nothing more');

    // ---- 6. A customer asks for their wallet back ---------------------------------------------------------------
    $walletNow = Wallet::balance($adaId);
    sht_eq(200000, $walletNow, 'setup: Ada\'s wallet holds 2,000 naira from the tomatoes');
    [, $walletPage] = sht_req($jarA, $base . '/wallet.php', null, false);
    sht_ok(str_contains($walletPage, 'name="action" value="request_cashout"') && str_contains($walletPage, 'name="cashout_token"'), 'her wallet offers to give it back, with a token for double taps');
    $token = bin2hex(random_bytes(8));
    $ask = ['action' => 'request_cashout', 'amount_mode' => 'part', 'amount' => '500', 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi', 'cashout_token' => $token, 'okv_csrf' => $csrfA];
    [$code] = sht_req($jarG, $base . '/api/v1/payments.php', ['okv_csrf' => $csrfG] + $ask);
    sht_eq(401, $code, 'someone signed out cannot ask');
    [$code] = sht_req($jarA, $base . '/api/v1/payments.php', array_diff_key($ask, ['okv_csrf' => 1]));
    sht_eq(419, $code, 'a request without the CSRF token is refused');
    [$code, , $result] = sht_req($jarA, $base . '/api/v1/payments.php', ['account_number' => '12'] + $ask);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'bad_bank', 'a bad account number is refused');
    [$code, , $result] = sht_req($jarA, $base . '/api/v1/payments.php', ['amount' => '99999'] + $ask);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'insufficient_balance', 'more than the wallet holds is refused');
    sht_eq($walletNow, Wallet::balance($adaId), 'and neither took anything');
    [$code, , $result] = sht_req($jarA, $base . '/api/v1/payments.php', $ask);
    sht_eq(200, $code, 'part of the wallet is asked for');
    sht_eq($walletNow - 50000, Wallet::balance($adaId), 'it leaves the wallet at once');
    [$code, , $result] = sht_req($jarA, $base . '/api/v1/payments.php', $ask);
    sht_eq(200, $code, 'a double tap is not an error');
    sht_eq($walletNow - 50000, Wallet::balance($adaId), 'but takes nothing twice');
    $cash = Database::one("SELECT * FROM manual_refunds WHERE user_id = :u AND kind = 'wallet_cashout'", [':u' => $adaId]);
    sht_ok($cash !== null && (int) $cash['amount_subunit'] === 50000, 'one cash out of 500 naira is waiting');
    [$code, $redirect] = sht_req($jarA, $base . '/api/v1/payments.php', ['cashout_token' => bin2hex(random_bytes(8))] + $ask, false);
    sht_eq(303, $code, 'without JavaScript the form redirects back to the wallet');
    sht_eq($walletNow - 100000, Wallet::balance($adaId), 'the second request took its own 500 naira');
    $second = Database::one("SELECT id FROM manual_refunds WHERE user_id = :u AND kind = 'wallet_cashout' AND id <> :id", [':u' => $adaId, ':id' => $cash['id']]);
    sht_ok(ManualRefunds::cancel((int) $second['id'], 'Fixture clean up of the second request', $payerId)['ok'], 'setup: the second request is cancelled and its money comes back');
    sht_eq($walletNow - 50000, Wallet::balance($adaId), 'leaving only the first one out of the wallet');

    [, $payPage] = sht_req($jarP, $base . '/admin/payments.php', null, false);
    sht_ok(str_contains($payPage, 'Wallet cash out') && str_contains($payPage, 'The money goes back into the customer'), 'the payer sees the cash out in the queue');
    [$code, , $result] = sht_req($jarP, $base . '/api/v1/payments.php', ['action' => 'cancel_manual_refund', 'refund_id' => $cash['id'], 'reason' => 'no', 'okv_csrf' => $csrfP]);
    sht_ok($code === 422 && ($result['code'] ?? '') === 'reason_required', 'cancelling needs a reason');
    [$code] = sht_req($jarP, $base . '/api/v1/payments.php', ['action' => 'cancel_manual_refund', 'refund_id' => $cash['id'], 'reason' => 'The account number does not match the name', 'okv_csrf' => $csrfP]);
    sht_eq(200, $code, 'the payer cancels it');
    sht_eq($walletNow, Wallet::balance($adaId), 'the money is back in her wallet');
    sht_ok(Wallet::reconcile($adaId)['ok'], 'and her ledger still agrees with the cached balance');

    // The page reads the same to the customer.
    [, $walletPage] = sht_req($jarA, $base . '/wallet.php', null, false);
    sht_ok(!str_contains($walletPage, '0123456789'), 'her wallet page never prints the whole account number');
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allCarts  = $carts ? implode(',', array_map('intval', $carts)) : '0';
    Database::run("DELETE nd FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.related_type IN ('order', 'order_shortage', 'manual_refund', 'credit_note') AND (n.related_id IN ($allOrders) OR nd.user_id IN ($allUsers) OR n.related_id IN (SELECT id FROM order_shortages WHERE order_id IN ($allOrders)) OR n.related_id IN (SELECT id FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers)))");
    Database::run("DELETE FROM notifications WHERE id NOT IN (SELECT notification_id FROM notification_deliveries) AND related_type IN ('order', 'order_shortage', 'manual_refund', 'credit_note')
                     AND (related_id IN ($allOrders) OR related_id IN (SELECT id FROM order_shortages WHERE order_id IN ($allOrders)) OR related_id IN (SELECT id FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers)))");
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'order' AND entity_id IN ($allOrders)) OR (entity_type = 'manual_refund' AND entity_id IN (SELECT id FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers))) OR (entity_type = 'wallet_entry' AND entity_id IN (SELECT id FROM wallet_entries WHERE user_id IN ($allUsers))) OR actor_user_id IN ($allUsers)");
    Database::run("DELETE FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers)");
    Database::run("DELETE FROM order_shortages WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM credit_notes WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_entries WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_accounts WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM order_trail_share_links WHERE order_id IN ($allOrders)");
    Database::run("DELETE h FROM payment_status_history h JOIN payments p ON p.id = h.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE t FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE FROM payments WHERE order_id IN ($allOrders)");
    foreach (['order_items', 'order_status_history', 'order_addresses', 'delivery_schedules'] as $table) {
        Database::run("DELETE FROM $table WHERE order_id IN ($allOrders)");
    }
    Database::run("DELETE FROM orders WHERE id IN ($allOrders)");
    Database::run("DELETE FROM shopping_carts WHERE id IN ($allCarts)");
    Database::run("DELETE FROM user_roles WHERE user_id IN ($allUsers)");
    foreach ($roles as $role) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $role]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $role]);
    }
    Database::run("DELETE FROM users WHERE id IN ($allUsers)");
    foreach ([$jarA, $jarB, $jarG, $jarS, $jarP, $jarV] as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
}

fwrite(STDOUT, "\n$passed / $tests shortage HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
