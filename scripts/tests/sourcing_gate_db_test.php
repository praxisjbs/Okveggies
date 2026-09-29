<?php
/**
 * scripts/tests/sourcing_gate_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR1 of the 23 Sep review. Placed to Sourced needs the order to be
 * covered, enforced inside the one transaction that changes the stage, against a
 * real MySQL 8 database.
 *
 * The pure rules are in SourcingGateTest.php. This covers what only a database
 * shows: that a refused order is left exactly as it was with no history written;
 * that "Source on credit line" is one atomic step that rolls back whole when the
 * draw is refused; that the exemptions are positive (a Kitchen Run conversion, a
 * colleague's entry) and everything else is gated; that the Owner override writes
 * its reason to the audit log and the history; and that pay on delivery gets a
 * deposit row that adds up to the total.
 *
 *   php scripts/tests/sourcing_gate_db_test.php
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$GLOBALS['sg_t'] = 0; $GLOBALS['sg_p'] = 0;
function sg_ok($cond, string $label): void {
    $GLOBALS['sg_t']++;
    if ($cond) { $GLOBALS['sg_p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function sg_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    sg_ok($same, $label);
}

$suffix   = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$delivery = date('Y-m-d', strtotime('+5 days'));
// The deposit is whatever the shop is configured to ask for, never a number this
// test assumes.
$pct      = Settings::depositPercentage();
$users = []; $businesses = []; $orders = []; $carts = []; $runs = [];

$makeUser = function (string $name, string $type) use ($suffix, &$users): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :p, :h, :t, :s)',
        [':f' => $name, ':l' => 'Gate', ':e' => strtolower($name) . "-$suffix@example.test",
         ':p' => '+23470' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':t' => $type, ':s' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    return $id;
};
$makeBusiness = function (string $name, int $limit) use ($suffix, $makeUser, &$businesses): array {
    $userId = $makeUser($name, 'business');
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, :c, 1, \'approved\', 7, :l)',
        [':u' => $userId, ':n' => "$name $suffix", ':c' => $name, ':l' => $limit]
    );
    $businesses[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    return ['user_id' => $userId, 'business_id' => end($businesses)];
};

/**
 * An order and its payment rows, written the way checkout writes an online order
 * ($origin 'online': a cart, created by the customer), the way a colleague enters
 * a phone order ('staff': no cart, created by staff), or with no provenance at all
 * ('unknown': no cart, no creator).
 */
$makeOrder = function (int $userId, string $option, int $total, string $origin = 'online', ?int $staffId = null) use ($suffix, $delivery, $pct, &$orders, &$carts): array {
    $cartId = null;
    if ($origin === 'online') {
        Database::run('INSERT INTO shopping_carts (user_id, status) VALUES (:u, \'converted\')', [':u' => $userId]);
        $cartId = (int) Database::getInstance()->getConnection()->lastInsertId();
        $carts[] = $cartId;
    }
    $createdBy = $origin === 'online' ? $userId : ($origin === 'staff' ? $staffId : null);
    $deposit   = $option === 'deposit' ? Money::deposit($total, $pct) : null;
    $number    = 'SGT-' . $suffix . '-' . random_int(1000, 9999);
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, shopping_cart_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_percentage, deposit_required_subunit,
             balance_due_subunit, preferred_delivery_date, created_by)
         VALUES (:n, :u, :cart, \'household\', \'pending\', :po, \'unpaid\', :t, :t2, :pct, :dep, :t3, :dd, :cb)',
        [':n' => $number, ':u' => $userId, ':cart' => $cartId, ':po' => $option, ':t' => $total, ':t2' => $total,
         ':pct' => $deposit === null ? null : $pct, ':dep' => $deposit, ':t3' => $total, ':dd' => $delivery, ':cb' => $createdBy]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[] = $id;
    Checkout::writePayments($id, $userId, $number, $option, $total, Checkout::amountDue($option, $total, $pct), $delivery);
    return ['id' => $id, 'number' => $number];
};

$order   = static fn(int $id): array => Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $id]);
$history = static fn(int $id): int => (int) Database::one('SELECT COUNT(*) AS c FROM order_status_history WHERE order_id = :o AND new_status = \'confirmed\'', [':o' => $id])['c'];
$payRow  = static function (int $orderId, int $amount) use ($order): void {
    // Cash arrives on the first row that can take it, then the order is recomputed
    // from its rows, which is exactly what every real payment path ends with.
    $row = Database::one('SELECT id, expected_amount_subunit FROM payments WHERE order_id = :o AND status <> \'void\' ORDER BY id LIMIT 1', [':o' => $orderId]);
    Database::run('UPDATE payments SET paid_amount_subunit = :a, status = :s, confirmed_at = NOW() WHERE id = :id',
        [':a' => $amount, ':s' => Payments::paymentStatus($amount, (int) $row['expected_amount_subunit']), ':id' => (int) $row['id']]);
    Payments::recomputeOrder($orderId);
};

try {
    $staff = $makeUser('Staff', 'staff');
    $house = $makeUser('House', 'household');

    // =========================================================================
    // 1. A household order that has not been paid cannot be sourced.
    // =========================================================================
    $a = $makeOrder($house, 'pay_in_full', 1000000);
    $r = OrderLifecycle::transition($a['id'], 'pending', 'confirmed', $staff, 'Ready');
    sg_ok(!$r['ok'], 'an unpaid household order cannot be sourced');
    sg_eq('payment_required', $r['code'], 'the reason is payment required');
    sg_ok(str_contains($r['message'], '₦10,000'), 'and the message names what is needed');
    $o = $order($a['id']);
    sg_eq('pending', $o['order_status'], 'a refused order stays Placed');
    sg_eq(null, $o['confirmed_at'], 'no confirmation time is written');
    sg_eq(0, $history($a['id']), 'and no history row is written');

    $payRow($a['id'], 999999);
    sg_ok(!OrderLifecycle::transition($a['id'], 'pending', 'confirmed', $staff, '')['ok'], 'one kobo short is still refused');
    $payRow($a['id'], 1000000);
    $r = OrderLifecycle::transition($a['id'], 'pending', 'confirmed', $staff, 'Paid, sourcing');
    sg_ok($r['ok'] && $r['code'] === 'transitioned', 'a paid household order is sourced');
    sg_eq(false, $r['gate_overridden'], 'without any override');
    sg_eq('confirmed', $order($a['id'])['order_status'], 'and reaches Sourced');
    sg_eq(1, $history($a['id']), 'with one history row');

    // Later stages are not gated: nothing else about the stage change moved.
    sg_ok(OrderLifecycle::transition($a['id'], 'confirmed', 'packed', $staff, '')['ok'], 'Sourced to Packed is not gated');

    // =========================================================================
    // 2. A deposit order needs its deposit.
    // =========================================================================
    $b = $makeOrder($house, 'deposit', 2000000);
    $r = OrderLifecycle::transition($b['id'], 'pending', 'confirmed', $staff, '');
    sg_ok(!$r['ok'] && $r['code'] === 'deposit_required', 'a deposit order without its deposit is refused as deposit required');
    $bDeposit = Money::deposit(2000000, $pct);
    $payRow($b['id'], $bDeposit - 1);
    sg_ok(!OrderLifecycle::transition($b['id'], 'pending', 'confirmed', $staff, '')['ok'], 'a deposit one kobo short is refused');
    $payRow($b['id'], $bDeposit);
    sg_ok(OrderLifecycle::transition($b['id'], 'pending', 'confirmed', $staff, '')['ok'], 'a deposit order with its deposit is sourced');

    // =========================================================================
    // 3. Pay on delivery is not a way round: it needs its deposit, and gets a
    //    deposit row the customer can pay.
    // =========================================================================
    $c = $makeOrder($house, 'pay_on_delivery', 5000000);
    $r = OrderLifecycle::transition($c['id'], 'pending', 'confirmed', $staff, '');
    sg_ok(!$r['ok'] && $r['code'] === 'deposit_required', 'a pay on delivery order with nothing paid is refused');
    $methods = PayMethods::forOrder($order($c['id']), null, true);
    sg_eq(['deposit'], array_column($methods, 'key'), 'the customer is offered the deposit');
    sg_eq('start_deposit', $methods[0]['action'], 'through the start_deposit action');
    $cDeposit = Money::deposit(5000000, $pct);
    sg_eq($cDeposit, $methods[0]['amount_subunit'], 'for the configured share of the order');

    $opened = Payments::openDepositPayment($c['id'], $house, $house);
    sg_ok($opened['ok'] && $opened['code'] === 'opened', 'the deposit row is opened');
    $rows = Database::all('SELECT * FROM payments WHERE order_id = :o ORDER BY id', [':o' => $c['id']]);
    sg_eq(2, count($rows), 'a second row now exists, nothing was deleted');
    sg_eq('paystack', $rows[1]['provider'], 'the deposit is a Paystack row');
    sg_eq('deposit', $rows[1]['payment_type'], 'of type deposit');
    sg_eq($cDeposit, (int) $rows[1]['expected_amount_subunit'], 'for the deposit');
    sg_eq(5000000 - $cDeposit, (int) $rows[0]['expected_amount_subunit'], 'and the pay on delivery row expects the rest');
    sg_eq(5000000, (int) $rows[0]['expected_amount_subunit'] + (int) $rows[1]['expected_amount_subunit'], 'so the rows add up to the total');
    sg_eq($cDeposit, (int) $order($c['id'])['deposit_required_subunit'], 'the order records the deposit it needs');
    $again = Payments::openDepositPayment($c['id'], $house, $house);
    sg_ok($again['ok'] && $again['code'] === 'already_open' && $again['payment_id'] === $opened['payment_id'], 'opening it twice returns the same row');
    sg_eq(2, (int) Database::one('SELECT COUNT(*) AS c FROM payments WHERE order_id = :o', [':o' => $c['id']])['c'], 'and adds no third row');
    $methods = PayMethods::forOrder($order($c['id']), null, true);
    sg_eq(['paystack'], array_column($methods, 'key'), 'the sheet now offers the ordinary card payment');
    sg_ok(str_starts_with($methods[0]['label'], 'Pay the deposit'), 'labelled as the deposit');
    sg_eq(['not_found'], [Payments::openDepositPayment($c['id'], $house + 999999, null)['code']], 'a stranger cannot open the deposit');
    sg_eq('not_available', Payments::openDepositPayment($a['id'], $house, $house)['code'], 'a pay in full order has no deposit to open');

    // The deposit is paid by card: the deposit row takes it, the order recomputes.
    Database::run('UPDATE payments SET paid_amount_subunit = :a, status = \'paid\', confirmed_at = NOW() WHERE id = :id', [':a' => $cDeposit, ':id' => $opened['payment_id']]);
    Payments::recomputeOrder($c['id']);
    sg_eq($cDeposit, (int) $order($c['id'])['amount_paid_subunit'], 'the order holds the deposit as cash');
    sg_ok(OrderLifecycle::transition($c['id'], 'pending', 'confirmed', $staff, '')['ok'], 'a pay on delivery order with its deposit is sourced');

    // =========================================================================
    // 4. The credit line.
    // =========================================================================
    $biz = $makeBusiness('Green', 100000000);
    $d = $makeOrder($biz['user_id'], 'on_account', 3000000, 'online');
    $r = OrderLifecycle::transition($d['id'], 'pending', 'confirmed', $staff, '');
    sg_ok(!$r['ok'] && $r['code'] === 'credit_missing', 'an on-account order with no posted charge is refused');
    $pdo = Database::getInstance()->getConnection();
    $pdo->beginTransaction();
    Credit::drawForOrder($biz['user_id'], $d['id'], 3000000, $delivery);
    $pdo->commit();
    $r = OrderLifecycle::transition($d['id'], 'pending', 'confirmed', $staff, '');
    sg_ok($r['ok'], 'once the credit charge is posted the order is sourced');

    // Source on credit line: one atomic step.
    $e = $makeOrder($biz['user_id'], 'pay_in_full', 7000000);
    $r = OrderLifecycle::transition($e['id'], 'pending', 'confirmed', $staff, '');
    sg_ok(!$r['ok'], 'setup: an unpaid business order without the shortcut is refused');
    $r = OrderLifecycle::transition($e['id'], 'pending', 'confirmed', $staff, '', 'admin', ['source_on_credit' => true]);
    sg_ok($r['ok'] && $r['credit_charged'] === true, 'Source on credit line puts the order on credit and sources it in one step');
    $o = $order($e['id']);
    sg_eq('confirmed', $o['order_status'], 'the order is Sourced');
    sg_eq('on_account', $o['payment_option'], 'on the credit line');
    sg_eq(0, (int) $o['amount_paid_subunit'], 'with no cash recorded');
    sg_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'charge\'', [':o' => $e['id']])['c'], 'and exactly one charge');
    // The form still carries the stage it was loaded at, so a double click posts
    // 'pending' to 'confirmed' again after the first one has committed.
    $again = OrderLifecycle::transition($e['id'], 'pending', 'confirmed', $staff, '', 'admin', ['source_on_credit' => true]);
    sg_eq('already_transitioned', $again['code'], 'repeating the click is an idempotent success');
    sg_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = \'charge\'', [':o' => $e['id']])['c'], 'and writes no second charge');

    // Refused draws roll the whole step back.
    $small = $makeBusiness('Small', 5000000);
    $f = $makeOrder($small['user_id'], 'deposit', 6000000);
    $beforeRows = Database::all('SELECT id, provider, payment_type, expected_amount_subunit, status FROM payments WHERE order_id = :o ORDER BY id', [':o' => $f['id']]);
    $beforeOrder = $order($f['id']);
    $r = OrderLifecycle::transition($f['id'], 'pending', 'confirmed', $staff, '', 'admin', ['source_on_credit' => true]);
    sg_ok(!$r['ok'] && $r['code'] === 'credit_limit_exceeded', 'an order above the available credit is refused');
    sg_eq($beforeOrder, $order($f['id']), 'the order is exactly as it was');
    sg_eq($beforeRows, Database::all('SELECT id, provider, payment_type, expected_amount_subunit, status FROM payments WHERE order_id = :o ORDER BY id', [':o' => $f['id']]), 'and so are its payment rows');
    sg_eq(0, $history($f['id']), 'no stage history was written');
    $g = $makeOrder($house, 'pay_in_full', 1000000);
    $r = OrderLifecycle::transition($g['id'], 'pending', 'confirmed', $staff, '', 'admin', ['source_on_credit' => true]);
    sg_ok(!$r['ok'] && $r['code'] === 'credit_not_approved', 'a household cannot be sourced on a credit line it does not have');
    sg_eq('pay_in_full', $order($g['id'])['payment_option'], 'and keeps its payment choice');

    // =========================================================================
    // 5. Exemptions are positive. Everything else is gated.
    // =========================================================================
    $run = $makeOrder($biz['user_id'], 'pay_in_full', 1000000, 'staff', $staff);
    Database::run(
        'INSERT INTO kitchen_run_requests (request_number, user_id, customer_type, input_mode, status, converted_order_id, state_version)
         VALUES (:n, :u, \'business\', \'shop\', \'converted\', :o, 1)',
        [':n' => 'KRG-' . $suffix, ':u' => $biz['user_id'], ':o' => $run['id']]
    );
    $runs[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    sg_eq('kitchen_run', SourcingGate::exemptionFor(Database::one('SELECT id, shopping_cart_id, created_by, user_id FROM orders WHERE id = :o', [':o' => $run['id']])), 'an order converted from a Kitchen Run is recognised');
    sg_ok(OrderLifecycle::transition($run['id'], 'pending', 'confirmed', $staff, '')['ok'], 'an unpaid Kitchen Run order can be sourced');

    $phone = $makeOrder($house, 'pay_in_full', 1000000, 'staff', $staff);
    sg_eq('staff_order', SourcingGate::exemptionFor(Database::one('SELECT id, shopping_cart_id, created_by, user_id FROM orders WHERE id = :o', [':o' => $phone['id']])), 'an order a colleague entered is recognised');
    sg_ok(OrderLifecycle::transition($phone['id'], 'pending', 'confirmed', $staff, '')['ok'], 'an unpaid order a colleague typed in can be sourced');

    $online = $makeOrder($house, 'pay_in_full', 1000000, 'online');
    sg_eq('', SourcingGate::exemptionFor(Database::one('SELECT id, shopping_cart_id, created_by, user_id FROM orders WHERE id = :o', [':o' => $online['id']])), 'an online order has no exemption');
    sg_ok(!OrderLifecycle::transition($online['id'], 'pending', 'confirmed', $staff, '')['ok'], 'and is gated');
    $unknown = $makeOrder($house, 'pay_in_full', 1000000, 'unknown');
    sg_eq('', SourcingGate::exemptionFor(Database::one('SELECT id, shopping_cart_id, created_by, user_id FROM orders WHERE id = :o', [':o' => $unknown['id']])), 'an order of unknown origin has no exemption');
    sg_ok(!OrderLifecycle::transition($unknown['id'], 'pending', 'confirmed', $staff, '')['ok'], 'so it is gated: the default is closed');

    // =========================================================================
    // 6. The Owner override: a reason, on the record.
    // =========================================================================
    $h = $makeOrder($house, 'pay_in_full', 1500000, 'online');
    $r = OrderLifecycle::transition($h['id'], 'pending', 'confirmed', $staff, '', 'admin', ['override_reason' => '   ']);
    sg_ok(!$r['ok'] && $r['code'] === 'payment_required', 'a blank reason is not an override');
    $r = OrderLifecycle::transition($h['id'], 'pending', 'confirmed', $staff, '', 'admin', ['override_reason' => 'too short']);
    sg_ok(!$r['ok'] && $r['code'] === 'override_reason_invalid', 'a reason under 10 characters is refused');
    $r = OrderLifecycle::transition($h['id'], 'pending', 'confirmed', $staff, '', 'admin', ['override_reason' => str_repeat('x', 201)]);
    sg_ok(!$r['ok'] && $r['code'] === 'override_reason_invalid', 'a reason over 200 characters is refused');
    sg_eq('pending', $order($h['id'])['order_status'], 'refused overrides leave the order Placed');
    sg_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = \'orders.source_override\' AND entity_id = :o', [':o' => $h['id']])['c'], 'and write no audit row');

    $r = OrderLifecycle::transition($h['id'], 'pending', 'confirmed', $staff, 'Regular customer', 'admin', ['override_reason' => 'Customer paying cash to the rider today']);
    sg_ok($r['ok'] && $r['gate_overridden'] === true, 'a valid reason lets the Owner source an unpaid order');
    sg_eq('confirmed', $order($h['id'])['order_status'], 'the order is Sourced');
    sg_eq('unpaid', $order($h['id'])['payment_status'], 'and is still honestly unpaid: an override records nothing as paid');
    $audit = Database::one('SELECT new_values, old_values, actor_user_id FROM audit_logs WHERE action = \'orders.source_override\' AND entity_id = :o', [':o' => $h['id']]);
    sg_ok($audit !== null && str_contains((string) $audit['new_values'], 'Customer paying cash to the rider today'), 'the reason is in the audit log');
    sg_ok(str_contains((string) $audit['old_values'], 'payment_required'), 'with what the gate found');
    sg_eq($staff, (int) $audit['actor_user_id'], 'and who did it');
    $note = (string) Database::one('SELECT note FROM order_status_history WHERE order_id = :o AND new_status = \'confirmed\'', [':o' => $h['id']])['note'];
    sg_ok(str_contains($note, 'Regular customer') && str_contains($note, 'Sourcing gate overridden: Customer paying cash to the rider today'), 'the history note carries the reason beside the ordinary note');

    // An override reason on an order the gate allows records nothing.
    $i = $makeOrder($house, 'pay_in_full', 1000000, 'online');
    $payRow($i['id'], 1000000);
    $r = OrderLifecycle::transition($i['id'], 'pending', 'confirmed', $staff, '', 'admin', ['override_reason' => 'Not needed at all here']);
    sg_ok($r['ok'] && $r['gate_overridden'] === false, 'a paid order is not marked as overridden');
    sg_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM audit_logs WHERE action = \'orders.source_override\' AND entity_id = :o', [':o' => $i['id']])['c'], 'and no override is audited');

    // The gate reads the same answer the screen showed.
    $j = $makeOrder($house, 'pay_in_full', 1000000, 'online');
    $gate = SourcingGate::forOrder($j['id']);
    sg_ok($gate !== null && !$gate['allowed'] && $gate['required_subunit'] === 1000000 && $gate['paid_subunit'] === 0, 'forOrder reports what is needed and what has arrived');
    sg_eq(null, SourcingGate::forOrder(0), 'and answers null for an order that does not exist');
} finally {
    $allOrders = $orders ? implode(',', array_map('intval', $orders)) : '0';
    $allUsers  = $users ? implode(',', array_map('intval', $users)) : '0';
    $allBiz    = $businesses ? implode(',', array_map('intval', $businesses)) : '0';
    $allCarts  = $carts ? implode(',', array_map('intval', $carts)) : '0';
    $allRuns   = $runs ? implode(',', array_map('intval', $runs)) : '0';
    Database::run("DELETE FROM kitchen_run_requests WHERE id IN ($allRuns)");
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

fwrite(STDOUT, "\n{$GLOBALS['sg_p']} / {$GLOBALS['sg_t']} sourcing gate database assertions passed.\n");
exit($GLOBALS['sg_p'] === $GLOBALS['sg_t'] ? 0 : 1);
