<?php
/**
 * scripts/tests/basket_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket against a real database. The maths and the rules are
 * covered by BasketTest.php; this covers what only a database can show:
 *
 *   - an add writes one row at today's price
 *   - a repeat add at the same price grows that row rather than adding another
 *   - a repeat add after a reprice writes a second row and leaves the first
 *     alone, so the basket holds 1kg at ₦2,700 and 1kg at ₦3,000
 *   - the server refuses a quantity under the minimum and over the ceiling,
 *     and rounds a between-steps quantity up
 *   - removals only touch a line in the caller's own basket
 *   - a guest basket merges into an account basket inside one transaction,
 *     the source cart ends up status 'merged' with no session token, and the
 *     collision rules hold on both sides
 *   - a guest basket past its 30 days is retired rather than served
 *
 *   php scripts/tests/basket_db_test.php
 *
 * Creates a throwaway category, throwaway products, a throwaway combo and a
 * throwaway customer, asserts, then removes them. It never touches a seeded
 * product, the seeded Stew Combo or a real customer. Run it after
 * php scripts/migrate.php on a scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Money.php';
require_once $root . '/includes/classes/Pricing.php';
require_once $root . '/includes/classes/Catalogue.php';
require_once $root . '/includes/classes/Products.php';
require_once $root . '/includes/classes/Combos.php';
require_once $root . '/includes/classes/Customer.php';
require_once $root . '/includes/classes/BasketError.php';
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
    if (!$same) {
        $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($same, $label);
}

/** The basket is session-bound, so the test drives the session directly. */
function be_guest(string $token): void {
    $_SESSION = ['okv_basket_token' => $token];
}
function be_customer(int $userId): void {
    $_SESSION = ['user_id' => $userId, 'user_type' => 'household', 'email_verified' => true, 'first_name' => 'Test'];
}
function be_customer_with_guest_basket(int $userId, string $token): void {
    be_customer($userId);
    $_SESSION['okv_basket_token'] = $token;
}
function cart_rows(int $cartId): array {
    return Database::all(
        'SELECT id, item_type, product_id, combo_package_id, quantity, unit_price_subunit
           FROM cart_items WHERE cart_id = :cart_id ORDER BY id',
        [':cart_id' => $cartId]
    );
}
function cart_id_for_token(string $token): ?int {
    $row = Database::one('SELECT id FROM shopping_carts WHERE session_token = :t ORDER BY id DESC LIMIT 1', [':t' => $token]);
    return $row ? (int) $row['id'] : null;
}
function account_cart_id(int $userId): ?int {
    $row = Database::one('SELECT id FROM shopping_carts WHERE user_id = :u AND status = \'active\' ORDER BY id DESC LIMIT 1', [':u' => $userId]);
    return $row ? (int) $row['id'] : null;
}
function refusal(callable $fn): string {
    try {
        $fn();
    } catch (BasketError $e) {
        return $e->reason();
    } catch (Throwable $e) {
        return 'threw:' . get_class($e);
    }
    return 'no_error';
}

// --- Fixtures ---------------------------------------------------------------

$suffix  = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$catSlug = 'zz-basket-' . strtolower($suffix);

Database::run(
    'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
     VALUES (:name, :slug, :description, 901, 1)',
    [':name' => 'ZZ Basket ' . $suffix, ':slug' => $catSlug, ':description' => 'Throwaway category for the basket test.']
);
$categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

$kgUnitId = (int) (Database::one('SELECT id FROM units_of_measurement WHERE symbol = :s', [':s' => 'kg'])['id'] ?? 1);

function make_basket_product(int $categoryId, string $suffix, string $name, int $unitId, int $priceSubunit, string $minimum, string $increment): int {
    [$clean, $errors] = Products::validate([
        'name'               => $name . ' ' . $suffix,
        'sku'                => 'ZZB-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)) . '-' . $suffix,
        'category_id'        => $categoryId,
        'unit_id'            => $unitId,
        'price'              => (string) Money::toNaira($priceSubunit),
        'minimum_quantity'   => $minimum,
        'quantity_increment' => $increment,
        'is_active'          => 1,
    ]);
    if ($errors) {
        fwrite(STDERR, 'fixture failed: ' . json_encode($errors) . "\n");
        exit(2);
    }
    return Products::create($clean, null);
}

// ₦2,700/kg, minimum 1kg, step 1kg. The acceptance scenario runs on this one.
$tomato = make_basket_product($categoryId, $suffix, 'Basket Tomato', $kgUnitId, 270000, '1', '1');
// ₦8,000/kg, minimum 0.5kg, step 0.5kg, for the rounding and minimum rules.
$ginger = make_basket_product($categoryId, $suffix, 'Basket Ginger', $kgUnitId, 800000, '0.5', '0.5');

[$comboClean, $comboErrors] = Combos::validate([
    'name'      => 'ZZ Basket Combo ' . $suffix,
    'sku'       => 'ZZB-COMBO-' . $suffix,
    'price'     => '16900',
    'is_active' => 0,
]);
if ($comboErrors) {
    fwrite(STDERR, 'combo fixture failed: ' . json_encode($comboErrors) . "\n");
    exit(2);
}
$comboId = Combos::create($comboClean, null);
Combos::addComponent($comboId, $tomato, 2.0, $kgUnitId);
Combos::publish($comboId);

Database::run(
    'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
     VALUES (:fn, :ln, :em, :ph, :pw, \'household\', \'active\')',
    [
        ':fn' => 'ZZ', ':ln' => 'Basket ' . $suffix,
        ':em' => 'zz.basket.' . strtolower($suffix) . '@example.test',
        ':ph' => '+234900' . substr((string) random_int(1000000, 9999999), 0, 7),
        ':pw' => password_hash('not-a-real-password-' . $suffix, PASSWORD_BCRYPT),
    ]
);
$userId = (int) Database::getInstance()->getConnection()->lastInsertId();

$tokens = [];

$cleanup = function () use ($tokens, $userId, $comboId, $tomato, $ginger, $categoryId) {
    foreach ($tokens as $token) {
        Database::run('DELETE ci FROM cart_items ci JOIN shopping_carts sc ON sc.id = ci.cart_id WHERE sc.session_token = :t', [':t' => $token]);
    }
    Database::run('DELETE ci FROM cart_items ci JOIN shopping_carts sc ON sc.id = ci.cart_id WHERE sc.user_id = :u', [':u' => $userId]);
    Database::run('DELETE FROM cart_items WHERE product_id IN (:p1, :p2) OR combo_package_id = :c', [':p1' => $tomato, ':p2' => $ginger, ':c' => $comboId]);
    Database::run('DELETE FROM shopping_carts WHERE user_id = :u', [':u' => $userId]);
    foreach ($tokens as $token) {
        Database::run('DELETE FROM shopping_carts WHERE session_token = :t', [':t' => $token]);
    }
    Database::run('DELETE FROM users WHERE id = :u', [':u' => $userId]);
    Database::run('DELETE FROM combo_package_items WHERE combo_package_id = :c', [':c' => $comboId]);
    Database::run('DELETE FROM combo_price_history WHERE combo_package_id = :c', [':c' => $comboId]);
    Database::run('DELETE FROM combo_packages WHERE id = :c', [':c' => $comboId]);
    foreach ([$tomato, $ginger] as $productId) {
        Database::run('DELETE FROM product_price_history WHERE product_id = :id', [':id' => $productId]);
        Database::run('DELETE FROM product_availability WHERE product_id = :id', [':id' => $productId]);
        Database::run('DELETE FROM products WHERE id = :id', [':id' => $productId]);
    }
    Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $categoryId]);
};

try {

// --- A first add writes one line at today's price ---------------------------

$guestToken = 'zz-basket-guest-' . strtolower($suffix);
$tokens[] = $guestToken;
be_guest($guestToken);
Basket::addProduct($tomato);

$guestCartId = cart_id_for_token($guestToken);
t_ok($guestCartId !== null, 'a guest add creates a cart against the session token');
$rows = cart_rows((int) $guestCartId);
t_eq(1, count($rows), 'the first add writes exactly one line');
t_eq(270000, (int) $rows[0]['unit_price_subunit'], 'the line carries the price the customer was shown, ₦2,700');
t_eq('1.000', (string) $rows[0]['quantity'], 'the first add puts the product minimum, 1kg, in the basket');
t_eq(1, Basket::count(), 'the badge reads one line');
t_eq(270000, Basket::state()['subtotal_subunit'], 'the subtotal is ₦2,700');

// --- A repeat add at the same price grows the same line ---------------------

Basket::addProduct($tomato);
$rows = cart_rows((int) $guestCartId);
t_eq(1, count($rows), 'a repeat add at the same price does not open a second line');
t_eq('2.000', (string) $rows[0]['quantity'], 'it adds one step, taking the line to 2kg');
t_eq(270000, (int) $rows[0]['unit_price_subunit'], 'and it leaves the price alone');
t_eq(540000, Basket::state()['subtotal_subunit'], 'two kilogrammes at ₦2,700 is ₦5,400');

// --- The acceptance scenario: ₦2,700 then ₦3,000 ---------------------------

// Reset to a single kilogramme so the two snapshots read 1kg and 1kg.
$state = Basket::state();
Basket::updateProductLine((int) $state['lines'][0]['id'], '1');

Pricing::change($tomato, 300000, 'Basket test reprice', null);

$result = Basket::addProduct($tomato);
t_ok($result['repriced'] === true, 'the add after a reprice reports the change');
t_eq(270000, $result['previous_price_subunit'], 'and it names the price the customer had before');
t_ok(str_contains($result['notice'], '₦2,700') && str_contains($result['notice'], '₦3,000'), 'the notice carries both prices');

$rows = cart_rows((int) $guestCartId);
t_eq(2, count($rows), 'the basket now holds two lines for one product, one per price');
t_eq(270000, (int) $rows[0]['unit_price_subunit'], 'the first line still holds ₦2,700, untouched');
t_eq('1.000', (string) $rows[0]['quantity'], 'and still holds 1kg');
t_eq(300000, (int) $rows[1]['unit_price_subunit'], 'the second line holds this week\'s ₦3,000');
t_eq('1.000', (string) $rows[1]['quantity'], 'and the 1kg just added');
t_eq(570000, Basket::state()['subtotal_subunit'], '1kg at ₦2,700 plus 1kg at ₦3,000 is ₦5,700');
t_ok(Basket::state()['has_repriced'], 'the basket knows to show the repriced notice');

// A third add at the new price folds into the new line, not the old one.
Basket::addProduct($tomato);
$rows = cart_rows((int) $guestCartId);
t_eq(2, count($rows), 'a third add still leaves two lines');
t_eq('1.000', (string) $rows[0]['quantity'], 'the ₦2,700 line is never touched again');
t_eq('2.000', (string) $rows[1]['quantity'], 'the ₦3,000 line grows instead');

// --- Quantity rules on the server ------------------------------------------

Basket::addProduct($ginger); // 0.5kg minimum, 0.5kg step
$state = Basket::state();
$gingerLine = null;
foreach ($state['lines'] as $line) {
    if ($line['product_id'] === $ginger) { $gingerLine = $line; }
}
t_ok($gingerLine !== null, 'the ginger line is in the basket');
$gingerLineId = (int) $gingerLine['id'];

t_eq('below_minimum', refusal(static fn() => Basket::updateProductLine($gingerLineId, '0.2')), 'a quantity under the 0.5kg minimum is refused');
t_eq('invalid_quantity', refusal(static fn() => Basket::updateProductLine($gingerLineId, 'plenty')), 'a word is refused');
t_eq('over_ceiling', refusal(static fn() => Basket::updateProductLine($gingerLineId, '500')), '500kg is over the packing ceiling');

$update = Basket::updateProductLine($gingerLineId, '1.2');
t_eq(1.5, $update['quantity'], '1.2kg rounds up to 1.5kg, the nearest step');
t_ok($update['adjusted'] === true, 'and the customer is told it was rounded');

$update = Basket::updateProductLine($gingerLineId, '2');
t_eq(2.0, $update['quantity'], 'a quantity on a step is kept as it is');
t_ok($update['adjusted'] === false, 'and nothing is said about rounding');

// --- Combo add, update and removal ------------------------------------------

Basket::addCombo($comboId);
Basket::addCombo($comboId);
$rows = Database::all(
    'SELECT id, quantity, unit_price_subunit FROM cart_items WHERE cart_id = :c AND item_type = \'combo\' ORDER BY id',
    [':c' => $guestCartId]
);
t_eq(1, count($rows), 'two adds of the same combo at the same price stay on one line');
t_eq('2.000', (string) $rows[0]['quantity'], 'that line reads two baskets');
$comboLineId = (int) $rows[0]['id'];

t_eq('over_ceiling', refusal(static fn() => Basket::updateComboLine($comboLineId, '21')), '21 combos is over the ceiling');
t_eq(3.0, Basket::updateComboLine($comboLineId, '2.4')['quantity'], 'a fractional combo count rounds up to a whole basket');
t_eq('not_found', refusal(static fn() => Basket::updateProductLine($comboLineId, '1')), 'a combo line cannot be edited through the product action');

Basket::removeLine($comboLineId, 'combo');
t_ok(Database::one('SELECT id FROM cart_items WHERE id = :id', [':id' => $comboLineId]) === null, 'the combo line is gone');
t_eq('not_found', refusal(static fn() => Basket::removeLine($comboLineId, 'combo')), 'removing it twice is refused rather than pretending');

// A line in someone else's basket is not removable.
$otherToken = 'zz-basket-other-' . strtolower($suffix);
$tokens[] = $otherToken;
be_guest($otherToken);
Basket::addProduct($tomato);
$otherCartId = cart_id_for_token($otherToken);
$otherLineId = (int) cart_rows((int) $otherCartId)[0]['id'];
be_guest($guestToken);
t_eq('not_found', refusal(static fn() => Basket::removeLine($otherLineId, 'product')), 'a line in another basket is not visible, let alone removable');

// --- Merge with no collisions ----------------------------------------------

$mergeToken = 'zz-basket-merge-' . strtolower($suffix);
$tokens[] = $mergeToken;
be_guest($mergeToken);
Basket::addProduct($ginger);
$mergeCartId = (int) cart_id_for_token($mergeToken);

be_customer_with_guest_basket($userId, $mergeToken);
$merge = Basket::mergeGuestCart($userId);
t_ok($merge['merged'] === true, 'the merge reports that it moved something');
t_eq(1, $merge['moved'], 'the one guest line moved across');
t_eq(0, $merge['folded'], 'nothing folded, the account basket was empty');

$sourceCart = Database::one('SELECT status, session_token FROM shopping_carts WHERE id = :id', [':id' => $mergeCartId]);
t_eq('merged', (string) $sourceCart['status'], 'the source guest cart is marked merged');
t_eq(null, $sourceCart['session_token'], 'and it gives up the unique session token so the next guest basket can claim one');
t_ok(!isset($_SESSION['okv_basket_token']), 'the session token is rotated after a merge');

$accountCartId = account_cart_id($userId);
t_ok($accountCartId !== null, 'the customer now has an active cart');
t_eq(1, count(cart_rows((int) $accountCartId)), 'holding the line that came from the guest basket');

// --- Merge with a collision at the same price ------------------------------

$foldToken = 'zz-basket-fold-' . strtolower($suffix);
$tokens[] = $foldToken;
be_guest($foldToken);
Basket::addProduct($ginger); // same product, same price as the account line

be_customer_with_guest_basket($userId, $foldToken);
$merge = Basket::mergeGuestCart($userId);
t_eq(1, $merge['folded'], 'the colliding line folded rather than moving');
t_eq(0, $merge['moved'], 'nothing moved across on its own');

$gingerRows = Database::all(
    'SELECT quantity, unit_price_subunit FROM cart_items WHERE cart_id = :c AND product_id = :p ORDER BY id',
    [':c' => $accountCartId, ':p' => $ginger]
);
t_eq(1, count($gingerRows), 'the account still holds one ginger line, not two');
t_eq('1.000', (string) $gingerRows[0]['quantity'], 'and its quantity is the sum of both, 1kg');

// --- Merge with a collision at a different price ---------------------------

Pricing::change($ginger, 900000, 'Basket test reprice, ginger', null);

$splitToken = 'zz-basket-split-' . strtolower($suffix);
$tokens[] = $splitToken;
be_guest($splitToken);
Basket::addProduct($ginger); // now at ₦9,000/kg

be_customer_with_guest_basket($userId, $splitToken);
$merge = Basket::mergeGuestCart($userId);
t_eq(1, $merge['moved'], 'a line at a different price moves across on its own');
t_eq(0, $merge['folded'], 'and nothing is blended');

$gingerRows = Database::all(
    'SELECT quantity, unit_price_subunit FROM cart_items WHERE cart_id = :c AND product_id = :p ORDER BY id',
    [':c' => $accountCartId, ':p' => $ginger]
);
t_eq(2, count($gingerRows), 'the account holds both price snapshots');
t_eq(800000, (int) $gingerRows[0]['unit_price_subunit'], 'the older line keeps ₦8,000');
t_eq(900000, (int) $gingerRows[1]['unit_price_subunit'], 'the newer line carries ₦9,000');
t_ok(Basket::state()['has_repriced'], 'the merged basket shows the repriced notice');

// Signing in with no guest basket at all is a quiet no-op.
be_customer($userId);
$merge = Basket::mergeGuestCart($userId);
t_ok($merge['merged'] === false, 'a sign-in with no guest basket merges nothing');

// --- A guest basket past its 30 days is retired ----------------------------

$staleToken = 'zz-basket-stale-' . strtolower($suffix);
$tokens[] = $staleToken;
be_guest($staleToken);
Basket::addProduct($tomato);
$staleCartId = (int) cart_id_for_token($staleToken);

$expiry = Database::one('SELECT expires_at FROM shopping_carts WHERE id = :id', [':id' => $staleCartId]);
t_ok(!empty($expiry['expires_at']), 'a guest cart is stamped with an expiry date');

Database::run('UPDATE shopping_carts SET expires_at = :when WHERE id = :id', [
    ':when' => date('Y-m-d H:i:s', strtotime('-1 day')),
    ':id'   => $staleCartId,
]);
be_guest($staleToken);
t_eq(0, Basket::count(), 'a basket past its 30 days reads as empty');

$staleCart = Database::one('SELECT status, session_token FROM shopping_carts WHERE id = :id', [':id' => $staleCartId]);
t_eq('abandoned', (string) $staleCart['status'], 'the stale cart is marked abandoned');
t_eq(null, $staleCart['session_token'], 'and releases its token, so a new basket on the same browser is not blocked');

// The same browser can start a fresh basket straight away.
be_guest($staleToken);
Basket::addProduct($tomato);
t_eq(1, Basket::count(), 'a fresh basket starts cleanly after an expiry');

} finally {
    $cleanup();
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} assertions passed.\n");
if ($GLOBALS['f']) {
    fwrite(STDOUT, count($GLOBALS['f']) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
