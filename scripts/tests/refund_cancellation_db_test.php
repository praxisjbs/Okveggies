<?php
/**
 * The one money path that cannot be proved against the real gateway without
 * moving real money: a Paystack-paid order cancelled by staff, the refund
 * actually raised, and the webhook that later says it landed.
 *
 * It runs against the stand-in gateway in scripts/tests/fake/paystack.php.
 * Start it first, and point the test at it:
 *
 *   php -S 127.0.0.1:8124 scripts/tests/fake/paystack.php &
 *   PAYSTACK_BASE_URL=http://127.0.0.1:8124 php scripts/tests/refund_cancellation_db_test.php
 *
 * The override is ignored unless PAYSTACK_SECRET_KEY is a test key, so this
 * test refuses to run against a live integration rather than risking a real
 * refund. Nothing here ever reaches api.paystack.co.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function r_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function r_eq($expected, $actual, string $label): void { r_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

// A live key here would mean a real refund. Refuse rather than risk it.
if (!Paystack::isTestMode()) {
    fwrite(STDERR, "This test needs a test key (sk_test_). Refusing to run against a live integration.\n");
    exit(2);
}
if (!Paystack::isOverridden()) {
    fwrite(STDERR, "Set PAYSTACK_BASE_URL to the stand-in gateway before running this.\n");
    exit(2);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userId = 0; $orderId = 0;
$oldForfeit = Settings::bool('cancellation_deposit_forfeit_after_cutoff', true);
$oldDispatch = Settings::bool('cancellation_dispatched_forfeit_deposit', true);
try {
    Settings::set('cancellation_deposit_forfeit_after_cutoff', true, 'bool', null);
    Settings::set('cancellation_dispatched_forfeit_deposit', true, 'bool', null);
    Settings::flushCache();

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Refund\', \'Buyer\', :email, :phone, :hash, \'household\', \'active\')',
        [':email' => "refund-$suffix@example.test", ':phone' => '+23477' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    // Paid in full online, 10,000 with a 3,000 deposit, and already on the van.
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_required_subunit, amount_paid_subunit, balance_due_subunit,
             preferred_delivery_date)
         VALUES (:number, :user, \'household\', \'dispatched\', \'deposit\', \'paid\',
             1000000, 1000000, 300000, 1000000, 0, :date)',
        [':number' => "ZZ-RF-$suffix", ':user' => $userId, ':date' => date('Y-m-d', strtotime('+3 days'))]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO order_status_history (order_id, new_status, source, changed_by) VALUES (:o, \'pending\', \'customer\', :u)', [':o' => $orderId, ':u' => $userId]);

    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status)
         VALUES (:number, :user, :order, \'paystack\', \'full\', 1000000, 1000000, \'paid\')',
        [':number' => "PAY-RF-$suffix", ':user' => $userId, ':order' => $orderId]
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $reference = 'OKVRF' . strtoupper($suffix);
    Database::run(
        'INSERT INTO payment_transactions (payment_id, provider, reference, domain, status, requested_amount_subunit, amount_subunit, customer_email, paid_at)
         VALUES (:payment, \'paystack\', :ref, \'test\', :status, 1000000, 1000000, :email, NOW())',
        [':payment' => $paymentId, ':ref' => $reference, ':status' => Payments::TXN_SUCCESS, ':email' => "refund-$suffix@example.test"]
    );

    // --- The cancellation raises a real refund through the M5 engine -------
    $done = OrderCancellation::cancelForStaff($orderId, $userId, 'customer_requested', 'Changed their mind at the gate.', true, true);
    r_ok($done['ok'], 'a Paystack paid order on the van can be cancelled by staff');
    r_eq(700000, (int) $done['refund_subunit'], 'the refund is everything above the deposit');
    r_eq(300000, (int) $done['forfeit_subunit'], 'the deposit is kept because the order was dispatched');
    r_eq(0, (int) $done['manual_subunit'], 'nothing is left needing a person: this is all gateway money');
    r_eq('pending', (string) $done['refund_status'], 'the cancellation reports the refund as raised and unconfirmed');

    $refund = Database::one('SELECT id, amount_subunit, status, provider_refund_id, merchant_note FROM refunds WHERE order_id = :o', [':o' => $orderId]);
    r_ok((bool) $refund, 'a refund row exists, so the money was actually asked for');
    r_eq(700000, (int) $refund['amount_subunit'], 'the refund row carries exactly the amount the policy allowed');
    r_eq(Refunds::STATUS_PENDING, (string) $refund['status'], 'the refund is pending until the gateway says otherwise');
    r_ok(trim((string) $refund['provider_refund_id']) !== '', 'the gateway id is recorded, so it can be chased');
    r_eq(1, count(Database::all('SELECT id FROM refunds WHERE order_id = :o', [':o' => $orderId])), 'exactly one refund is raised, never two');

    // The customer is not told the money has arrived before it has.
    r_ok(
        str_contains(Refunds::customerStatusLine((string) $refund['status']), 'on its way'),
        'a pending refund is described as on its way, not as arrived'
    );

    // --- The webhook that says it landed ----------------------------------
    $applied = Refunds::applyWebhook('refund.processed', [
        'id'          => (int) $refund['provider_refund_id'],
        'status'      => 'processed',
        'amount'      => 700000,
        'transaction' => ['reference' => $reference],
    ]);
    r_ok($applied['ok'], 'the refund webhook is accepted');
    r_eq(Refunds::STATUS_PROCESSED, (string) $applied['code'], 'the webhook moves the refund to processed');
    r_eq($orderId, (int) $applied['order_id'], 'the webhook result names the order, so the customer can be told');
    r_eq(700000, (int) $applied['amount_subunit'], 'the webhook result carries the amount for the email');
    r_eq(Refunds::STATUS_PROCESSED, (string) Database::one('SELECT status FROM refunds WHERE id = :id', [':id' => (int) $refund['id']])['status'], 'the refund row is processed');
    r_eq('processed', (string) Database::one('SELECT refund_status FROM order_cancellations WHERE order_id = :o', [':o' => $orderId])['refund_status'], 'the cancellation summary follows the refund to processed');
    r_ok(
        str_contains(Refunds::customerStatusLine(Refunds::STATUS_PROCESSED), 'has been sent'),
        'only now does the customer copy say the money has gone'
    );

    // A repeat of the same webhook does not refund anyone twice.
    $again = Refunds::applyWebhook('refund.processed', [
        'id' => (int) $refund['provider_refund_id'], 'status' => 'processed', 'amount' => 700000,
        'transaction' => ['reference' => $reference],
    ]);
    r_ok(in_array((string) $again['code'], ['unchanged', 'already_final'], true), 'a repeated refund webhook is ignored');
    r_eq(1, count(Database::all('SELECT id FROM refunds WHERE order_id = :o', [':o' => $orderId])), 'a repeated webhook raises no second refund');

    // The reference-only path. Paystack sends `transaction` as an object, and a
    // refund event can arrive before the gateway id is stored, so this lookup is
    // the one that has to work when the id lookup misses.
    Database::run('UPDATE refunds SET provider_refund_id = NULL, status = :s WHERE id = :id', [':s' => Refunds::STATUS_PENDING, ':id' => (int) $refund['id']]);
    $byReference = Refunds::applyWebhook('refund.processed', [
        'status'      => 'processed',
        'amount'      => 700000,
        'transaction' => ['reference' => $reference],
    ]);
    r_ok($byReference['ok'], 'a refund event with no gateway id is matched on the transaction reference');
    r_eq($orderId, (int) $byReference['order_id'], 'the reference match lands on the right order');
    r_eq(Refunds::STATUS_PROCESSED, (string) Database::one('SELECT status FROM refunds WHERE id = :id', [':id' => (int) $refund['id']])['status'], 'the reference matched refund is processed');

    // --- What the customer is sent ----------------------------------------
    $notified = Notifications::announceRefund($applied);
    $sent = Database::all(
        'SELECT n.event_type FROM notifications n WHERE n.related_type = \'order\' AND n.related_id = :o AND n.event_type = \'refund_processed\'',
        [':o' => $orderId]
    );
    r_eq(1, count($sent), 'a processed refund tells the customer, once');
} finally {
    Settings::set('cancellation_deposit_forfeit_after_cutoff', $oldForfeit, 'bool', null);
    Settings::set('cancellation_dispatched_forfeit_deposit', $oldDispatch, 'bool', null);
    Settings::flushCache();
    if ($orderId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :o)', [':o' => $orderId]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM refund_status_history WHERE refund_id IN (SELECT id FROM refunds WHERE order_id = :o)', [':o' => $orderId]);
        Database::run('DELETE FROM refunds WHERE order_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM order_cancellations WHERE order_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)', [':o' => $orderId]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :o)', [':o' => $orderId]);
        Database::run('DELETE FROM payments WHERE order_id = :o', [':o' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :o', [':o' => $orderId]);
    }
    if ($userId) {
        Database::run('DELETE FROM notification_deliveries WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $userId]);
    }
}
fwrite(STDOUT, "\n$passed / $tests refund and cancellation assertions passed.\n");
exit($passed === $tests ? 0 : 1);
