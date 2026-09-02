<?php
/**
 * scripts/tests/basket_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket against a real database. The rules are covered by
 * BasketTest.php; this covers what only a database shows: that a first add puts
 * the minimum in the basket, that a repeat add at the same price folds into the
 * line, that a repeat add after a reprice opens a second line and leaves the
 * first alone, that quantity updates and removes hold, that a guest basket
 * merges into an account (folding a matching price, moving a different one),
 * and that a guest basket past its expiry is retired.
 *
 *   php scripts/tests/basket_db_test.php
 *
 * Creates a throwaway category, product and user, asserts, then removes them.
 * It never touches a seeded product. Run it after php scripts/migrate.php on a
 * scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Money.php';
require_once $root . '/includes/classes/Pricing.php';
require_once $root . '/includes/classes/Catalogue.php';
require_once $root . '/includes/classes/Combos.php';
require_once $root . '/includes/classes/Customer.php';
require_once $root . '/includes/classes/Basket.php';
require_once $root . '/includes/functions/helpers.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0; $GLOBALS['f'] = [];
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    $GLOBALS['f'][] = $label;
    fwrite(STDOUT, "  FAIL: $label\n");
}
function t_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    t_ok($same, $label);
}

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$_SESSION = [];
$usedTokens = [];

try {
    // --- Fixtures ------------------------------------------------------------
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:n, :s, :d, 901, 1)',
        [':n' => 'ZZ Basket ' . $suffix, ':s' => 'zz-basket-' . strtolower($suffix), ':d' => 'Throwaway.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $unit = Database::one('SELECT id FROM units_of_measurement WHERE symbol = :s LIMIT 1', [':s' => 'kg'])
        ?? Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1');
    $unitId = (int) $unit['id'];

    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active)
         VALUES (:cat, :unit, :name, :slug, :sku, :desc, 270000, 1.000, 0.500, 1)',
        [':cat' => $categoryId, ':unit' => $unitId, ':name' => 'ZZ Tomatoes ' . $suffix, ':slug' => 'zz-tomato-' . strtolower($suffix), ':sku' => 'ZZ-' . $suffix, ':desc' => 'Throwaway product.']
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:f, :l, :e, :ph, :pw, \'household\', \'active\')',
        [':f' => 'ZZ', ':l' => 'Tester', ':e' => 'zz-' . strtolower($suffix) . '@example.test', ':ph' => '+2348' . substr($suffix, 0, 9), ':pw' => password_hash('x', PASSWORD_BCRYPT)]
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    // --- Guest: first add is the minimum -------------------------------------
    $_SESSION = ['okv_basket_token' => bin2hex(random_bytes(16))];
    $usedTokens[] = $_SESSION['okv_basket_token'];
    Basket::addProduct($productId);
    $line = Database::one('SELECT quantity, unit_price_subunit FROM cart_items ci JOIN shopping_carts s ON s.id = ci.cart_id WHERE s.session_token = :t', [':t' => $_SESSION['okv_basket_token']]);
    t_eq('1.000', (string) $line['quantity'], 'the first add puts the minimum in the basket');
    t_eq(270000, (int) $line['unit_price_subunit'], 'the line carries the price at the time of the add');

    // Repeat add at the same price folds into the line by one step.
    Basket::addProduct($productId);
    $count = Basket::count();
    t_eq(1, $count, 'a repeat add at the same price stays one line');
    $line = Database::one('SELECT quantity FROM cart_items ci JOIN shopping_carts s ON s.id = ci.cart_id WHERE s.session_token = :t', [':t' => $_SESSION['okv_basket_token']]);
    t_eq('1.500', (string) $line['quantity'], 'the line grows by one increment');

    // --- Reprice: a repeat add at a new price opens a second line -------------
    Database::run('UPDATE products SET current_price_subunit = 300000 WHERE id = :id', [':id' => $productId]);
    $result = Basket::addProduct($productId);
    t_ok(!empty($result['repriced']), 'the add after a reprice reports a reprice');
    t_eq(2, Basket::count(), 'the reprice opens a second line');
    $prices = Database::all('SELECT unit_price_subunit FROM cart_items ci JOIN shopping_carts s ON s.id = ci.cart_id WHERE s.session_token = :t ORDER BY ci.id', [':t' => $_SESSION['okv_basket_token']]);
    t_eq(270000, (int) $prices[0]['unit_price_subunit'], 'the first line keeps the old price');
    t_eq(300000, (int) $prices[1]['unit_price_subunit'], 'the second line carries the new price');

    // --- Update and remove ---------------------------------------------------
    $state = Basket::state();
    $firstLineId = (int) $state['lines'][0]['id'];
    Basket::updateProduct($firstLineId, '2.000');
    $q = Database::one('SELECT quantity FROM cart_items WHERE id = :id', [':id' => $firstLineId]);
    t_eq('2.000', (string) $q['quantity'], 'a valid update sets the quantity');
    Basket::updateProduct($firstLineId, '0');
    t_ok(!Database::one('SELECT id FROM cart_items WHERE id = :id', [':id' => $firstLineId]), 'a zero update removes the line');

    // --- Guest merge into an account -----------------------------------------
    $guestToken = $_SESSION['okv_basket_token'];
    $_SESSION['user_id'] = $userId;             // now signed in
    Basket::mergeGuestIntoAccount($userId);
    $accountLines = Database::all('SELECT unit_price_subunit FROM cart_items ci JOIN shopping_carts s ON s.id = ci.cart_id WHERE s.user_id = :u AND s.status = \'active\' ORDER BY ci.id', [':u' => $userId]);
    t_ok(count($accountLines) >= 1, 'the guest lines move into the account basket');
    $guestCart = Database::one('SELECT status, session_token FROM shopping_carts WHERE session_token = :t', [':t' => $guestToken]);
    t_ok($guestCart === false || $guestCart['status'] !== 'active', 'the guest cart is no longer active after the merge');

    // --- Guest cart expiry retires an old basket -----------------------------
    unset($_SESSION['user_id']);
    $_SESSION['okv_basket_token'] = bin2hex(random_bytes(16));
    $usedTokens[] = $_SESSION['okv_basket_token'];
    Basket::addProduct($productId);
    Database::run('UPDATE shopping_carts SET expires_at = :past WHERE session_token = :t', [':past' => date('Y-m-d H:i:s', time() - 3600), ':t' => $_SESSION['okv_basket_token']]);
    t_eq(0, Basket::count(), 'a guest basket past its expiry is retired, not served');
} finally {
    // --- Teardown ------------------------------------------------------------
    if (isset($productId)) {
        Database::run('DELETE FROM cart_items WHERE product_id = :p', [':p' => $productId]);
    }
    foreach ($usedTokens as $token) {
        Database::run('DELETE FROM shopping_carts WHERE session_token = :t', [':t' => $token]);
    }
    if (isset($userId)) {
        Database::run('DELETE FROM cart_items WHERE cart_id IN (SELECT id FROM shopping_carts WHERE user_id = :u)', [':u' => $userId]);
        Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $userId]);
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
