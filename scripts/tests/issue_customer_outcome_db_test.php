<?php
/** M10 Task E private customer outcome tests on a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function icodb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function icodb_eq($expected, $actual, string $label): void { icodb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $orderIds = []; $issueIds = []; $refundIds = []; $paymentIds = [];
try {
    foreach (['Owner', 'Stranger'] as $name) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
            [':first' => $name, ':last' => 'Outcome Test', ':email' => strtolower($name) . "-$suffix@example.test",
                ':phone' => '+23476' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
                ':type' => 'household', ':status' => 'active']
        );
        $userIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    foreach ([1, 2] as $sequence) {
        Database::run(
            'INSERT INTO orders
                (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                 subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
                 preferred_delivery_date, delivered_at)
             VALUES (:number, :user, :type, :order_status, :payment_option, :payment_status,
                     :subtotal, :total, :paid, :balance, :delivery_date, NOW())',
            [':number' => "ZZ-OUT-$sequence-$suffix", ':user' => $userIds[0], ':type' => 'household',
                ':order_status' => 'delivered', ':payment_option' => 'full', ':payment_status' => 'paid',
                ':subtotal' => 800000, ':total' => 800000, ':paid' => 800000, ':balance' => 0,
                ':delivery_date' => date('Y-m-d')]
        );
        $orderIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run(
        'INSERT INTO issue_reports
            (order_id, user_id, category, description, status, resolution_type, resolution_note,
             resolution_amount_subunit, replacement_order_id, resolved_at, active_slot, created_at)
         VALUES (:order_id, :user_id, :category, :description, :status, :type, :note,
                 :amount, NULL, NOW(), NULL, DATE_SUB(NOW(), INTERVAL 1 DAY))',
        [':order_id' => $orderIds[0], ':user_id' => $userIds[0], ':category' => 'quality',
            ':description' => 'The spinach was not fresh enough.', ':status' => 'resolved', ':type' => 'refund',
            ':note' => 'We approved a refund for the affected spinach.', ':amount' => 250000]
    );
    $issueIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO payments
            (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit,
             paid_amount_subunit, status, confirmed_at)
         VALUES (:number, :user, :order_id, :provider, :type, :expected, :paid, :status, NOW())',
        [':number' => "PAY-OUT-$suffix", ':user' => $userIds[0], ':order_id' => $orderIds[0],
            ':provider' => 'paystack', ':type' => 'full', ':expected' => 800000, ':paid' => 800000, ':status' => 'paid']
    );
    $paymentIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, provider, reference, domain, status, requested_amount_subunit, amount_subunit, customer_email, paid_at)
         VALUES (:payment, :provider, :reference, :domain, :status, :requested, :amount, :email, NOW())',
        [':payment' => $paymentIds[0], ':provider' => 'paystack', ':reference' => "OUT-$suffix",
            ':domain' => 'test', ':status' => 'success', ':requested' => 800000, ':amount' => 800000,
            ':email' => "owner-$suffix@example.test"]
    );
    $transactionId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO refunds
            (payment_transaction_id, order_id, issue_report_id, amount_subunit, status, customer_note)
         VALUES (:transaction, :order_id, :issue_id, :amount, :status, :note)',
        [':transaction' => $transactionId, ':order_id' => $orderIds[0], ':issue_id' => $issueIds[0],
            ':amount' => 250000, ':status' => Refunds::STATUS_PROCESSING, ':note' => 'Outcome test']
    );
    $refundIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO issue_reports
            (order_id, user_id, category, description, status, active_slot, created_at)
         VALUES (:order_id, :user_id, :category, :description, :status, 1, NOW())',
        [':order_id' => $orderIds[0], ':user_id' => $userIds[0], ':category' => 'missing_item',
            ':description' => 'One item was missing from this delivery.', ':status' => 'open']
    );
    $issueIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    $history = IssueReports::historyForCustomer($orderIds[0], $userIds[0]);
    icodb_eq(2, count($history ?? []), 'Task E returns every report for the owned order');
    icodb_eq($issueIds[1], (int) $history[0]['id'], 'Task E returns newest report first');
    icodb_eq('Received', (string) $history[0]['status_label'], 'Task E gives the open report customer copy');
    icodb_eq(Refunds::customerStatusLine(Refunds::STATUS_PROCESSING), (string) $history[1]['refund_line'], 'Task E reads the live M5 refund state');
    icodb_eq(null, IssueReports::historyForCustomer($orderIds[0], $userIds[1]), 'another customer cannot read the report history');

    Database::run('UPDATE refunds SET status = :status WHERE id = :id', [':status' => Refunds::STATUS_PROCESSED, ':id' => $refundIds[0]]);
    $updated = IssueReports::historyForCustomer($orderIds[0], $userIds[0]);
    icodb_eq(Refunds::customerStatusLine(Refunds::STATUS_PROCESSED), (string) $updated[1]['refund_line'], 'the customer sees a later refund status without duplicated issue state');
} finally {
    foreach ($refundIds as $id) { Database::run('DELETE FROM refunds WHERE id = :id', [':id' => $id]); }
    foreach ($issueIds as $id) { Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $id]); }
    foreach ($paymentIds as $id) {
        Database::run('DELETE FROM payment_transactions WHERE payment_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE id = :id', [':id' => $id]);
    }
    foreach ($orderIds as $id) { Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]); }
    foreach ($userIds as $id) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
}
fwrite(STDOUT, "$passed / $tests assertions passed.\n");
exit($passed === $tests ? 0 : 1);
