<?php
/**
 * Cancellation integration against a migrated scratch database.
 * Creates direct order fixtures and removes them afterwards.
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function c_ok($condition, string $label): void {
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}
function c_eq($expected, $actual, string $label): void {
    c_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $orderIds = [];
$oldAllowed = Settings::bool('cancellation_customer_allowed', true);
$oldCutoff = Settings::str('cancellation_cutoff_time', '18:00');
$oldForfeit = Settings::bool('cancellation_deposit_forfeit_after_cutoff', true);
function c_order(int $userId, string $status, string $paymentStatus, int $paid, int $deposit, string $date, string $suffix): int {
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, deposit_required_subunit, amount_paid_subunit,
             balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, \'household\', :status, \'deposit\', :payment,
                 1000000, 1000000, :deposit, :paid, :balance, :delivery)',
        [':number' => 'ZZ-CAN-' . $suffix . '-' . random_int(100, 999), ':user' => $userId,
         ':status' => $status, ':payment' => $paymentStatus, ':deposit' => $deposit,
         ':paid' => $paid, ':balance' => max(0, 1000000 - $paid), ':delivery' => $date]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
}

try {
    foreach (['one', 'two'] as $who) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (\'Cancel\', :last, :email, :phone, :hash, \'household\', \'active\')',
            [':last' => $who, ':email' => 'cancel-' . $who . '-' . $suffix . '@example.test',
             ':phone' => '+23470' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
        );
        $userIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    Settings::set('cancellation_customer_allowed', true, 'bool', null);
    Settings::set('cancellation_cutoff_time', '23:59', 'string', null);
    Settings::set('cancellation_deposit_forfeit_after_cutoff', true, 'bool', null);
    Settings::flushCache();

    $eligible = c_order($userIds[0], 'pending', 'unpaid', 0, 300000, date('Y-m-d', strtotime('+5 days')), $suffix);
    $orderIds[] = $eligible;
    $wrongOwner = OrderCancellation::cancelForCustomer($eligible, $userIds[1], 'changed_mind', '');
    c_eq('not_found', $wrongOwner['code'], 'another customer cannot see or cancel the order');
    $done = OrderCancellation::cancelForCustomer($eligible, $userIds[0], 'changed_mind', 'Plans changed');
    c_ok($done['ok'], 'an eligible owner cancels');
    c_eq('not_required', $done['refund_status'], 'an unpaid cancellation needs no refund');
    $stored = Database::one('SELECT order_status, cancelled_at FROM orders WHERE id = :id', [':id' => $eligible]);
    c_eq('cancelled', (string) $stored['order_status'], 'the order status is cancelled');
    c_ok($stored['cancelled_at'] !== null, 'the cancellation time is recorded');
    $history = Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id AND new_status = \'cancelled\'', [':id' => $eligible]);
    c_eq(1, (int) $history['n'], 'one cancellation history event is appended');
    $again = OrderCancellation::cancelForCustomer($eligible, $userIds[0], 'changed_mind', '');
    c_eq('already_cancelled', $again['code'], 'a repeat submission is harmless');
    $history = Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id AND new_status = \'cancelled\'', [':id' => $eligible]);
    c_eq(1, (int) $history['n'], 'a repeat writes no second history event');

    $closed = c_order($userIds[0], 'delivered', 'paid', 1000000, 300000, date('Y-m-d', strtotime('+5 days')), $suffix);
    $orderIds[] = $closed;
    $refused = OrderCancellation::cancelForStaff($closed, $userIds[0], 'customer_requested', '', true);
    c_eq('not_eligible', $refused['code'], 'a delivered order is refused');

    $paid = c_order($userIds[0], 'confirmed', 'paid', 300000, 300000, date('Y-m-d', strtotime('+5 days')), $suffix);
    $orderIds[] = $paid;
    $noRefundPermission = OrderCancellation::cancelForStaff($paid, $userIds[0], 'customer_requested', '', false);
    c_eq('refund_permission_required', $noRefundPermission['code'], 'paid cancellation requires refund permission');

    $manual = c_order($userIds[0], 'confirmed', 'part_paid', 300000, 300000, date('Y-m-d', strtotime('+5 days')), $suffix);
    $orderIds[] = $manual;
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status)
         VALUES (:number, :user, :order, \'manual\', \'deposit\', 300000, 300000, \'paid\')',
        [':number' => 'ZZ-PAY-' . $suffix, ':user' => $userIds[0], ':order' => $manual]
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, provider, reference, domain, status, requested_amount_subunit, amount_subunit, customer_email, paid_at)
         VALUES (:payment, \'manual\', :reference, \'test\', \'success\', 300000, 300000, :email, NOW())',
        [':payment' => $paymentId, ':reference' => 'ZZ-MAN-' . $suffix, ':email' => 'cancel-one-' . $suffix . '@example.test']
    );
    $manualDone = OrderCancellation::cancelForStaff($manual, $userIds[0], 'customer_requested', '', true);
    c_ok($manualDone['ok'], 'staff may cancel a manually paid order with both permissions');
    c_eq('manual_required', $manualDone['refund_status'], 'manual money is left visibly requiring return');
    c_eq(300000, $manualDone['manual_subunit'], 'the exact manual amount is reported');
} finally {
    Settings::set('cancellation_customer_allowed', $oldAllowed, 'bool', null);
    Settings::set('cancellation_cutoff_time', $oldCutoff, 'string', null);
    Settings::set('cancellation_deposit_forfeit_after_cutoff', $oldForfeit, 'bool', null);
    Settings::flushCache();
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM refund_status_history WHERE refund_id IN (SELECT id FROM refunds WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM refunds WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_cancellations WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($userIds as $id) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests cancellation database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
