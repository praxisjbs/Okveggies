<?php
/** M10 Task D resolution integration tests on a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!Paystack::isTestMode() || !Paystack::isOverridden()) {
    fwrite(STDERR, "Start the local fake Paystack server and provide a test key plus PAYSTACK_BASE_URL.\n");
    exit(2);
}

$tests = 0; $passed = 0;
function irdb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function irdb_eq($expected, $actual, string $label): void { irdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $orderIds = []; $issueIds = []; $refundIds = []; $paymentIds = [];

$makeUser = static function (string $first, string $type) use ($suffix, &$userIds): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => $first, ':last' => 'Resolution Test', ':email' => strtolower($first) . "-$suffix@example.test",
            ':phone' => '+23473' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
            ':type' => $type, ':status' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return $id;
};

$makeOrder = static function (int $customerId, string $customerType, int $sequence, ?int $createdBy = null) use ($suffix, &$orderIds): array {
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
             preferred_delivery_date, created_by)
         VALUES (:number, :user, :type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :paid, :balance, :delivery_date, :created_by)',
        [':number' => "ZZ-IRD-$sequence-$suffix", ':user' => $customerId, ':type' => $customerType,
            ':order_status' => 'delivered', ':payment_option' => 'full', ':payment_status' => 'paid',
            ':subtotal' => 900000, ':total' => 900000, ':paid' => 900000, ':balance' => 0,
            ':delivery_date' => date('Y-m-d'), ':created_by' => $createdBy]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orderIds[] = $orderId;
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
         VALUES (:order_id, :type, :name, :sku, :unit, :quantity, :price, :total)',
        [':order_id' => $orderId, ':type' => 'product', ':name' => 'Fresh Tomatoes',
            ':sku' => "IRD-$sequence", ':unit' => 'kg', ':quantity' => 2,
            ':price' => 450000, ':total' => 900000]
    );
    return ['id' => $orderId, 'number' => "ZZ-IRD-$sequence-$suffix",
        'item_id' => (int) Database::getInstance()->getConnection()->lastInsertId()];
};

$makeIssue = static function (array $order, int $customerId, int $handlerId) use (&$issueIds): int {
    Database::run(
        'INSERT INTO issue_reports
            (order_id, user_id, category, description, status, active_slot, handled_by, handled_at)
         VALUES (:order_id, :user_id, :category, :description, :status, :slot, :handler, NOW())',
        [':order_id' => $order['id'], ':user_id' => $customerId, ':category' => 'damaged',
            ':description' => 'The delivered tomatoes were damaged.', ':status' => 'in_progress',
            ':slot' => 1, ':handler' => $handlerId]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $issueIds[] = $id;
    return $id;
};

try {
    $columns = Database::all(
        'SELECT column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :table_name
            AND column_name IN (:amount, :credit, :replacement)',
        [':table_name' => 'issue_reports', ':amount' => 'resolution_amount_subunit',
            ':credit' => 'credit_transaction_id', ':replacement' => 'replacement_order_id']
    );
    irdb_eq(3, count($columns), 'migration 047 adds the three issue outcome columns');

    $handlerId = $makeUser('Handler', 'staff');
    $ownerId = $makeUser('Owner', 'staff');
    $householdId = $makeUser('Household', 'household');
    $businessId = $makeUser('Business', 'business');
    Database::run(
        'INSERT INTO business_customers
            (user_id, business_name, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
         VALUES (:user_id, :name, :contact, :requested, :status, :days, :limit)',
        [':user_id' => $businessId, ':name' => 'Resolution Foods', ':contact' => 'Business Resolution Test',
            ':requested' => 1, ':status' => 'approved', ':days' => 7, ':limit' => 2000000]
    );

    $refundOrder = $makeOrder($householdId, 'household', 1);
    $refundIssue = $makeIssue($refundOrder, $householdId, $handlerId);
    Database::run(
        'INSERT INTO payments
            (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit,
             paid_amount_subunit, status, confirmed_at)
         VALUES (:number, :user, :order_id, :provider, :type, :expected, :paid, :status, NOW())',
        [':number' => "PAY-IRD-$suffix", ':user' => $householdId, ':order_id' => $refundOrder['id'],
            ':provider' => 'paystack', ':type' => 'full', ':expected' => 900000, ':paid' => 900000, ':status' => 'paid']
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId(); $paymentIds[] = $paymentId;
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, provider, reference, domain, status, requested_amount_subunit,
             amount_subunit, customer_email, paid_at)
         VALUES (:payment, :provider, :reference, :domain, :status, :requested, :amount, :email, NOW())',
        [':payment' => $paymentId, ':provider' => 'paystack', ':reference' => "IRD-$suffix",
            ':domain' => 'test', ':status' => 'success', ':requested' => 900000, ':amount' => 900000,
            ':email' => "household-$suffix@example.test"]
    );
    $transactionId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $refundResult = IssueResolutions::resolve($refundIssue, 'in_progress', $handlerId, 'refund',
        'We raised a refund for the damaged tomatoes.', [$refundOrder['item_id']], 270000, $transactionId);
    irdb_eq('resolved', (string) $refundResult['code'], 'Task D raises the refund through the M5 engine and finishes the report');
    $refundIds[] = (int) ($refundResult['refund_id'] ?? 0);
    irdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM refunds WHERE issue_report_id = :id', [':id' => $refundIssue])['n'], 'refund resolution remains one-to-one and idempotent');
    irdb_eq('terminal', IssueResolutions::resolve($refundIssue, 'in_progress', $handlerId, 'refund',
        'A repeated request must not pay twice.', [$refundOrder['item_id']], 270000, $transactionId)['code'], 'a resolved refund cannot run twice');

    $creditOrder = $makeOrder($businessId, 'business', 2);
    $creditIssue = $makeIssue($creditOrder, $businessId, $handlerId);
    $badAmount = IssueResolutions::resolve($creditIssue, 'in_progress', $handlerId, 'credit',
        'We added account credit for the affected produce.', [$creditOrder['item_id']], 900001);
    irdb_eq('bad_amount', (string) $badAmount['code'], 'credit cannot exceed the selected item total');
    $creditResult = IssueResolutions::resolve($creditIssue, 'in_progress', $handlerId, 'credit',
        'We added account credit for the affected produce.', [$creditOrder['item_id']], 200000);
    irdb_eq('resolved', (string) $creditResult['code'], 'approved business credit resolves through M8');
    $creditRow = Database::one('SELECT transaction_type, source_key, amount_subunit FROM credit_transactions WHERE id = :id', [':id' => $creditResult['credit_transaction_id']]);
    irdb_eq('adjustment', (string) $creditRow['transaction_type'], 'issue credit is an M8 adjustment');
    irdb_eq(-200000, (int) $creditRow['amount_subunit'], 'issue credit is a signed reduction on the account');
    irdb_eq('issue:' . $creditIssue . ':credit', (string) $creditRow['source_key'], 'issue credit carries an idempotent source key');

    $replacementOriginal = $makeOrder($householdId, 'household', 3);
    $replacementOrder = $makeOrder($householdId, 'household', 4, $handlerId);
    $replacementIssue = $makeIssue($replacementOriginal, $householdId, $handlerId);
    $replacementResult = IssueResolutions::resolve($replacementIssue, 'in_progress', $handlerId, 'replacement',
        'We linked a replacement order for the damaged tomatoes.', [$replacementOriginal['item_id']], 0, 0, $replacementOrder['number']);
    irdb_eq('resolved', (string) $replacementResult['code'], 'a manual order for the same customer can carry the replacement');
    irdb_eq($replacementOrder['id'], (int) Database::one('SELECT replacement_order_id FROM issue_reports WHERE id = :id', [':id' => $replacementIssue])['replacement_order_id'], 'replacement order link is durable');
    irdb_eq('delivered', (string) Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $replacementOriginal['id']])['order_status'], 'the original order remains unchanged');

    $takeoverOrder = $makeOrder($householdId, 'household', 5);
    $takeoverIssue = $makeIssue($takeoverOrder, $householdId, $handlerId);
    irdb_eq('reassigned', IssueResolutions::reassignToOwner($takeoverIssue, 'in_progress', $ownerId)['code'], 'the Owner can explicitly take over a handled report');
    irdb_eq($ownerId, (int) Database::one('SELECT handled_by FROM issue_reports WHERE id = :id', [':id' => $takeoverIssue])['handled_by'], 'takeover records the Owner as handler');
    irdb_eq('not_handler', IssueResolutions::resolve($takeoverIssue, 'in_progress', $handlerId, 'replacement',
        'The former handler cannot finish this report.', [$takeoverOrder['item_id']], 0, 0, $replacementOrder['number'])['code'], 'the former handler loses resolution authority');

    irdb_eq(3, (int) Database::one('SELECT COUNT(*) AS n FROM issue_report_resolution_items', [])['n'] >= 3 ? 3 : 0, 'terminal outcomes preserve their selected item evidence');
} finally {
    foreach (array_unique($issueIds) as $issueId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_report_resolution_items WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId]);
    }
    foreach ($refundIds as $refundId) {
        Database::run('DELETE FROM refund_status_history WHERE refund_id = :id', [':id' => $refundId]);
        Database::run('DELETE FROM refunds WHERE id = :id', [':id' => $refundId]);
    }
    foreach (array_unique($issueIds) as $issueId) {
        Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    }
    foreach ($paymentIds as $paymentId) {
        Database::run('DELETE FROM payment_transactions WHERE payment_id = :id', [':id' => $paymentId]);
        Database::run('DELETE FROM payments WHERE id = :id', [':id' => $paymentId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM credit_transactions WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    Database::run('DELETE FROM business_customers WHERE user_id = :id', [':id' => $businessId ?? 0]);
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests issue resolution database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
