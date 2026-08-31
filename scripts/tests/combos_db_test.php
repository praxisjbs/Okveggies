<?php
/**
 * Database coverage for the combo builder. Run after migrations on a scratch
 * database: php scripts/tests/combos_db_test.php
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
require_once $root . '/includes/classes/Basket.php';
require_once $root . '/includes/functions/helpers.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0; $GLOBALS['f'] = [];
function combo_db_ok($condition, string $label): void {
    $GLOBALS['t']++;
    if ($condition) { $GLOBALS['p']++; return; }
    $GLOBALS['f'][] = $label;
    fwrite(STDOUT, "  FAIL: $label\n");
}
function combo_db_eq($expected, $actual, string $label): void {
    combo_db_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$categoryId = 0; $pricedProduct = 0; $unpricedProduct = 0; $comboId = 0; $cartToken = '';
try {
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:name, :slug, :description, 990, 1)',
        [':name' => 'ZZ Combo ' . $suffix, ':slug' => 'zz-combo-' . strtolower($suffix), ':description' => 'Throwaway combo test category.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $makeProduct = static function (string $name, int $price) use ($categoryId, $suffix): int {
        [$clean, $errors] = Products::validate([
            'name' => $name . ' ' . $suffix,
            'sku' => 'ZZC-' . strtoupper($name) . '-' . $suffix,
            'category_id' => $categoryId,
            'unit_id' => 1,
            'price' => $price > 0 ? (string) Money::toNaira($price) : '',
            'minimum_quantity' => '1', 'quantity_increment' => '1', 'is_active' => 1,
        ]);
        if ($errors) { throw new RuntimeException('Fixture failed.'); }
        return Products::create($clean, null);
    };
    $pricedProduct = $makeProduct('Tomato', 270000);
    $unpricedProduct = $makeProduct('Draft', 0);

    [$clean, $errors] = Combos::validate([
        'name' => 'ZZ Dinner ' . $suffix, 'sku' => 'ZZC-COMBO-' . $suffix,
        'description' => 'Throwaway combo.', 'price' => '', 'is_active' => 0,
    ]);
    combo_db_eq([], $errors, 'a draft combo validates without a price');
    $comboId = Combos::create($clean, null);
    combo_db_eq(0, count(Combos::history($comboId)), 'an unpriced draft opens no price-history row');

    Combos::replaceComponents($comboId, [['product_id' => $pricedProduct, 'unit_id' => 1, 'quantity' => 2]]);
    combo_db_eq(540000, Combos::componentTotal($comboId), 'the builder component total uses the current product price');
    $noPrice = false;
    try { Combos::publish($comboId); } catch (DomainException $e) { $noPrice = $e->getMessage() === 'no_price'; }
    combo_db_ok($noPrice, 'a combo without a sell price cannot publish');

    Combos::changePrice($comboId, 500000, 'Opening dinner price', null);
    $history = Combos::history($comboId);
    combo_db_eq(1, count($history), 'setting the first sell price writes one history row');
    combo_db_eq(null, $history[0]['old_price_subunit'], 'the first sell-price history row has no old price');
    combo_db_eq(500000, (int) $history[0]['new_price_subunit'], 'the first sell-price history row stores the new price');
    combo_db_ok(Combos::isLossMaking(500000, Combos::componentTotal($comboId)), 'the manager loss flag uses the live component total');

    Combos::publish($comboId);
    $row = Combos::find($comboId);
    combo_db_eq(1, (int) $row['is_active'], 'a complete priced combo publishes');

    $_SESSION = [];
    $added = Basket::addCombo($comboId);
    $cartToken = (string) ($_SESSION['okv_basket_token'] ?? '');
    combo_db_eq(1, $added['count'], 'adding a combo creates one basket line');
    combo_db_eq(1, $added['quantity_added'], 'the first add carries quantity 1');
    $again = Basket::addCombo($comboId);
    combo_db_eq(1, $again['count'], 'adding the same combo again keeps one basket line');
    combo_db_eq(2, $again['quantity_added'], 'the same combo line increments by 1');

    Combos::changePrice($comboId, 550000, 'Friday reprice', null);
    $history = Combos::history($comboId);
    combo_db_eq(2, count($history), 'a reprice adds one sell-price history row');
    combo_db_eq(500000, (int) $history[0]['old_price_subunit'], 'the reprice records the prior sell price');
    combo_db_eq(null, $history[0]['effective_to'], 'the newest sell-price row stays open');
    combo_db_ok($history[1]['effective_to'] !== null, 'the previous sell-price row is closed in the same change');

    Combos::replaceComponents($comboId, [['product_id' => $unpricedProduct, 'unit_id' => 1, 'quantity' => 1]]);
    $unpriced = false;
    try { Combos::publish($comboId); } catch (DomainException $e) { $unpriced = $e->getMessage() === 'component_without_price'; }
    combo_db_ok($unpriced, 'an unpriced selected product blocks publishing');
    $row = Combos::find($comboId);
    combo_db_eq(1, (int) $row['is_active'], 'a refused publish leaves an already-live combo unchanged');
} finally {
    if ($comboId) {
        Database::run('DELETE FROM cart_items WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_price_history WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_package_items WHERE combo_package_id = :id', [':id' => $comboId]);
        Database::run('DELETE FROM combo_packages WHERE id = :id', [':id' => $comboId]);
    }
    if ($cartToken !== '') {
        Database::run('DELETE FROM shopping_carts WHERE session_token = :token', [':token' => $cartToken]);
    }
    foreach ([$pricedProduct, $unpricedProduct] as $productId) {
        if ($productId) {
            Database::run('DELETE FROM product_price_history WHERE product_id = :id', [':id' => $productId]);
            Database::run('DELETE FROM products WHERE id = :id', [':id' => $productId]);
        }
    }
    if ($categoryId) { Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $categoryId]); }
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} assertions passed.\n");
if ($GLOBALS['p'] !== $GLOBALS['t']) { exit(1); }
fwrite(STDOUT, "All green.\n");
