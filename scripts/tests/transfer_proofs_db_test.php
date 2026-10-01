<?php
/**
 * scripts/tests/transfer_proofs_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Direct bank transfer against a real database (PRD Section 9.3a).
 *
 * TransferProofsTest and PaymentSummaryTest cover the pure rules. This covers
 * what only a database shows: that an order placed to be paid by transfer is
 * held and cannot be confirmed, that a receipt credits nothing until staff
 * verify it, that the figure staff type is the figure credited, that a decline
 * is closed and never deleted, that a part payment leaves a balance and a second
 * receipt settles it, and that the private receipt link and the ownership rules
 * hold.
 *
 *   php scripts/tests/transfer_proofs_db_test.php
 *
 * Creates throwaway fixtures, asserts, then removes everything it made and puts
 * the bank settings back as it found them. Run after php scripts/migrate.php on
 * a scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

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

$suffix    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$_SESSION  = [];
$orderIds  = [];
$userIds   = [];
$productId = null;
$categoryId = null;
$savedSettings = [];
$bankKeys = ['bank_transfer_enabled', 'bank_transfer_bank_name', 'bank_transfer_account_name', 'bank_transfer_account_number'];

/** Place one order and return its placed result plus what the basket came to. */
function okv_place_transfer_order(string $option, string $method, ?int $userId, int $productId, string $guestEmail = ''): array
{
    $_SESSION = $userId !== null ? ['user_id' => $userId] : [];
    Basket::addProduct($productId);
    $state = Basket::state();

    $dates = Delivery::nextEligibleDates('household', 1);
    $date  = $dates ? $dates[0]['date'] : date('Y-m-d', strtotime('+7 days'));
    $zone  = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order LIMIT 1');

    $result = Checkout::place([
        'user_id'          => $userId,
        'customer_type'    => 'household',
        'activated'        => $userId !== null,
        'email'            => $guestEmail,
        'recipient_name'   => 'ZZ Buyer',
        'recipient_phone'  => '+2348012345678',
        'address_line_1'   => '1 Test Street',
        'city'             => 'Lagos',
        'state'            => 'Lagos',
        'delivery_date'    => $date,
        'delivery_zone_id' => (int) $zone['id'],
        'payment_option'   => $option,
        'payment_method'   => $method,
    ]);
    $result['subtotal'] = (int) $state['subtotal_subunit'];
    return $result;
}

function okv_payment_row(int $orderId, string $type): array
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
    Settings::set('bank_transfer_bank_name', '', 'string');
    Settings::set('bank_transfer_account_name', '', 'string');
    Settings::set('bank_transfer_account_number', '', 'string');
    Settings::flushCache();

    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:n, :s, :d, 904, 1)',
        [':n' => 'ZZ Transfer ' . $suffix, ':s' => 'zz-transfer-' . strtolower($suffix), ':d' => 'Throwaway.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $unit   = Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active)
         VALUES (:cat, :unit, :name, :slug, :sku, :desc, 1000000, 1.000, 1.000, 1)',
        [':cat' => $categoryId, ':unit' => (int) $unit['id'], ':name' => 'ZZ Transfer Yam ' . $suffix,
         ':slug' => 'zz-transfer-' . strtolower($suffix), ':sku' => 'ZT-' . $suffix, ':desc' => 'Throwaway.']
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();

    foreach (['buyer', 'stranger', 'staff'] as $i => $who) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:f, :l, :e, :ph, :pw, \'household\', \'active\')',
            [':f' => 'ZZ', ':l' => ucfirst($who), ':e' => 'zt-' . $who . '-' . strtolower($suffix) . '@example.test',
             ':ph' => '+23470' . $i . substr($suffix, 0, 7), ':pw' => password_hash('x', PASSWORD_BCRYPT)]
        );
        $userIds[$who] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    $buyer = $userIds['buyer'];
    $staff = $userIds['staff'];

    // =========================================================================
    // 1. The option is only offered once the Owner has set the account up.
    // =========================================================================
    t_ok(!TransferProofs::isEnabled(), 'direct transfer is off until the Owner switches it on');
    $refused = false;
    try {
        okv_place_transfer_order('pay_in_full', 'bank_transfer', $buyer, $productId);
    } catch (DomainException $e) {
        $refused = $e->getMessage() === 'payment_not_allowed';
    }
    t_ok($refused, 'an order cannot be placed by transfer while the option is off, whatever the form posted');

    Settings::set('bank_transfer_enabled', true, 'bool');
    Settings::flushCache();
    t_ok(!TransferProofs::isEnabled(), 'switched on but with no account set, it is still not offered');

    Settings::set('bank_transfer_bank_name', 'Test Bank', 'string');
    Settings::set('bank_transfer_account_name', 'OK Veggies Test', 'string');
    Settings::set('bank_transfer_account_number', '012345678', 'string');   // nine digits
    Settings::flushCache();
    t_ok(!TransferProofs::isEnabled(), 'an account number with a digit missing is never offered');

    Settings::set('bank_transfer_account_number', '0123456789', 'string');
    Settings::flushCache();
    t_ok(TransferProofs::isEnabled(), 'with the switch on and the whole account set, direct transfer is offered');
    t_eq('0123456789', TransferProofs::bankDetails()['account_number'], 'the account number keeps its leading zero');

    // A method on a choice that has none is refused: cash on delivery cannot be a transfer.
    $refusedPod = false;
    try {
        okv_place_transfer_order('pay_on_delivery', 'bank_transfer', $buyer, $productId);
    } catch (DomainException $e) {
        $refusedPod = $e->getMessage() === 'payment_not_allowed';
    }
    t_ok($refusedPod, 'pay on delivery cannot be combined with a bank transfer');

    // =========================================================================
    // 2. A pay-in-full order placed by transfer: recorded as manual, held.
    // =========================================================================
    $placed  = okv_place_transfer_order('pay_in_full', 'bank_transfer', $buyer, $productId);
    $orderId = (int) $placed['order_id'];
    $orderIds[] = $orderId;
    $total   = (int) $placed['subtotal'];

    $row = okv_payment_row($orderId, 'pay_in_full');
    t_eq('manual', (string) $row['provider'], 'a transfer order is recorded as manual, not sent to Paystack');
    t_eq(null, Payments::pendingOnlinePayment($orderId), 'a transfer order has no Paystack charge waiting');
    $next = TransferProofs::nextTransferPayment($orderId);
    t_ok($next !== null, 'a transfer order has a payment a receipt can be uploaded against');
    t_eq($total, (int) $next['due_subunit'], 'the receipt is for the whole order total');

    $gate = TransferProofs::confirmationBlock($orderId);
    t_ok($gate !== null, 'a transfer order nobody has paid is held from confirmation');
    $blocked = OrderLifecycle::transition($orderId, 'pending', 'confirmed', $staff);
    t_eq(false, $blocked['ok'], 'staff cannot confirm an order whose transfer is not verified');
    t_eq('payment_unverified', $blocked['code'], 'and are told why');
    t_eq('pending', (string) Database::one('SELECT order_status FROM orders WHERE id = :o', [':o' => $orderId])['order_status'], 'the order stays pending');

    // =========================================================================
    // 3. A receipt credits nothing.
    // =========================================================================
    $sub = TransferProofs::submit((int) $row['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('a', 32) . '.jpg', 'payer_name' => 'ZZ Sender']);
    t_eq(true, $sub['ok'], 'a receipt can be submitted');
    t_eq($total, (int) $sub['amount_subunit'], 'the amount is worked out by us, not typed by the customer');
    $after = okv_payment_row($orderId, 'pay_in_full');
    t_eq(0, (int) $after['paid_amount_subunit'], 'a submitted receipt credits nothing');
    t_eq('unpaid', (string) $after['status'], 'the payment stays unpaid');
    $order = Database::one('SELECT payment_status, amount_paid_subunit FROM orders WHERE id = :o', [':o' => $orderId]);
    t_eq('unpaid', (string) $order['payment_status'], 'and so does the order');
    $txn = Database::one('SELECT * FROM payment_transactions WHERE id = :i', [':i' => (int) $sub['transaction_id']]);
    t_eq('awaiting_review', (string) $txn['status'], 'the transaction waits for review');
    t_eq('manual', (string) $txn['provider'], 'and is a manual one, so reversals and refunds read it');
    $proof = Database::one('SELECT * FROM manual_payment_proofs WHERE id = :i', [':i' => (int) $sub['proof_id']]);
    t_eq('submitted', (string) $proof['status'], 'the proof is in the submitted state');
    t_eq(1, (int) $proof['submitted_by_customer'], 'and is marked as the customer\'s');
    t_eq(null, $proof['recorded_by'], 'nobody on staff recorded it');

    $again = TransferProofs::submit((int) $row['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('b', 32) . '.jpg']);
    t_eq('already_submitted', $again['code'], 'a second receipt while one is being checked is refused');
    t_eq(null, TransferProofs::nextTransferPayment($orderId), 'nothing more can be submitted while one is with the team');

    $summary = PaymentSummary::forOrder($orderId);
    t_eq('awaiting', $summary['state'], 'the order says pending verification');
    t_eq(0, $summary['received_subunit'], 'and shows nothing received');
    t_eq($total, $summary['due_subunit'], 'with the whole amount still due');
    t_eq(1, count($summary['receipts']), 'one receipt is listed');
    t_ok($summary['via_transfer'], 'the summary knows it is a transfer order');

    t_ok(TransferProofs::confirmationBlock($orderId) !== null, 'the order is still held while the receipt is checked');

    // =========================================================================
    // 4. A decline is closed and never deleted, and the customer can try again.
    // =========================================================================
    $noReason = TransferProofs::decline((int) $sub['proof_id'], '   ', $staff);
    t_eq('reason_required', $noReason['code'], 'a decline needs a reason, because the customer is sent it');
    $dec = TransferProofs::decline((int) $sub['proof_id'], 'We cannot see this transfer in our account.', $staff);
    t_eq(true, $dec['ok'], 'staff can decline a receipt');
    $proof = Database::one('SELECT * FROM manual_payment_proofs WHERE id = :i', [':i' => (int) $sub['proof_id']]);
    t_eq('declined', (string) $proof['status'], 'the proof is closed as declined');
    t_ok(str_contains((string) $proof['review_note'], 'cannot see this transfer'), 'the reason is kept on the proof');
    t_eq($staff, (int) $proof['reviewed_by'], 'who declined it is kept');
    $txn = Database::one('SELECT status FROM payment_transactions WHERE id = :i', [':i' => (int) $sub['transaction_id']]);
    t_eq('failed', (string) $txn['status'], 'the transaction is closed as failed');
    t_eq(0, (int) okv_payment_row($orderId, 'pay_in_full')['paid_amount_subunit'], 'a declined receipt credits nothing');
    $decAgain = TransferProofs::decline((int) $sub['proof_id'], 'Again', $staff);
    t_eq('already_reviewed', $decAgain['code'], 'a receipt cannot be declined twice');
    $verifyDeclined = TransferProofs::verify((int) $sub['proof_id'], $total, '', $staff);
    t_eq('already_reviewed', $verifyDeclined['code'], 'a declined receipt cannot be verified afterwards');

    $summary = PaymentSummary::forOrder($orderId);
    t_eq('declined', $summary['state'], 'the order says the transfer could not be confirmed');
    t_ok(TransferProofs::nextTransferPayment($orderId) !== null, 'and the customer can upload another');
    t_ok(TransferProofs::confirmationBlock($orderId) !== null, 'the order is still held, nothing has been paid');
    t_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM manual_payment_proofs mp JOIN payment_transactions t ON t.id = mp.payment_transaction_id WHERE t.payment_id = :p', [':p' => (int) $row['id']])['n'], 'the declined receipt is still on file');

    // =========================================================================
    // 5. Verify a shortfall: the staff figure is what is credited.
    // =========================================================================
    $sub2 = TransferProofs::submit((int) $row['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('c', 32) . '.png', 'bank_reference' => 'FT99']);
    t_eq(true, $sub2['ok'], 'a fresh receipt is a fresh pair of rows');
    $half = intdiv($total, 2);

    t_eq('bad_amount', TransferProofs::verify((int) $sub2['proof_id'], 0, '', $staff)['code'], 'a zero amount is refused');
    $ver = TransferProofs::verify((int) $sub2['proof_id'], $half, 'Saw half in the bank.', $staff);
    t_eq(true, $ver['ok'], 'staff can verify a receipt for what they saw');
    t_eq('part', $ver['outcome'], 'a shortfall is reported as a part payment');
    t_eq($total, $ver['declared_subunit'], 'the declared amount is kept for comparison');
    $pay = okv_payment_row($orderId, 'pay_in_full');
    t_eq($half, (int) $pay['paid_amount_subunit'], 'the figure staff typed is what is credited');
    t_eq('part_paid', (string) $pay['status'], 'the payment is part paid');
    $order = Database::one('SELECT payment_status, amount_paid_subunit, balance_due_subunit FROM orders WHERE id = :o', [':o' => $orderId]);
    t_eq('part_paid', (string) $order['payment_status'], 'the order is part paid');
    t_eq($half, (int) $order['amount_paid_subunit'], 'the order records what was received');
    t_eq($total - $half, (int) $order['balance_due_subunit'], 'and what is still owed');
    $txn = Database::one('SELECT * FROM payment_transactions WHERE id = :i', [':i' => (int) $sub2['transaction_id']]);
    t_eq('success', (string) $txn['status'], 'the transaction is now a successful one');
    t_eq($half, (int) $txn['amount_subunit'], 'holding the credited amount');
    t_eq($half, (int) $txn['requested_amount_subunit'], 'and the requested amount follows it, so the dashboard sums the truth');
    $proof = Database::one('SELECT * FROM manual_payment_proofs WHERE id = :i', [':i' => (int) $sub2['proof_id']]);
    t_eq('approved', (string) $proof['status'], 'the proof is approved');
    t_eq($half, (int) $proof['verified_amount_subunit'], 'with the verified figure kept beside the declared one');
    t_eq($total, (int) $proof['amount_subunit'], 'and the declared figure untouched');
    t_eq('verified', $ver['code'], 'the result says verified');
    t_eq('already_reviewed', TransferProofs::verify((int) $sub2['proof_id'], $half, '', $staff)['code'], 'a receipt cannot be verified twice, so it cannot credit twice');
    t_eq($half, (int) okv_payment_row($orderId, 'pay_in_full')['paid_amount_subunit'], 'and the second attempt credited nothing');

    $summary = PaymentSummary::forOrder($orderId);
    t_eq('part_paid', $summary['state'], 'the order screen now says part paid');
    t_eq($half, $summary['received_subunit'], 'with the received figure');
    t_eq($total - $half, $summary['due_subunit'], 'and the still-due figure');
    t_eq('Bank transfer', $summary['last_received']['method'], 'the method reads as a bank transfer');
    t_eq(null, TransferProofs::confirmationBlock($orderId), 'once money is verified the order can be confirmed');

    // The remainder arrives as a second transfer and settles it.
    $next = TransferProofs::nextTransferPayment($orderId);
    t_ok($next !== null, 'the same payment can take a second receipt for the rest');
    t_eq($total - $half, (int) $next['due_subunit'], 'for exactly what is left');
    $sub3 = TransferProofs::submit((int) $row['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('d', 32) . '.pdf']);
    t_eq($total - $half, (int) $sub3['amount_subunit'], 'the second receipt is for the balance');
    $ver3 = TransferProofs::verify((int) $sub3['proof_id'], $total - $half, '', $staff);
    t_eq('settles', $ver3['outcome'], 'and settles the payment');
    $order = Database::one('SELECT payment_status, amount_paid_subunit, balance_due_subunit FROM orders WHERE id = :o', [':o' => $orderId]);
    t_eq('paid', (string) $order['payment_status'], 'the order is paid');
    t_eq($total, (int) $order['amount_paid_subunit'], 'in full');
    t_eq(0, (int) $order['balance_due_subunit'], 'with nothing left');
    t_eq(null, TransferProofs::nextTransferPayment($orderId), 'nothing more can be submitted once it is paid');
    t_eq('paid', PaymentSummary::forOrder($orderId)['state'], 'the screen says paid');
    $history = Database::all('SELECT source, reason FROM payment_status_history WHERE payment_id = :p ORDER BY id', [':p' => (int) $row['id']]);
    t_ok(count($history) >= 6, 'every step is in the append-only history');
    t_ok(in_array('customer', array_column($history, 'source'), true), 'including the customer handing the receipt in');

    // =========================================================================
    // 6. More than was owed is credited and flagged, never hidden.
    // =========================================================================
    $over = okv_place_transfer_order('pay_in_full', 'bank_transfer', $buyer, $productId);
    $orderIds[] = (int) $over['order_id'];
    $overRow = okv_payment_row((int) $over['order_id'], 'pay_in_full');
    $overSub = TransferProofs::submit((int) $overRow['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('e', 32) . '.jpg']);
    $overVer = TransferProofs::verify((int) $overSub['proof_id'], (int) $over['subtotal'] + 50000, '', $staff);
    t_eq('over', $overVer['outcome'], 'more than was owed is reported as over');
    t_eq((int) $over['subtotal'] + 50000, (int) okv_payment_row((int) $over['order_id'], 'pay_in_full')['paid_amount_subunit'], 'the figure received is credited as received');
    t_eq(0, PaymentSummary::forOrder((int) $over['order_id'])['due_subunit'], 'and an overpayment is never shown as a debt');

    // =========================================================================
    // 7. A cancelled order cannot be credited.
    // =========================================================================
    $cx = okv_place_transfer_order('pay_in_full', 'bank_transfer', $buyer, $productId);
    $orderIds[] = (int) $cx['order_id'];
    $cxRow = okv_payment_row((int) $cx['order_id'], 'pay_in_full');
    $cxSub = TransferProofs::submit((int) $cxRow['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('f', 32) . '.jpg']);
    Database::run('UPDATE orders SET order_status = \'cancelled\' WHERE id = :o', [':o' => (int) $cx['order_id']]);
    t_eq('order_cancelled', TransferProofs::verify((int) $cxSub['proof_id'], (int) $cx['subtotal'], '', $staff)['code'], 'staff cannot credit a cancelled order');
    t_eq(0, (int) okv_payment_row((int) $cx['order_id'], 'pay_in_full')['paid_amount_subunit'], 'and it credited nothing');
    t_eq('order_cancelled', TransferProofs::submit((int) $cxRow['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('9', 32) . '.jpg'])['code'], 'a customer cannot send a receipt for a cancelled order');
    t_eq(null, TransferProofs::nextTransferPayment((int) $cx['order_id']), 'and none is asked for');
    t_eq('cancelled', PaymentSummary::forOrder((int) $cx['order_id'])['state'], 'the summary says cancelled');
    t_eq(true, TransferProofs::decline((int) $cxSub['proof_id'], 'Order cancelled, refund arranged by hand.', $staff)['ok'], 'the receipt on a cancelled order can still be closed with a decline');

    // =========================================================================
    // 8. A deposit paid by transfer, and the balance after it.
    // =========================================================================
    $dep = okv_place_transfer_order('deposit', 'bank_transfer', $buyer, $productId);
    $orderIds[] = (int) $dep['order_id'];
    $depRow = okv_payment_row((int) $dep['order_id'], 'deposit');
    $balRow = okv_payment_row((int) $dep['order_id'], 'balance');
    t_eq('manual', (string) $depRow['provider'], 'a deposit by transfer is recorded as manual');
    t_eq('manual', (string) $balRow['provider'], 'and the balance is manual as before');
    $depNext = TransferProofs::nextTransferPayment((int) $dep['order_id']);
    t_eq((int) $depRow['id'], (int) $depNext['id'], 'the deposit is asked for before the balance');
    $depDue = (int) $depNext['due_subunit'];
    t_eq(Money::deposit((int) $dep['subtotal'], Settings::depositPercentage()), $depDue, 'and it is the configured deposit');
    $depSub = TransferProofs::submit((int) $depRow['id'], ['proof_url' => 'uploads/payment_proofs/' . str_repeat('1', 32) . '.jpg']);
    $depVer = TransferProofs::verify((int) $depSub['proof_id'], $depDue, '', $staff);
    t_eq('settles', $depVer['outcome'], 'verifying the deposit settles it');
    t_eq('deposit', $depVer['payment_type'], 'and says it was the deposit');
    $depSummary = PaymentSummary::forOrder((int) $dep['order_id']);
    t_eq('part_paid', $depSummary['state'], 'an order with only its deposit paid is part paid');
    t_eq((int) $dep['subtotal'] - $depDue, $depSummary['due_subunit'], 'and says what is left after the deposit');
    t_eq(2, count($depSummary['lines']), 'with a line for the deposit and one for the balance');
    t_eq('paid', $depSummary['lines'][0]['status'], 'the deposit line is paid');
    t_eq('unpaid', $depSummary['lines'][1]['status'], 'the balance line is not');
    $balNext = TransferProofs::nextTransferPayment((int) $dep['order_id']);
    t_eq((int) $balRow['id'], (int) $balNext['id'], 'the balance can then be paid by transfer as well');
    t_eq(null, TransferProofs::confirmationBlock((int) $dep['order_id']), 'a deposit order with the deposit verified can be confirmed');

    // =========================================================================
    // 9. Who may act on an order.
    // =========================================================================
    t_ok(TransferProofs::customerMayActOn($orderId, $buyer, ''), 'the signed-in owner may send a receipt');
    t_ok(!TransferProofs::customerMayActOn($orderId, $userIds['stranger'], ''), 'another account may not');
    t_ok(!TransferProofs::customerMayActOn($orderId, null, ''), 'nobody with no credential may');
    t_ok(!TransferProofs::customerMayActOn(0, $buyer, ''), 'a missing order is refused');

    $guest = okv_place_transfer_order('pay_in_full', 'bank_transfer', null, $productId, 'zt-guest-' . strtolower($suffix) . '@example.test');
    $orderIds[] = (int) $guest['order_id'];
    t_ok($guest['trail_token'] !== '', 'a guest order has a trail token');
    t_ok(TransferProofs::customerMayActOn((int) $guest['order_id'], null, (string) $guest['trail_token']), 'a guest holding the trail token may send a receipt');
    t_ok(!TransferProofs::customerMayActOn((int) $guest['order_id'], null, str_repeat('x', 43)), 'a wrong token may not');
    t_ok(!TransferProofs::customerMayActOn($orderId, null, (string) $guest['trail_token']), 'a token opens its own order and no other');
    t_ok(!TransferProofs::customerMayActOn($orderId, null, (string) $guest['trail_token']) && !TransferProofs::customerMayActOn($orderId, null, ''), 'and never an account owned order, whatever token is sent');
    $shareToken = OrderTrail::issueForOrder((int) $guest['order_id'], $staff);
    t_ok($shareToken !== null && TransferProofs::customerMayActOn((int) $guest['order_id'], null, (string) $shareToken), 'a link issued into an email for a guest order works too, so a declined guest can always upload again');
    $ownedShare = OrderTrail::issueForOrder($orderId, $staff);
    t_ok($ownedShare !== null && !TransferProofs::customerMayActOn($orderId, null, (string) $ownedShare), 'but a link for an account owned order can never be used without signing in');

    // =========================================================================
    // 10. The private receipt link.
    // =========================================================================
    $token = ReceiptLink::issue($orderId);
    t_ok($token !== null && ReceiptLink::isValidToken($token), 'a receipt link can be issued');
    t_eq($orderId, ReceiptLink::findOrderId((string) $token), 'and opens its own order');
    t_eq(null, ReceiptLink::findOrderId(str_repeat('z', 43)), 'a made-up token opens nothing');
    t_eq(null, ReceiptLink::findOrderId('short'), 'a malformed token opens nothing');
    t_eq(null, ReceiptLink::findOrderId((string) $guest['trail_token']), 'the shareable trail token does not open the receipt screen');
    $stored = Database::one('SELECT token_hash FROM order_receipt_links WHERE order_id = :o ORDER BY id DESC LIMIT 1', [':o' => $orderId]);
    t_eq(OrderTrail::hashToken((string) $token), (string) $stored['token_hash'], 'only the hash is stored');
    t_ok((string) $stored['token_hash'] !== (string) $token, 'the plain token is never stored');
    t_eq(null, ReceiptLink::issue(999999999), 'no link is issued for an order that does not exist');
    t_ok(str_contains(ReceiptLink::url((string) $token), '/public/payment/receipt.php?token='), 'the link points at the receipt screen');

    // =========================================================================
    // 11. The words that go out.
    // =========================================================================
    Notifications::announceTransferSubmitted($sub2);
    $rows = Database::all('SELECT event_type, title, body, cta_url FROM notifications WHERE related_id = :o AND event_type = \'transfer_receipt_received\'', [':o' => $orderId]);
    t_ok(count($rows) >= 1, 'handing in a receipt tells the customer it is with the team');
    t_ok(str_contains((string) $rows[0]['body'], 'pending verification'), 'in words that say pending verification');

    Notifications::announceTransferVerified($ver3, $staff);
    $rows = Database::all('SELECT event_type, title, body, cta_url FROM notifications WHERE related_type = \'payment_receipt\' AND related_id = :o', [':o' => $orderId]);
    t_ok(count($rows) >= 1, 'verifying a transfer tells the customer');
    t_ok(str_contains((string) $rows[0]['cta_url'], '/public/payment/receipt.php?token='), 'and the button opens the green payment screen, not the money-free trail');
    t_ok(!str_contains((string) $rows[0]['body'], '/public/order.php'), 'and the words carry no trail link');
    t_eq('Payment received for ' . $placed['order_number'], (string) $rows[0]['title'], 'the subject says payment received');

    $decOrderRow = Database::one('SELECT id FROM orders WHERE id = :o', [':o' => (int) $cx['order_id']]);
    Notifications::announceTransferDeclined($dec, $staff);
    $rows = Database::all('SELECT body, cta_url FROM notifications WHERE event_type = \'transfer_declined\' AND related_id = :o', [':o' => $orderId]);
    t_ok(count($rows) >= 1, 'declining a receipt tells the customer');
    t_ok(str_contains((string) $rows[0]['body'], 'cannot see this transfer'), 'with the reason staff gave');
    t_ok(str_contains((string) $rows[0]['cta_url'], '/public/order.php'), 'and a way back to upload another');

    // =========================================================================
    // 12. A stale Paystack path is untouched: an ordinary order is not held.
    // =========================================================================
    $card = okv_place_transfer_order('pay_in_full', 'paystack', $buyer, $productId);
    $orderIds[] = (int) $card['order_id'];
    t_eq('paystack', (string) okv_payment_row((int) $card['order_id'], 'pay_in_full')['provider'], 'choosing Paystack still records Paystack');
    t_ok(Payments::pendingOnlinePayment((int) $card['order_id']) !== null, 'and still leaves a charge to take');
    t_eq(null, TransferProofs::confirmationBlock((int) $card['order_id']), 'and is never held by the transfer gate');
    t_eq(null, TransferProofs::nextTransferPayment((int) $card['order_id']), 'and offers no receipt upload');
    $plain = okv_place_transfer_order('pay_in_full', '', $buyer, $productId);
    $orderIds[] = (int) $plain['order_id'];
    t_eq('paystack', (string) okv_payment_row((int) $plain['order_id'], 'pay_in_full')['provider'], 'an order placed with no method is a Paystack order, as before');

} catch (Throwable $e) {
    fwrite(STDOUT, "  FAIL: threw " . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDOUT, "        " . $e->getFile() . ':' . $e->getLine() . "\n");
    $GLOBALS['t']++;
} finally {
    // --- Clean up ------------------------------------------------------------
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type IN (\'order\', \'payment_receipt\') AND related_id = :o)', [':o' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type IN (\'order\', \'payment_receipt\') AND related_id = :o', [':o' => $id]);
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
    foreach ($userIds as $uid) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $uid]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $uid]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $uid]);
    }
    if ($productId)  { Database::run('DELETE FROM products WHERE id = :p', [':p' => $productId]); }
    if ($categoryId) { Database::run('DELETE FROM product_categories WHERE id = :c', [':c' => $categoryId]); }
    foreach ($savedSettings as $key => $value) {
        if ($value !== null) {
            Database::run('UPDATE site_settings SET setting_value = :v WHERE setting_key = :k', [':v' => $value, ':k' => $key]);
        }
    }
    Settings::flushCache();
}

fwrite(STDOUT, "\n" . $GLOBALS['p'] . ' / ' . $GLOBALS['t'] . " database assertions passed.\n");
exit($GLOBALS['p'] === $GLOBALS['t'] ? 0 : 1);
