<?php
/** Task A persistence tests against a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function irdb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function irdb_eq($expected, $actual, string $label): void { irdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = []; $orderIds = []; $issueIds = [];
$ipBuckets = [];
$oldWindow = Settings::get(IssueReports::REPORTING_WINDOW_KEY, null);

$makeUser = static function (string $name) use ($suffix, &$userIds): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [
            ':first' => $name,
            ':last' => 'Issue Test',
            ':email' => strtolower($name) . "-$suffix@example.test",
            ':phone' => '+23470' . random_int(10000000, 99999999),
            ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
            ':type' => 'household',
            ':status' => 'active',
        ]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return $id;
};

$makeOrder = static function (int $userId, string $status, string $eventAt, bool $writeEvent = true) use ($suffix, &$orderIds): int {
    $number = 'ZZ-ISSUE-' . count($orderIds) . '-' . $suffix;
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, delivered_at)
         VALUES (:number, :user, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date, :delivered_at)',
        [
            ':number' => $number,
            ':user' => $userId,
            ':customer_type' => 'household',
            ':order_status' => $status,
            ':payment_option' => 'pay_on_delivery',
            ':payment_status' => 'unpaid',
            ':subtotal' => 500000,
            ':total' => 500000,
            ':balance' => 500000,
            ':delivery_date' => date('Y-m-d'),
            ':delivered_at' => $status === 'delivered' && $writeEvent ? $eventAt : null,
        ]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orderIds[] = $id;
    if ($writeEvent && $status === 'dispatched') {
        Database::run(
            'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
             VALUES (:order_id, :old_status, :new_status, :source, :actor, :created_at)',
            [':order_id' => $id, ':old_status' => 'packed', ':new_status' => 'dispatched',
                ':source' => 'admin', ':actor' => $userId, ':created_at' => $eventAt]
        );
    }
    return $id;
};

$clearRate = static function (int $userId, string $ip) use (&$ipBuckets): void {
    $account = 'issues:account:' . $userId;
    $ipBucket = 'issues:ip:' . hash('sha256', $ip);
    Database::run('DELETE FROM rate_limits WHERE bucket IN (:account, :ip)', [':account' => $account, ':ip' => $ipBucket]);
    $ipBuckets[] = $account;
    $ipBuckets[] = $ipBucket;
};

try {
    $column = Database::one(
        'SELECT generation_expression FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
        [':table_name' => 'issue_reports', ':column_name' => 'active_slot']
    );
    irdb_ok($column !== null, 'migration 045 adds the active-report slot column');
    $index = Database::one(
        'SELECT non_unique AS is_non_unique FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name',
        [':table_name' => 'issue_reports', ':index_name' => 'uq_issue_reports_one_active_order']
    );
    irdb_eq(0, (int) ($index['is_non_unique'] ?? 1), 'migration 045 adds a unique active-order index');
    irdb_eq(3, (int) Database::one(
        'SELECT COUNT(*) AS n FROM notification_templates
          WHERE template_key IN (:received, :resolved, :staff)',
        [':received' => 'issue_report_received', ':resolved' => 'issue_report_resolved', ':staff' => 'admin_new_issue_report']
    )['n'], 'migration 045 seeds exactly the three M10 notification templates');

    Settings::set(IssueReports::REPORTING_WINDOW_KEY, 3, 'int', null);
    Settings::flushCache();
    irdb_eq(3, IssueReports::reportingWindowDays(), 'the reporting window is read from the managed setting');
    Settings::set(IssueReports::REPORTING_WINDOW_KEY, 7, 'int', null);
    Settings::flushCache();

    $ownerId = $makeUser('Owner');
    $otherId = $makeUser('Other');
    $eligibleOrder = $makeOrder($ownerId, 'dispatched', date('Y-m-d H:i:s', strtotime('-1 day')));
    $ip = '192.0.2.41';
    $clearRate($ownerId, $ip);
    $saved = IssueReports::submit($eligibleOrder, $ownerId, 'damaged', '<b>Two tomato packs arrived crushed.</b>', $ip);
    irdb_eq('reported', (string) $saved['code'], 'an eligible owner can report an issue');
    $issueId = (int) ($saved['issue_id'] ?? 0);
    $issueIds[] = $issueId;
    $row = Database::one('SELECT order_id, user_id, category, description, status FROM issue_reports WHERE id = :id', [':id' => $issueId]);
    irdb_eq($eligibleOrder, (int) $row['order_id'], 'the report keeps the existing order relationship');
    irdb_eq($ownerId, (int) $row['user_id'], 'the report keeps its authenticated owner');
    irdb_eq('damaged', (string) $row['category'], 'the approved category is stored');
    irdb_ok(str_contains((string) $row['description'], '<b>'), 'customer markup is stored only as text for escaped rendering');
    irdb_eq('open', (string) $row['status'], 'a new report starts open');
    irdb_eq(1, (int) Database::one(
        'SELECT COUNT(*) AS n FROM audit_logs WHERE entity_type = :type AND entity_id = :id AND action = :action',
        [':type' => 'issue_report', ':id' => $issueId, ':action' => 'issue_reports.create']
    )['n'], 'the report and its audit record commit together');

    $again = IssueReports::submit($eligibleOrder, $ownerId, 'quality', 'The leaves were already brown.', $ip);
    irdb_eq('already_open', (string) $again['code'], 'a repeated submission is an idempotent already-open result');
    irdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :id', [':id' => $eligibleOrder])['n'], 'a repeat creates no second row');
    $uniqueStopped = false;
    try {
        Database::run(
            'INSERT INTO issue_reports (order_id, user_id, category, description, status, active_slot)
             VALUES (:order_id, :user_id, :category, :description, :status, :active_slot)',
            [':order_id' => $eligibleOrder, ':user_id' => $ownerId, ':category' => 'late',
                ':description' => 'A direct competing insert.', ':status' => 'open', ':active_slot' => 1]
        );
    } catch (PDOException $e) {
        $uniqueStopped = (string) $e->getCode() === '23000';
    }
    irdb_ok($uniqueStopped, 'MySQL itself refuses a competing active report');

    $otherOrder = $makeOrder($otherId, 'dispatched', date('Y-m-d H:i:s', strtotime('-1 day')));
    $clearRate($ownerId, '192.0.2.42');
    $notOwned = IssueReports::submit($otherOrder, $ownerId, 'missing_item', 'One bunch of spinach was missing.', '192.0.2.42');
    irdb_eq('not_found', (string) $notOwned['code'], 'another customer\'s order is not disclosed or accepted');
    irdb_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :id', [':id' => $otherOrder])['n'], 'the ownership refusal writes no report');

    $packedOrder = $makeOrder($ownerId, 'packed', date('Y-m-d H:i:s'));
    $clearRate($ownerId, '192.0.2.43');
    irdb_eq('ineligible_status', IssueReports::submit($packedOrder, $ownerId, 'late', 'The order has not left yet.', '192.0.2.43')['code'], 'an order before dispatch is refused');
    $expiredOrder = $makeOrder($ownerId, 'dispatched', date('Y-m-d H:i:s', strtotime('-8 days')));
    $clearRate($ownerId, '192.0.2.44');
    irdb_eq('expired', IssueReports::submit($expiredOrder, $ownerId, 'quality', 'The produce did not keep well.', '192.0.2.44')['code'], 'an order outside the configured window is refused');
    $missingOrder = $makeOrder($ownerId, 'dispatched', date('Y-m-d H:i:s'), false);
    $clearRate($ownerId, '192.0.2.45');
    irdb_eq('missing_timestamp', IssueReports::submit($missingOrder, $ownerId, 'quality', 'There is no lifecycle evidence.', '192.0.2.45')['code'], 'an eligible status without a recorded timestamp fails closed');

    $rateIp = '192.0.2.46';
    $clearRate($otherId, $rateIp);
    for ($attempt = 0; $attempt < IssueReports::ACCOUNT_LIMIT; $attempt++) {
        IssueReports::submit(0, $otherId, 'bad', 'This attempt still counts.', $rateIp);
    }
    irdb_eq('rate_limited', IssueReports::submit(0, $otherId, 'bad', 'This attempt is over the cap.', $rateIp)['code'], 'the sixth account attempt in 1 hour is rate limited');

    $ipLimited = '192.0.2.47';
    $clearRate($ownerId, $ipLimited);
    $ipBucket = 'issues:ip:' . hash('sha256', $ipLimited);
    for ($attempt = 0; $attempt < IssueReports::IP_LIMIT; $attempt++) {
        RateLimiter::hit($ipBucket, IssueReports::IP_LIMIT, IssueReports::RATE_WINDOW);
    }
    irdb_eq('rate_limited', IssueReports::submit(0, $ownerId, 'bad', 'This connection is over its cap.', $ipLimited)['code'], 'the separate IP allowance is enforced');

    $oldPort = $_ENV['SMTP_PORT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    Notifications::announceIssueReportReceived($issueId);
    if ($oldPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $oldPort; }
    $events = array_column(Database::all(
        'SELECT event_type FROM notifications WHERE related_type = :type AND related_id = :id',
        [':type' => 'issue_report', ':id' => $issueId]
    ), 'event_type');
    irdb_ok(in_array('issue_report_received', $events, true), 'the committed report acknowledges its customer');
    irdb_ok(in_array('admin_new_issue_report', $events, true), 'the committed report alerts staff');
    irdb_ok(Database::one('SELECT id FROM issue_reports WHERE id = :id', [':id' => $issueId]) !== null, 'notification failure cannot remove the report');
} finally {
    foreach ($issueIds as $issueId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM issue_reports WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    foreach (array_unique($ipBuckets) as $bucket) {
        Database::run('DELETE FROM rate_limits WHERE bucket = :bucket', [':bucket' => $bucket]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'");
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
    if ($oldWindow === null) {
        Database::run('DELETE FROM site_settings WHERE setting_key = :key', [':key' => IssueReports::REPORTING_WINDOW_KEY]);
    } else {
        Settings::set(IssueReports::REPORTING_WINDOW_KEY, $oldWindow, 'int', null);
    }
    Settings::flushCache();
}

fwrite(STDOUT, "\n$passed / $tests issue report database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
