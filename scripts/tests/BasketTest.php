<?php
/**
 * scripts/tests/BasketTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket maths and the rules that decide what a line becomes.
 * Money logic, so it is unit tested before it is wired to anything.
 *
 * Everything here is pure: quantities in, quantities out, rows in, a plan out.
 * The database side (a real merge inside one transaction, the source cart
 * flipped to merged, the guest token rotated, a repeat add writing a second
 * row) lives in scripts/tests/basket_db_test.php, which needs a live MariaDB.
 *
 * The prices below are the seeded ones, so a change to the seed cannot quietly
 * drift the maths: Fresh Tomatoes ₦2,700 per kg (270000 subunits), Onion ₦1,400
 * per kg, The Stew Combo ₦16,900.
 * -----------------------------------------------------------------------------
 */

// --- Quantity rules: minimum and increment, enforced on the server ----------

// The catalogue's own rule for tomatoes: minimum 1kg, step 1kg.
okv_test_eq(1.0, Basket::normaliseProductQuantity('1', 1, 1), '1kg at a 1kg minimum is kept');
okv_test_eq(3.0, Basket::normaliseProductQuantity('3', 1, 1), '3kg on a 1kg step is kept');
okv_test_eq(3.0, Basket::normaliseProductQuantity('2.4', 1, 1), '2.4kg on a 1kg step rounds up to 3kg, never down');
okv_test_eq(2.0, Basket::normaliseProductQuantity(2, 1, 1), 'an integer quantity is accepted as it stands');

// A half-kilogramme step, which ginger and rodo use.
okv_test_eq(0.5, Basket::normaliseProductQuantity('0.5', 0.5, 0.5), 'the minimum itself is always valid');
okv_test_eq(1.5, Basket::normaliseProductQuantity('1.2', 0.5, 0.5), '1.2kg on a 0.5kg step rounds up to 1.5kg');
okv_test_eq(2.5, Basket::normaliseProductQuantity('2.2', 1, 0.5), '2.2kg with a 1kg minimum and 0.5kg step is 2.5kg');
okv_test_eq(0.25, Basket::normaliseProductQuantity('0.25', 0.25, 0.25), 'a quarter-kilogramme minimum survives');

// A step of nothing would divide by zero. It falls back to the minimum.
okv_test_eq(2.0, Basket::normaliseProductQuantity('2', 1, 0), 'a zero step falls back to the minimum as the step');

function basket_quantity_code(callable $fn): string
{
    try {
        $fn();
    } catch (DomainException $e) {
        return $e->getMessage();
    }
    return 'no_error';
}

okv_test_eq('below_minimum', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('0.5', 1, 1)), 'half a kilogramme under a 1kg minimum is refused, not rounded up');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('0', 1, 1)), 'a zero quantity is not an update, the caller removes the line instead');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('-2', 1, 1)), 'a negative quantity is refused');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('plenty', 1, 1)), 'a word is not a quantity');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('', 1, 1)), 'an empty field is refused rather than read as zero');

// The ceiling: 100 units on a product line. Above that is Kitchen Runs work.
okv_test_eq(100.0, Basket::normaliseProductQuantity('100', 1, 1), '100kg is the ceiling and is allowed');
okv_test_eq('over_ceiling', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('101', 1, 1)), '101kg is over the ceiling and is refused');
okv_test_eq('over_ceiling', basket_quantity_code(static fn() => Basket::normaliseProductQuantity('2000', 1, 1)), 'a fat-fingered 2,000kg is refused, not packed');

// A ₦ sign or a comma pasted into the box is stripped, not refused.
okv_test_eq(2.0, Basket::normaliseProductQuantity(' 2 ', 1, 1), 'spaces around a quantity are ignored');

// Combos are whole baskets. Twenty is the ceiling.
okv_test_eq(1.0, Basket::normaliseComboQuantity('1'), 'one combo is one combo');
okv_test_eq(3.0, Basket::normaliseComboQuantity(3), 'three combos are allowed');
okv_test_eq(2.0, Basket::normaliseComboQuantity('1.4'), 'half a combo rounds up to a whole one, you cannot pack half a basket');
okv_test_eq(20.0, Basket::normaliseComboQuantity('20'), '20 combos is the ceiling and is allowed');
okv_test_eq('over_ceiling', basket_quantity_code(static fn() => Basket::normaliseComboQuantity('21')), '21 combos is over the ceiling and is refused');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseComboQuantity('0')), 'zero combos is a removal, not an update');
okv_test_eq('invalid_quantity', basket_quantity_code(static fn() => Basket::normaliseComboQuantity('-1')), 'a negative combo count is refused');

// --- Subtotal maths ---------------------------------------------------------

$twoKgTomatoes = ['item_type' => 'product', 'product_id' => 4, 'quantity' => 2.0, 'unit_price_subunit' => 270000];
$oneKgOnion    = ['item_type' => 'product', 'product_id' => 13, 'quantity' => 1.0, 'unit_price_subunit' => 140000];
$oneStewCombo  = ['item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 1.0, 'unit_price_subunit' => 1690000];

okv_test_eq(0, Basket::subtotal([]), 'an empty basket costs nothing');
okv_test_eq(540000, Basket::subtotal([$twoKgTomatoes]), '2kg of tomatoes at ₦2,700 is ₦5,400');
okv_test_eq(680000, Basket::subtotal([$twoKgTomatoes, $oneKgOnion]), '₦5,400 of tomatoes plus ₦1,400 of onion is ₦6,800');
okv_test_eq(2370000, Basket::subtotal([$twoKgTomatoes, $oneKgOnion, $oneStewCombo]), 'a combo line adds its own price, ₦23,700 in total');

// Fractional quantities are exact, because the line total is integer subunits.
okv_test_eq(135000, Basket::subtotal([
    ['item_type' => 'product', 'quantity' => 0.5, 'unit_price_subunit' => 270000],
]), '0.5kg of tomatoes is ₦1,350, to the kobo');
okv_test_eq(200000, Basket::subtotal([
    ['item_type' => 'product', 'quantity' => 0.25, 'unit_price_subunit' => 800000],
]), '0.25kg of ginger at ₦8,000 is ₦2,000');

// The acceptance scenario: 1kg at ₦2,700 and 1kg at ₦3,000, in one basket.
$repriced = [
    ['id' => 11, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000],
    ['id' => 12, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 300000],
];
okv_test_eq(570000, Basket::subtotal($repriced), '1kg at ₦2,700 plus 1kg at ₦3,000 is ₦5,700, both prices kept');

// The badge counts lines, never the sum of quantities. 2kg of tomatoes is one
// line, not two items.
okv_test_eq(0, Basket::lineCount([]), 'an empty basket has no lines');
okv_test_eq(1, Basket::lineCount([$twoKgTomatoes]), '2kg on one line counts as one line');
okv_test_eq(2, Basket::lineCount($repriced), 'a repriced product is two lines, because it holds two prices');

// --- How a repriced product is presented -----------------------------------

$decorated = Basket::decorateLines($repriced);
okv_test_eq(270000, $decorated[0]['line_total_subunit'], 'the older line totals at the price the customer was given');
okv_test_eq(300000, $decorated[1]['line_total_subunit'], 'the newer line totals at this week\'s price');
okv_test_ok($decorated[0]['price_changed'] === false, 'the first line is the original, so it carries no badge');
okv_test_ok($decorated[1]['price_changed'] === true, 'the newer line is the one flagged as repriced');
okv_test_eq(270000, $decorated[1]['previous_price_subunit'], 'the newer line remembers the price it moved from');
okv_test_ok(Basket::hasRepricedLines($decorated), 'a split product puts the repriced notice on the basket');

$single = Basket::decorateLines([$twoKgTomatoes]);
okv_test_ok($single[0]['price_changed'] === false, 'one line at one price is never flagged');
okv_test_ok(!Basket::hasRepricedLines($single), 'a basket with no split shows no repriced notice');
okv_test_ok(!Basket::hasRepricedLines(Basket::decorateLines([])), 'an empty basket shows no repriced notice');

// Two different products at different prices are not a reprice.
okv_test_ok(!Basket::hasRepricedLines(Basket::decorateLines([$twoKgTomatoes, $oneKgOnion])), 'two different products at two prices are not a reprice');

// A combo repriced between two adds splits the same way a product does.
$comboSplit = Basket::decorateLines([
    ['id' => 21, 'item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 1.0, 'unit_price_subunit' => 1690000],
    ['id' => 22, 'item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 1.0, 'unit_price_subunit' => 1800000],
]);
okv_test_ok($comboSplit[1]['price_changed'] === true, 'a combo that repriced between two adds flags the newer line');
okv_test_eq(3490000, Basket::subtotal($comboSplit), 'the two combo snapshots total ₦34,900, not two at either price');

// --- Repeat add: the same price folds, a new price opens a new line ---------

// Nothing in the basket yet.
$plan = Basket::planAdd([], 'product', 4, 1.0, 270000);
okv_test_ok($plan['line_id'] === null, 'a first add has no line to fold into');
okv_test_eq(1.0, $plan['quantity'], 'a first add carries the quantity asked for');
okv_test_ok($plan['repriced'] === false, 'a first add is never a reprice');

// Same product, same price. One line, quantity grows. This is the ordinary case.
$existing = [['id' => 11, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000]];
$plan = Basket::planAdd($existing, 'product', 4, 1.0, 270000);
okv_test_eq(11, $plan['line_id'], 'a repeat add at the same price folds into the line already there');
okv_test_eq(2.0, $plan['quantity'], 'the folded line holds 2kg');
okv_test_ok($plan['repriced'] === false, 'a repeat add at the same price is not a reprice');
okv_test_ok($plan['previous_price_subunit'] === null, 'no previous price is reported when nothing changed');

// Same product, the price moved from ₦2,700 to ₦3,000. The old line is left
// exactly as it was and a second line opens at the new price.
$plan = Basket::planAdd($existing, 'product', 4, 1.0, 300000);
okv_test_ok($plan['line_id'] === null, 'a repeat add after a price change opens a new line rather than rewriting the old one');
okv_test_eq(1.0, $plan['quantity'], 'the new line holds only the kilogrammes just added');
okv_test_ok($plan['repriced'] === true, 'the caller is told the item repriced');
okv_test_eq(270000, $plan['previous_price_subunit'], 'the notice can name the old price, ₦2,700');
okv_test_eq(300000, $plan['unit_price_subunit'], 'the new line carries this week\'s ₦3,000');

// A third add, back at the price of the newest line, folds into that line and
// leaves the ₦2,700 line untouched.
$split = [
    ['id' => 11, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000],
    ['id' => 12, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 300000],
];
$plan = Basket::planAdd($split, 'product', 4, 1.0, 300000);
okv_test_eq(12, $plan['line_id'], 'an add at the current price folds into the line already holding it');
okv_test_eq(2.0, $plan['quantity'], 'that line grows to 2kg');
okv_test_ok($plan['repriced'] === false, 'folding into an existing snapshot is not a fresh reprice');

// A different product is never confused with this one.
$plan = Basket::planAdd($existing, 'product', 13, 1.0, 140000);
okv_test_ok($plan['line_id'] === null, 'onion does not fold into the tomato line');

// A combo and a product with the same id are different things.
$plan = Basket::planAdd([['id' => 30, 'item_type' => 'combo', 'combo_package_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 1690000]], 'product', 4, 1.0, 270000);
okv_test_ok($plan['line_id'] === null, 'a combo with id 4 is not the product with id 4');

// The ceiling applies to a fold too.
$plan = Basket::planAdd([['id' => 11, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 100.0, 'unit_price_subunit' => 270000]], 'product', 4, 1.0, 270000);
okv_test_eq(100.0, $plan['quantity'], 'a fold stops at the 100 unit ceiling');
okv_test_ok($plan['capped'] === true, 'the caller is told the line was capped so it can say so');

$plan = Basket::planAdd([['id' => 30, 'item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 20.0, 'unit_price_subunit' => 1690000]], 'combo', 1, 1.0, 1690000);
okv_test_eq(20.0, $plan['quantity'], 'a combo fold stops at the 20 basket ceiling');
okv_test_ok($plan['capped'] === true, 'the combo cap is reported too');

// --- The merge plan: guest basket into the account basket -------------------

// Nothing in the account. Everything moves across, nothing folds.
$guest = [
    ['id' => 1, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 2.0, 'unit_price_subunit' => 270000],
    ['id' => 2, 'item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 1.0, 'unit_price_subunit' => 1690000],
];
$plan = Basket::planMerge($guest, []);
okv_test_eq([1, 2], $plan['move'], 'with an empty account basket every guest line simply moves');
okv_test_eq([], $plan['fold'], 'nothing folds when there is nothing to fold into');

// No collisions: different products on each side.
$account = [['id' => 9, 'item_type' => 'product', 'product_id' => 13, 'quantity' => 1.0, 'unit_price_subunit' => 140000]];
$plan = Basket::planMerge($guest, $account);
okv_test_eq([1, 2], $plan['move'], 'lines the account does not hold move across untouched');
okv_test_eq([], $plan['fold'], 'different products never fold together');

// A collision at the same price: the quantities add up on one line.
$account = [['id' => 9, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000]];
$plan = Basket::planMerge([$guest[0]], $account);
okv_test_eq([], $plan['move'], 'a line at a price the account already holds does not move, it folds');
okv_test_eq(1, count($plan['fold']), 'the collision produces one fold instruction');
okv_test_eq(9, $plan['fold'][0]['account_line_id'], 'the account line is the one that survives');
okv_test_eq(1, $plan['fold'][0]['guest_line_id'], 'the guest line is named so it can be removed');
okv_test_eq(3.0, $plan['fold'][0]['quantity'], '2kg from the guest basket plus 1kg on the account is 3kg');

// A collision at a different price: both prices are kept, as separate lines.
$account = [['id' => 9, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 300000]];
$plan = Basket::planMerge([$guest[0]], $account);
okv_test_eq([1], $plan['move'], 'a guest line at ₦2,700 moves across beside the account line at ₦3,000');
okv_test_eq([], $plan['fold'], 'two prices are never blended into one');

// The same collision on a combo.
$account = [['id' => 9, 'item_type' => 'combo', 'combo_package_id' => 1, 'quantity' => 1.0, 'unit_price_subunit' => 1690000]];
$plan = Basket::planMerge([$guest[1]], $account);
okv_test_eq(1, count($plan['fold']), 'the same combo at the same price folds');
okv_test_eq(2.0, $plan['fold'][0]['quantity'], 'two Stew Combos end up on one line');

// A fold that would break the ceiling stops at the ceiling.
$account = [['id' => 9, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 99.0, 'unit_price_subunit' => 270000]];
$plan = Basket::planMerge([$guest[0]], $account);
okv_test_eq(100.0, $plan['fold'][0]['quantity'], 'a merge fold stops at the 100 unit ceiling');

// Two guest lines colliding with one account line fold once each, in order,
// so nothing is dropped and nothing is counted twice.
$guestTwice = [
    ['id' => 1, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000],
    ['id' => 2, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000],
];
$account = [['id' => 9, 'item_type' => 'product', 'product_id' => 4, 'quantity' => 1.0, 'unit_price_subunit' => 270000]];
$plan = Basket::planMerge($guestTwice, $account);
okv_test_eq(2, count($plan['fold']), 'both colliding guest lines fold into the same account line');
okv_test_eq(3.0, $plan['fold'][1]['quantity'], 'the running quantity carries between folds, ending at 3kg');
okv_test_eq([], $plan['move'], 'nothing is left behind to move');

// An empty guest basket is a no-op, which is what most sign-ins are.
$plan = Basket::planMerge([], $account);
okv_test_eq([], $plan['move'], 'signing in with an empty guest basket moves nothing');
okv_test_eq([], $plan['fold'], 'signing in with an empty guest basket folds nothing');

// --- Customer-facing copy for the quantity refusals ------------------------

okv_test_eq(
    'The smallest we can pack is 1kg. Please ask for at least that much.',
    Basket::quantityMessage('below_minimum', ['minimum' => 1, 'unit' => 'kg']),
    'the below-minimum message names the minimum and the unit'
);
okv_test_eq(
    'The smallest we can pack is 0.5kg. Please ask for at least that much.',
    Basket::quantityMessage('below_minimum', ['minimum' => 0.5, 'unit' => 'kg']),
    'a fractional minimum reads without trailing zeroes'
);
okv_test_eq(
    'That is more than we can pack into one order. For an order that size, send us a Kitchen Run.',
    Basket::quantityMessage('over_ceiling', []),
    'the ceiling message points at Kitchen Runs rather than just refusing'
);
okv_test_eq(
    'Enter how much you need, for example 2.',
    Basket::quantityMessage('invalid_quantity', []),
    'an unreadable quantity gets a plain instruction'
);

// The repriced notice names both prices and the unit, in numerals.
okv_test_eq(
    'Fresh Tomatoes moved from ₦2,700 to ₦3,000 per kg. What you added earlier keeps the old price.',
    Basket::repricedMessage('Fresh Tomatoes', 270000, 300000, 'kg'),
    'the repriced notice names the product, both prices and the unit'
);
okv_test_eq(
    'The Stew Combo moved from ₦16,900 to ₦18,000. What you added earlier keeps the old price.',
    Basket::repricedMessage('The Stew Combo', 1690000, 1800000, ''),
    'a combo has no unit, so the notice does not invent one'
);
