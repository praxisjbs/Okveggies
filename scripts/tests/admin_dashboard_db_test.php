<?php
/** M11 dashboard aggregation against a migrated MySQL 8 scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0;
$passed = 0;
function adb_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}
function adb_eq($expected, $actual, string $label): void
{
    adb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

$pdo = Database::getInstance()->getConnection();
$suffix = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
$now = new DateTimeImmutable('2099-12-31 12:00:00', new DateTimeZone('Africa/Lagos'));
$pdo->beginTransaction();

try {
    $unit = Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    $category = Database::one('SELECT id FROM product_categories WHERE slug = :slug', [':slug' => 'vegetables']);
    if (!$unit || !$category) {
        throw new RuntimeException('Run all migrations before the dashboard database test.');
    }

    Database::run(
        'INSERT INTO products
            (category_id, unit_id, name, slug, sku, description, current_price_subunit,
             minimum_quantity, quantity_increment, is_active)
         VALUES (:category, :unit, :name, :slug, :sku, :description, :price, :minimum, :increment, :active)',
        [
            ':category' => (int) $category['id'], ':unit' => (int) $unit['id'],
            ':name' => 'M11 Tomatoes ' . $suffix, ':slug' => 'm11-tomatoes-' . strtolower($suffix),
            ':sku' => 'M11-P-' . $suffix, ':description' => 'Dashboard test produce.',
            ':price' => 10000, ':minimum' => '1.000', ':increment' => '1.000', ':active' => 1,
        ]
    );
    $productId = (int) $pdo->lastInsertId();

    $orderIds = [];
    $makeOrder = static function (string $status, string $createdAt, int $total, string $tag) use (&$orderIds, $suffix, $pdo): int {
        Database::run(
            'INSERT INTO orders
                (order_number, customer_type, order_status, payment_option, payment_status,
                 subtotal_subunit, order_total_subunit, balance_due_subunit,
                 preferred_delivery_date, created_at)
             VALUES (:number, :customer_type, :order_status, :payment_option, :payment_status,
                     :subtotal, :total, :balance, :delivery_date, :created_at)',
            [
                ':number' => 'M11-' . $tag . '-' . $suffix, ':customer_type' => 'household',
                ':order_status' => $status, ':payment_option' => 'pay_in_full', ':payment_status' => 'unpaid',
                ':subtotal' => $total, ':total' => $total, ':balance' => $total,
                ':delivery_date' => '2100-01-02', ':created_at' => $createdAt,
            ]
        );
        $id = (int) $pdo->lastInsertId();
        $orderIds[$tag] = $id;
        return $id;
    };

    $todayOrder = $makeOrder('pending', '2099-12-31 00:00:00', 10000, 'TODAY');
    $cancelledOrder = $makeOrder('cancelled', '2099-12-31 23:59:59', 5000, 'CANCELLED');
    $comboOrder = $makeOrder('delivered', '2099-12-30 09:00:00', 8000, 'COMBO');
    $kitchenOrder = $makeOrder('confirmed', '2099-12-29 09:00:00', 6000, 'KITCHEN');
    $manualOrder = $makeOrder('pending', '2099-12-28 09:00:00', 4000, 'MANUAL');

    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, product_id, item_name, sku, unit_name, quantity,
             unit_price_subunit, line_total_subunit, created_at)
         VALUES (:order_id, :item_type, :product_id, :item_name, :sku, :unit_name, :quantity,
                 :unit_price, :line_total, :created_at)',
        [
            ':order_id' => $todayOrder, ':item_type' => 'product', ':product_id' => $productId,
            ':item_name' => 'M11 Tomatoes ' . $suffix, ':sku' => 'M11-P-' . $suffix,
            ':unit_name' => 'kg', ':quantity' => '2.000', ':unit_price' => 5000,
            ':line_total' => 10000, ':created_at' => '2099-12-31 00:00:00',
        ]
    );
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit, created_at)
         VALUES (:order_id, :item_type, :item_name, :sku, :unit_name, :quantity, :unit_price, :line_total, :created_at)',
        [
            ':order_id' => $comboOrder, ':item_type' => 'combo', ':item_name' => 'M11 Combo ' . $suffix,
            ':sku' => 'M11-C-' . $suffix, ':unit_name' => 'basket', ':quantity' => '1.000',
            ':unit_price' => 8000, ':line_total' => 8000, ':created_at' => '2099-12-30 09:00:00',
        ]
    );
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit, created_at)
         VALUES (:order_id, :item_type, :item_name, :sku, :unit_name, :quantity, :unit_price, :line_total, :created_at)',
        [
            ':order_id' => $kitchenOrder, ':item_type' => 'product', ':item_name' => 'M11 Pomo ' . $suffix,
            ':sku' => 'KITCHEN-RUN', ':unit_name' => 'kg', ':quantity' => '3.000',
            ':unit_price' => 2000, ':line_total' => 6000, ':created_at' => '2099-12-29 09:00:00',
        ]
    );
    Database::run(
        'INSERT INTO kitchen_run_requests
            (request_number, customer_type, input_mode, pricing_mode, status, converted_order_id)
         VALUES (:number, :customer_type, :input_mode, :pricing_mode, :status, :order_id)',
        [
            ':number' => 'M11-KR-' . $suffix, ':customer_type' => 'business',
            ':input_mode' => 'custom', ':pricing_mode' => 'by_us', ':status' => 'converted',
            ':order_id' => $kitchenOrder,
        ]
    );
    Database::run(
        'INSERT INTO order_items
            (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit, created_at)
         VALUES (:order_id, :item_type, :item_name, :sku, :unit_name, :quantity, :unit_price, :line_total, :created_at)',
        [
            ':order_id' => $manualOrder, ':item_type' => 'product', ':item_name' => 'M11 Market Item ' . $suffix,
            ':sku' => 'CUSTOM', ':unit_name' => 'bag', ':quantity' => '1.000',
            ':unit_price' => 4000, ':line_total' => 4000, ':created_at' => '2099-12-28 09:00:00',
        ]
    );

    $makePayment = static function (int $orderId, string $type, int $expected, int $paid, string $status, ?string $dueAt) use ($pdo, $suffix): int {
        Database::run(
            'INSERT INTO payments
                (payment_number, order_id, provider, payment_type, expected_amount_subunit,
                 paid_amount_subunit, currency, status, due_at)
             VALUES (:number, :order_id, :provider, :payment_type, :expected, :paid, :currency, :status, :due_at)',
            [
                ':number' => 'M11-PAY-' . $type . '-' . $suffix, ':order_id' => $orderId,
                ':provider' => 'paystack', ':payment_type' => $type, ':expected' => $expected,
                ':paid' => $paid, ':currency' => Money::CODE, ':status' => $status, ':due_at' => $dueAt,
            ]
        );
        return (int) $pdo->lastInsertId();
    };

    $revenuePayment = $makePayment($todayOrder, 'revenue', 10000, 10000, 'paid', null);
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :attempt, :provider, :reference, :domain, :status,
                 :requested, :amount, :currency, :email, :paid_at)',
        [
            ':payment_id' => $revenuePayment, ':attempt' => 1, ':provider' => 'paystack',
            ':reference' => 'M11-TXN-' . $suffix, ':domain' => 'test', ':status' => 'part_refunded',
            ':requested' => 10000, ':amount' => 11000, ':currency' => Money::CODE,
            ':email' => 'm11@example.test', ':paid_at' => '2099-12-31 10:00:00',
        ]
    );
    $revenueTxn = (int) $pdo->lastInsertId();
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :attempt, :provider, :reference, :domain, :status,
                 :requested, :amount, :currency, :email, :paid_at)',
        [
            ':payment_id' => $revenuePayment, ':attempt' => 2, ':provider' => 'paystack',
            ':reference' => 'M11-FAILED-' . $suffix, ':domain' => 'test', ':status' => 'failed',
            ':requested' => 9000, ':amount' => 9000, ':currency' => Money::CODE,
            ':email' => 'm11@example.test', ':paid_at' => '2099-12-31 10:05:00',
        ]
    );
    Database::run(
        'INSERT INTO refunds
            (payment_transaction_id, order_id, amount_subunit, currency, status, refunded_at)
         VALUES (:transaction_id, :order_id, :amount, :currency, :status, :refunded_at)',
        [
            ':transaction_id' => $revenueTxn, ':order_id' => $todayOrder, ':amount' => 3000,
            ':currency' => Money::CODE, ':status' => Refunds::STATUS_PROCESSED,
            ':refunded_at' => '2099-12-31 11:00:00',
        ]
    );
    Database::run(
        'INSERT INTO refunds
            (payment_transaction_id, order_id, amount_subunit, currency, status, refunded_at)
         VALUES (:transaction_id, :order_id, :amount, :currency, :status, :refunded_at)',
        [
            ':transaction_id' => $revenueTxn, ':order_id' => $todayOrder, ':amount' => 2000,
            ':currency' => Money::CODE, ':status' => Refunds::STATUS_REQUESTED,
            ':refunded_at' => '2099-12-31 11:30:00',
        ]
    );

    $reversedPayment = $makePayment($todayOrder, 'reversed_test', 5000, 0, 'unpaid', null);
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :attempt, :provider, :reference, :domain, :status,
                 :requested, :amount, :currency, :email, :paid_at)',
        [
            ':payment_id' => $reversedPayment, ':attempt' => 1, ':provider' => 'manual',
            ':reference' => 'M11-REVERSED-' . $suffix, ':domain' => 'test', ':status' => 'reversed',
            ':requested' => 5000, ':amount' => 5000, ':currency' => Money::CODE,
            ':email' => 'm11@example.test', ':paid_at' => '2099-12-31 09:00:00',
        ]
    );

    $undatedPayment = $makePayment($todayOrder, 'undated_test', 2000, 2000, 'paid', null);
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :attempt, :provider, :reference, :domain, :status,
                 :requested, :amount, :currency, :email, NULL)',
        [
            ':payment_id' => $undatedPayment, ':attempt' => 1, ':provider' => 'manual',
            ':reference' => 'M11-UNDATED-' . $suffix, ':domain' => 'test', ':status' => 'success',
            ':requested' => 2000, ':amount' => 2000, ':currency' => Money::CODE,
            ':email' => 'm11@example.test',
        ]
    );

    $makePayment($comboOrder, 'due_overdue', 9000, 2000, 'part_paid', '2099-12-30 18:00:00');
    $makePayment($kitchenOrder, 'due_today', 4000, 0, 'unpaid', '2099-12-31 18:00:00');
    $makePayment($manualOrder, 'due_future', 4000, 0, 'unpaid', '2100-01-01 00:00:00');
    $cancelledPayment = $makePayment($cancelledOrder, 'due_cancelled', 5000, 5000, 'paid', '2099-12-30 18:00:00');
    Database::run(
        'INSERT INTO payment_transactions
            (payment_id, attempt_number, provider, reference, domain, status,
             requested_amount_subunit, amount_subunit, currency, customer_email, paid_at)
         VALUES (:payment_id, :attempt, :provider, :reference, :domain, :status,
                 :requested, :amount, :currency, :email, :paid_at)',
        [
            ':payment_id' => $cancelledPayment, ':attempt' => 1, ':provider' => 'manual',
            ':reference' => 'M11-CANCELLED-CASH-' . $suffix, ':domain' => 'test', ':status' => 'success',
            ':requested' => 5000, ':amount' => 5000, ':currency' => Money::CODE,
            ':email' => 'm11@example.test', ':paid_at' => '2099-12-31 08:00:00',
        ]
    );

    $userIds = [];
    $businessIds = [];
    foreach (['OWES', 'CREDIT'] as $index => $tag) {
        Database::run(
            'INSERT INTO users
                (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first_name, :last_name, :email, :phone, :password_hash, :user_type, :status)',
            [
                ':first_name' => 'M11', ':last_name' => $tag,
                ':email' => strtolower($tag) . '-' . strtolower($suffix) . '@example.test',
                ':phone' => '+23470' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
                ':password_hash' => password_hash('test-password', PASSWORD_BCRYPT),
                ':user_type' => 'business', ':status' => 'active',
            ]
        );
        $userIds[] = (int) $pdo->lastInsertId();
        Database::run(
            'INSERT INTO business_customers
                (user_id, business_name, contact_person, credit_status, credit_limit_subunit)
             VALUES (:user_id, :business_name, :contact_person, :credit_status, :credit_limit)',
            [
                ':user_id' => $userIds[$index], ':business_name' => 'M11 ' . $tag . ' ' . $suffix,
                ':contact_person' => 'M11 Tester', ':credit_status' => $index === 0 ? 'approved' : 'withdrawn',
                ':credit_limit' => $index === 0 ? 50000 : 0,
            ]
        );
        $businessIds[] = (int) $pdo->lastInsertId();
    }
    foreach ([
        [$businessIds[0], 'charge', 12000, '2099-12-30'],
        [$businessIds[0], 'repayment', -5000, null],
        [$businessIds[1], 'repayment', -9000, null],
    ] as [$businessId, $type, $amount, $dueDate]) {
        Database::run(
            'INSERT INTO credit_transactions
                (business_customer_id, transaction_type, amount_subunit, due_date, status)
             VALUES (:business_id, :transaction_type, :amount, :due_date, :status)',
            [
                ':business_id' => $businessId, ':transaction_type' => $type,
                ':amount' => $amount, ':due_date' => $dueDate, ':status' => 'open',
            ]
        );
    }

    adb_eq(1, AdminDashboard::ordersToday($now), 'today counts the midnight order and excludes the cancelled order');

    $revenue = AdminDashboard::revenueToday($now);
    adb_eq(12000, $revenue['revenue_subunit'], 'revenue keeps real cancelled-order cash and subtracts only the processed refund');
    adb_eq(15000, $revenue['revenue_gross_subunit'], 'gateway fees and a failed retry are not treated as revenue');
    adb_eq(3000, $revenue['revenue_refund_subunit'], 'a processed refund is recognised while a requested refund is not');
    adb_ok($revenue['undated_receipts_count'] >= 1, 'an undated credited receipt is surfaced for integrity review');

    $due = AdminDashboard::paymentsDue($now);
    adb_eq(2, $due['payments_due_count'], 'only overdue and due-today obligations count');
    adb_eq(11000, $due['payments_due_subunit'], 'due balances are summed without future or cancelled payments');
    adb_eq(7000, $due['payments_overdue_subunit'], 'the unpaid part of the older obligation is overdue');
    adb_eq(4000, $due['payments_due_today_subunit'], 'the due-today obligation remains separate');

    adb_eq(7000, AdminDashboard::creditOutstanding($now), 'global credit floors each business before summing');

    $sales = AdminDashboard::salesOverTime(7, $now);
    adb_eq(7, count($sales['series']), 'the database sales projection returns every day in the preset');
    adb_eq(12000, $sales['series'][6]['amount_subunit'], 'the final day contains net cash revenue');

    $top = AdminDashboard::topProducts(7, $now);
    $topByLabel = array_column($top, null, 'label');
    adb_eq(7000, $topByLabel['M11 Tomatoes ' . $suffix]['amount_subunit'], 'top product value is reduced by the order refund');
    adb_eq('combo', $topByLabel['M11 Combo ' . $suffix]['kind'], 'a Combo stays a separate sellable line');
    adb_eq('kitchen_run', $topByLabel['M11 Pomo ' . $suffix]['kind'], 'a converted Kitchen Run stays a separate sellable line');

    $share = AdminDashboard::categoryShare(7, $now);
    $shareBySlug = array_column($share['rows'], null, 'category_slug');
    adb_eq(10000, array_sum(array_column($share['rows'], 'share_basis_points')), 'database category share totals 10000 basis points');
    adb_eq(7000, $shareBySlug['vegetables']['amount_subunit'], 'product sales use the product current category');
    adb_eq(8000, $shareBySlug['combos']['amount_subunit'], 'Combo sales use the Combos group');
    adb_eq(6000, $shareBySlug['kitchen-runs']['amount_subunit'], 'Kitchen Run sales use the Kitchen Runs group');
    adb_eq(4000, $share['uncategorised_subunit'], 'an unrelated typed line remains explicitly uncategorised');

    $selective = AdminDashboard::overview(['orders_today'], 7, $now);
    adb_ok(isset($selective['summary']['orders_today']), 'a requested section is returned');
    adb_ok(!isset($selective['summary']['revenue_subunit']), 'an unrequested financial summary is absent');
    adb_ok(!isset($selective['sales_over_time']), 'an unrequested chart is absent');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

fwrite(STDOUT, "\n$passed / $tests Admin dashboard database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
