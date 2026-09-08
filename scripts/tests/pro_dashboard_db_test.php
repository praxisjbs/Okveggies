<?php
/** Task B dashboard reads, credit arithmetic and business isolation on MySQL 8. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0;
$passed = 0;
function pdb_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}
function pdb_eq($expected, $actual, string $label): void
{
    pdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$userIds = [];
$businessIds = [];
$orderIds = [];
$templateIds = [];
$exceptionDate = '2026-09-08';
$oldException = Database::one(
    'SELECT exception_date, is_available, reason, replacement_date, created_by
       FROM delivery_date_exceptions
      WHERE exception_date = :date',
    [':date' => $exceptionDate]
);

try {
    foreach (['Alpha', 'Beta'] as $index => $name) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first_name, :last_name, :email, :phone, :password_hash, :user_type, :status, NOW())',
            [
                ':first_name' => $name,
                ':last_name' => 'Kitchen',
                ':email' => strtolower($name) . "-$suffix@example.test",
                ':phone' => '+23475' . random_int(10000000, 99999999),
                ':password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT),
                ':user_type' => 'business',
                ':status' => 'active',
            ]
        );
        $userIds[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
        Database::run(
            'INSERT INTO business_customers
                (user_id, business_name, business_type, contact_person, credit_requested, credit_status, credit_days, credit_limit_subunit)
             VALUES (:user_id, :business_name, :business_type, :contact_person, :credit_requested, :credit_status, :credit_days, :credit_limit)',
            [
                ':user_id' => $userIds[$index],
                ':business_name' => "$name Kitchen $suffix",
                ':business_type' => 'Restaurant',
                ':contact_person' => "$name Kitchen",
                ':credit_requested' => 1,
                ':credit_status' => 'approved',
                ':credit_days' => 10,
                ':credit_limit' => $index === 0 ? 50000000 : 90000000,
            ]
        );
        $businessIds[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    foreach ([
        [$businessIds[0], 10000000, '2026-09-05', 'open'],
        [$businessIds[0], 2500000, '2026-09-10', 'open'],
        [$businessIds[0], 8000000, '2026-09-04', 'paid'],
        [$businessIds[0], -8000000, null, 'posted'],
        [$businessIds[1], 40000000, '2026-09-07', 'open'],
    ] as [$businessId, $amount, $dueDate, $status]) {
        Database::run(
            'INSERT INTO credit_transactions
                (business_customer_id, transaction_type, amount_subunit, due_date, status, paid_at)
             VALUES (:business_id, :transaction_type, :amount_subunit, :due_date, :status, :paid_at)',
            [
                ':business_id' => $businessId,
                ':transaction_type' => $amount < 0 ? 'repayment' : 'charge',
                ':amount_subunit' => $amount,
                ':due_date' => $dueDate,
                ':status' => $status,
                ':paid_at' => $status === 'paid' ? '2026-09-06 12:00:00' : null,
            ]
        );
    }

    $statuses = ['pending', 'confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'];
    foreach ($statuses as $index => $status) {
        Database::run(
            'INSERT INTO orders
                (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                 subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, created_at)
             VALUES (:order_number, :user_id, :customer_type, :order_status, :payment_option, :payment_status,
                     :subtotal, :total, :balance, :delivery_date, :created_at)',
            [
                ':order_number' => 'PD-A-' . ($index + 1) . '-' . $suffix,
                ':user_id' => $userIds[0],
                ':customer_type' => 'business',
                ':order_status' => $status,
                ':payment_option' => 'on_account',
                ':payment_status' => 'unpaid',
                ':subtotal' => 1000000 + ($index * 100000),
                ':total' => 1000000 + ($index * 100000),
                ':balance' => 1000000 + ($index * 100000),
                ':delivery_date' => '2026-09-' . str_pad((string) (11 + $index), 2, '0', STR_PAD_LEFT),
                ':created_at' => '2026-09-0' . ($index + 1) . ' 09:00:00',
            ]
        );
        $orderIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, created_at)
         VALUES (:order_number, :user_id, :customer_type, :order_status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date, :created_at)',
        [
            ':order_number' => "PD-B-1-$suffix",
            ':user_id' => $userIds[1],
            ':customer_type' => 'business',
            ':order_status' => 'pending',
            ':payment_option' => 'on_account',
            ':payment_status' => 'unpaid',
            ':subtotal' => 9900000,
            ':total' => 9900000,
            ':balance' => 9900000,
            ':delivery_date' => '2026-09-11',
            ':created_at' => '2026-09-07 09:00:00',
        ]
    );
    $orderIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    foreach ([
        [$userIds[0], 'Breakfast', '2026-09-01 08:00:00'],
        [$userIds[0], 'Lunch', '2026-09-02 08:00:00'],
        [$userIds[0], 'Dinner', '2026-09-03 08:00:00'],
        [$userIds[0], 'Weekend', '2026-09-04 08:00:00'],
        [$userIds[1], 'Other business list', '2026-09-05 08:00:00'],
    ] as [$userId, $name, $updatedAt]) {
        Database::run(
            'INSERT INTO kitchen_run_templates (user_id, name, updated_at) VALUES (:user_id, :name, :updated_at)',
            [':user_id' => $userId, ':name' => $name, ':updated_at' => $updatedAt]
        );
        $templateIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run(
        'INSERT INTO kitchen_run_template_items (template_id, item_name, sort_order)
         VALUES (:template_id, :first_item, :first_sort), (:template_id_again, :second_item, :second_sort)',
        [
            ':template_id' => $templateIds[3],
            ':first_item' => 'Tomatoes',
            ':first_sort' => 1,
            ':template_id_again' => $templateIds[3],
            ':second_item' => 'Onions',
            ':second_sort' => 2,
        ]
    );

    Database::run(
        'INSERT INTO delivery_date_exceptions (exception_date, is_available, reason)
         VALUES (:date, :is_available, :reason)
         ON DUPLICATE KEY UPDATE is_available = VALUES(is_available), reason = VALUES(reason), replacement_date = NULL',
        [':date' => $exceptionDate, ':is_available' => 0, ':reason' => 'Task B test closure']
    );

    $now = new DateTimeImmutable('2026-09-07 09:00:00', new DateTimeZone('Africa/Lagos'));
    $overview = ProDashboard::overview($userIds[0], $now);

    pdb_eq("Alpha Kitchen $suffix", (string) $overview['business']['business_name'], 'the dashboard loads the signed-in business profile');
    pdb_eq(12500000, $overview['credit']['outstanding_subunit'], 'open charges total ₦125,000');
    pdb_eq(37500000, $overview['credit']['available_subunit'], 'a ₦500,000 limit leaves ₦375,000 available');
    pdb_eq(10000000, $overview['credit']['overdue_subunit'], 'only open charges due before today are overdue');
    pdb_eq('2026-09-05', $overview['credit']['earliest_due_date'], 'the earliest unpaid due date is returned');

    pdb_eq(5, count($overview['orders']), 'the dashboard returns the newest 5 orders');
    pdb_eq("PD-A-6-$suffix", (string) $overview['orders'][0]['order_number'], 'orders are newest first');
    pdb_ok(!in_array("PD-B-1-$suffix", array_column($overview['orders'], 'order_number'), true), 'another business order is never returned');
    pdb_eq('Cancelled', (string) $overview['orders'][0]['status_label'], 'the newest order uses the customer lifecycle label');

    pdb_eq(3, count($overview['saved_lists']), 'the dashboard returns the 3 most recently updated saved lists');
    pdb_eq('Weekend', (string) $overview['saved_lists'][0]['name'], 'saved lists are most recently updated first');
    pdb_eq(2, (int) $overview['saved_lists'][0]['item_count'], 'saved-list activity carries its item count');
    pdb_ok(!in_array('Other business list', array_column($overview['saved_lists'], 'name'), true), 'another business saved list is never returned');

    pdb_eq('2026-09-11', (string) $overview['next_delivery']['date'], 'a closed Tuesday is skipped for the next configured business day');

    $otherOverview = ProDashboard::overview($userIds[1], $now);
    pdb_eq(40000000, $otherOverview['credit']['outstanding_subunit'], 'the second business sees only its own outstanding amount');
    pdb_eq(0, $otherOverview['credit']['overdue_subunit'], 'a charge due today is not overdue');
    pdb_eq('2026-09-07', $otherOverview['credit']['earliest_due_date'], 'a charge due today still appears as the earliest unpaid due date');
} finally {
    Database::run('DELETE FROM delivery_date_exceptions WHERE exception_date = :date', [':date' => $exceptionDate]);
    if ($oldException !== null) {
        Database::run(
            'INSERT INTO delivery_date_exceptions (exception_date, is_available, reason, replacement_date, created_by)
             VALUES (:date, :is_available, :reason, :replacement_date, :created_by)',
            [
                ':date' => $oldException['exception_date'],
                ':is_available' => $oldException['is_available'],
                ':reason' => $oldException['reason'],
                ':replacement_date' => $oldException['replacement_date'],
                ':created_by' => $oldException['created_by'],
            ]
        );
    }
    foreach ($templateIds as $templateId) {
        Database::run('DELETE FROM kitchen_run_templates WHERE id = :id', [':id' => $templateId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    foreach ($businessIds as $businessId) {
        Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id', [':id' => $businessId]);
        Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $businessId]);
    }
    foreach ($userIds as $userId) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests Pro dashboard database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
