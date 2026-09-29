<?php
/**
 * scripts/tests/credit_line_existing_order_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR1 of the 23 Sep review. Putting an EXISTING order on the credit
 * line, and paying it back, against a real MySQL 8 database.
 *
 * What only a database shows: that the conversion writes one charge and never
 * two; that a refused draw leaves the order, its payment rows and the ledger
 * exactly as they were; that a payment row is reshaped and voided rather than
 * deleted; that money can never land on a void row; that a card attempt in
 * flight blocks the switch; that a repayment is capped at what is open, frees
 * the limit once, and that the customer never sees "unpaid" on a credit order.
 *
 *   php scripts/tests/credit_line_existing_order_db_test.php
 *
 * Creates its own users, businesses and orders, asserts, then removes them. Run it
 * after php scripts/migrate.php on a scratch database.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$GLOBALS['cl_t'] = 0; $GLOBALS['cl_p'] = 0;
function cl_ok($cond, string $label): void {
    $GLOBALS['cl_t']++;
    if ($cond) { $GLOBALS['cl_p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function cl_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    cl_ok($same, $label);
}

$pdo      = Database::getInstance()->getConnection();
$suffix   = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$delivery = date('Y-m-d', strtotime('+4 days'));
$users = []; $businesses = []; $orders = []; $carts = [];

$makeUser = function (string $name, string $type) use ($suffix, &$users): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :p, :h, :t, :s)',
        [':f' => $name, ':l' => 'Line', ':e' => strtolower($name) . "-$suffix@example.test",
         ':p' => '+23470' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':t' => $type, ':s' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return $id;
};
$makeBusiness = function (string $name, string $state, int $limit, int $days) use ($suffix, $makeUser, &$businesses): array {
    $userId = $makeUser($name, 'business');
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, :c, 1, :s, :d, :l)',
        [':u' => $userId, ':n' => "$name $suffix", ':c' => $name, ':s' => $state, ':d' => $days, ':l' => $limit]
    );
    $businessId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $businesses[] = $businessId;
    return ['user_id' => $userId, 'business_id' => $businessId];
};

/**
 * An online order, written the way checkout writes it: a cart, the order row and
 * the payment rows Checkout::writePayments gives that option.
 */
$makeOrder = function (int $userId, string $option, int $total, string $type = 'business') use ($suffix, $delivery, &$orders, &$carts): array {
    Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:u, \'converted\')', [':u' => $userId]);
    $cartId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $carts[] = $cartId;
    $pct     = 30.0;
    $deposit = $option === 'deposit' ? Money::deposit($total, $pct) : null;
    $number  = 'CLE-' . $suffix . '-' . random_int(1000, 9999);
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

$order    = static fn(int $id): array => Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
$rows     = static fn(int $id): array => Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $id]);
$charges  = static fn(int $id): int => (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'charge\'', [':o' => $id])['c'];
$ledger   = static fn(int $businessId): int => (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE business_customer_id = :b', [':b' => $businessId])['s'];
$snapshot = static function (int $id) use ($order, $rows, $charges): array {
    return ['order' => $order($id), 'rows' => $rows($id), 'charges' => $charges($id)];
};

try {
    $staff = $makeUser('Staff', 'staff');
    // A 1,000,000 naira facility on 7 day terms.
    $biz = $makeBusiness('Green', 'approved', 100000000, 7);

    // =========================================================================
    // 1. A pay in full order moves onto the credit line, once, and reads as paid
    //    with the credit line rather than unpaid.
    // =========================================================================
    $a = $makeOrder($biz['user_id'], 'pay_in_full', 42000000);
    cl_eq(1, count($rows($a['id'])), 'setup: a pay in full order has one payment row');
    cl_eq('paystack', $rows($a['id'])[0]['provider'], 'setup: that row is the Paystack row');

    $done = Credit::useCreditLine($a['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok($done['ok'], 'the customer can put an unpaid order on the credit line');
    cl_eq('on_credit', $done['code'], 'and is told it happened');
    $o = $order($a['id']);
    cl_eq('on_account', $o['payment_option'], 'the order is now an on-account order');
    cl_eq(null, $o['deposit_required_subunit'], 'a stale deposit figure is cleared');
    cl_eq(0, (int) $o['amount_paid_subunit'], 'no cash was recorded: cash fields stay cash only');
    cl_eq(42000000, (int) $o['balance_due_subunit'], 'the balance is untouched');
    cl_eq(1, $charges($a['id']), 'exactly one credit charge exists');
    cl_eq(Credit::dueDateFor($delivery, 7), (string) Database::one('SELECT due_date FROM credit_transactions WHERE order_id = :o AND transaction_type = \'charge\'', [':o' => $a['id']])['due_date'], 'the charge is due on the approved term after delivery');
    cl_eq(42000000, $ledger($biz['business_id']), 'the journal balance is the charge');
    cl_eq(58000000, (int) Credit::facilityForUser($biz['user_id'])['available_subunit'], 'available credit falls by the charge');

    $r = $rows($a['id']);
    cl_eq(1, count($r), 'still one payment row: reshaped, not added to');
    cl_eq('account', $r[0]['provider'], 'the row is now the on-account row');
    cl_eq('on_account', $r[0]['payment_type'], 'with the on-account type');
    cl_eq(42000000, (int) $r[0]['expected_amount_subunit'], 'expecting the whole total');
    cl_eq('unpaid', $r[0]['status'], 'and holding no cash');
    cl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM payment_status_history WHERE payment_id = :p AND source = \'credit_line\'', [':p' => $r[0]['id']])['c'], 'the reshape is in the payment history');
    cl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = \'orders.credit_line\' AND entity_id = :o', [':o' => $a['id']])['c'], 'and in the audit log');

    $money = OrderMoney::forOrder($a['id']);
    cl_eq(OrderMoney::KIND_CREDIT, $money['kind'], 'the order reads as on the credit line');
    cl_eq('Paid with your credit line.', $money['headline'], 'the headline says paid with the credit line');
    cl_ok($money['settled'] && $money['owed_subunit'] === 0, 'no cash is owed and nothing asks for payment');
    cl_eq(42000000, $money['credit_open_subunit'], 'the credit layer shows what is open');
    cl_eq(Credit::dueDateFor($delivery, 7), $money['credit_due_date'], 'and when it is due');

    // 2. A repeat, a double submit or a retry writes nothing new.
    $again = Credit::useCreditLine($a['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok($again['ok'] && $again['code'] === 'already_on_credit', 'a repeat is an idempotent success');
    cl_eq(1, $charges($a['id']), 'a repeat writes no second charge');
    cl_eq(42000000, $ledger($biz['business_id']), 'and does not move the ledger');
    cl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = \'orders.credit_line\' AND entity_id = :o', [':o' => $a['id']])['c'], 'nor write a second audit row');

    // =========================================================================
    // 3. A deposit order has two rows. The first is reshaped, the second is voided
    //    and expects nothing, so the rows still add up to the order total.
    // =========================================================================
    $b = $makeOrder($biz['user_id'], 'deposit', 10000000);
    cl_eq(2, count($rows($b['id'])), 'setup: a deposit order has a deposit row and a balance row');
    $done = Credit::useCreditLine($b['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok($done['ok'], 'a deposit order can move to the credit line');
    $r = $rows($b['id']);
    cl_eq(2, count($r), 'no payment row is ever deleted');
    cl_eq('account', $r[0]['provider'], 'the first row becomes the on-account row');
    cl_eq(10000000, (int) $r[0]['expected_amount_subunit'], 'for the whole total');
    cl_eq('void', $r[1]['status'], 'the other row is void');
    cl_eq(0, (int) $r[1]['expected_amount_subunit'], 'and expects nothing');
    cl_eq(10000000, array_sum(array_map(static fn($row) => (int) $row['expected_amount_subunit'], $r)), 'the rows still add up to the total');
    cl_eq(null, $order($b['id'])['deposit_percentage'], 'the deposit percentage is cleared');

    // A pay on delivery order (one manual row) moves too.
    $c = $makeOrder($biz['user_id'], 'pay_on_delivery', 5000000);
    cl_ok(Credit::useCreditLine($c['id'], $biz['user_id'], $biz['user_id'], 'customer')['ok'], 'a pay on delivery order can move to the credit line');
    cl_eq('account', $rows($c['id'])[0]['provider'], 'its manual row becomes the on-account row');

    // =========================================================================
    // 4. Refusals leave everything exactly as it was.
    // =========================================================================
    // Somebody else's order reads as not found.
    $other = $makeBusiness('Other', 'approved', 100000000, 7);
    $d = $makeOrder($biz['user_id'], 'pay_in_full', 1000000);
    $before = $snapshot($d['id']);
    $r = Credit::useCreditLine($d['id'], $other['user_id'], $other['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'not_found', 'a stranger cannot move an order and is not told whose it is');
    cl_eq($before, $snapshot($d['id']), 'a stranger changes nothing');

    // A household has no facility.
    $house = $makeUser('House', 'household');
    $h = $makeOrder($house, 'pay_in_full', 1000000, 'household');
    $before = $snapshot($h['id']);
    $r = Credit::useCreditLine($h['id'], $house, $house, 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'credit_not_approved', 'a household cannot use a credit line it does not have');
    cl_eq($before, $snapshot($h['id']), 'a refused household changes nothing');

    // A facility that is not approved.
    $pending = $makeBusiness('Pending', 'requested', 100000000, 7);
    $p = $makeOrder($pending['user_id'], 'pay_in_full', 1000000);
    $before = $snapshot($p['id']);
    $r = Credit::useCreditLine($p['id'], $pending['user_id'], $pending['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'credit_not_approved', 'credit that is only requested cannot be used');
    cl_eq($before, $snapshot($p['id']), 'and changes nothing');

    // Over the limit: the order, its payment rows and the ledger are untouched.
    $small = $makeBusiness('Small', 'approved', 5000000, 7);
    $big = $makeOrder($small['user_id'], 'deposit', 6000000);
    $before = $snapshot($big['id']);
    $ledgerBefore = $ledger($small['business_id']);
    $r = Credit::useCreditLine($big['id'], $small['user_id'], $small['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'credit_limit_exceeded', 'an order above the available credit is refused');
    cl_eq($before, $snapshot($big['id']), 'a refused draw rolls the order and both payment rows back exactly');
    cl_eq($ledgerBefore, $ledger($small['business_id']), 'and writes nothing to the ledger');

    // Anything already paid cannot move.
    $paid = $makeOrder($biz['user_id'], 'pay_in_full', 2000000);
    Database::run('UPDATE payments SET paid_amount_subunit = 1000, status = \'part_paid\' WHERE order_id = :o', [':o' => $paid['id']]);
    Database::run('UPDATE orders SET amount_paid_subunit = 1000 WHERE id = :o', [':o' => $paid['id']]);
    $before = $snapshot($paid['id']);
    $r = Credit::useCreditLine($paid['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'not_convertible', 'an order with cash on it cannot move');
    cl_eq($before, $snapshot($paid['id']), 'and is left alone');

    // Only Placed orders move.
    $sourced = $makeOrder($biz['user_id'], 'pay_in_full', 2000000);
    Database::run('UPDATE orders SET order_status = \'confirmed\' WHERE id = :o', [':o' => $sourced['id']]);
    $r = Credit::useCreditLine($sourced['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'not_convertible', 'a sourced order cannot move');
    Database::run('UPDATE orders SET order_status = \'cancelled\' WHERE id = :o', [':o' => $sourced['id']]);
    cl_ok(!Credit::useCreditLine($sourced['id'], $biz['user_id'], $biz['user_id'], 'customer')['ok'], 'a cancelled order cannot move');

    // A card attempt in flight blocks the switch, and a settled attempt does not.
    $flight = $makeOrder($biz['user_id'], 'pay_in_full', 3000000);
    $flightPayment = $rows($flight['id'])[0];
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 1, \'paystack\', :ref, :domain, \'initialized\', :amount, \'NGN\', \'x@example.test\')',
        [':pid' => $flightPayment['id'], ':ref' => 'CLE' . $suffix . '-01-aa', ':domain' => Paystack::domain(), ':amount' => 3000000]
    );
    cl_ok(Payments::hasAttemptInFlight($flight['id']), 'an initialised attempt counts as in flight');
    $methods = PayMethods::forOrder($order($flight['id']), Credit::facilityForUser($biz['user_id']), true);
    cl_eq(['paystack'], array_column($methods, 'key'), 'while a card attempt is in flight the sheet does not offer the credit line');
    $before = $snapshot($flight['id']);
    $r = Credit::useCreditLine($flight['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'payment_in_progress', 'the switch waits for a card attempt in flight');
    cl_eq($before, $snapshot($flight['id']), 'and changes nothing');
    Database::run('UPDATE payment_transactions SET status = \'abandoned\' WHERE payment_id = :p', [':p' => $flightPayment['id']]);
    cl_ok(Credit::useCreditLine($flight['id'], $biz['user_id'], $biz['user_id'], 'customer')['ok'], 'once the attempt is abandoned the switch goes through');

    // Two orders racing for one limit: exactly one charge is committed.
    $race = $makeBusiness('Race', 'approved', 10000000, 7);
    $first  = $makeOrder($race['user_id'], 'pay_in_full', 6000000);
    $second = $makeOrder($race['user_id'], 'pay_in_full', 6000000);
    cl_ok(Credit::useCreditLine($first['id'], $race['user_id'], $race['user_id'], 'customer')['ok'], 'the first order takes the credit');
    $r = Credit::useCreditLine($second['id'], $race['user_id'], $race['user_id'], 'customer');
    cl_ok(!$r['ok'] && $r['code'] === 'credit_limit_exceeded', 'the second order is refused because the limit is now used');
    cl_eq(6000000, $ledger($race['business_id']), 'exactly one charge is committed');
    cl_eq('pay_in_full', $order($second['id'])['payment_option'], 'and the refused order keeps its original payment choice');

    // =========================================================================
    // 5. Money can never land on a void row.
    // =========================================================================
    $voidRow = $rows($b['id'])[1];
    cl_eq('void', $voidRow['status'], 'setup: the deposit order has a void row');
    $begin = Payments::beginCharge((int) $voidRow['id'], 'http://127.0.0.1/callback');
    cl_ok(!$begin['ok'] && $begin['code'] === 'payment_void', 'a card charge cannot be started on a void row');
    $manual = ManualPayments::record([
        'payment_id' => (int) $voidRow['id'], 'amount_subunit' => 1000, 'method' => 'transfer',
        'record_token' => 'tok-' . $suffix, 'bank_reference' => 'REF' . $suffix,
    ], $staff);
    cl_ok(!$manual['ok'] && $manual['code'] === 'payment_void', 'staff cannot record money on a void row');
    // A late gateway confirmation for a void row is kept for staff, never credited.
    $lateRef = Payments::reference($b['number'], 9);
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 9, \'paystack\', :ref, :domain, \'initialized\', 1000, \'NGN\', \'late@example.test\')',
        [':pid' => $voidRow['id'], ':ref' => $lateRef, ':domain' => Paystack::domain()]
    );
    $late = Payments::applyVerifiedCharge($lateRef, [
        'id' => 111, 'domain' => Paystack::domain(), 'status' => 'success', 'reference' => $lateRef,
        'amount' => 1000, 'requested_amount' => 1000, 'fees' => 0, 'currency' => 'NGN', 'channel' => 'card',
        'paid_at' => date('c'), 'metadata' => '', 'authorization' => [], 'customer' => [],
    ], 'webhook', null);
    cl_ok(!$late['ok'] && $late['code'] === 'payment_void', 'a late charge on a void row is not credited');
    cl_eq(0, (int) $order($b['id'])['amount_paid_subunit'], 'and no cash reaches the order');
    cl_eq('mismatch', (string) Database::one('SELECT status FROM payment_transactions WHERE reference = :r', [':r' => $lateRef])['status'], 'the transaction is kept as a mismatch for staff');

    // =========================================================================
    // 6. The sheet offers exactly the methods that will work.
    // =========================================================================
    $fresh = $makeOrder($biz['user_id'], 'pay_in_full', 1500000);
    $facility = Credit::facilityForUser($biz['user_id']);
    $methods = PayMethods::forOrder($order($fresh['id']), $facility, true);
    cl_eq(['paystack', 'credit_line'], array_column($methods, 'key'), 'a business with credit is offered card and credit line');
    cl_ok($methods[0]['primary'] && !$methods[1]['primary'], 'the card is the primary button');
    cl_eq('/api/v1/orders.php', $methods[1]['endpoint'], 'the credit line posts to the orders endpoint');
    cl_eq('use_credit_line', $methods[1]['action'], 'with the use_credit_line action');
    cl_eq(['paystack'], array_column(PayMethods::forOrder($order($fresh['id']), $facility, false), 'key'), 'a viewer who does not own the account is never offered the credit line');
    cl_eq(['paystack'], array_column(PayMethods::forOrder($order($fresh['id']), null, true), 'key'), 'without a facility only the card is offered');
    cl_eq(['paystack'], array_column(PayMethods::forOrder($order($fresh['id']), ['available_subunit' => 100] + $facility, true), 'key'), 'a facility too small for the order is not offered');
    cl_eq([], PayMethods::forOrder(['order_status' => 'cancelled'] + $order($fresh['id']), $facility, true), 'a cancelled order offers nothing');

    // A settled order offers nothing; an on-account order offers only Repay.
    $onCredit = $order($a['id']);
    $methods = PayMethods::forOrder($onCredit, $facility, true);
    cl_eq(['repay'], array_column($methods, 'key'), 'an order on the credit line offers Repay and nothing else');
    cl_eq(42000000, $methods[0]['amount_subunit'], 'Repay is for what is open');
    cl_eq('initialise', $methods[0]['action'], 'and starts an ordinary Paystack charge');

    // =========================================================================
    // 7. Repayment: capped at what is open, frees the limit once.
    // =========================================================================
    $repayRow = Payments::repayablePayment($a['id']);
    cl_ok($repayRow !== null, 'an on-account order with something open has a repayable row');
    cl_eq(42000000, Payments::dueFor($repayRow, ['open_subunit' => 42000000]), 'the repayment is the open amount');
    cl_eq(30000000, Payments::dueFor($repayRow, ['open_subunit' => 30000000]), 'a repayment is capped at what the ledger still shows open');
    cl_eq(0, Payments::dueFor($repayRow, ['open_subunit' => 0]), 'nothing open means nothing to charge');
    cl_eq(0, Payments::dueFor($repayRow, null), 'no ledger summary means nothing to charge');
    cl_eq(1500000, Payments::dueFor(['provider' => 'paystack', 'expected_amount_subunit' => 1500000, 'paid_amount_subunit' => 0], null), 'a card row is not capped by the ledger');

    // The customer pays it: the same verified path every payment takes.
    $repayRef = Payments::reference($a['number'], 1);
    Database::run(
        'INSERT INTO payment_transactions (payment_id, attempt_number, provider, reference, domain, status, requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 1, \'paystack\', :ref, :domain, \'initialized\', 42000000, \'NGN\', \'g@example.test\')',
        [':pid' => $repayRow['id'], ':ref' => $repayRef, ':domain' => Paystack::domain()]
    );
    $verified = [
        'id' => 222, 'domain' => Paystack::domain(), 'status' => 'success', 'reference' => $repayRef,
        'amount' => 42000000, 'requested_amount' => 42000000, 'fees' => 0, 'currency' => 'NGN', 'channel' => 'card',
        'paid_at' => date('c'), 'metadata' => '', 'authorization' => [], 'customer' => [],
    ];
    $applied = Payments::applyVerifiedCharge($repayRef, $verified, 'callback', null);
    cl_ok($applied['ok'], 'a verified repayment is credited');
    cl_eq(42000000, (int) $order($a['id'])['amount_paid_subunit'], 'the cash is recorded as cash');
    cl_eq(0, (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE order_id = :o', [':o' => $a['id']])['s'], 'the order has nothing left open on the ledger');
    cl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'repayment\'', [':o' => $a['id']])['c'], 'exactly one repayment row is posted');
    cl_eq(100000000 - 10000000 - 5000000 - 3000000, (int) Credit::facilityForUser($biz['user_id'])['available_subunit'], 'the repaid amount is back in the limit');
    $money = OrderMoney::forOrder($a['id']);
    cl_eq(OrderMoney::KIND_CREDIT_REPAID, $money['kind'], 'the order now reads as repaid');
    cl_ok(Payments::repayablePayment($a['id']) === null, 'a repaid order offers no more repayment');
    cl_eq([], PayMethods::forOrder($order($a['id']), $facility, true), 'and the sheet offers nothing');
    $dup = Payments::applyVerifiedCharge($repayRef, $verified, 'webhook', null);
    cl_ok(!$dup['ok'], 'the same confirmation again is refused');
    cl_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'repayment\'', [':o' => $a['id']])['c'], 'and frees the limit only once');

    // A cancelled credit order releases its credit and offers no repayment.
    $x = $makeOrder($biz['user_id'], 'pay_in_full', 4000000);
    Credit::useCreditLine($x['id'], $biz['user_id'], $biz['user_id'], 'customer');
    cl_eq(4000000, (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE order_id = :o', [':o' => $x['id']])['s'], 'setup: the order has its charge open');
    Credit::adjustCancelledOrder($x['id']);
    Database::run('UPDATE orders SET order_status = \'cancelled\' WHERE id = :o', [':o' => $x['id']]);
    cl_eq(0, (int) Database::one('SELECT COALESCE(SUM(amount_subunit),0) AS s FROM credit_transactions WHERE order_id = :o', [':o' => $x['id']])['s'], 'cancelling releases the credit');
    cl_ok(Payments::repayablePayment($x['id']) === null, 'a cancelled credit order offers no repayment');
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allBiz    = $businesses ? implode(',', array_map('intval', $businesses)) : '0';
    $allCarts  = $carts ? implode(',', array_map('intval', $carts)) : '0';
    Database::run("DELETE FROM audit_logs WHERE entity_type = 'order' AND entity_id IN ($allOrders)");
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

fwrite(STDOUT, "\n{$GLOBALS['cl_p']} / {$GLOBALS['cl_t']} credit line on an existing order assertions passed.\n");
exit($GLOBALS['cl_p'] === $GLOBALS['cl_t'] ? 0 : 1);
