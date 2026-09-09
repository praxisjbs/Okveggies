<?php
/** Task E Pro order pagination, detail, documents, share links and isolation. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
$tests = 0; $passed = 0;
function pod_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function pod_eq($expected, $actual, string $label): void { pod_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = bin2hex(random_bytes(5)); $users = []; $businesses = []; $orders = []; $selectedId = 0;
try {
    foreach (['First', 'Second'] as $index => $name) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first_name, :last_name, :email, :phone, :password_hash, :user_type, :status, NOW())',
            [':first_name' => $name, ':last_name' => 'Orders', ':email' => "pod-$index-$suffix@example.test",
             ':phone' => '+23470' . random_int(10000000, 99999999), ':password_hash' => password_hash('test-only', PASSWORD_BCRYPT),
             ':user_type' => 'business', ':status' => 'active']
        );
        $users[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
        Database::run('INSERT INTO business_customers (user_id, business_name, contact_person) VALUES (:user_id, :name, :contact)', [':user_id' => $users[$index], ':name' => "$name Orders $suffix", ':contact' => "$name Orders"]);
        $businesses[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    $originalToken = OrderTrail::newToken();
    for ($i = 1; $i <= 26; $i++) {
        Database::run(
            'INSERT INTO orders
                (order_number, order_trail_token_hash, user_id, customer_type, order_status, payment_option, payment_status,
                 subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit, preferred_delivery_date, created_at)
             VALUES (:number, :token_hash, :user_id, :customer_type, :order_status, :payment_option, :payment_status,
                     :subtotal, :total, :paid, :balance, :delivery_date, :created_at)',
            [':number' => "POD-A-$i-$suffix", ':token_hash' => $i === 26 ? OrderTrail::hashToken($originalToken) : null,
             ':user_id' => $users[0], ':customer_type' => 'business', ':order_status' => $i % 2 ? 'pending' : 'confirmed',
             ':payment_option' => 'on_account', ':payment_status' => $i === 26 ? 'part_paid' : 'unpaid',
             ':subtotal' => 1000000 + $i, ':total' => 1000000 + $i, ':paid' => $i === 26 ? 250000 : 0,
             ':balance' => $i === 26 ? 750026 : 1000000 + $i, ':delivery_date' => '2026-09-11',
             ':created_at' => '2026-09-' . str_pad((string) min($i, 26), 2, '0', STR_PAD_LEFT) . ' 09:00:00']
        );
        $orders[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    $selectedId = $orders[25];
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date)
         VALUES (:number, :user_id, :customer_type, :order_status, :payment_option, :payment_status, :subtotal, :total, :balance, :delivery_date)',
        [':number' => "POD-B-1-$suffix", ':user_id' => $users[1], ':customer_type' => 'business', ':order_status' => 'delivered',
         ':payment_option' => 'pay_in_full', ':payment_status' => 'paid', ':subtotal' => 9000000, ':total' => 9000000,
         ':balance' => 0, ':delivery_date' => '2026-09-11']
    );
    $otherOrder = (int) Database::getInstance()->getConnection()->lastInsertId(); $orders[] = $otherOrder;

    Database::run('INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state) VALUES (:order_id, :name, :phone, :line1, :city, :state)', [':order_id' => $selectedId, ':name' => 'First Orders', ':phone' => '08030000000', ':line1' => '1 Test Road', ':city' => 'Lagos', ':state' => 'Lagos']);
    Database::run('INSERT INTO order_items (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit) VALUES (:order_id, :type, :name, :sku, :unit, :quantity, :price, :total)', [':order_id' => $selectedId, ':type' => 'product', ':name' => 'Tomatoes', ':sku' => 'TEST-TOM', ':unit' => 'kg', ':quantity' => '2.000', ':price' => 500013, ':total' => 1000026]);
    Database::run('INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at) VALUES (:order_id, NULL, :pending, :source, :user_id, :created_at)', [':order_id' => $selectedId, ':pending' => 'pending', ':source' => 'customer', ':user_id' => $users[0], ':created_at' => '2026-09-07 09:00:00']);
    Database::run('INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at) VALUES (:order_id, :pending, :confirmed, :source, NULL, :created_at)', [':order_id' => $selectedId, ':pending' => 'pending', ':confirmed' => 'confirmed', ':source' => 'admin', ':created_at' => '2026-09-08 09:00:00']);
    Database::run('INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status, confirmed_at) VALUES (:number, :user_id, :order_id, :provider, :type, :expected, :paid, :status, :confirmed)', [':number' => "PAY-POD-$suffix", ':user_id' => $users[0], ':order_id' => $selectedId, ':provider' => 'manual', ':type' => 'deposit', ':expected' => 1000026, ':paid' => 250000, ':status' => 'part_paid', ':confirmed' => '2026-09-08 10:00:00']);
    Database::run('INSERT INTO kitchen_run_requests (request_number, user_id, customer_type, input_mode, pricing_mode, status, converted_order_id) VALUES (:number, :user_id, :customer_type, :mode, :pricing, :status, :order_id)', [':number' => "KR-POD-$suffix", ':user_id' => $users[0], ':customer_type' => 'business', ':mode' => 'custom', ':pricing' => 'by_us', ':status' => 'converted', ':order_id' => $selectedId]);
    Database::run('INSERT INTO credit_transactions (business_customer_id, order_id, transaction_type, amount_subunit, due_date, status) VALUES (:business_id, :order_id, :type, :amount, :due_date, :status)', [':business_id' => $businesses[0], ':order_id' => $selectedId, ':type' => 'charge', ':amount' => 1000026, ':due_date' => '2026-09-18', ':status' => 'open']);

    $page1 = ProOrders::listing($users[0], [], 1); $page2 = ProOrders::listing($users[0], [], 2);
    pod_eq(26, $page1['count'], 'the first business sees all 26 of its orders');
    pod_eq(25, count($page1['orders']), 'the first page holds 25 orders');
    pod_eq(1, count($page2['orders']), 'the second page holds the remaining order');
    pod_eq("POD-A-26-$suffix", (string) $page1['orders'][0]['order_number'], 'orders are newest first');
    pod_eq(1, ProOrders::listing($users[1], [], 1)['count'], 'the second business sees only its own order');
    pod_eq(13, ProOrders::listing($users[0], ['status' => 'confirmed'], 1)['count'], 'the status filter stays inside the owned order set');

    $detail = ProOrders::detail($selectedId, $users[0]);
    pod_ok($detail !== null, 'the owner opens its order detail');
    pod_eq(null, ProOrders::detail($selectedId, $users[1]), 'another business cannot open that detail');
    pod_eq('Tomatoes', (string) $detail['items'][0]['item_name'], 'detail uses the order item snapshot');
    pod_eq('1 Test Road', (string) $detail['address']['address_line_1'], 'detail uses the order address snapshot');
    pod_eq(['Placed', 'Sourced'], array_column($detail['trail'], 'label'), 'detail uses the customer-facing lifecycle projection');
    pod_ok($detail['kitchen_run'] !== null && $detail['credit_charge'] !== null, 'detail carries owned Kitchen Run and credit links');
    pod_ok(OrderDocument::loadForCustomer($selectedId, $users[0]) !== null, 'the owner is authorised for invoice and receipt data');
    pod_eq(null, OrderDocument::loadForCustomer($selectedId, $users[1]), 'another business is refused document data');

    $shareToken = OrderTrail::issueForCustomer($selectedId, $users[0]);
    pod_ok(OrderTrail::isValidToken((string) $shareToken), 'an owned order receives a fresh valid share token');
    pod_eq(null, OrderTrail::issueForCustomer($selectedId, $users[1]), 'another business cannot issue a share token');
    pod_eq($selectedId, (int) OrderTrail::findByToken($originalToken)['id'], 'the original Order Trail token remains valid');
    pod_eq($selectedId, (int) OrderTrail::findByToken((string) $shareToken)['id'], 'the additional Order Trail token opens the same order');
    $shareRow = Database::one('SELECT token_hash FROM order_trail_share_links WHERE order_id = :order_id', [':order_id' => $selectedId]);
    pod_eq(OrderTrail::hashToken((string) $shareToken), (string) $shareRow['token_hash'], 'only the share-token hash is stored');
    pod_ok((string) $shareRow['token_hash'] !== (string) $shareToken, 'the raw share token is never stored');
} finally {
    foreach ($orders as $orderId) {
        Database::run('DELETE FROM order_trail_share_links WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM credit_transactions WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM kitchen_run_requests WHERE converted_order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM payments WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM order_items WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :order_id', [':order_id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :order_id', [':order_id' => $orderId]);
    }
    foreach ($businesses as $id) { Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $id]); }
    foreach ($users as $id) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
}
fwrite(STDOUT, "\n$passed / $tests Pro order database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
