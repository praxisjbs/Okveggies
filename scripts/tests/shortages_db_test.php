<?php
/**
 * scripts/tests/shortages_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR2b of the 23 Sep review. An item that cannot be sourced, the
 * customer's choice, and the manual refund queue, against a real MySQL 8
 * database.
 *
 * What only a database shows: that marking a line short takes the value off the
 * unpaid part first and only owes the customer what they have paid beyond what
 * they now owe; that the order, its line and its payment rows still agree
 * afterwards; that a choice is made once however many times it is sent; that a
 * refund is paid once; that a wallet cash out leaves the wallet at once and
 * comes back whole when it is cancelled; that an undo puts back exactly what the
 * shortage changed and refuses once money has moved; and that cancelling an
 * order afterwards does not return the same money twice.
 *
 *   php scripts/tests/shortages_db_test.php
 *
 * Creates its own users, orders and ledger rows, asserts, then removes them.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$GLOBALS['sh_t'] = 0; $GLOBALS['sh_p'] = 0;
function sh_ok($cond, string $label): void {
    $GLOBALS['sh_t']++;
    if ($cond) { $GLOBALS['sh_p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function sh_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    sh_ok($same, $label);
}

$pdo      = Database::getInstance()->getConnection();
$suffix   = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$delivery = date('Y-m-d', strtotime('+4 days'));
$users = []; $businesses = []; $orders = []; $carts = [];

$makeUser = function (string $name, string $type) use ($suffix, &$users): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, \'Short\', :e, :p, :h, :t, \'active\')',
        [':f' => $name, ':e' => strtolower($name) . "-$suffix@example.test", ':p' => '+23471' . random_int(10000000, 99999999),
         ':h' => password_hash('x', PASSWORD_BCRYPT), ':t' => $type]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return $id;
};
/**
 * An order written the way checkout writes it, with real lines. $lines is a list
 * of [name, unit, quantity, unit price in kobo]. $status is the stage.
 */
$makeOrder = function (?int $userId, string $option, array $lines, string $status = 'pending', string $type = 'household') use ($suffix, $delivery, &$orders, &$carts): array {
    $total = 0;
    foreach ($lines as $l) { $total += Money::lineTotal($l[2], $l[3]); }
    $cartId = null;
    if ($userId !== null) {
        Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:u, \'converted\')', [':u' => $userId]);
        $cartId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $carts[] = $cartId;
    }
    $pct     = 25.0;
    $deposit = $option === 'deposit' ? Money::deposit($total, $pct) : null;
    $number  = 'SHT-' . $suffix . '-' . random_int(1000, 9999);
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, shopping_cart_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_percentage, deposit_required_subunit,
             balance_due_subunit, preferred_delivery_date, created_by, contact_email)
         VALUES (:n, :u, :cart, :ct, :st, :po, \'unpaid\', :t, :t2, :pct, :dep, :t3, :dd, :u2, :em)',
        [':n' => $number, ':u' => $userId, ':cart' => $cartId, ':ct' => $type, ':st' => $status, ':po' => $option, ':t' => $total,
         ':t2' => $total, ':pct' => $deposit === null ? null : $pct, ':dep' => $deposit, ':t3' => $total,
         ':dd' => $delivery, ':u2' => $userId, ':em' => "guest-$suffix@example.test"]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[] = $id;
    $itemIds = [];
    foreach ($lines as $i => $l) {
        Database::run(
            'INSERT INTO order_items (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
             VALUES (:o, \'product\', :n, :sku, :u, :q, :p, :t)',
            [':o' => $id, ':n' => $l[0], ':sku' => "SHT-$suffix-$i", ':u' => $l[1], ':q' => $l[2], ':p' => $l[3], ':t' => Money::lineTotal($l[2], $l[3])]
        );
        $itemIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Checkout::writePayments($id, $userId, $number, $option, $total, Checkout::amountDue($option, $total, $pct), $delivery);
    return ['id' => $id, 'number' => $number, 'total' => $total, 'items' => $itemIds];
};
/** Mark one payment row paid in full, the way a verified charge leaves it. */
$settle = static function (int $orderId, string $type): void {
    $row = Database::one('SELECT id, expected_amount_subunit FROM payments WHERE order_id = :o AND payment_type = :t ORDER BY id LIMIT 1', [':o' => $orderId, ':t' => $type]);
    Database::run('UPDATE payments SET paid_amount_subunit = expected_amount_subunit, status = \'paid\', confirmed_at = NOW() WHERE id = :id', [':id' => $row['id']]);
    Payments::recomputeOrder($orderId);
};
$order  = static fn(int $id): array => Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
$item   = static fn(int $id): array => Database::one('SELECT * FROM order_items WHERE id = :id', [':id' => $id]);
$rows   = static fn(int $id): array => Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $id]);
$expectedSum = static fn(int $id): int => (int) Database::one('SELECT COALESCE(SUM(expected_amount_subunit),0) AS s FROM payments WHERE order_id = :o', [':o' => $id])['s'];
$linesSum    = static fn(int $id): int => (int) Database::one('SELECT COALESCE(SUM(line_total_subunit),0) AS s FROM order_items WHERE order_id = :o', [':o' => $id])['s'];
$bank = ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi'];

try {
    $staff = $makeUser('Staff', 'staff');
    $ada   = $makeUser('Ada', 'household');
    $bola  = $makeUser('Bola', 'household');

    // =========================================================================
    // 1. A fully paid order: everything short is the customer's to take back.
    // =========================================================================
    $a = $makeOrder($ada, 'pay_in_full', [['Tomatoes', 'kg', '2.000', 300000], ['Onions', 'kg', '1.000', 400000]]);
    $settle($a['id'], 'pay_in_full');
    sh_eq(1000000, $a['total'], 'setup: a 10,000 naira order');
    sh_eq('paid', (string) $order($a['id'])['payment_status'], 'setup: it is paid in full');

    $r = Shortages::record($a['id'], $a['items'][0], '1', 'Short on the bench', $staff);
    sh_ok($r['ok'] && $r['code'] === 'recorded_choice', 'a paid order marked short asks the customer to choose');
    sh_eq(300000, $r['amount_subunit'], 'one kg of tomatoes is worth 3,000 naira');
    sh_eq(0, $r['reduced_subunit'], 'nothing unpaid to take it off');
    sh_eq(300000, $r['refund_due_subunit'], 'so all of it is owed back');
    sh_ok(is_string($r['token']) && strlen($r['token']) === 48, 'the customer gets a link token');
    $o = $order($a['id']);
    sh_eq(700000, (int) $o['order_total_subunit'], 'the order total comes down');
    sh_eq(700000, (int) $o['subtotal_subunit'], 'and the subtotal');
    sh_eq(700000, $linesSum($a['id']), 'the lines add up to the total');
    sh_eq('1.000', (string) $item($a['items'][0])['quantity'], 'the line holds what will be delivered');
    sh_eq(300000, (int) $item($a['items'][0])['line_total_subunit'], 'and what it costs');
    sh_eq('paid', (string) $o['payment_status'], 'the order still reads as paid');
    sh_eq(0, (int) $o['balance_due_subunit'], 'with nothing due');
    sh_eq(1000000, (int) $o['amount_paid_subunit'], 'and the money received is untouched');
    $s1 = Database::one('SELECT * FROM order_shortages WHERE id = :id', [':id' => $r['shortage_id']]);
    sh_eq('awaiting_choice', (string) $s1['status'], 'the shortage waits for a choice');
    sh_eq(hash('sha256', $r['token']), (string) $s1['token_hash'], 'only the hash of the token is stored');
    sh_eq('2.000', (string) $s1['original_quantity'], 'what was ordered is kept');
    sh_eq(600000, (int) $s1['original_line_total_subunit'], 'with what it cost');
    $found = Shortages::byToken($r['token']);
    sh_ok($found !== null && (int) $found['id'] === (int) $r['shortage_id'], 'the link finds the shortage');
    sh_ok(Shortages::byToken(str_repeat('0', 48)) === null && Shortages::byToken('nope') === null, 'a wrong or malformed token finds nothing');
    sh_eq('1 kg Tomatoes', (string) $found['item_line'], 'and reads the line plainly');

    // The customer picks the wallet.
    $d = Shortages::decide((int) $r['shortage_id'], 'wallet', [], 'customer', $ada);
    sh_ok($d['ok'] && $d['code'] === 'decided' && $d['resolution'] === 'wallet', 'choosing the wallet works');
    sh_eq(300000, Wallet::balance($ada), 'the money is in the wallet');
    $noteRow = Database::one('SELECT n.credit_note_number, n.reason_code FROM credit_notes n JOIN order_shortages s ON s.wallet_entry_id = n.wallet_entry_id WHERE s.id = :id', [':id' => $r['shortage_id']]);
    sh_eq('shortage', (string) ($noteRow['reason_code'] ?? ''), 'with a credit note that says why');
    sh_eq('settled', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $r['shortage_id']])['status'], 'the shortage is settled');
    $again = Shortages::decide((int) $r['shortage_id'], 'wallet', [], 'customer', $ada);
    sh_ok($again['ok'] && $again['code'] === 'already_decided', 'sending the choice again is recognised');
    sh_eq(300000, Wallet::balance($ada), 'and credits nothing twice');
    $other = Shortages::decide((int) $r['shortage_id'], 'bank', $bank, 'customer', $ada);
    sh_ok($other['ok'] && $other['code'] === 'already_decided', 'and a different choice afterwards changes nothing');
    sh_eq(300000, Shortages::returnedSubunit($a['id']), 'the order knows 3,000 naira was given back');
    sh_ok(!Shortages::forOrder($a['id'])[0]['can_undo'], 'a settled refund cannot be undone');
    $undo = Shortages::withdraw((int) $r['shortage_id'], $staff);
    sh_ok(!$undo['ok'] && $undo['code'] === 'cannot_undo', 'and the undo is refused');

    // The rest of the line, by bank.
    $r2 = Shortages::record($a['id'], $a['items'][1], 'all', '', $staff);
    sh_eq(400000, $r2['amount_subunit'], 'the whole onion line is worth 4,000 naira');
    sh_eq('0.000', (string) $item($a['items'][1])['quantity'], 'and nothing is left on it');
    sh_eq(300000, (int) $order($a['id'])['order_total_subunit'] - 0, 'the order is now only the tomatoes left');
    $bad = Shortages::decide((int) $r2['shortage_id'], 'bank', ['bank_name' => '', 'account_number' => '123', 'account_name' => ''], 'customer', $ada);
    sh_ok(!$bad['ok'] && $bad['code'] === 'bad_bank' && count($bad['errors']) === 3, 'a refund to a bank needs all three details');
    sh_eq('awaiting_choice', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $r2['shortage_id']])['status'], 'and a refused choice leaves it waiting');
    $d2 = Shortages::decide((int) $r2['shortage_id'], 'bank', $bank, 'customer', $ada);
    sh_ok($d2['ok'] && $d2['resolution'] === 'bank' && $d2['refund_id'] > 0, 'choosing the bank queues a refund');
    $refund = ManualRefunds::find($d2['refund_id']);
    sh_eq(400000, (int) $refund['amount_subunit'], 'for the amount owed');
    sh_eq('requested', (string) $refund['status'], 'waiting to be sent');
    sh_eq('shortage', (string) $refund['kind'], 'as a shortage refund');
    sh_eq('0123456789', (string) $refund['account_number'], 'with the bank details given');
    sh_eq('refund_pending', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $r2['shortage_id']])['status'], 'the shortage reads as a refund to pay');
    sh_eq(700000, Shortages::returnedSubunit($a['id']), 'and counts as given back');
    sh_eq(1, count(array_filter(ManualRefunds::queue(), static fn($q) => (int) $q['id'] === $d2['refund_id'])), 'it is in the queue');
    $dupe = Shortages::decide((int) $r2['shortage_id'], 'bank', $bank, 'customer', $ada);
    sh_ok($dupe['code'] === 'already_decided', 'choosing again queues no second refund');
    sh_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM manual_refunds WHERE order_id = :o', [':o' => $a['id']])['c'], 'one refund row for the order');

    $p0 = ManualRefunds::markPaid($d2['refund_id'], 'ab', $staff);
    sh_ok(!$p0['ok'] && $p0['code'] === 'reference_required', 'a refund needs a bank reference to be marked paid');
    $p1 = ManualRefunds::markPaid($d2['refund_id'], 'TRF-0001', $staff);
    sh_ok($p1['ok'], 'with a reference it is marked paid');
    sh_eq('paid', (string) ManualRefunds::find($d2['refund_id'])['status'], 'and reads as paid');
    sh_eq('TRF-0001', (string) ManualRefunds::find($d2['refund_id'])['payment_reference'], 'with the reference kept');
    sh_eq('settled', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $r2['shortage_id']])['status'], 'and the shortage is settled');
    $p2 = ManualRefunds::markPaid($d2['refund_id'], 'TRF-0002', $staff);
    sh_ok(!$p2['ok'] && $p2['code'] === 'not_requested', 'a refund is paid once');
    $c0 = ManualRefunds::cancel($d2['refund_id'], 'Changed my mind', $staff);
    sh_ok(!$c0['ok'] && $c0['code'] === 'not_requested', 'and a paid refund cannot be cancelled');

    // =========================================================================
    // 2. A deposit order: the value comes off the balance still to pay.
    // =========================================================================
    $b = $makeOrder($bola, 'deposit', [['Plantain', 'bunch', '4.000', 250000], ['Peppers', 'kg', '2.000', 200000]]);
    $settle($b['id'], 'deposit');
    $depositPaid = (int) $order($b['id'])['amount_paid_subunit'];
    sh_eq(Money::deposit(1400000, 25.0), $depositPaid, 'setup: the deposit is paid');
    $balanceBefore = (int) Database::one('SELECT expected_amount_subunit AS e FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']])['e'];
    $rb = Shortages::record($b['id'], $b['items'][0], '1', '', $staff);
    sh_ok($rb['ok'] && $rb['code'] === 'recorded_reduced' && !$rb['needs_choice'], 'a deposit order with a balance owing needs no choice');
    sh_eq(250000, $rb['reduced_subunit'], 'the value came off the balance');
    sh_eq(0, $rb['refund_due_subunit'], 'and nothing is owed back');
    sh_eq(null, $rb['token'], 'so there is no link');
    sh_eq($balanceBefore - 250000, (int) Database::one('SELECT expected_amount_subunit AS e FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']])['e'], 'the balance row expects less');
    sh_eq(1150000, (int) $order($b['id'])['order_total_subunit'], 'the order total is lower');
    sh_eq($expectedSum($b['id']), (int) $order($b['id'])['order_total_subunit'], 'the payment rows still add up to the order total');
    sh_eq((int) $order($b['id'])['order_total_subunit'] - $depositPaid, (int) $order($b['id'])['balance_due_subunit'], 'and the balance due is what is left to collect');
    sh_eq('settled', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $rb['shortage_id']])['status'], 'the shortage is settled at once');
    sh_eq(0, Shortages::returnedSubunit($b['id']), 'no money was given back');

    // Undo puts back exactly what it changed.
    $u = Shortages::withdraw((int) $rb['shortage_id'], $staff);
    sh_ok($u['ok'], 'a shortage that only reduced the balance can be undone');
    sh_eq(1400000, (int) $order($b['id'])['order_total_subunit'], 'the total is back');
    sh_eq($balanceBefore, (int) Database::one('SELECT expected_amount_subunit AS e FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']])['e'], 'the balance row is back');
    sh_eq('4.000', (string) $item($b['items'][0])['quantity'], 'the line is back');
    sh_eq(1000000, (int) $item($b['items'][0])['line_total_subunit'], 'at its price');
    sh_eq($expectedSum($b['id']), (int) $order($b['id'])['order_total_subunit'], 'and the rows still add up');
    sh_eq('withdrawn', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $rb['shortage_id']])['status'], 'the shortage reads as undone');
    sh_ok(Shortages::byToken('0') === null, 'sanity: a bad token still finds nothing');
    $u2 = Shortages::withdraw((int) $rb['shortage_id'], $staff);
    sh_ok(!$u2['ok'], 'an undo cannot run twice');

    // The whole plantain line is worth less than the balance still to pay: absorbed.
    $big = Shortages::record($b['id'], $b['items'][0], 'all', '', $staff);
    sh_eq(1000000, $big['amount_subunit'], 'the whole plantain line is 10,000 naira');
    sh_eq(1000000, $big['reduced_subunit'], 'the balance absorbs all of it');
    sh_ok(!$big['needs_choice'] && $big['refund_due_subunit'] === 0, 'so nothing is owed back');
    sh_eq($balanceBefore - 1000000, (int) Database::one('SELECT expected_amount_subunit AS e FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']])['e'], 'and the balance row has 500 naira left on it');

    // More short than the balance can absorb: the rest is the customer's.
    $big2 = Shortages::record($b['id'], $b['items'][1], 'all', '', $staff);
    sh_eq(400000, $big2['amount_subunit'], 'the peppers are worth 4,000 naira');
    sh_eq($balanceBefore - 1000000, $big2['reduced_subunit'], 'the balance absorbs what it has left');
    sh_eq(400000 - ($balanceBefore - 1000000), $big2['refund_due_subunit'], 'and the part of the deposit that is now too much is owed back');
    sh_ok($big2['needs_choice'], 'so the customer is asked');
    $balanceRow = Database::one('SELECT expected_amount_subunit AS e, status FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']]);
    sh_eq(0, (int) $balanceRow['e'], 'the balance row expects nothing');
    sh_eq('void', (string) $balanceRow['status'], 'and is voided, not deleted');
    sh_eq(0, (int) $order($b['id'])['order_total_subunit'], 'the whole order is short');
    sh_eq(Money::deposit(1400000, 25.0), (int) $order($b['id'])['amount_paid_subunit'], 'and the deposit received is untouched');
    sh_eq(Money::deposit(1400000, 25.0), $big2['refund_due_subunit'], 'all of it is owed back');
    $ub2 = Shortages::withdraw((int) $big2['shortage_id'], $staff);
    sh_ok($ub2['ok'], 'a shortage still waiting for a choice can be undone');
    $restored = Database::one('SELECT expected_amount_subunit AS e, status FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']]);
    sh_eq($balanceBefore - 1000000, (int) $restored['e'], 'the voided balance row is restored');
    sh_eq('unpaid', (string) $restored['status'], 'to what it was');
    $ub = Shortages::withdraw((int) $big['shortage_id'], $staff);
    sh_ok($ub['ok'], 'and so is the one before it');
    sh_eq(1400000, (int) $order($b['id'])['order_total_subunit'], 'the total is whole again');
    sh_eq($balanceBefore, (int) Database::one('SELECT expected_amount_subunit AS e FROM payments WHERE order_id = :o AND payment_type = \'balance\'', [':o' => $b['id']])['e'], 'and so is the balance');
    sh_eq($expectedSum($b['id']), 1400000, 'with the rows adding up');

    // =========================================================================
    // 3. Unpaid pay on delivery, and a guest order.
    // =========================================================================
    $pod = $makeOrder($ada, 'pay_on_delivery', [['Yam', 'tuber', '3.000', 100000]]);
    $rp = Shortages::record($pod['id'], $pod['items'][0], '2', '', $staff);
    sh_ok($rp['ok'] && !$rp['needs_choice'] && $rp['reduced_subunit'] === 200000, 'an unpaid order just owes less');
    sh_eq(100000, (int) $order($pod['id'])['balance_due_subunit'], 'and the amount to collect at the door drops');
    sh_eq(100000, $expectedSum($pod['id']), 'on its payment row');

    $guest = $makeOrder(null, 'pay_in_full', [['Cabbage', 'head', '2.000', 150000]]);
    $settle($guest['id'], 'pay_in_full');
    $rg = Shortages::record($guest['id'], $guest['items'][0], '1', '', $staff);
    sh_ok($rg['ok'] && $rg['needs_choice'], 'a paid guest order asks for a choice too');
    $gw = Shortages::decide((int) $rg['shortage_id'], 'wallet', [], 'customer', null);
    sh_ok(!$gw['ok'] && $gw['code'] === 'no_account', 'but a guest has no wallet to put it in');
    $gb = Shortages::decide((int) $rg['shortage_id'], 'bank', $bank, 'staff', $staff);
    sh_ok($gb['ok'], 'a colleague can choose the bank for them');
    sh_eq('staff', (string) Database::one('SELECT decided_by_type AS t FROM order_shortages WHERE id = :id', [':id' => $rg['shortage_id']])['t'], 'and it is recorded as a staff decision');
    sh_eq(null, ManualRefunds::find($gb['refund_id'])['user_id'], 'the refund has no account behind it');

    // =========================================================================
    // 4. The credit line: the charge comes down, and goes back up on undo.
    // =========================================================================
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Bisi\', \'Short\', :e, :p, :h, \'business\', \'active\')',
        [':e' => "bisi-$suffix@example.test", ':p' => '+23472' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT)]
    );
    $biz = (int) $pdo->lastInsertId(); $users[] = $biz;
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, \'Bisi\', 1, \'approved\', 7, 900000000)',
        [':u' => $biz, ':n' => "Bisi $suffix"]
    );
    $businesses[] = (int) $pdo->lastInsertId();
    $cr = $makeOrder($biz, 'pay_in_full', [['Carrots', 'kg', '5.000', 200000]], 'pending', 'business');
    $used = Credit::useCreditLine($cr['id'], $biz, $biz, 'customer');
    sh_ok($used['ok'], 'setup: an order on the credit line');
    $open = static fn(int $id): int => (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE order_id = :o', [':o' => $id])['s'];
    sh_eq(1000000, $open($cr['id']), 'setup: 10,000 naira is open');
    $rc = Shortages::record($cr['id'], $cr['items'][0], '1', '', $staff);
    sh_ok($rc['ok'] && !$rc['needs_choice'], 'a credit order owes less and needs no choice');
    sh_eq(800000, $open($cr['id']), 'the charge came down by the value');
    sh_eq(800000, $expectedSum($cr['id']), 'and the account row expects less');
    sh_eq(0, Shortages::returnedSubunit($cr['id']), 'no money given back');
    $ruc = Shortages::withdraw((int) $rc['shortage_id'], $staff);
    sh_ok($ruc['ok'], 'the undo works on a credit order');
    sh_eq(1000000, $open($cr['id']), 'the charge is back');
    sh_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND source_key LIKE :k', [':o' => $cr['id'], ':k' => '%:undo'])['c'], 'by an opposite row, nothing deleted');

    // =========================================================================
    // 5. Refusals leave everything exactly as it was.
    // =========================================================================
    $z = $makeOrder($ada, 'pay_in_full', [['Beans', 'kg', '2.000', 100000]]);
    $settle($z['id'], 'pay_in_full');
    $snap = static fn(int $id): array => ['o' => $order($id), 'rows' => $rows($id), 'items' => Database::all('SELECT * FROM order_items WHERE order_id = :o ORDER BY id', [':o' => $id]),
        'n' => (int) Database::one('SELECT COUNT(*) AS c FROM order_shortages WHERE order_id = :o', [':o' => $id])['c']];
    $before = $snap($z['id']);
    foreach (['0', '-1', 'abc', '2.0001', '3', ''] as $badQty) {
        $x = Shortages::record($z['id'], $z['items'][0], $badQty, '', $staff);
        sh_ok(!$x['ok'] && $x['code'] === 'bad_quantity', "quantity '$badQty' is refused");
    }
    sh_eq($before, $snap($z['id']), 'and refused quantities change nothing');
    $wrong = Shortages::record($z['id'], $a['items'][0], '1', '', $staff);
    sh_ok(!$wrong['ok'] && $wrong['code'] === 'not_found', 'a line from another order is refused');
    $none = Shortages::record(987654321, 1, '1', '', $staff);
    sh_ok(!$none['ok'] && $none['code'] === 'not_found', 'an order that does not exist is refused');
    Database::run('UPDATE orders SET order_status = \'packed\' WHERE id = :o', [':o' => $z['id']]);
    $packed = Shortages::record($z['id'], $z['items'][0], '1', '', $staff);
    sh_ok(!$packed['ok'] && $packed['code'] === 'not_sourcing', 'a packed order cannot have an item marked short');
    Database::run('UPDATE orders SET order_status = \'confirmed\' WHERE id = :o', [':o' => $z['id']]);
    $busyRow = Database::one('SELECT id FROM payments WHERE order_id = :o ORDER BY id LIMIT 1', [':o' => $z['id']]);
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 7, \'paystack\', :ref, :domain, \'initialized\', 100000, \'NGN\', \'s@example.test\')',
        [':pid' => $busyRow['id'], ':ref' => Payments::reference($z['number'], 7), ':domain' => Paystack::domain()]
    );
    $before = $snap($z['id']);
    $inflight = Shortages::record($z['id'], $z['items'][0], '1', '', $staff);
    sh_ok(!$inflight['ok'] && $inflight['code'] === 'payment_in_progress', 'a card attempt in flight blocks it');
    sh_eq($before, $snap($z['id']), 'and nothing moved');
    Database::run('UPDATE payment_transactions SET status = \'abandoned\' WHERE payment_id = :p', [':p' => $busyRow['id']]);
    $okNow = Shortages::record($z['id'], $z['items'][0], 'all', '', $staff);
    sh_ok($okNow['ok'], 'once resolved it works on a sourced order too');
    $twice = Shortages::record($z['id'], $z['items'][0], '1', '', $staff);
    sh_ok(!$twice['ok'] && $twice['code'] === 'already_short', 'a line already fully short cannot be marked again');
    $cancelledOrder = $makeOrder($ada, 'pay_in_full', [['Rice', 'kg', '1.000', 100000]], 'cancelled');
    $cx = Shortages::record($cancelledOrder['id'], $cancelledOrder['items'][0], '1', '', $staff);
    sh_ok(!$cx['ok'] && $cx['code'] === 'not_sourcing', 'a cancelled order cannot either');

    // =========================================================================
    // 6. Cancelling afterwards does not give the same money back twice.
    // =========================================================================
    $fund = Wallet::creditNow($ada, 1000000, 'goodwill', 'Test credit', "sh:$suffix:fund", null, null);
    sh_ok($fund['ok'], 'setup: Ada has 10,000 naira in her wallet');
    $balanceNow = Wallet::balance($ada);
    $w = $makeOrder($ada, 'pay_in_full', [['Melon', 'each', '2.000', 500000]]);
    $paidFromWallet = Wallet::payOrder($w['id'], $ada, $ada);
    sh_ok($paidFromWallet['ok'], 'setup: the wallet paid a 10,000 naira order');
    $rw = Shortages::record($w['id'], $w['items'][0], '1', '', $staff);
    sh_eq(500000, $rw['refund_due_subunit'], 'half of it is owed back');
    Shortages::decide((int) $rw['shortage_id'], 'wallet', [], 'customer', $ada);
    sh_eq($balanceNow - 1000000 + 500000, Wallet::balance($ada), 'the shortage put 5,000 naira back');
    $cancel = OrderCancellation::cancelForStaff($w['id'], $staff, 'customer_requested', '', true);
    sh_ok($cancel['ok'], 'staff cancel the order');
    sh_eq(500000, (int) $cancel['refund_subunit'], 'only the other half is refunded');
    sh_eq($balanceNow, Wallet::balance($ada), 'so the wallet ends exactly where it started, no more');

    // =========================================================================
    // 7. A wallet cash out.
    // =========================================================================
    $eve = $makeUser('Eve', 'household');
    Wallet::creditNow($eve, 500000, 'goodwill', 'Cash out test', "sh:$suffix:eve", null, null);
    $badBank = ManualRefunds::requestCashout($eve, 200000, ['bank_name' => 'X', 'account_number' => '12', 'account_name' => ''], 'tok1');
    sh_ok(!$badBank['ok'] && $badBank['code'] === 'bad_bank' && isset($badBank['errors']['account_number']), 'a cash out needs real bank details');
    $tooMuch = ManualRefunds::requestCashout($eve, 600000, $bank, 'tok2');
    sh_ok(!$tooMuch['ok'] && $tooMuch['code'] === 'insufficient_balance', 'it cannot be more than the wallet holds');
    $zero = ManualRefunds::requestCashout($eve, 0, $bank, 'tok3');
    sh_ok(!$zero['ok'] && $zero['code'] === 'bad_amount', 'nor nothing');
    sh_eq(500000, Wallet::balance($eve), 'refused requests take nothing');
    $co = ManualRefunds::requestCashout($eve, 200000, $bank, 'tok4');
    sh_ok($co['ok'] && !$co['already'], 'a cash out is accepted');
    sh_eq(300000, Wallet::balance($eve), 'the money leaves the wallet now');
    $cor = ManualRefunds::find($co['refund_id']);
    sh_eq('wallet_cashout', (string) $cor['kind'], 'as a cash out');
    sh_eq('requested', (string) $cor['status'], 'waiting to be sent');
    $sameForm = ManualRefunds::requestCashout($eve, 200000, $bank, 'tok4');
    sh_ok($sameForm['ok'] && $sameForm['already'] && $sameForm['refund_id'] === $co['refund_id'], 'the same form sent again answers with the first request');
    sh_eq(300000, Wallet::balance($eve), 'and takes nothing twice');
    $ledgerRow = Database::one('SELECT entry_type, amount_subunit FROM wallet_entries WHERE id = :e', [':e' => $cor['wallet_entry_id']]);
    sh_eq('cashout', (string) $ledgerRow['entry_type'], 'the ledger row is a cash out');
    sh_eq(-200000, (int) $ledgerRow['amount_subunit'], 'and negative');
    sh_ok(Wallet::reconcile($eve)['ok'], 'the ledger still reconciles');
    $noReason = ManualRefunds::cancel($co['refund_id'], 'no', $staff);
    sh_ok(!$noReason['ok'] && $noReason['code'] === 'reason_required', 'cancelling needs a reason');
    $cc = ManualRefunds::cancel($co['refund_id'], 'Bank details were wrong', $staff);
    sh_ok($cc['ok'], 'a cash out can be cancelled before it is sent');
    sh_eq(500000, Wallet::balance($eve), 'and the money is back in the wallet');
    sh_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM credit_notes WHERE user_id = :u AND reason_code <> \'goodwill\'', [':u' => $eve])['c'], 'without a new credit note: it is her own money returning');
    sh_ok(Wallet::reconcile($eve)['ok'], 'the ledger still reconciles after the reversal');
    $ccAgain = ManualRefunds::cancel($co['refund_id'], 'Cancelling again', $staff);
    sh_ok(!$ccAgain['ok'], 'a cancelled cash out cannot be cancelled again');
    sh_eq(500000, Wallet::balance($eve), 'and the wallet is not credited twice');
    $payCancelled = ManualRefunds::markPaid($co['refund_id'], 'TRF-9', $staff);
    sh_ok(!$payCancelled['ok'] && $payCancelled['code'] === 'not_requested', 'a cancelled cash out cannot be paid');
    $co2 = ManualRefunds::requestCashout($eve, 500000, $bank, 'tok5');
    sh_ok($co2['ok'], 'all of the wallet can be asked for');
    sh_eq(0, Wallet::balance($eve), 'leaving nothing');
    $pay2 = ManualRefunds::markPaid($co2['refund_id'], 'TRF-0099', $staff);
    sh_ok($pay2['ok'], 'and paid');
    sh_eq(0, Wallet::balance($eve), 'the wallet stays empty once it is sent');
    sh_eq(1, count(ManualRefunds::forUser($eve)), 'the customer sees the cash out that was sent, and not the one that was cancelled');

    // A shortage refund that is cancelled goes back to the customer to choose again.
    $sc = $makeOrder($bola, 'pay_in_full', [['Garri', 'kg', '2.000', 100000]]);
    $settle($sc['id'], 'pay_in_full');
    $rsc = Shortages::record($sc['id'], $sc['items'][0], '1', '', $staff);
    $dsc = Shortages::decide((int) $rsc['shortage_id'], 'bank', $bank, 'customer', $bola);
    $csc = ManualRefunds::cancel($dsc['refund_id'], 'Wrong account number', $staff);
    sh_ok($csc['ok'], 'a shortage refund can be cancelled');
    sh_eq('awaiting_choice', (string) Database::one('SELECT status FROM order_shortages WHERE id = :id', [':id' => $rsc['shortage_id']])['status'], 'and the shortage waits for a choice again');
    sh_eq(0, Shortages::returnedSubunit($sc['id']), 'with nothing counted as given back');
    $redo = Shortages::decide((int) $rsc['shortage_id'], 'wallet', [], 'customer', $bola);
    sh_ok($redo['ok'] && $redo['code'] === 'decided', 'and the customer can choose again');
    sh_eq(100000, Wallet::balance($bola), 'this time the wallet');

    // =========================================================================
    // 8. Every wallet still reconciles; the pure rules.
    // =========================================================================
    foreach ([$ada, $bola, $eve] as $uid) {
        sh_ok(Wallet::reconcile($uid)['ok'], "wallet $uid: the ledger agrees with the cached balance");
    }
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allBiz    = $businesses ? implode(',', array_map('intval', $businesses)) : '0';
    $allCarts  = $carts ? implode(',', array_map('intval', $carts)) : '0';
    Database::run("DELETE nd FROM notification_deliveries nd JOIN notifications n ON n.id = nd.notification_id WHERE n.related_type IN ('order', 'order_shortage', 'manual_refund', 'credit_note') AND (n.related_id IN ($allOrders) OR nd.user_id IN ($allUsers))");
    Database::run("DELETE FROM notifications WHERE id NOT IN (SELECT notification_id FROM notification_deliveries) AND related_type = 'order' AND related_id IN ($allOrders)");
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'order' AND entity_id IN ($allOrders)) OR (entity_type = 'manual_refund' AND entity_id IN (SELECT id FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers))) OR (entity_type = 'wallet_entry' AND entity_id IN (SELECT id FROM wallet_entries WHERE user_id IN ($allUsers)))");
    Database::run("DELETE FROM manual_refunds WHERE order_id IN ($allOrders) OR user_id IN ($allUsers)");
    Database::run("DELETE FROM order_shortages WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM order_cancellations WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM credit_notes WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_entries WHERE user_id IN ($allUsers)");
    Database::run("DELETE FROM wallet_accounts WHERE user_id IN ($allUsers)");
    Database::run("DELETE h FROM payment_status_history h JOIN payments p ON p.id = h.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE t FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id IN ($allOrders)");
    Database::run("DELETE FROM payments WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM credit_transactions WHERE business_customer_id IN ($allBiz)");
    Database::run("DELETE FROM order_items WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM order_status_history WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM delivery_schedules WHERE order_id IN ($allOrders)");
    Database::run("DELETE FROM orders WHERE id IN ($allOrders)");
    Database::run("DELETE FROM shopping_carts WHERE id IN ($allCarts)");
    Database::run("DELETE FROM business_customers WHERE id IN ($allBiz)");
    Database::run("DELETE FROM users WHERE id IN ($allUsers)");
}

fwrite(STDOUT, "\n{$GLOBALS['sh_p']} / {$GLOBALS['sh_t']} shortage and manual refund assertions passed.\n");
exit($GLOBALS['sh_p'] === $GLOBALS['sh_t'] ? 0 : 1);
