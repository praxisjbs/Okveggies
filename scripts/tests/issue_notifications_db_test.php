<?php
/** M10 Task G notification integration tests on a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function indb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function indb_eq($expected, $actual, string $label): void { indb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $roleIds = []; $orderId = 0; $issueId = 0;
$oldPort = $_ENV['SMTP_PORT'] ?? null;
try {
    $makeUser = static function (string $first, string $type) use ($suffix, &$userIds): int {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
            [':first' => $first, ':last' => 'Notice Test', ':email' => strtolower($first) . "-$suffix@example.test",
                ':phone' => '+23479' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
                ':type' => $type, ':status' => 'active']
        );
        $id = (int) Database::getInstance()->getConnection()->lastInsertId();
        $userIds[] = $id;
        return $id;
    };
    $customerId = $makeUser('Customer', 'household');
    $viewerId = $makeUser('Viewer', 'staff');
    $otherStaffId = $makeUser('Other', 'staff');

    foreach (['Issue viewer ' . $suffix, 'No issue access ' . $suffix] as $name) {
        Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => $name, ':description' => 'Task G test role']);
        $roleIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $viewerId, ':role' => $roleIds[0]]);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $otherStaffId, ':role' => $roleIds[1]]);
    Database::run(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT :role, id FROM permissions WHERE `key` = :permission',
        [':role' => $roleIds[0], ':permission' => 'issues.view']
    );

    $recipients = Notifications::staffRecipientsForPermission('issues.view');
    $recipientIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $recipients);
    indb_ok(in_array($viewerId, $recipientIds, true), 'Task G alerts staff whose role grants issues.view');
    indb_ok(!in_array($otherStaffId, $recipientIds, true), 'Task G excludes staff without issues.view');

    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, delivered_at)
         VALUES (:number, :user, :type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date, NOW())',
        [':number' => "ZZ-ING-$suffix", ':user' => $customerId, ':type' => 'household', ':order_status' => 'delivered',
            ':payment_option' => 'pay_on_delivery', ':payment_status' => 'unpaid', ':subtotal' => 500000,
            ':total' => 500000, ':balance' => 500000, ':delivery_date' => date('Y-m-d')]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO issue_reports (order_id, user_id, category, description, status, active_slot)
         VALUES (:order_id, :user_id, :category, :description, :status, 1)',
        [':order_id' => $orderId, ':user_id' => $customerId, ':category' => 'damaged',
            ':description' => 'The 1kg tomato pack arrived crushed.', ':status' => 'open']
    );
    $issueId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $_ENV['SMTP_PORT'] = 1; putenv('SMTP_PORT=1');
    Notifications::announceIssueReportReceived($issueId);
    $received = Database::one(
        'SELECT id, body FROM notifications
          WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'issue_report_received']
    );
    indb_ok(str_contains((string) ($received['body'] ?? ''), 'Damaged'), 'customer acknowledgement names the selected category');
    indb_ok(str_contains((string) ($received['body'] ?? ''), 'The 1kg tomato pack arrived crushed.'), 'customer acknowledgement includes the description preview');
    indb_eq('failed', (string) Database::one(
        'SELECT status FROM notification_deliveries
          WHERE notification_id = :id AND channel = :channel AND user_id = :user',
        [':id' => (int) $received['id'], ':channel' => 'email', ':user' => $customerId]
    )['status'], 'failed email delivery remains recorded for retry');
    indb_ok(Database::one('SELECT id FROM issue_reports WHERE id = :id', [':id' => $issueId]) !== null, 'email failure cannot remove the report');

    $staffNotice = Database::one(
        'SELECT id, body FROM notifications
          WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'admin_new_issue_report']
    );
    indb_ok(str_contains((string) ($staffNotice['body'] ?? ''), "ZZ-ING-$suffix"), 'staff alert names the order');
    indb_eq(1, (int) Database::one(
        'SELECT COUNT(*) AS n FROM notification_deliveries WHERE notification_id = :notice AND user_id = :user',
        [':notice' => (int) $staffNotice['id'], ':user' => $viewerId]
    )['n'] > 0 ? 1 : 0, 'permitted staff receives the alert');
    indb_eq(0, (int) Database::one(
        'SELECT COUNT(*) AS n FROM notification_deliveries WHERE notification_id = :notice AND user_id = :user',
        [':notice' => (int) $staffNotice['id'], ':user' => $otherStaffId]
    )['n'], 'staff without permission receives no alert');

    $reason = 'The delivery record confirms the 1kg tomato pack arrived complete.';
    Database::run(
        'UPDATE issue_reports SET status = :status, resolution_type = :type, resolution_note = :note,
                resolved_at = NOW(), active_slot = NULL WHERE id = :id',
        [':status' => 'declined', ':type' => 'declined', ':note' => $reason, ':id' => $issueId]
    );
    Notifications::announceIssueReportResolved($issueId, $viewerId);
    $outcome = Database::one(
        'SELECT body FROM notifications
          WHERE related_type = :type AND related_id = :id AND event_type = :event',
        [':type' => 'issue_report', ':id' => $issueId, ':event' => 'issue_report_resolved']
    );
    indb_ok(str_contains((string) ($outcome['body'] ?? ''), $reason), 'the shared outcome template carries the exact decline reason');
} finally {
    if ($issueId > 0) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    }
    if ($orderId > 0) { Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]); }
    foreach ($userIds as $userId) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]); }
    foreach ($roleIds as $roleId) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $roleId]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }
    foreach ($userIds as $userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    if ($oldPort === null) { unset($_ENV['SMTP_PORT']); putenv('SMTP_PORT'); } else { $_ENV['SMTP_PORT'] = $oldPort; putenv('SMTP_PORT=' . $oldPort); }
}
fwrite(STDOUT, "$passed / $tests assertions passed.\n");
exit($passed === $tests ? 0 : 1);
