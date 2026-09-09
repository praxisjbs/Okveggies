<?php
/** Task C queue and locked workflow tests on a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function iwdb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function iwdb_eq($expected, $actual, string $label): void { iwdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $orderIds = []; $issueIds = [];

$makeUser = static function (string $first, string $type = 'household') use ($suffix, &$userIds): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => $first, ':last' => 'Workflow Test', ':email' => strtolower($first) . "-$suffix@example.test",
            ':phone' => '+23472' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
            ':type' => $type, ':status' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return $id;
};

$makeOrder = static function (int $customerId, int $sequence) use ($suffix, &$orderIds): int {
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :paid, :balance, :delivery_date)',
        [':number' => "ZZ-IW-$sequence-$suffix", ':user' => $customerId, ':customer_type' => 'household',
            ':order_status' => 'dispatched', ':payment_option' => 'deposit', ':payment_status' => 'part_paid',
            ':subtotal' => 900000, ':total' => 900000, ':paid' => 300000, ':balance' => 600000,
            ':delivery_date' => date('Y-m-d', strtotime('+1 day'))]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orderIds[] = $id;
    Database::run(
        'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
         VALUES (:order_id, :old_status, :new_status, :source, :actor, :created_at)',
        [':order_id' => $id, ':old_status' => 'packed', ':new_status' => 'dispatched',
            ':source' => 'admin', ':actor' => $customerId, ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]
    );
    Database::run(
        'INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state)
         VALUES (:order_id, :name, :phone, :address, :city, :state)',
        [':order_id' => $id, ':name' => 'Ada Workflow', ':phone' => '08030000000',
            ':address' => '14 Market Road', ':city' => 'Ikeja', ':state' => 'Lagos']
    );
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit)
         VALUES (:order_id, :item_type, :name, :sku, :unit, :quantity, :price, :total)',
        [':order_id' => $id, ':item_type' => 'product', ':name' => 'Tomatoes', ':sku' => 'TEST-TOM',
            ':unit' => 'kg', ':quantity' => 2, ':price' => 450000, ':total' => 900000]
    );
    return $id;
};

try {
    iwdb_ok(Database::one(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name',
        [':name' => 'issue_report_history']
    ) !== null, 'migration 046 creates issue_report_history');
    $handledAtColumn = Database::one(
        'SELECT data_type FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
        [':table_name' => 'issue_reports', ':column_name' => 'handled_at']
    );
    iwdb_eq('datetime', strtolower((string) ($handledAtColumn['data_type'] ?? $handledAtColumn['DATA_TYPE'] ?? '')), 'migration 046 records when a report was taken');

    $customerId = $makeUser('Customer');
    $handlerId = $makeUser('Handler', 'staff');
    $otherStaffId = $makeUser('Colleague', 'staff');
    $firstOrder = $makeOrder($customerId, 1);
    $secondOrder = $makeOrder($customerId, 2);
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    $first = IssueReports::submit($firstOrder, $customerId, 'damaged', 'The tomato packs arrived crushed.', '192.0.2.81');
    $second = IssueReports::submit($secondOrder, $customerId, 'missing_item', 'One spinach bunch was missing.', '192.0.2.82');
    $firstId = (int) $first['issue_id'];
    $secondId = (int) $second['issue_id'];
    $issueIds[] = $firstId; $issueIds[] = $secondId;
    Database::run('UPDATE issue_reports SET created_at = :created_at WHERE id = :id', [':created_at' => '2026-09-01 08:00:00', ':id' => $firstId]);
    Database::run('UPDATE issue_reports SET created_at = :created_at WHERE id = :id', [':created_at' => '2026-09-02 08:00:00', ':id' => $secondId]);

    $queueIds = array_map(static fn(array $row): int => (int) $row['id'], IssueReports::forStaff('active'));
    iwdb_ok(array_search($firstId, $queueIds, true) < array_search($secondId, $queueIds, true), 'the active queue is oldest first');
    iwdb_eq(2, IssueReports::countForStaff('active', '', '2026-09-01', '2026-09-02'), 'inclusive date filters cover both boundary dates');
    iwdb_eq(1, IssueReports::countForStaff('active', 'damaged'), 'the category filter narrows the queue');

    $detail = IssueReports::findForStaff($firstId);
    iwdb_eq(300000, (int) $detail['verified_paid_subunit'], 'detail uses the order ledger paid amount');
    iwdb_eq('14 Market Road', (string) $detail['address_line_1'], 'detail includes delivery context');
    iwdb_eq('Tomatoes', (string) $detail['items'][0]['item_name'], 'detail includes immutable order items');
    iwdb_eq(1, count($detail['history']), 'report creation starts permanent history');

    iwdb_eq('taken', IssueReports::take($firstId, 'open', $handlerId)['code'], 'an open report can be taken');
    $taken = Database::one('SELECT status, handled_by, handled_at FROM issue_reports WHERE id = :id', [':id' => $firstId]);
    iwdb_eq('in_progress', (string) $taken['status'], 'take moves the report to in progress');
    iwdb_eq($handlerId, (int) $taken['handled_by'], 'take records the handler');
    iwdb_ok($taken['handled_at'] !== null, 'take records when it happened');
    iwdb_eq(2, count(IssueReports::historyForStaff($firstId)), 'take appends one history entry');
    iwdb_eq('already_taken', IssueReports::take($firstId, 'open', $handlerId)['code'], 'same-actor take replay is idempotent');
    iwdb_eq(2, count(IssueReports::historyForStaff($firstId)), 'take replay adds no history');
    iwdb_eq('stale', IssueReports::take($firstId, 'open', $otherStaffId)['code'], 'another colleague cannot take ownership');
    iwdb_eq('not_handler', IssueReports::decline($firstId, 'in_progress', $otherStaffId, 'We could not verify the reported damage.')['code'], 'only the handler may decline');
    iwdb_eq('resolution_note_too_short', IssueReports::decline($firstId, 'in_progress', $handlerId, 'No proof')['code'], 'short decline reason is refused');

    iwdb_eq('declined', IssueReports::decline($firstId, 'in_progress', $handlerId, 'We could not verify the reported damage from the order record.')['code'], 'handler can decline with a clear reason');
    $terminal = Database::one('SELECT status, resolution_type, resolution_note, resolved_at, active_slot FROM issue_reports WHERE id = :id', [':id' => $firstId]);
    iwdb_eq('declined', (string) $terminal['status'], 'decline has its own terminal status');
    iwdb_eq('declined', (string) $terminal['resolution_type'], 'decline records its resolution type');
    iwdb_ok($terminal['resolved_at'] !== null, 'decline records when it was finished');
    iwdb_eq(null, $terminal['active_slot'], 'terminal report releases the active slot');
    iwdb_eq(3, count(IssueReports::historyForStaff($firstId)), 'decline appends terminal history');
    iwdb_eq('terminal', IssueReports::decline($firstId, 'in_progress', $handlerId, 'A replay must not change the result.')['code'], 'terminal report cannot be declined twice');
    iwdb_eq(3, count(IssueReports::historyForStaff($firstId)), 'terminal replay adds no history');
    iwdb_eq(1, IssueReports::countForStaff('active'), 'active queue excludes declined reports');
    iwdb_eq(1, IssueReports::countForStaff('declined'), 'declined filter finds the outcome');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    $later = IssueReports::submit($firstOrder, $customerId, 'quality', 'A separate later problem needs checking.', '192.0.2.83');
    iwdb_eq('reported', (string) $later['code'], 'a later report is allowed after a terminal outcome');
    $issueIds[] = (int) $later['issue_id'];
    iwdb_eq(2, count(IssueReports::forOrderStaff($firstOrder)), 'order link preserves both report records');
    iwdb_eq(2, (int) Database::one(
        'SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type = :type AND entity_id = :id
          AND action IN (:take_action, :decline_action)',
        [':type' => 'issue_report', ':id' => $firstId, ':take_action' => 'issue_reports.take', ':decline_action' => 'issue_reports.decline']
    )['n'], 'take and decline are audited');
} finally {
    foreach (array_unique($issueIds) as $issueId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_report_photos WHERE issue_id = :id', [':id' => $issueId]);
        Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests issue workflow database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
