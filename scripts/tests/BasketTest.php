<?php
/** Basket maths, quantity rules and price-snapshot merge behaviour. */

$lines = [
    ['quantity' => '1.000', 'unit_price_subunit' => 270000],
    ['quantity' => '0.500', 'unit_price_subunit' => 400000],
    ['quantity' => '2.000', 'unit_price_subunit' => 1690000],
];
okv_test_eq(3850000, Basket::subtotalFromLines($lines), 'basket subtotal sums product and combo lines');
okv_test_eq(0, Basket::subtotalFromLines([]), 'an empty basket has a zero subtotal');

okv_test_ok(Basket::isValidProductQuantity('1.000', '1.000', '0.250'), 'product minimum is valid');
okv_test_ok(Basket::isValidProductQuantity('1.500', '1.000', '0.250'), 'a valid product increment is accepted');
okv_test_ok(!Basket::isValidProductQuantity('0.750', '1.000', '0.250'), 'quantity below product minimum is refused');
okv_test_ok(!Basket::isValidProductQuantity('1.100', '1.000', '0.250'), 'quantity outside product increment is refused');
okv_test_ok(Basket::isValidProductQuantity('3', '1', '1'), 'whole-unit product quantity is valid');
okv_test_ok(!Basket::isValidProductQuantity('2.5', '1', '1'), 'fractional quantity is refused for a whole-unit product');
okv_test_ok(Basket::isValidComboQuantity('99'), 'combo ceiling itself is valid');
okv_test_ok(!Basket::isValidComboQuantity('100'), 'combo quantity above ceiling is refused');
okv_test_ok(!Basket::isValidComboQuantity('1.5'), 'fractional combo quantity is refused');

$account = [
    ['item_type' => 'product', 'product_id' => 4, 'combo_package_id' => null, 'quantity' => '1.000', 'unit_price_subunit' => 270000],
];
$guestNoCollision = [
    ['item_type' => 'combo', 'product_id' => null, 'combo_package_id' => 2, 'quantity' => '1.000', 'unit_price_subunit' => 1690000],
];
$merged = Basket::mergeSnapshotLines($account, $guestNoCollision);
okv_test_eq(2, count($merged), 'guest merge without collisions keeps both lines');

$guestCollision = [
    ['item_type' => 'product', 'product_id' => 4, 'combo_package_id' => null, 'quantity' => '0.500', 'unit_price_subunit' => 270000],
    ['item_type' => 'product', 'product_id' => 4, 'combo_package_id' => null, 'quantity' => '1.000', 'unit_price_subunit' => 300000],
];
$merged = Basket::mergeSnapshotLines($account, $guestCollision);
okv_test_eq(2, count($merged), 'merge combines equal snapshots but preserves a repriced snapshot');
okv_test_eq('1.500', $merged[0]['quantity'], 'merge collision adds matching quantities');
okv_test_eq(300000, $merged[1]['unit_price_subunit'], 'merge collision retains the new unit price');

$same = Basket::repeatAddPlan(['quantity' => '1.000', 'unit_price_subunit' => 270000], 270000, '1.000');
okv_test_eq('increment', $same['operation'], 'repeat add at the same price increments the existing line');
okv_test_eq('2.000', $same['quantity'], 'same-price repeat add increments quantity');
$repriced = Basket::repeatAddPlan(['quantity' => '1.000', 'unit_price_subunit' => 270000], 300000, '1.000');
okv_test_eq('append', $repriced['operation'], 'repeat add after a price change appends a snapshot line');
okv_test_ok($repriced['repriced'], 'repeat add after a price change is marked repriced');
okv_test_eq(300000, $repriced['unit_price_subunit'], 'new snapshot carries the current price');

okv_test_eq('remove', Basket::productUpdateAction('0', '1.000', '0.250'), 'product quantity zero means remove');
okv_test_eq('invalid', Basket::productUpdateAction('0.500', '1.000', '0.250'), 'below-minimum product update is invalid');
okv_test_eq('update', Basket::productUpdateAction('1.250', '1.000', '0.250'), 'valid product update is accepted');
okv_test_eq('remove', Basket::comboUpdateAction('0'), 'combo quantity zero means remove');
okv_test_eq('invalid', Basket::comboUpdateAction('100'), 'combo update above ceiling is invalid');
okv_test_eq('update', Basket::comboUpdateAction('2'), 'valid combo update is accepted');
