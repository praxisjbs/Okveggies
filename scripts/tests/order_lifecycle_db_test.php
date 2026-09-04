<?php
/** M6 lifecycle integration against a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function ol_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function ol_eq($expected, $actual, string $label): void { ol_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userId = 0; $orderId = 0;
$oldRegions = Settings::str('source_regions', 'Ogun State, Jos');
try {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Lifecycle\', \'Tester\', :email, :phone, :hash, \'staff\', \'active\')',
        [':email' => "lifecycle-$suffix@example.test", ':phone' => '+23474' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user, \'household\', \'pending\', \'pay_on_delivery\', \'unpaid\', 500000, 500000, 500000, :date)',
        [':number' => "ZZ-LIFE-$suffix", ':user' => $userId, ':date' => date('Y-m-d', strtotime('+7 days'))]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by) VALUES (:id, NULL, \'pending\', \'customer\', :user)', [':id' => $orderId, ':user' => $userId]);
    Database::run('INSERT INTO delivery_schedules (order_id, delivery_date, status) SELECT id, preferred_delivery_date, \'scheduled\' FROM orders WHERE id = :id', [':id' => $orderId]);

    Settings::set('source_regions', 'Ogun State, Jos', 'string', null);
    Settings::flushCache();
    $done = OrderLifecycle::transition($orderId, 'pending', 'confirmed', $userId, 'Ready to source');
    ol_ok($done['ok'], 'a valid staff transition succeeds');
    $stored = Database::one('SELECT order_status, confirmed_at, source_regions_snapshot FROM orders WHERE id = :id', [':id' => $orderId]);
    ol_eq('confirmed', (string) $stored['order_status'], 'status is updated');
    ol_ok($stored['confirmed_at'] !== null, 'confirmation timestamp is recorded');
    ol_eq('Ogun State, Jos', (string) $stored['source_regions_snapshot'], 'source regions are snapshotted at confirmation');
    $history = Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id AND new_status = \'confirmed\' AND changed_by = :user AND source = \'admin\'', [':id' => $orderId, ':user' => $userId]);
    ol_eq(1, (int) $history['n'], 'one actor-attributed history row is appended atomically');

    $again = OrderLifecycle::transition($orderId, 'pending', 'confirmed', $userId, 'Repeated click');
    ol_eq('already_transitioned', $again['code'], 'a repeated transition is an idempotent success');
    $history = Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id AND new_status = \'confirmed\'', [':id' => $orderId]);
    ol_eq(1, (int) $history['n'], 'a repeat does not append duplicate history');

    $stale = OrderLifecycle::transition($orderId, 'packed', 'dispatched', $userId, 'Stale form');
    ol_eq('stale', $stale['code'], 'a stale form is refused');
    $invalid = OrderLifecycle::transition($orderId, 'confirmed', 'delivered', $userId, 'Skipped stages');
    ol_eq('invalid_transition', $invalid['code'], 'a skipped lifecycle stage is refused');
    $unchanged = Database::one('SELECT order_status, COUNT(h.id) AS history_count FROM orders o LEFT JOIN order_status_history h ON h.order_id = o.id WHERE o.id = :id GROUP BY o.id', [':id' => $orderId]);
    ol_eq('confirmed', (string) $unchanged['order_status'], 'refused transitions leave status unchanged');
    ol_eq(2, (int) $unchanged['history_count'], 'refused transitions write no history');
} finally {
    Settings::set('source_regions', $oldRegions, 'string', null); Settings::flushCache();
    if ($orderId) {
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    if ($userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
}
fwrite(STDOUT, "\n$passed / $tests lifecycle database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
