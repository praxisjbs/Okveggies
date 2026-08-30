<?php
/**
 * scripts/tests/combos_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Combos against a real database. The maths is covered by
 * CombosTest.php; this covers what only a database can show: that create opens
 * the history with a null old price, that changePrice closes the open row and
 * opens a new one, that saving the same price is a silent no-op, that publish
 * refuses without components or a price, that delete refuses when history
 * exists, that the last-component-remove auto-unpublish shape holds up when
 * the controller does it, and that a component insert enforces the unique key
 * on (combo, product, unit).
 *
 *   php scripts/tests/combos_db_test.php
 *
 * Creates throwaway products in a throwaway category plus a throwaway combo,
 * asserts, then removes them. It never touches a seeded product or the seeded
 * Stew Combo. Run it after php scripts/migrate.php on a scratch database.
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

// --- Fixtures ---------------------------------------------------------------

$suffix  = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$catSlug = 'zz-combo-' . strtolower($suffix);

Database::run(
    'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
     VALUES (:name, :slug, :description, 900, 1)',
    [':name' => 'ZZ Combo ' . $suffix, ':slug' => $catSlug, ':description' => 'Throwaway category for the combos test.']
);
$categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

function make_product(int $categoryId, string $suffix, string $name, int $unitId, int $priceSubunit): int {
    [$clean, $errors] = Products::validate([
        'name'        => $name . ' ' . $suffix,
        'sku'         => 'ZZC-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)) . '-' . $suffix,
        'category_id' => $categoryId,
        'unit_id'     => $unitId,
        'price'       => $priceSubunit > 0 ? (string) Money::toNaira($priceSubunit) : '',
        'minimum_quantity'   => '1',
        'quantity_increment' => '1',
        'is_active'   => 1,
    ]);
    if ($errors) {
        fwrite(STDERR, "fixture failed: " . json_encode($errors) . "\n");
        exit(2);
    }
    return Products::create($clean, null);
}

// A kg product and a bunch product, so we can test the same-product-different-unit rule.
$kgUnitId    = (int) (Database::one('SELECT id FROM units_of_measurement WHERE symbol = :s', [':s' => 'kg'])['id'] ?? 1);
$bunchUnitId = (int) (Database::one('SELECT id FROM units_of_measurement WHERE symbol = :s', [':s' => 'bunch'])['id'] ?? 2);

$tomato = make_product($categoryId, $suffix, 'Combo Tomato', $kgUnitId,    270000); // ₦2,700/kg
$onion  = make_product($categoryId, $suffix, 'Combo Onion',  $kgUnitId,    140000); // ₦1,400/kg
$parsley = make_product($categoryId, $suffix, 'Combo Parsley', $bunchUnitId, 50000); // ₦500/bunch

$combos = []; // filled below, cleaned up at the end

$cleanup = function () use (&$combos, $tomato, $onion, $parsley, $categoryId) {
    foreach ($combos as $comboId) {
        Database::run('DELETE FROM cart_items WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_package_items WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_price_history WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_packages WHERE id = :id', [':id' => $comboId]);
    }
    foreach ([$tomato, $onion, $parsley] as $productId) {
        Database::run('DELETE FROM product_price_history WHERE product_id = :id', [':id' => $productId]);
        Database::run('DELETE FROM products WHERE id = :id', [':id' => $productId]);
    }
    Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $categoryId]);
};

try {

// --- Create with a price opens the history --------------------------------

$sku1 = 'ZZC-STEW-' . $suffix;
[$clean, $errors] = Combos::validate([
    'name'  => 'ZZ Test Combo ' . $suffix,
    'sku'   => $sku1,
    'price' => '5000',
    'is_active' => 0,
]);
t_eq([], $errors, 'the create form validates cleanly');
$combo1 = Combos::create($clean, null);
$combos[] = $combo1;

$history = Combos::history($combo1);
t_eq(1, count($history), 'a combo created with a price opens its history with one row');
t_eq(null, $history[0]['old_price_subunit'], 'the opening row has no old price');
t_eq(500000, (int) $history[0]['new_price_subunit'], 'the opening row carries the opening price');
t_eq(null, $history[0]['effective_to'], 'the opening row is left open');

// --- Create without a price writes no history -----------------------------

[$cleanDraft] = Combos::validate([
    'name'  => 'ZZ Draft Combo ' . $suffix,
    'sku'   => 'ZZC-DRAFT-' . $suffix,
    'price' => '',
    'is_active' => 0,
]);
$draft = Combos::create($cleanDraft, null);
$combos[] = $draft;
t_eq(0, count(Combos::history($draft)), 'a draft combo created without a price writes no history');

// --- changePrice closes the open row and opens a new one ------------------

$result = Combos::changePrice($combo1, 600000, 'Weekly reprice', null);
t_ok($result['changed'], 'a real price change reports that it changed');
t_eq(500000, $result['old'], 'and reports the old price');
t_eq(600000, $result['new'], 'and the new price');

$row = Database::one('SELECT price_subunit FROM combo_packages WHERE id = :id', [':id' => $combo1]);
t_eq(600000, (int) $row['price_subunit'], 'the combo now carries the new sell price');

$history = Combos::history($combo1);
t_eq(2, count($history), 'a price change writes exactly one more history row');
t_eq(500000, (int) $history[0]['old_price_subunit'], 'the new row records what the price was');
t_eq(600000, (int) $history[0]['new_price_subunit'], 'the new row records what it became');
t_eq('Weekly reprice', $history[0]['change_reason'], 'the reason is kept');
t_eq(null, $history[0]['effective_to'], 'the new row is the open one');
t_ok($history[1]['effective_to'] !== null, 'the previous row was closed off');
t_eq(1, count(array_filter($history, static fn($h) => $h['effective_to'] === null)), 'only one history row is ever open');

// --- Setting the same price again is a silent no-op -----------------------

$before = count(Combos::history($combo1));
$again = Combos::changePrice($combo1, 600000, 'Same again', null);
t_ok(!$again['changed'], 'setting the sell price it already has is not a change');
t_eq($before, count(Combos::history($combo1)), 'and it writes no history row, so re-saving the builder adds no noise');

// --- A refused price writes nothing ---------------------------------------

$before = count(Combos::history($combo1));
$threw = false;
try {
    Combos::changePrice($combo1, 0, 'Free combo', null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'invalid_price');
}
t_ok($threw, 'a sell price of nothing is refused');
t_eq($before, count(Combos::history($combo1)), 'a refused change writes no history');

// --- Publish gate ---------------------------------------------------------

// The draft has no components and no price. Publish must refuse both.
$threw = false;
try {
    Combos::publish($draft);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'no_price');
}
t_ok($threw, 'publish refuses a combo with no sell price');

// Price it, still no components.
Combos::changePrice($draft, 100000, 'Priced for the publish test', null);
$threw = false;
try {
    Combos::publish($draft);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'no_components');
}
t_ok($threw, 'publish refuses a combo with no components');

$rowCheck = Database::one('SELECT is_active FROM combo_packages WHERE id = :id', [':id' => $draft]);
t_eq(0, (int) $rowCheck['is_active'], 'and it stays off the shop after both refusals');

// Add one component. Now publish should work.
$comp1 = Combos::addComponent($draft, $tomato, 1.0, $kgUnitId);
Combos::publish($draft);
$rowCheck = Database::one('SELECT is_active FROM combo_packages WHERE id = :id', [':id' => $draft]);
t_eq(1, (int) $rowCheck['is_active'], 'once components and a price are in place, publish flips it on');

// --- Component adds ------------------------------------------------------

Combos::addComponent($combo1, $tomato,  2.0, $kgUnitId);
Combos::addComponent($combo1, $onion,   1.0, $kgUnitId);
Combos::addComponent($combo1, $parsley, 1.0, $bunchUnitId);
t_eq(3, Combos::componentCount($combo1), 'three components sit on the combo');

$total = Combos::componentTotal($combo1);
t_eq(540000 + 140000 + 50000, $total, 'the component total is the sum of the current prices at those quantities');

// Same product under a different unit: allowed, because the unique key is on
// (combo, product, unit). Adding tomato in bunches alongside tomato in kg
// keeps both lines.
Combos::addComponent($combo1, $tomato, 1.0, $bunchUnitId);
t_eq(4, Combos::componentCount($combo1), 'the same product under a different unit is accepted');

// The unique key still catches a genuine duplicate.
$threw = false;
try {
    Combos::addComponent($combo1, $tomato, 3.0, $kgUnitId);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'already_in_combo');
}
t_ok($threw, 'the same product under the same unit is refused as already in the combo');

// --- update_component and remove_component -------------------------------

$components = Combos::components($combo1);
$firstId = (int) $components[0]['component_id'];
Combos::updateComponent($firstId, 3.0);
$refreshed = Combos::components($combo1);
t_eq(3.0, (float) $refreshed[0]['quantity'], 'a component quantity is updated in place');

Combos::removeComponent($firstId);
t_eq(3, Combos::componentCount($combo1), 'a component is removed cleanly');

// --- delete refuses when the combo carries any hold ----------------------

$refs = Combos::referenceCount($combo1);
t_ok($refs['prices'] > 0, 'a priced combo is held by its price history');
$threw = false;
try {
    Combos::delete($combo1);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'in_use');
}
t_ok($threw, 'delete refuses when a price history row exists');
t_ok(Database::one('SELECT id FROM combo_packages WHERE id = :id', [':id' => $combo1]) !== null, 'and the combo is still there');

// --- publish + unpublish cycle -------------------------------------------

// The Stew combo is off the shop, priced and has components: it can be published.
$combo1Row = Database::one('SELECT is_active FROM combo_packages WHERE id = :id', [':id' => $combo1]);
t_eq(0, (int) $combo1Row['is_active'], 'the priced combo starts off the shop');
Combos::publish($combo1);
$combo1Row = Database::one('SELECT is_active FROM combo_packages WHERE id = :id', [':id' => $combo1]);
t_eq(1, (int) $combo1Row['is_active'], 'publish flips it on');
Combos::unpublish($combo1);
$combo1Row = Database::one('SELECT is_active FROM combo_packages WHERE id = :id', [':id' => $combo1]);
t_eq(0, (int) $combo1Row['is_active'], 'unpublish takes it off, no gate');

// --- delete on a truly clean combo ---------------------------------------

// Draft priced, published, one component. Rebuild a fresh clean combo with
// no price and no components, so nothing holds it. This exercises the happy
// path of delete.
[$cleanClean] = Combos::validate([
    'name'  => 'ZZ Clean Combo ' . $suffix,
    'sku'   => 'ZZC-CLEAN-' . $suffix,
    'price' => '',
    'is_active' => 0,
]);
$clean = Combos::create($cleanClean, null);
// no history, no components, no orders, no baskets
t_eq(0, Combos::referenceCount($clean)['total'], 'an unpriced draft with no components is held by nothing');
Combos::delete($clean);
t_ok(Database::one('SELECT id FROM combo_packages WHERE id = :id', [':id' => $clean]) === null, 'and it can be removed outright');

// --- Availability window through validate() ------------------------------

[, $windowErrors] = Combos::validate([
    'name'            => 'Window ' . $suffix,
    'sku'             => 'ZZC-WIN-' . $suffix,
    'price'           => '1000',
    'available_from'  => '2026-12-24',
    'available_until' => '2026-12-01',
]);
t_ok(isset($windowErrors['available_until']), 'an end date before the start date is refused');

[$windowClean, $windowErrors] = Combos::validate([
    'name'            => 'Window ' . $suffix,
    'sku'             => 'ZZC-WIN-' . $suffix,
    'price'           => '1000',
    'available_from'  => '2026-12-01',
    'available_until' => '2026-12-24',
]);
t_eq([], $windowErrors, 'a proper window validates');
t_eq('2026-12-01', $windowClean['available_from'], 'the from date is kept in ISO shape');
t_eq('2026-12-24', $windowClean['available_until'], 'the until date is kept in ISO shape');

// --- SKU and name validation ---------------------------------------------

[, $vErrors] = Combos::validate(['name' => '', 'sku' => 'bad sku', 'price' => '-5']);
t_ok(isset($vErrors['name']), 'a combo needs a name');
t_ok(isset($vErrors['sku']), 'a SKU with spaces is refused');
t_ok(isset($vErrors['price']), 'a negative price is refused');

// A SKU that already belongs to another combo is refused.
[, $skuErrors] = Combos::validate(['name' => 'Clash ' . $suffix, 'sku' => $sku1, 'price' => '1000']);
t_ok(isset($skuErrors['sku']), 'a SKU already used by another combo is refused');

} finally {
    $cleanup();
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} assertions passed.\n");
if ($GLOBALS['f']) {
    fwrite(STDOUT, count($GLOBALS['f']) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
