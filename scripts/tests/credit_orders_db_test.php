<?php
/**
 * scripts/tests/credit_orders_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. M8 Task H. Credit orders drawing on the limit, against a real
 * MySQL 8 database. The arithmetic is covered by CreditTest.php; this covers
 * what only a database shows: that the charge is written once, that an
 * over-limit order writes neither an order nor a charge, that a repayment frees
 * the limit again, that a cancellation and a refund append adjustments without
 * touching the original charge, and that two orders racing for the same limit
 * cannot both succeed.
 *
 *   php scripts/tests/credit_orders_db_test.php
 *
 * Creates its own users, business profiles and orders, asserts, then removes
 * everything it made. Run it after php scripts/migrate.php on a scratch
 * database.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0;
function co_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function co_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    co_ok($same, $label);
}
function co_refuses(callable $work, string $code, string $label): void {
    try { $work(); co_ok(false, $label . ' (nothing was refused)'); }
    catch (DomainException $e) { co_eq($code, $e->getMessage(), $label); }
}

$pdo    = Database::getInstance()->getConnection();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$delivery = date('Y-m-d', strtotime('+3 days'));
$users = []; $businesses = []; $orders = [];

/** A throwaway business customer with the facility this test needs. */
$makeBusiness = function (string $name, string $state, int $limit, int $days) use ($suffix, &$users, &$businesses): array {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :p, :h, :t, :s)',
        [':f' => $name, ':l' => 'Credit', ':e' => strtolower($name) . "-$suffix@example.test",
         ':p' => '+23470' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':t' => 'business', ':s' => 'active']
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:u, :n, :c, 1, :s, :d, :l)',
        [':u' => $userId, ':n' => "$name $suffix", ':c' => $name, ':s' => $state, ':d' => $days, ':l' => $limit]
    );
    $businessId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $userId; $businesses[] = $businessId;
    return ['user_id' => $userId, 'business_id' => $businessId];
};

/** An on-account order row, written the way checkout writes it. */
$writeOrder = function (int $userId, int $total, string $date) use ($suffix, &$orders): int {
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:n, :u, :ct, :os, :po, :ps, :st, :ot, :bd, :dd)',
        [':n' => 'COD-' . $suffix . '-' . random_int(1000, 9999), ':u' => $userId, ':ct' => 'business',
         ':os' => 'pending', ':po' => 'on_account', ':ps' => 'unpaid', ':st' => $total, ':ot' => $total,
         ':bd' => $total, ':dd' => $date]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[] = $id;
    return $id;
};

/** The signed journal balance, read straight from the table. */
$balance = static function (int $businessId): int {
    return (int) (Database::one(
        'SELECT COALESCE(SUM(amount_subunit), 0) AS total FROM credit_transactions WHERE business_customer_id = :b',
        [':b' => $businessId]
    )['total'] ?? 0);
};

try {
    // A 500,000 naira facility on 7 day terms.
    $green = $makeBusiness('Green', 'approved', 50000000, 7);
    $facility = Credit::facilityForUser($green['user_id']);
    co_eq(50000000, (int) $facility['available_subunit'], 'a new facility has its whole limit available');

    // 1. A credit order writes exactly one charge, dated on the approved term.
    $orderId = $writeOrder($green['user_id'], 42000000, $delivery);
    $pdo->beginTransaction();
    $draw = Credit::drawForOrder($green['user_id'], $orderId, 42000000, $delivery);
    $pdo->commit();
    co_ok(!$draw['already'], 'a credit order appends a charge');
    co_eq(Credit::dueDateFor($delivery, 7), (string) $draw['due_date'], 'the charge is due on the approved term after delivery');
    co_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $orderId, ':t' => 'charge'])['c'], 'exactly one charge exists for the order');
    co_eq(42000000, $balance($green['business_id']), 'the journal balance is the charge');
    co_eq(8000000, (int) Credit::facilityForUser($green['user_id'])['available_subunit'], 'available credit falls by the charge');

    // 2. A retry of the same order writes no second charge.
    $pdo->beginTransaction();
    $retry = Credit::drawForOrder($green['user_id'], $orderId, 42000000, $delivery);
    $pdo->commit();
    co_ok($retry['already'], 'a retried draw reports the charge it already made');
    co_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $orderId, ':t' => 'charge'])['c'], 'a retry cannot double charge an order');

    // 3. One kobo over the available credit writes neither order nor charge.
    $overId = $writeOrder($green['user_id'], 8000001, $delivery);
    $pdo->beginTransaction();
    co_refuses(fn() => Credit::drawForOrder($green['user_id'], $overId, 8000001, $delivery),
        'credit_limit_exceeded', 'one kobo above the available credit is refused');
    $pdo->rollBack();
    co_eq(null, Database::one('SELECT id FROM orders WHERE id = :id', [':id' => $overId]),
        'a refused credit order leaves no order behind');
    co_eq(0, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o', [':o' => $overId])['c'],
        'a refused credit order leaves no charge behind');
    array_pop($orders);

    // 4. An order exactly equal to the available credit succeeds.
    $exactId = $writeOrder($green['user_id'], 8000000, $delivery);
    $pdo->beginTransaction();
    Credit::drawForOrder($green['user_id'], $exactId, 8000000, $delivery);
    $pdo->commit();
    co_eq(50000000, $balance($green['business_id']), 'an order for the exact available credit is allowed');
    co_eq(0, (int) Credit::facilityForUser($green['user_id'])['available_subunit'], 'the facility is now fully drawn');

    // 5. A repayment frees exactly what it repays.
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
         VALUES (:n, :u, :o, :p, :t, :e, :a, :s, NOW())',
        [':n' => 'COD-P-' . $suffix, ':u' => $green['user_id'], ':o' => $orderId, ':p' => 'manual',
         ':t' => 'credit_repayment', ':e' => 3000000, ':a' => 3000000, ':s' => 'paid']
    );
    $paymentId = (int) $pdo->lastInsertId();
    $repayment = Credit::recordRepayment($green['business_id'], $paymentId);
    co_eq(3000000, (int) $repayment['amount_subunit'], 'the repayment is the confirmed payment amount');
    co_eq(3000000, (int) Credit::facilityForUser($green['user_id'])['available_subunit'], 'a repayment frees the same amount of limit');
    co_refuses(fn() => Credit::recordRepayment($green['business_id'], $paymentId), 'repayment_recorded',
        'the same payment cannot be credited twice');

    // 6. A cancellation appends an adjustment and never edits the charge.
    $chargeBefore = Database::one('SELECT id, amount_subunit, due_date FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $exactId, ':t' => 'charge']);
    Credit::adjustCancelledOrder($exactId);
    $chargeAfter = Database::one('SELECT id, amount_subunit, due_date FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $exactId, ':t' => 'charge']);
    co_eq($chargeBefore, $chargeAfter, 'cancelling a credit order leaves the original charge untouched');
    co_eq(-8000000, (int) Database::one('SELECT amount_subunit FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $exactId, ':t' => 'adjustment'])['amount_subunit'], 'cancellation appends a signed adjustment for the open amount');
    co_eq(39000000, $balance($green['business_id']), 'the calculated balance reconciles with the journal after cancellation');
    Credit::adjustCancelledOrder($exactId);
    co_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $exactId, ':t' => 'adjustment'])['c'], 'a repeated cancellation appends nothing further');

    // 7. A refund appends its own adjustment, capped at the refunded amount.
    Database::run(
        'INSERT INTO refunds (order_id, payment_id, amount_subunit, reason, status, requested_by)
         VALUES (:o, :p, :a, :r, :s, :u)',
        [':o' => $orderId, ':p' => $paymentId, ':a' => 2000000, ':r' => 'Short delivery on the tomatoes.',
         ':s' => 'succeeded', ':u' => $green['user_id']]
    );
    $refundId = (int) $pdo->lastInsertId();
    Credit::adjustRefund($orderId, $refundId, 2000000);
    co_eq(-2000000, (int) Database::one('SELECT amount_subunit FROM credit_transactions WHERE order_id = :o AND transaction_type = :t ORDER BY id DESC LIMIT 1',
        [':o' => $orderId, ':t' => 'adjustment'])['amount_subunit'], 'a refund appends a signed adjustment for the refunded amount');
    co_eq(37000000, $balance($green['business_id']), 'the refund reduces the calculated balance');
    Credit::adjustRefund($orderId, $refundId, 2000000);
    co_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE order_id = :o AND transaction_type = :t',
        [':o' => $orderId, ':t' => 'adjustment'])['c'], 'the same refund cannot be adjusted twice');

    // 8. Suspended and withdrawn facilities cannot be drawn on.
    foreach (['suspended', 'withdrawn'] as $state) {
        Database::run('UPDATE business_customers SET credit_status = :s WHERE id = :id', [':s' => $state, ':id' => $green['business_id']]);
        $blockedId = $writeOrder($green['user_id'], 100000, $delivery);
        $pdo->beginTransaction();
        co_refuses(fn() => Credit::drawForOrder($green['user_id'], $blockedId, 100000, $delivery),
            'credit_not_approved', "$state credit refuses a new order");
        $pdo->rollBack();
        array_pop($orders);
    }
    Database::run('UPDATE business_customers SET credit_status = :s WHERE id = :id', [':s' => 'approved', ':id' => $green['business_id']]);

    // 9. A household or an unknown business cannot draw at all.
    $other = $makeBusiness('Bowl', 'not_requested', 0, 0);
    $otherOrder = $writeOrder($other['user_id'], 100000, $delivery);
    $pdo->beginTransaction();
    co_refuses(fn() => Credit::drawForOrder($other['user_id'], $otherOrder, 100000, $delivery),
        'credit_not_approved', 'a business with no facility cannot go on account');
    $pdo->rollBack();
    array_pop($orders);

    // 10. Two orders racing for the same limit. The second connection waits on
    //     the facility row, so it cannot read a stale balance and overspend.
    $race = $makeBusiness('Race', 'approved', 10000000, 7);
    $firstId  = $writeOrder($race['user_id'], 6000000, $delivery);
    $secondId = $writeOrder($race['user_id'], 6000000, $delivery);

    $second = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $second->exec('SET SESSION innodb_lock_wait_timeout = 2');

    $pdo->beginTransaction();
    Credit::drawForOrder($race['user_id'], $firstId, 6000000, $delivery);

    $second->beginTransaction();
    $blocked = false;
    try {
        $lock = $second->prepare('SELECT id FROM business_customers WHERE user_id = :u FOR UPDATE');
        $lock->execute([':u' => $race['user_id']]);
    } catch (PDOException $e) {
        $blocked = true;
    }
    $second->rollBack();
    co_ok($blocked, 'a second credit order waits for the first to finish with the facility');
    $pdo->commit();

    // The loser now sees the committed charge and is refused on the limit.
    $pdo->beginTransaction();
    co_refuses(fn() => Credit::drawForOrder($race['user_id'], $secondId, 6000000, $delivery),
        'credit_limit_exceeded', 'the second order cannot overspend the limit');
    $pdo->rollBack();
    co_eq(6000000, $balance($race['business_id']), 'exactly one charge is committed for two racing orders');
    co_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM credit_transactions WHERE business_customer_id = :b AND transaction_type = :t',
        [':b' => $race['business_id'], ':t' => 'charge'])['c'], 'the race leaves one charge, not two');

    // 11. The draw refuses to run outside a transaction at all.
    $looseId = $writeOrder($race['user_id'], 1000000, $delivery);
    try {
        Credit::drawForOrder($race['user_id'], $looseId, 1000000, $delivery);
        co_ok(false, 'an unprotected draw should not be possible');
    } catch (LogicException $e) {
        co_ok(true, 'the draw refuses to run outside a transaction');
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    foreach ($businesses as $id) {
        Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id', [':id' => $id]);
        Database::run('DELETE FROM credit_applications WHERE business_customer_id = :id', [':id' => $id]);
    }
    foreach ($orders as $id) {
        Database::run('DELETE FROM refunds WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($businesses as $id) { Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $id]); }
    foreach ($users as $id) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} credit order assertions passed.\n");
exit($GLOBALS['p'] === $GLOBALS['t'] ? 0 : 1);
