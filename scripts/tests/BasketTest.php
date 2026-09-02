<?php
/**
 * scripts/tests/BasketTest.php
 * OK Veggies. The basket rules that must never be wrong: quantity validation,
 * the repeat-add plan (the reprice-as-a-second-line decision), the guest merge
 * and the money on a line. All pure, no database. The database side lives in
 * scripts/tests/basket_db_test.php.
 */

// --- Quantity validation. Minimum, step and ceiling, on the server. ----------
okv_test_ok(Basket::isValidProductQuantity('1.000', '1.000', '0.500'), 'the minimum is a valid quantity');
okv_test_ok(Basket::isValidProductQuantity('1.500', '1.000', '0.500'), 'one step above the minimum is valid');
okv_test_ok(!Basket::isValidProductQuantity('1.250', '1.000', '0.500'), 'off the step grid is refused');
okv_test_ok(!Basket::isValidProductQuantity('0.500', '1.000', '0.500'), 'below the minimum is refused');
okv_test_ok(Basket::isValidProductQuantity('100.000', '1.000', '1.000'), 'the product ceiling is allowed');
okv_test_ok(!Basket::isValidProductQuantity('101.000', '1.000', '1.000'), 'past the product ceiling is refused');
okv_test_ok(!Basket::isValidProductQuantity('abc', '1.000', '0.500'), 'a non-number is refused');

okv_test_ok(Basket::isValidComboQuantity('1.000'), 'one whole combo is valid');
okv_test_ok(Basket::isValidComboQuantity('20.000'), 'the combo ceiling is allowed');
okv_test_ok(!Basket::isValidComboQuantity('1.500'), 'a fractional combo is refused');
okv_test_ok(!Basket::isValidComboQuantity('21.000'), 'past the combo ceiling is refused');
okv_test_ok(!Basket::isValidComboQuantity('0.000'), 'zero combos is not a quantity');

// --- Update actions. A cleared or zero box means remove. ---------------------
okv_test_eq('remove', Basket::productUpdateAction('', '1.000', '0.500'), 'clearing the box removes the line');
okv_test_eq('remove', Basket::productUpdateAction('0', '1.000', '0.500'), 'zero removes the line');
okv_test_eq('update', Basket::productUpdateAction('1.500', '1.000', '0.500'), 'a valid quantity updates the line');
okv_test_eq('invalid', Basket::productUpdateAction('1.250', '1.000', '0.500'), 'an off-grid quantity is refused');
okv_test_eq('remove', Basket::comboUpdateAction('0'), 'zero combos removes the line');
okv_test_eq('update', Basket::comboUpdateAction('2.000'), 'two combos updates the line');
okv_test_eq('invalid', Basket::comboUpdateAction('2.500'), 'a fractional combo update is refused');

// --- The repeat-add plan. One row, one price. --------------------------------
$fresh = Basket::repeatAddPlan(null, 270000, '1.000', 'product');
okv_test_eq('append', $fresh['operation'], 'nothing there yet opens a new line');
okv_test_eq('1.000', $fresh['quantity'], 'the new line starts at the amount added');
okv_test_ok(!$fresh['repriced'], 'a first add is not a reprice');

$same = Basket::repeatAddPlan(['unit_price_subunit' => 270000, 'quantity' => '1.000'], 270000, '0.500', 'product');
okv_test_eq('increment', $same['operation'], 'the same item at the same price folds into the line');
okv_test_eq('1.500', $same['quantity'], 'the line grows by one step');
okv_test_ok(!$same['repriced'], 'a same-price add is not a reprice');

$moved = Basket::repeatAddPlan(['unit_price_subunit' => 270000, 'quantity' => '1.000'], 300000, '1.000', 'product');
okv_test_eq('append', $moved['operation'], 'the same item at a new price opens a second line');
okv_test_eq(300000, $moved['unit_price_subunit'], 'the new line carries the new price');
okv_test_ok($moved['repriced'], 'a changed price is flagged as a reprice');

$cap = Basket::repeatAddPlan(['unit_price_subunit' => 270000, 'quantity' => '99.500'], 270000, '1.000', 'product');
okv_test_eq('100.000', $cap['quantity'], 'a product line is capped at the ceiling');
okv_test_ok($cap['capped'], 'the cap is reported so the customer is told');

$comboCap = Basket::repeatAddPlan(['unit_price_subunit' => 500000, 'quantity' => '20.000'], 500000, '1.000', 'combo');
okv_test_eq('20.000', $comboCap['quantity'], 'a combo line is capped at the combo ceiling');

// --- The guest merge. Same price folds, a different price moves across. -------
$account = [
    ['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '1.000', 'unit_price_subunit' => 270000],
];
$guest = [
    ['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '2.000', 'unit_price_subunit' => 270000],
    ['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '1.000', 'unit_price_subunit' => 300000],
    ['item_type' => 'product', 'product_id' => 9, 'combo_package_id' => 0, 'quantity' => '1.000', 'unit_price_subunit' => 150000],
];
$merged = Basket::mergeSnapshotLines($account, $guest);
okv_test_eq(3, count($merged), 'a fold, a price move and a new item leave three lines');
okv_test_eq('3.000', $merged[0]['quantity'], 'the same price folds the quantities together');
okv_test_eq(300000, $merged[1]['unit_price_subunit'], 'the other price snapshot survives on its own line');
okv_test_eq(9, (int) $merged[2]['product_id'], 'a new item is carried across');

$mergeCap = Basket::mergeSnapshotLines(
    [['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '99.500', 'unit_price_subunit' => 270000]],
    [['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '1.000', 'unit_price_subunit' => 270000]]
);
okv_test_eq('100.000', $mergeCap[0]['quantity'], 'a merge cannot push a line past the ceiling');

// --- Money on the lines, and the reprice notice. -----------------------------
$lines = [
    ['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '2.000', 'unit_price_subunit' => 270000],
    ['item_type' => 'product', 'product_id' => 5, 'combo_package_id' => 0, 'quantity' => '1.000', 'unit_price_subunit' => 300000],
];
okv_test_eq(840000, Basket::subtotalFromLines($lines), 'the subtotal sums the two price snapshots honestly');
$decorated = Basket::decorateLines($lines);
okv_test_ok(!$decorated[0]['price_changed'], 'the first line for an item is never the changed one');
okv_test_ok($decorated[1]['price_changed'], 'the later line at a new price is flagged');
okv_test_eq(270000, $decorated[1]['previous_price_subunit'], 'the flagged line remembers the price it moved from');
okv_test_ok(Basket::hasRepricedLines($decorated), 'a basket holding two prices shows the reprice notice');
okv_test_ok(!Basket::hasRepricedLines(Basket::decorateLines([$lines[0]])), 'a basket at one price shows no notice');
