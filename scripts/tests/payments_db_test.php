<?php
/**
 * scripts/tests/payments_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The money path against a real database.
 *
 * The pure rules are covered by PaymentsTest, ManualPaymentsTest, RefundsTest
 * and CancellationTest. This covers what only a database shows, and what the
 * unit tests provably could not: that placing an order leaves something a
 * customer can actually pay, that a verified charge credits the right figure
 * exactly once, that manual money and reversals move the order, and that the
 * guards hold when two things race.
 *
 *   php scripts/tests/payments_db_test.php
 *
 * Creates throwaway fixtures, asserts, then removes everything it made.
 * Run it after php scripts/migrate.php on a scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/bootstrap.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0;
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function t_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    t_ok($same, $label);
}

$suffix   = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$_SESSION = [];
$orderIds = [];
$userId   = null;
$productId = null;
$categoryId = null;

/** Place one order of the given payment option and return its id. */
function okv_place_order(string $option, int $userId, int $productId): array
{
    $_SESSION = ['user_id' => $userId];
    Basket::addProduct($productId);
    $state = Basket::state();

    $dates = Delivery::nextEligibleDates('household', 1);
    $date  = $dates ? $dates[0]['date'] : date('Y-m-d', strtotime('+7 days'));
    $zone  = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order LIMIT 1');

    $result = Checkout::place([
        'user_id'          => $userId,
        'customer_type'    => 'household',
        'activated'        => true,
        'recipient_name'   => 'ZZ Buyer',
        'recipient_phone'  => '+2348012345678',
        'address_line_1'   => '1 Test Street',
        'city'             => 'Lagos',
        'state'            => 'Lagos',
        'delivery_date'    => $date,
        'delivery_zone_id' => (int) $zone['id'],
        'payment_option'   => $option,
    ]);
    $result['subtotal'] = (int) $state['subtotal_subunit'];
    return $result;
}

try {
    // --- Fixtures ------------------------------------------------------------
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:n, :s, :d, 903, 1)',
        [':n' => 'ZZ Pay ' . $suffix, ':s' => 'zz-pay-' . strtolower($suffix), ':d' => 'Throwaway.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $unit   = Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    $unitId = (int) $unit['id'];

    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active)
         VALUES (:cat, :unit, :name, :slug, :sku, :desc, 300500, 1.000, 1.000, 1)',
        [':cat' => $categoryId, ':unit' => $unitId, ':name' => 'ZZ Pay Tomato ' . $suffix,
         ':slug' => 'zz-pay-' . strtolower($suffix), ':sku' => 'ZP-' . $suffix, ':desc' => 'Throwaway.']
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :ph, :pw, \'household\', \'active\')',
        [':f' => 'ZZ', ':l' => 'Payer', ':e' => 'zp-' . strtolower($suffix) . '@example.test',
         ':ph' => '+2347' . substr($suffix, 0, 9), ':pw' => password_hash('x', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    // =========================================================================
    // 1. A placed order must leave something the customer can actually pay.
    //    This is the exact failure that reached production: the ledger was
    //    built, and nothing on the order page could find anything to charge.
    // =========================================================================
    $placed = okv_place_order('pay_in_full', $userId, $productId);
    $orderId = (int) $placed['order_id'];
    $orderIds[] = $orderId;

    $pending = Payments::pendingOnlinePayment($orderId);
    t_ok($pending !== null, 'a placed pay-in-full order has a payment the customer can pay online');
    t_eq($placed['subtotal'], (int) $pending['expected_amount_subunit'], 'the payment expects the order total');
    t_eq('pay_in_full', (string) $pending['payment_type'], 'the payment carries the choice the customer made');
    t_eq(0, (int) $pending['paid_amount_subunit'], 'nothing is paid on it yet');

    // =========================================================================
    // 2. A verified charge credits requested_amount, never the gross amount.
    //    Paystack's own sample has amount = requested_amount + fees, so
    //    crediting the wrong field over-credits every order by the fee.
    // =========================================================================
    $paymentId = (int) $pending['id'];
    $reference = Payments::reference((string) $placed['order_number'], 1);
    $goods     = (int) $pending['expected_amount_subunit'];
    $fees      = 10283;

    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, currency, customer_email)
         VALUES (:pid, 1, \'paystack\', :ref, :domain, \'initialized\', :amount, :cur, :email)',
        [':pid' => $paymentId, ':ref' => $reference, ':domain' => Paystack::domain(),
         ':amount' => $goods, ':cur' => Money::CODE, ':email' => 'zp@example.test']
    );

    $verified = [
        'id'               => 4099260516,
        'domain'           => Paystack::domain(),
        'status'           => 'success',
        'reference'        => $reference,
        'amount'           => $goods + $fees,   // gross, fee borne by the customer
        'requested_amount' => $goods,           // the price of the goods
        'fees'             => $fees,
        'currency'         => 'NGN',
        'channel'          => 'card',
        'paid_at'          => date('c'),
        'metadata'         => '',               // Paystack really does send ""
        'authorization'    => ['card_type' => 'visa ', 'last4' => '4081', 'bank' => 'TEST BANK'],
        'customer'         => ['customer_code' => 'CUS_zztest'],
    ];

    $applied = Payments::applyVerifiedCharge($reference, $verified, 'webhook', null);
    t_ok($applied['ok'], 'a verified charge is accepted');
    t_eq($goods, $applied['credited'], 'the order is credited the goods price, not the gross charge');
    t_eq('exact', $applied['mismatch'], 'the credited amount matches what was expected');

    $order = Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
    t_eq('paid', (string) $order['payment_status'], 'the order reads as paid');
    t_eq($goods, (int) $order['amount_paid_subunit'], 'the order records what was paid');
    t_eq(0, (int) $order['balance_due_subunit'], 'nothing is left owing');

    $txn = Database::one('SELECT * FROM payment_transactions WHERE reference = :r', [':r' => $reference]);
    t_eq($fees, (int) $txn['provider_fee_subunit'], 'the Paystack fee is recorded separately');
    t_eq('visa', (string) $txn['card_type'], 'the trailing space in Paystack card_type is trimmed');
    t_eq('4081', (string) $txn['last4'], 'the last four digits are kept');
    t_ok($txn['metadata'] !== null, 'the empty-string metadata did not fatal');

    t_ok(
        Payments::pendingOnlinePayment($orderId) === null,
        'once paid, the order offers nothing more to pay'
    );

    // =========================================================================
    // 3. The same event again must not credit twice. Paystack retries for 72
    //    hours, so this is the single most important guard in the ledger.
    // =========================================================================
    $again = Payments::applyVerifiedCharge($reference, $verified, 'webhook', null);
    t_ok(!$again['ok'], 'the same charge applied twice is refused');
    t_eq('duplicate', $again['code'], 'and is recorded as a duplicate');

    $order = Database::one('SELECT amount_paid_subunit FROM orders WHERE id = :id', [':id' => $orderId]);
    t_eq($goods, (int) $order['amount_paid_subunit'], 'a duplicate event does not move the money again');

    // A test-mode event must never credit a live order, and the reverse.
    $wrongDomain = $verified;
    $wrongDomain['domain'] = Paystack::domain() === 'test' ? 'live' : 'test';
    $refused = Payments::applyVerifiedCharge($reference, $wrongDomain, 'webhook', null);
    t_ok(!$refused['ok'], 'an event from the wrong domain is refused');

    // =========================================================================
    // 4. A deposit order writes two payment rows, and only the deposit is due.
    // =========================================================================
    $dep = okv_place_order('deposit', $userId, $productId);
    $depOrderId = (int) $dep['order_id'];
    $orderIds[] = $depOrderId;

    $rows = Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $depOrderId]);
    t_eq(2, count($rows), 'a deposit order writes a deposit row and a balance row');
    t_eq('deposit', (string) $rows[0]['payment_type'], 'the first row is the deposit');
    t_eq('balance', (string) $rows[1]['payment_type'], 'the second row is the balance');
    t_eq(
        (int) $dep['subtotal'],
        (int) $rows[0]['expected_amount_subunit'] + (int) $rows[1]['expected_amount_subunit'],
        'deposit plus balance is exactly the order total, no kobo lost'
    );
    t_ok($rows[1]['due_at'] !== null, 'the balance carries a due date');

    $depPending = Payments::pendingOnlinePayment($depOrderId);
    t_ok($depPending !== null, 'a deposit order offers the deposit to pay online');
    t_eq('deposit', (string) $depPending['payment_type'], 'and it is the deposit, not the balance');

    // =========================================================================
    // 5. Manual money: staff record a transfer against the balance.
    // =========================================================================
    $balanceId = (int) $rows[1]['id'];
    $balanceDue = (int) $rows[1]['expected_amount_subunit'];
    $token = ManualPayments::newToken();

    $rec = ManualPayments::record([
        'payment_id'     => $balanceId,
        'amount_subunit' => $balanceDue,
        'method'         => 'transfer',
        'record_token'   => $token,
        'bank_reference' => 'FT' . $suffix,
    ], $userId);
    t_ok($rec['ok'], 'staff can record a transfer against the balance');
    t_eq('settles', $rec['outcome'], 'recording the full balance settles it');

    $balance = Database::one('SELECT * FROM payments WHERE id = :id', [':id' => $balanceId]);
    t_eq('paid', (string) $balance['status'], 'the balance payment reads as paid');
    t_eq($balanceDue, (int) $balance['paid_amount_subunit'], 'the balance holds what was recorded');

    // The same form submitted twice must not credit twice.
    $dupe = ManualPayments::record([
        'payment_id'     => $balanceId,
        'amount_subunit' => $balanceDue,
        'method'         => 'transfer',
        'record_token'   => $token,
        'bank_reference' => 'FT' . $suffix,
    ], $userId);
    t_ok(!$dupe['ok'], 'the same record form submitted twice is refused');
    t_eq('already_recorded', $dupe['code'], 'and says it was already recorded');

    $balance = Database::one('SELECT paid_amount_subunit FROM payments WHERE id = :id', [':id' => $balanceId]);
    t_eq($balanceDue, (int) $balance['paid_amount_subunit'], 'a double submit does not double credit');

    // A transfer with no evidence at all is refused.
    $noProof = ManualPayments::record([
        'payment_id'     => $balanceId,
        'amount_subunit' => 1000,
        'method'         => 'transfer',
        'record_token'   => ManualPayments::newToken(),
    ], $userId);
    t_ok(!$noProof['ok'], 'a transfer with neither a reference nor a screenshot is refused');
    t_eq('evidence_required', $noProof['code'], 'and says why');

    // =========================================================================
    // 6. A reversal takes the money back off the order, and needs two people.
    // =========================================================================
    $manualTxn = Database::one(
        'SELECT id FROM payment_transactions WHERE payment_id = :p AND provider = \'manual\' ORDER BY id DESC LIMIT 1',
        [':p' => $balanceId]
    );
    $req = ManualPayments::requestReversal((int) $manualTxn['id'], 'Recorded against the wrong order.', $userId);
    t_ok($req['ok'], 'a reversal can be requested');

    $reversal = Database::one(
        'SELECT id FROM payment_reversals WHERE payment_transaction_id = :t ORDER BY id DESC LIMIT 1',
        [':t' => (int) $manualTxn['id']]
    );
    $self = ManualPayments::decideReversal((int) $reversal['id'], 'approved', '', $userId, false);
    t_ok(!$self['ok'], 'the person who asked cannot approve their own reversal');
    t_eq('self_approval', $self['code'], 'and is told why');

    $ok = ManualPayments::decideReversal((int) $reversal['id'], 'approved', 'Confirmed.', $userId, true);
    t_ok($ok['ok'], 'an Owner may approve it, because they may be the only account');

    $balance = Database::one('SELECT * FROM payments WHERE id = :id', [':id' => $balanceId]);
    t_eq(0, (int) $balance['paid_amount_subunit'], 'the reversal takes the money back off the payment');
    t_eq('unpaid', (string) $balance['status'], 'and the payment is unpaid again');

    $reversedTxn = Database::one('SELECT status FROM payment_transactions WHERE id = :id', [':id' => (int) $manualTxn['id']]);
    t_eq('reversed', (string) $reversedTxn['status'], 'the reversed transaction is marked, never deleted');

    // =========================================================================
    // 7. Every move left an audit trail.
    // =========================================================================
    $history = Database::all(
        'SELECT * FROM payment_status_history WHERE payment_id = :id ORDER BY id',
        [':id' => $balanceId]
    );
    t_ok(count($history) >= 2, 'the recording and the reversal are both in the payment history');

} catch (Throwable $e) {
    fwrite(STDOUT, "  FAIL: threw " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDOUT, "        " . $e->getFile() . ':' . $e->getLine() . "\n");
    $GLOBALS['t']++;
} finally {
    // --- Clean up ------------------------------------------------------------
    foreach ($orderIds as $id) {
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
    if ($userId) {
        Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $userId]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $userId]);
    }
    if ($productId)  { Database::run('DELETE FROM products WHERE id = :p', [':p' => $productId]); }
    if ($categoryId) { Database::run('DELETE FROM product_categories WHERE id = :c', [':c' => $categoryId]); }
}

fwrite(STDOUT, "\n" . $GLOBALS['p'] . ' / ' . $GLOBALS['t'] . " database assertions passed.\n");
exit($GLOBALS['p'] === $GLOBALS['t'] ? 0 : 1);
