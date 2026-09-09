<?php
/**
 * scripts/tests/checkout_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Checkout placement against a real database. The rules are covered
 * by CheckoutTest.php; this covers what only a database shows: that placing an
 * order writes the order, its address snapshot, its line snapshots, the first
 * status event and an unpaid payment, that the basket is converted in the same
 * transaction, and that placing the same basket again returns the first order
 * rather than a second one.
 *
 *   php scripts/tests/checkout_db_test.php
 *
 * Creates a throwaway category, product and user, places one order, asserts,
 * then removes everything it made. Run it after php scripts/migrate.php on a
 * scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Money.php';
require_once $root . '/includes/classes/OrderNumber.php';
require_once $root . '/includes/classes/Settings.php';
require_once $root . '/includes/classes/Phone.php';
require_once $root . '/includes/classes/Pricing.php';
require_once $root . '/includes/classes/Catalogue.php';
require_once $root . '/includes/classes/Combos.php';
require_once $root . '/includes/classes/Customer.php';
require_once $root . '/includes/classes/Basket.php';
require_once $root . '/includes/classes/Delivery.php';
require_once $root . '/includes/classes/OrderTrail.php';
require_once $root . '/includes/classes/Checkout.php';
require_once $root . '/includes/functions/helpers.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0;
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function t_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    t_ok($same, $label);
}

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$_SESSION = [];
$orderId = null;

try {
    // --- Fixtures ------------------------------------------------------------
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:n, :s, :d, 902, 1)',
        [':n' => 'ZZ Checkout ' . $suffix, ':s' => 'zz-checkout-' . strtolower($suffix), ':d' => 'Throwaway.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $unit = Database::one('SELECT id FROM units_of_measurement WHERE symbol = :s LIMIT 1', [':s' => 'kg'])
        ?? Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    $unitId = (int) $unit['id'];

    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active)
         VALUES (:cat, :unit, :name, :slug, :sku, :desc, 270000, 1.000, 0.500, 1)',
        [':cat' => $categoryId, ':unit' => $unitId, ':name' => 'ZZ Checkout Tomato ' . $suffix, ':slug' => 'zz-cout-' . strtolower($suffix), ':sku' => 'ZC-' . $suffix, ':desc' => 'Throwaway.']
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :ph, :pw, \'household\', \'active\')',
        [':f' => 'ZZ', ':l' => 'Buyer', ':e' => 'zc-' . strtolower($suffix) . '@example.test', ':ph' => '+2347' . substr($suffix, 0, 9), ':pw' => password_hash('x', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    // Sign in and build a basket.
    $_SESSION['user_id'] = $userId;
    Basket::addProduct($productId);
    $state = Basket::state();
    $cartId = (int) $state['cart_id'];
    $expectedTotal = (int) $state['subtotal_subunit'];

    // A valid delivery day and an active zone.
    $dates = Delivery::nextEligibleDates('household', 1);
    $date = $dates ? $dates[0]['date'] : date('Y-m-d', strtotime('+7 days'));
    $zone = Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order LIMIT 1');
    $zoneId = (int) $zone['id'];

    $input = [
        'user_id'          => $userId,
        'customer_type'    => 'household',
        'activated'        => false,
        'recipient_name'   => 'ZZ Buyer',
        'recipient_phone'  => '+2348012345678',
        'address_line_1'   => '1 Test Street',
        'city'             => 'Lagos',
        'state'            => 'Lagos',
        'delivery_date'    => $date,
        'delivery_zone_id' => $zoneId,
        'payment_option'   => 'pay_in_full',
    ];

    // --- Place the order -----------------------------------------------------
    $result = Checkout::place($input);
    $orderId = (int) $result['order_id'];
    t_ok($orderId > 0, 'placing an order returns an order id');
    t_ok($result['order_number'] !== '', 'the order has an order number');
    t_ok(OrderTrail::isValidToken((string) $result['trail_token']), 'the order has a valid trail token');

    $order = Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
    t_eq($cartId, (int) $order['shopping_cart_id'], 'the order records the basket it converted');
    t_eq('pending', (string) $order['order_status'], 'a new order is pending');
    t_eq('pay_in_full', (string) $order['payment_option'], 'the payment choice is recorded');
    t_eq($expectedTotal, (int) $order['order_total_subunit'], 'the order total matches the basket subtotal');

    $items = Database::all('SELECT * FROM order_items WHERE order_id = :id', [':id' => $orderId]);
    t_eq(count($state['lines']), count($items), 'every basket line is snapshotted as an order item');

    t_ok((bool) Database::one('SELECT id FROM order_addresses WHERE order_id = :id', [':id' => $orderId]), 'the delivery address is snapshotted');
    t_ok((bool) Database::one('SELECT id FROM order_status_history WHERE order_id = :id AND new_status = \'pending\'', [':id' => $orderId]), 'the first status event is written');
    $schedule = Database::one('SELECT delivery_date, status FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId]);
    t_eq($date, (string) $schedule['delivery_date'], 'checkout creates the delivery schedule for the chosen day');
    t_eq('scheduled', (string) $schedule['status'], 'a new delivery schedule is ready for fulfilment');

    $payment = Database::one('SELECT expected_amount_subunit, status FROM payments WHERE order_id = :id', [':id' => $orderId]);
    t_eq($expectedTotal, (int) $payment['expected_amount_subunit'], 'the payment records the amount due');
    t_eq('unpaid', (string) $payment['status'], 'no payment has been taken yet');

    $cart = Database::one('SELECT status FROM shopping_carts WHERE id = :id', [':id' => $cartId]);
    t_eq('converted', (string) $cart['status'], 'the basket is converted in the same transaction');

    // --- Idempotency: placing the same basket again returns the same order ---
    $again = Checkout::place($input);
    t_eq($orderId, (int) $again['order_id'], 'placing the same basket again returns the first order, not a second');
    $orderCount = Database::one('SELECT COUNT(*) AS c FROM orders WHERE shopping_cart_id = :c', [':c' => $cartId]);
    t_eq(1, (int) $orderCount['c'], 'only one order exists for the basket');

    // --- A guest order (PRD 9.2, Milestone 6/7) ------------------------------
    //
    // No account, no sign in, and it still has to be a whole order: an address
    // snapshot, a payment row, a delivery schedule, a trail token, and the email
    // address to reach the customer on. The account write paths are the ones
    // that used to demand a user id, so each is checked for a guest.
    unset($_SESSION['user_id'], $_SESSION['okv_checkout']);
    Basket::addProduct($productId);
    $guestState = Basket::state();
    $guestCartId = $guestState['cart_id'] === null ? 0 : (int) $guestState['cart_id'];
    t_ok($guestCartId > 0 && $guestCartId !== $cartId, 'a signed out visitor gets a basket of their own');

    $guestInput = [
        'user_id'          => null,
        'customer_type'    => 'household',
        'activated'        => false,
        'email'            => 'zz-guest@example.test',
        'recipient_name'   => 'ZZ Guest',
        'recipient_phone'  => '+2348012345679',
        'address_line_1'   => '2 Test Street',
        'city'             => 'Lagos',
        'state'            => 'Lagos',
        'delivery_date'    => $date,
        'delivery_zone_id' => $zoneId,
        'payment_option'   => 'pay_in_full',
    ];
    $guestResult = Checkout::place($guestInput);
    $guestOrderId = (int) $guestResult['order_id'];
    t_ok($guestOrderId > 0 && $guestOrderId !== $orderId, 'a guest places a real order of their own');
    t_ok(OrderTrail::isValidToken((string) $guestResult['trail_token']), 'and it carries a trail token, which is the only way back to it');

    $guestOrder = Database::one('SELECT user_id, contact_email, created_by FROM orders WHERE id = :id', [':id' => $guestOrderId]);
    t_eq(null, $guestOrder['user_id'], 'a guest order belongs to no account');
    t_eq(null, $guestOrder['created_by'], 'and names no account as its author');
    t_eq('zz-guest@example.test', (string) $guestOrder['contact_email'], 'the order keeps the address its email goes to');

    t_ok((bool) Database::one('SELECT id FROM order_addresses WHERE order_id = :o', [':o' => $guestOrderId]), 'a guest order snapshots its delivery address');
    t_ok((bool) Database::one('SELECT id FROM payments WHERE order_id = :o', [':o' => $guestOrderId]), 'and records what is owed');
    t_ok((bool) Database::one('SELECT id FROM delivery_schedules WHERE order_id = :o', [':o' => $guestOrderId]), 'and is on the delivery schedule like any other order');
    t_eq(
        0,
        (int) Database::one('SELECT COUNT(*) AS c FROM customer_addresses WHERE recipient_name = :n', [':n' => 'ZZ Guest'])['c'],
        'a guest has no account, so nothing is saved to one for next time'
    );

    // The trail link is the credential, so it has to find the order.
    t_ok(OrderTrail::findByToken((string) $guestResult['trail_token']) !== null, 'the trail token opens the guest order');
    t_eq(
        $guestOrderId,
        (int) OrderTrail::findByToken((string) $guestResult['trail_token'])['id'],
        'and opens that order and no other'
    );
} finally {
    // --- Teardown ------------------------------------------------------------
    foreach (array_filter([$orderId ?? 0, $guestOrderId ?? 0]) as $o) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :o)', [':o' => $o]);
        Database::run('DELETE FROM order_items WHERE order_id = :o', [':o' => $o]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :o', [':o' => $o]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :o', [':o' => $o]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :o', [':o' => $o]);
        Database::run('DELETE FROM payments WHERE order_id = :o', [':o' => $o]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :o)', [':o' => $o]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :o', [':o' => $o]);
        Database::run('DELETE FROM orders WHERE id = :o', [':o' => $o]);
    }
    if (isset($guestCartId) && $guestCartId > 0) {
        Database::run('DELETE FROM cart_items WHERE cart_id = :c', [':c' => $guestCartId]);
        Database::run('DELETE FROM shopping_carts WHERE id = :c', [':c' => $guestCartId]);
    }
    if (isset($userId)) {
        Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $userId]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $userId]);
        Database::run('DELETE FROM users WHERE id = :u', [':u' => $userId]);
    }
    if (isset($productId)) {
        Database::run('DELETE FROM products WHERE id = :p', [':p' => $productId]);
    }
    if (isset($categoryId)) {
        Database::run('DELETE FROM product_categories WHERE id = :c', [':c' => $categoryId]);
    }
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} database assertions passed.\n");
exit($GLOBALS['p'] === $GLOBALS['t'] ? 0 : 1);
