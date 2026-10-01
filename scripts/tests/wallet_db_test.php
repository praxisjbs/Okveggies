<?php
/**
 * scripts/tests/wallet_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR2 of the 23 Sep review. The customer wallet and its credit
 * notes, against a real MySQL 8 database.
 *
 * What only a database shows: that a credit writes one ledger row and one credit
 * note and never two, however many times it is retried; that the balance can
 * never go below zero; that a wallet payment lands as an ordinary paid payment
 * row and the row still owed shrinks by the same amount, so a card charge for
 * the remainder later adds up to the order total; that a card attempt in flight
 * blocks a wallet payment; that a repayment on the credit line settles the
 * ledger exactly as a card repayment does; and that the ledger always agrees
 * with the cached balance.
 *
 *   php scripts/tests/wallet_db_test.php
 *
 * Creates its own users, businesses and orders, asserts, then removes them. Run it
 * after php scripts/migrate.php on a scratch database.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$GLOBALS['wl_t'] = 0; $GLOBALS['wl_p'] = 0;
function wl_ok($cond, string $label): void {
    $GLOBALS['wl_t']++;
    if ($cond) { $GLOBALS['wl_p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function wl_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    wl_ok($same, $label);
}

$pdo      = Database::getInstance()->getConnection();
$suffix   = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$delivery = date('Y-m-d', strtotime('+4 days'));
$users = []; $businesses = []; $orders = []; $carts = [];

$makeUser = function (string $name, string $type) use ($suffix, &$users): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :p, :h, :t, :s)',
        [':f' => $name, ':l' => 'Wallet', ':e' => strtolower($name) . "-$suffix@example.test",
         ':p' => '+23470' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':t' => $type, ':s' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return $id;
};
$makeBusiness = function (string $name, int $limit, int $days) use ($suffix, $makeUser, &$businesses): array {
    $userId = $makeUser($name, 'business');
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, :c, 1, \'approved\', :d, :l)',
        [':u' => $userId, ':n' => "$name $suffix", ':c' => $name, ':d' => $days, ':l' => $limit]
    );
    $businessId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $businesses[] = $businessId;
    return ['user_id' => $userId, 'business_id' => $businessId];
};
$makeOrder = function (int $userId, string $option, int $total, string $type = 'household') use ($suffix, $delivery, &$orders, &$carts): array {
    Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:u, \'converted\')', [':u' => $userId]);
    $cartId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $carts[] = $cartId;
    $pct     = 30.0;
    $deposit = $option === 'deposit' ? Money::deposit($total, $pct) : null;
    $number  = 'WLT-' . $suffix . '-' . random_int(1000, 9999);
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, shopping_cart_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_percentage, deposit_required_subunit,
             balance_due_subunit, preferred_delivery_date, created_by)
         VALUES (:n, :u, :cart, :ct, \'pending\', :po, \'unpaid\', :t, :t2, :pct, :dep, :t3, :dd, :u2)',
        [':n' => $number, ':u' => $userId, ':cart' => $cartId, ':ct' => $type, ':po' => $option, ':t' => $total,
         ':t2' => $total, ':pct' => $deposit === null ? null : $pct, ':dep' => $deposit, ':t3' => $total,
         ':dd' => $delivery, ':u2' => $userId]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[] = $id;
    Checkout::writePayments($id, $userId, $number, $option, $total, Checkout::amountDue($option, $total, $pct), $delivery);
    return ['id' => $id, 'number' => $number];
};
/** Give a wallet money the only way money gets in: a credit, in its own transaction. */
$fund = function (int $userId, int $amount, string $key) {
    $r = Wallet::creditNow($userId, $amount, 'goodwill', 'Test credit', $key, null, null);
    return $r;
};
$order   = static fn(int $id): array => Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
$rows    = static fn(int $id): array => Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $id]);
$byProv  = static fn(int $id, string $p): array => array_values(array_filter(Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $id]), static fn($r) => $r['provider'] === $p));
$entries = static fn(int $userId): int => (int) Database::one('SELECT COUNT(*) AS c FROM wallet_entries WHERE user_id = :u', [':u' => $userId])['c'];
$notes   = static fn(int $userId): int => (int) Database::one('SELECT COUNT(*) AS c FROM credit_notes WHERE user_id = :u', [':u' => $userId])['c'];
/** A verified Paystack payload for a reference, as the callback sees it. */
$verified = static fn(string $ref, int $amount): array => [
    'id' => random_int(100000, 999999), 'domain' => Paystack::domain(), 'status' => 'success', 'reference' => $ref,
    'amount' => $amount, 'requested_amount' => $amount, 'fees' => 0, 'currency' => 'NGN', 'channel' => 'card',
    'paid_at' => date('c'), 'metadata' => '', 'authorization' => [], 'customer' => [],
];
$cardCharge = static function (array $payment, string $number, int $attempt, int $amount) use ($verified): array {
    $ref = Payments::reference($number, $attempt);
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, :a, \'paystack\', :ref, :domain, \'initialized\', :amt, \'NGN\', \'w@example.test\')',
        [':pid' => $payment['id'], ':a' => $attempt, ':ref' => $ref, ':domain' => Paystack::domain(), ':amt' => $amount]
    );
    return Payments::applyVerifiedCharge($ref, $verified($ref, $amount), 'callback', null);
};

try {
    $staff = $makeUser('Staff', 'staff');
    $ada   = $makeUser('Ada', 'household');
    $bola  = $makeUser('Bola', 'household');

    // =========================================================================
    // 1. Credit: one ledger row, one credit note, once.
    // =========================================================================
    wl_eq(0, Wallet::balance($ada), 'a customer with no wallet has a zero balance');
    $c1 = $fund($ada, 500000, "test:$suffix:c1");
    wl_ok($c1['ok'] && !$c1['already'], 'a credit is accepted');
    wl_eq(500000, Wallet::balance($ada), 'the balance holds the credit');
    wl_eq(1, $entries($ada), 'one ledger row was written');
    wl_eq(1, $notes($ada), 'and one credit note');
    wl_ok((bool) preg_match('/^CN\d{2}\d{3,}$/', (string) $c1['credit_note_number']), 'the credit note number is CN, the year and a sequence');
    $note = Database::one('SELECT * FROM credit_notes WHERE id = :id', [':id' => $c1['credit_note_id']]);
    wl_eq(500000, (int) $note['amount_subunit'], 'the credit note carries the amount');
    wl_eq('goodwill', (string) $note['reason_code'], 'and the reason');
    wl_eq((int) $c1['entry_id'], (int) $note['wallet_entry_id'], 'and points at its ledger row');

    $replay = $fund($ada, 500000, "test:$suffix:c1");
    wl_ok($replay['ok'] && $replay['already'], 'the same key again is recognised');
    wl_eq((int) $c1['credit_note_id'], (int) $replay['credit_note_id'], 'and answers with the first credit note');
    wl_eq(500000, Wallet::balance($ada), 'the balance does not move on a replay');
    wl_eq(1, $entries($ada), 'no second ledger row');
    wl_eq(1, $notes($ada), 'and no second credit note');

    $c2 = $fund($ada, 250000, "test:$suffix:c2");
    wl_ok($c2['credit_note_number'] !== $c1['credit_note_number'], 'a second credit gets its own number');
    wl_eq(750000, Wallet::balance($ada), 'and the balance adds up');

    $bad = Wallet::creditNow($ada, 0, 'goodwill', 'x', "test:$suffix:bad0", null, null);
    wl_ok(!$bad['ok'], 'a zero credit is refused');
    $bad = Wallet::creditNow($ada, 100, 'not_a_reason', 'x', "test:$suffix:bad1", null, null);
    wl_ok(!$bad['ok'], 'an unknown reason is refused');
    $bad = Wallet::creditNow($ada, 100, 'goodwill', 'x', '', null, null);
    wl_ok(!$bad['ok'], 'an empty key is refused');
    $bad = Wallet::creditNow(987654321, 100, 'goodwill', 'x', "test:$suffix:nouser", null, null);
    wl_ok(!$bad['ok'], 'a credit for a user that does not exist is refused');
    wl_eq(750000, Wallet::balance($ada), 'refused credits move nothing');

    $threw = false;
    try { Wallet::credit($ada, 100, 'goodwill', 'x', "test:$suffix:notxn", null, null); } catch (LogicException $e) { $threw = true; }
    wl_ok($threw, 'a credit outside a transaction is refused loudly');

    wl_ok(Wallet::reconcile($ada)['ok'], 'the ledger agrees with the cached balance');

    // The database refuses a negative balance on its own, whatever the code does.
    $refused = false;
    try { Database::run('UPDATE wallet_accounts SET balance_subunit = balance_subunit - 99999999999 WHERE user_id = :u', [':u' => $ada]); }
    catch (PDOException $e) { $refused = true; }
    wl_ok($refused, 'the database itself refuses a negative balance');
    wl_eq(750000, Wallet::balance($ada), 'and the balance is untouched');

    // =========================================================================
    // 2. A wallet that covers the whole order pays it and replaces the card row.
    // =========================================================================
    $a = $makeOrder($ada, 'pay_in_full', 600000);
    $prev = Wallet::preview($a['id'], 'pay_in_full', $ada);
    wl_ok($prev !== null && $prev['covers_all'] && $prev['apply_subunit'] === 600000, 'the preview says the wallet covers it');
    $res = Wallet::payOrder($a['id'], $ada, $ada);
    wl_ok($res['ok'] && $res['code'] === 'paid', 'paying from the wallet succeeds');
    wl_eq(600000, $res['amount_subunit'], 'for the whole amount');
    wl_eq(150000, Wallet::balance($ada), 'the balance drops by what was spent');
    $o = $order($a['id']);
    wl_eq(600000, (int) $o['amount_paid_subunit'], 'the order records the money as received');
    wl_eq('paid', (string) $o['payment_status'], 'and reads as paid');
    wl_eq(0, (int) $o['balance_due_subunit'], 'with nothing due');
    $w = $byProv($a['id'], 'wallet');
    wl_eq(1, count($w), 'one wallet payment row');
    wl_eq('paid', (string) $w[0]['status'], 'that is paid');
    wl_eq(600000, (int) $w[0]['paid_amount_subunit'], 'for the amount');
    $card = $byProv($a['id'], 'paystack');
    wl_eq('void', (string) $card[0]['status'], 'the card row nothing is owed on is voided, not deleted');
    wl_eq(0, (int) $card[0]['expected_amount_subunit'], 'and expects nothing');
    wl_eq(600000, array_sum(array_map(static fn($r) => (int) $r['expected_amount_subunit'], $rows($a['id']))), 'the rows still add up to the order total');
    $txn = Database::one('SELECT * FROM payment_transactions WHERE payment_id = :p', [':p' => $w[0]['id']]);
    wl_eq('wallet', (string) $txn['provider'], 'the transaction records the wallet as the provider');
    wl_eq('success', (string) $txn['status'], 'as a success');
    $spend = Database::one('SELECT * FROM wallet_entries WHERE user_id = :u AND entry_type = \'spend\'', [':u' => $ada]);
    wl_eq(-600000, (int) $spend['amount_subunit'], 'the ledger row is negative');
    wl_eq(150000, (int) $spend['balance_after_subunit'], 'and carries the balance after it');
    wl_eq((int) $txn['id'], (int) $spend['payment_transaction_id'], 'and points at the transaction');
    wl_eq((int) $a['id'], (int) $spend['order_id'], 'and at the order');
    wl_ok(Wallet::reconcile($ada)['ok'], 'the ledger still agrees with the cached balance');
    wl_ok(count(Database::all('SELECT id FROM payment_status_history WHERE payment_id IN (' . (int) $w[0]['id'] . ',' . (int) $card[0]['id'] . ')')) >= 2, 'both rows have history');
    wl_ok(SourcingGate::evaluate($order($a['id']), ['exempt' => '', 'credit' => null, 'deposit_percentage' => 30.0])['allowed'], 'a wallet paid order clears the sourcing gate');

    $unpaidCheck = $makeOrder($ada, 'pay_in_full', 100000);
    wl_ok(!SourcingGate::evaluate($order($unpaidCheck['id']), ['exempt' => '', 'credit' => null, 'deposit_percentage' => 30.0])['allowed'], 'sanity: the same order unpaid is refused by the gate');

    $again = Wallet::payOrder($a['id'], $ada, $ada);
    wl_ok(!$again['ok'] && $again['code'] === 'nothing_due', 'a second tap finds nothing left to pay');
    wl_eq(150000, Wallet::balance($ada), 'and spends nothing');
    wl_eq(1, count($byProv($a['id'], 'wallet')), 'and writes no second row');

    // =========================================================================
    // 3. A wallet that covers part of the order pays that part; the card pays the
    //    rest, and the two add up.
    // =========================================================================
    $b = $makeOrder($bola, 'pay_in_full', 800000);
    $fund($bola, 300000, "test:$suffix:b1");
    $prev = Wallet::preview($b['id'], 'pay_in_full', $bola);
    wl_ok($prev !== null && !$prev['covers_all'] && $prev['apply_subunit'] === 300000 && $prev['remaining_subunit'] === 500000, 'the preview shows a part payment');
    $res = Wallet::payOrder($b['id'], $bola, $bola);
    wl_ok($res['ok'] && $res['code'] === 'part_paid', 'a part payment succeeds');
    wl_eq(500000, $res['remaining_subunit'], 'and reports what is left');
    wl_eq(0, Wallet::balance($bola), 'the wallet is emptied');
    $o = $order($b['id']);
    wl_eq(300000, (int) $o['amount_paid_subunit'], 'the order holds the wallet money');
    wl_eq('part_paid', (string) $o['payment_status'], 'and reads as part paid');
    wl_eq(500000, (int) $o['balance_due_subunit'], 'with the rest due');
    $card = $byProv($b['id'], 'paystack')[0];
    wl_eq(500000, (int) $card['expected_amount_subunit'], 'the card row now expects only the remainder');
    wl_ok((string) $card['status'] !== 'void' && (string) $card['status'] !== 'paid', 'and is still open');
    wl_eq((int) $card['id'], (int) $res['payment_id'], 'the result names the row still owed');
    $second = Wallet::payOrder($b['id'], $bola, $bola);
    wl_ok(!$second['ok'] && $second['code'] === 'wallet_empty', 'an empty wallet cannot pay again');

    $paid = $cardCharge($card, $b['number'], 1, 500000);
    wl_ok($paid['ok'], 'the card charge for the remainder is credited');
    $o = $order($b['id']);
    wl_eq(800000, (int) $o['amount_paid_subunit'], 'wallet and card add up to the order total');
    wl_eq('paid', (string) $o['payment_status'], 'and the order is paid');
    wl_eq('exact', (string) $paid['mismatch'], 'the card charge is an exact match for the reduced row');
    wl_eq(300000, (int) $byProv($b['id'], 'wallet')[0]['paid_amount_subunit'], 'the wallet row was not overwritten by the card charge');

    // =========================================================================
    // 4. Refusals leave everything as it was.
    // =========================================================================
    $cara = $makeUser('Cara', 'household');
    $dan  = $makeUser('Dan', 'household');
    $fund($cara, 900000, "test:$suffix:cara1");
    $c = $makeOrder($dan, 'pay_in_full', 200000);
    $stranger = Wallet::payOrder($c['id'], $cara, $cara);
    wl_ok(!$stranger['ok'] && $stranger['code'] === 'not_found', 'a wallet cannot pay another customer\'s order');
    wl_eq(900000, Wallet::balance($cara), 'and nothing is spent');
    wl_eq(0, count($byProv($c['id'], 'wallet')), 'and nothing is written on that order');

    $own = $makeOrder($cara, 'pay_in_full', 200000);
    Database::run('UPDATE orders SET order_status = \'cancelled\' WHERE id = :o', [':o' => $own['id']]);
    $cancelled = Wallet::payOrder($own['id'], $cara, $cara);
    wl_ok(!$cancelled['ok'] && $cancelled['code'] === 'order_cancelled', 'a cancelled order cannot be paid');

    $busy = $makeOrder($cara, 'pay_in_full', 200000);
    $busyRow = $byProv($busy['id'], 'paystack')[0];
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 1, \'paystack\', :ref, :domain, \'initialized\', 200000, \'NGN\', \'c@example.test\')',
        [':pid' => $busyRow['id'], ':ref' => Payments::reference($busy['number'], 1), ':domain' => Paystack::domain()]
    );
    $before = ['wallet' => Wallet::balance($cara), 'rows' => $rows($busy['id'])];
    $inflight = Wallet::payOrder($busy['id'], $cara, $cara);
    wl_ok(!$inflight['ok'] && $inflight['code'] === 'payment_in_progress', 'a card attempt in flight blocks the wallet');
    wl_eq($before, ['wallet' => Wallet::balance($cara), 'rows' => $rows($busy['id'])], 'and nothing moved');

    $none = Wallet::payOrder(987654321, $cara, $cara);
    wl_ok(!$none['ok'] && $none['code'] === 'not_found', 'an order that does not exist is refused');

    // =========================================================================
    // 5. A deposit order: the wallet pays the deposit, the balance stays.
    // =========================================================================
    $erin = $makeUser('Erin', 'household');
    $fund($erin, 5000000, "test:$suffix:erin1");
    $d = $makeOrder($erin, 'deposit', 1000000);
    $res = Wallet::payOrder($d['id'], $erin, $erin);
    wl_ok($res['ok'] && $res['code'] === 'paid', 'the wallet pays the deposit');
    wl_eq(300000, $res['amount_subunit'], 'which is the deposit, not the whole order');
    wl_eq(300000, (int) $order($d['id'])['amount_paid_subunit'], 'the order holds the deposit');
    $balanceRow = array_values(array_filter($rows($d['id']), static fn($r) => $r['payment_type'] === 'balance'))[0];
    wl_eq(700000, (int) $balanceRow['expected_amount_subunit'], 'the balance row is untouched');
    wl_eq(1000000, array_sum(array_map(static fn($r) => (int) $r['expected_amount_subunit'], $rows($d['id']))), 'the rows still add up');
    wl_ok(SourcingGate::evaluate($order($d['id']), ['exempt' => '', 'credit' => null, 'deposit_percentage' => 30.0])['allowed'], 'a deposit paid from the wallet clears the sourcing gate');

    // =========================================================================
    // 6. The credit line: a wallet repayment settles the ledger like a card one.
    // =========================================================================
    $biz = $makeBusiness('Green', 100000000, 7);
    $fund($biz['user_id'], 1500000, "test:$suffix:biz1");
    $e = $makeOrder($biz['user_id'], 'pay_in_full', 4000000, 'business');
    $used = Credit::useCreditLine($e['id'], $biz['user_id'], $biz['user_id'], 'customer');
    wl_ok($used['ok'], 'setup: the order is on the credit line');
    $open = static fn(int $id): int => (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE order_id = :o', [':o' => $id])['s'];
    wl_eq(4000000, $open($e['id']), 'setup: 40,000 naira is open');
    $prev = Wallet::preview($e['id'], 'on_account', $biz['user_id']);
    wl_ok($prev !== null && $prev['apply_subunit'] === 1500000, 'the wallet is offered against the open amount');
    $res = Wallet::payOrder($e['id'], $biz['user_id'], $biz['user_id']);
    wl_ok($res['ok'] && $res['code'] === 'part_paid', 'the wallet repays part of the credit');
    wl_eq(2500000, $open($e['id']), 'the ledger shows the repayment');
    wl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'repayment\'', [':o' => $e['id']])['c'], 'as one repayment row');
    $acct = $byProv($e['id'], 'account')[0];
    wl_eq(2500000, (int) $acct['expected_amount_subunit'], 'the account row expects only what is still open');
    wl_eq(0, Wallet::balance($biz['user_id']), 'the wallet is emptied');
    $paid = $cardCharge($acct, $e['number'], 1, 2500000);
    wl_ok($paid['ok'], 'a card repayment of the rest is credited');
    wl_eq(0, $open($e['id']), 'the order is repaid on the ledger');
    wl_eq(OrderMoney::KIND_CREDIT_REPAID, OrderMoney::forOrder($e['id'])['kind'], 'and reads as repaid');
    wl_eq(4000000, (int) $order($e['id'])['amount_paid_subunit'], 'wallet and card together equal the order');

    // A wallet larger than what is open pays only what is open.
    $fund($biz['user_id'], 9000000, "test:$suffix:biz2");
    $f = $makeOrder($biz['user_id'], 'pay_in_full', 1000000, 'business');
    Credit::useCreditLine($f['id'], $biz['user_id'], $biz['user_id'], 'customer');
    $res = Wallet::payOrder($f['id'], $biz['user_id'], $biz['user_id']);
    wl_ok($res['ok'] && $res['code'] === 'paid' && $res['amount_subunit'] === 1000000, 'a bigger wallet pays only what is open');
    wl_eq(8000000, Wallet::balance($biz['user_id']), 'and keeps the rest');
    wl_eq(0, $open($f['id']), 'the order is repaid');
    $after = Wallet::payOrder($f['id'], $biz['user_id'], $biz['user_id']);
    wl_ok(!$after['ok'] && $after['code'] === 'nothing_due', 'a repaid credit order has nothing left for the wallet');

    // =========================================================================
    // 7. Cancelling an order that was paid from the wallet gives the money back to
    //    the wallet, once, with a credit note. Nobody has to send it.
    // =========================================================================
    $gina = $makeUser('Gina', 'household');
    $fund($gina, 2000000, "test:$suffix:gina1");
    $g = $makeOrder($gina, 'pay_in_full', 1500000);
    Wallet::payOrder($g['id'], $gina, $gina);
    wl_eq(500000, Wallet::balance($gina), 'setup: the wallet paid a 15,000 naira order');
    $notesBefore = $notes($gina);
    // A paid order is cancelled by staff who may also refund it.
    $cancel = OrderCancellation::cancelForStaff($g['id'], $staff, 'customer_requested', '', true);
    wl_ok($cancel['ok'], 'staff cancel the order ' . json_encode($cancel));
    wl_eq(1500000, (int) $cancel['refund_subunit'], 'the whole payment is owed back');
    wl_eq(1500000, (int) $cancel['wallet_subunit'], 'and all of it went to the wallet');
    wl_eq('processed', (string) $cancel['refund_status'], 'so the refund is done, with nothing waiting on a person');
    wl_eq(0, (int) $cancel['manual_subunit'], 'and nothing is left for staff to send by hand');
    wl_eq(2000000, Wallet::balance($gina), 'the wallet is whole again');
    wl_eq($notesBefore + 1, $notes($gina), 'a credit note was issued for the refund');
    $back = Database::one('SELECT source, order_id FROM wallet_entries WHERE user_id = :u AND source = \'cancellation\'', [':u' => $gina]);
    wl_eq((int) $g['id'], (int) $back['order_id'], 'tied to the cancelled order');
    $again = OrderCancellation::cancelForStaff($g['id'], $staff, 'customer_requested', '', true);
    wl_ok(!empty($again['ok']) || ($again['code'] ?? '') === 'already_cancelled', 'cancelling again is recognised');
    wl_eq(2000000, Wallet::balance($gina), 'and pays nothing twice');
    wl_ok(Wallet::reconcile($gina)['ok'], 'the ledger still reconciles');

    // The plan puts wallet money where it came from, whatever else the order holds.
    $plan = OrderCancellation::refundPlan([
        ['id' => 3, 'provider' => 'wallet', 'refundable_subunit' => 400000],
        ['id' => 2, 'provider' => 'paystack', 'refundable_subunit' => 500000],
        ['id' => 1, 'provider' => 'manual', 'refundable_subunit' => 100000],
    ], 950000);
    wl_eq([['transaction_id' => 3, 'amount_subunit' => 400000]], $plan['wallet'], 'the newest money, in the wallet, goes back to the wallet');
    wl_eq(500000, $plan['paystack'][0]['amount_subunit'], 'card money goes back to the card');
    wl_eq(50000, $plan['manual_subunit'], 'and only cash recorded by staff is left for a person');

    // =========================================================================
    // 8. Every wallet still reconciles.
    // =========================================================================
    foreach ([$ada, $bola, $cara, $erin, $gina, $biz['user_id']] as $uid) {
        wl_ok(Wallet::reconcile($uid)['ok'], "wallet $uid: the ledger agrees with the cached balance");
    }

    // =========================================================================
    // 9. Pure rules.
    // =========================================================================
    wl_eq(0, Wallet::planSpend(0, 500), 'an empty wallet spends nothing');
    wl_eq(0, Wallet::planSpend(500, 0), 'nothing owed, nothing spent');
    wl_eq(300, Wallet::planSpend(300, 500), 'a small wallet is spent in full');
    wl_eq(500, Wallet::planSpend(900, 500), 'a big wallet spends only what is owed');
    wl_eq(['expected' => 0, 'status' => 'void'], Wallet::reducedRow(500, 0, 500), 'a row nothing is left on is voided');
    wl_eq(['expected' => 200, 'status' => null], Wallet::reducedRow(500, 0, 300), 'a row still owed keeps its status');
    wl_eq(['expected' => 100, 'status' => 'paid'], Wallet::reducedRow(300, 100, 200), 'a row that already holds money is never reduced below it');
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allBiz    = $businesses ? implode(',', array_map('intval', $businesses)) : '0';
    $allCarts  = $carts ? implode(',', array_map('intval', $carts)) : '0';
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'order' AND entity_id IN ($allOrders)) OR (entity_type = 'wallet_entry' AND entity_id IN (SELECT id FROM wallet_entries WHERE user_id IN ($allUsers)))");
    Database::run("DELETE nd FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.related_type = 'order' AND n.related_id IN ($allOrders)");
    Database::run("DELETE FROM notifications WHERE related_type = 'order' AND related_id IN ($allOrders)");
    Database::run("DELETE FROM order_cancellations WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM credit_notes WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_entries WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_accounts WHERE user_id IN ($allUsers)");
    Database::run("DELETE h FROM payment_status_history h JOIN payments p ON p.id = h.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE t FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE FROM payments WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM credit_transactions WHERE business_customer_id IN ($allBiz)");
    Database::run("DELETE FROM order_status_history WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM delivery_schedules WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM orders WHERE id IN ($allOrders)");
    Database::run("DELETE FROM shopping_carts WHERE id IN ($allCarts)");
    Database::run("DELETE FROM business_customers WHERE id IN ($allBiz)");
    Database::run("DELETE FROM users WHERE id IN ($allUsers)");
}

fwrite(STDOUT, "\n{$GLOBALS['wl_p']} / {$GLOBALS['wl_t']} wallet assertions passed.\n");
exit($GLOBALS['wl_p'] === $GLOBALS['wl_t'] ? 0 : 1);
