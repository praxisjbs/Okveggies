<?php
/**
 * scripts/tests/CombosTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Combos maths. The component total, the loss-making check, the
 * saving shown to a customer, quantity cleaning and the availability-window
 * gate. Money logic, so it is unit-tested before it is wired to anything.
 *
 * The database side (create writes history, changePrice opens and closes
 * history, publish gate, deletion rules) lives in
 * scripts/tests/combos_db_test.php.
 *
 * The seeded Stew Combo drives the component-total scenarios so a change to
 * the seed cannot silently drift the maths.
 * -----------------------------------------------------------------------------
 */

// --- sumComponents (the maths a builder shows live) -------------------------

// The Stew Combo, at seed prices. Component total is ₦17,550, or 1,755,000
// subunits. Rows are shaped like Combos::components() returns.
$stew = [
    ['product_id' =>  4, 'quantity' => 2.000, 'current_price_subunit' => 270000], // Fresh Tomatoes
    ['product_id' => 21, 'quantity' => 1.000, 'current_price_subunit' => 450000], // Tatashe
    ['product_id' => 17, 'quantity' => 0.500, 'current_price_subunit' => 400000], // Rodo
    ['product_id' => 18, 'quantity' => 0.500, 'current_price_subunit' => 450000], // Shombo
    ['product_id' => 13, 'quantity' => 1.000, 'current_price_subunit' => 140000], // Onion
    ['product_id' =>  6, 'quantity' => 0.250, 'current_price_subunit' => 800000], // Ginger
];
okv_test_eq(1755000, Combos::sumComponents($stew), 'The Stew Combo sums to ₦17,550 at seed prices');

okv_test_eq(0, Combos::sumComponents([]), 'no components sums to nothing');

// A single line reproduces Money::lineTotal exactly, so the total the builder
// shows agrees with the pricing screen's line arithmetic.
okv_test_eq(540000, Combos::sumComponents([
    ['quantity' => 2.000, 'current_price_subunit' => 270000],
]), 'a 2 kg line at ₦2,700 is ₦5,400');

okv_test_eq(200000, Combos::sumComponents([
    ['quantity' => 0.500, 'current_price_subunit' => 400000],
]), 'a fractional 0.5 kg line at ₦4,000 is ₦2,000');

okv_test_eq(200000, Combos::sumComponents([
    ['quantity' => 0.250, 'current_price_subunit' => 800000],
]), 'the 0.25 kg ginger line comes out to ₦2,000');

// A component whose product has no price yet contributes zero. The Manager can
// still open the builder while one product is a draft, without a warning.
okv_test_eq(540000, Combos::sumComponents([
    ['quantity' => 2.000, 'current_price_subunit' => 270000],
    ['quantity' => 1.000, 'current_price_subunit' => 0],
    ['quantity' => 1.000, 'current_price_subunit' => -100],
]), 'components with no price or a bad price are skipped, not counted');

okv_test_eq(0, Combos::sumComponents([
    ['quantity' => 0,      'current_price_subunit' => 270000],
    ['quantity' => -1.0,   'current_price_subunit' => 270000],
]), 'components with a zero or negative quantity are skipped, not counted');

// A missing column defaults to zero rather than tripping a warning: the pure
// function is meant to work off rows built by tests, not just the query.
okv_test_eq(540000, Combos::sumComponents([
    ['quantity' => 2.000, 'current_price_subunit' => 270000],
    [],
]), 'a blank row contributes nothing rather than tripping a warning');

// --- isLossMaking (the red flag shown to the Manager only) ------------------

okv_test_ok(Combos::isLossMaking(1690000, 1755000), 'The Stew Combo at ₦16,900 with components at ₦17,550 is loss-making');
okv_test_ok(!Combos::isLossMaking(1690000, 1690000), 'a combo priced exactly at the component total is not loss-making');
okv_test_ok(!Combos::isLossMaking(1800000, 1755000), 'a combo priced above the component total is not loss-making');
okv_test_ok(!Combos::isLossMaking(0, 1755000), 'a draft with no sell price is not loss-making, there is nothing to compare with');
okv_test_ok(!Combos::isLossMaking(1690000, 0), 'a combo with no components (nothing to sum) is not loss-making');

// --- customerSaving (never negative) ---------------------------------------

okv_test_eq(65000, Combos::customerSaving(1690000, 1755000), 'The Stew Combo saves ₦650 against the components');
okv_test_eq(0, Combos::customerSaving(1800000, 1755000), 'a combo priced above its components shows no saving, not a negative one');
okv_test_eq(0, Combos::customerSaving(1690000, 1690000), 'a combo priced at its components exactly shows no saving');

// --- cleanComponentQuantity (unit rules, not customer minimums) ------------

$kg     = ['allows_decimal' => 1];
$bunch  = ['allows_decimal' => 0];

okv_test_eq(0.25,  Combos::cleanComponentQuantity('0.25', $kg),     '0.25 kg is kept because kg allows decimals');
okv_test_eq(2.0,   Combos::cleanComponentQuantity('2', $kg),        '2 kg is kept as 2');
okv_test_eq(1.5,   Combos::cleanComponentQuantity('1.5', $kg),      '1.5 kg is kept because kg allows decimals');
okv_test_eq(0.001, Combos::cleanComponentQuantity('0.0009', $kg),   'below three decimals rounds to the smallest kilogramme step');
okv_test_eq(2.0,   Combos::cleanComponentQuantity('1.5', $bunch),   '1.5 bunch rounds up to 2, because a bunch is not decimal');
okv_test_eq(3.0,   Combos::cleanComponentQuantity('2.1', $bunch),   '2.1 bunch rounds up to 3');
okv_test_eq(1.0,   Combos::cleanComponentQuantity('₦1', $kg),       'non-numeric characters are stripped, leaving 1');
okv_test_eq(0.0,   Combos::cleanComponentQuantity('', $kg),         'an empty input is zero, which the caller then refuses');
okv_test_eq(2.0,   Combos::cleanComponentQuantity('2', null),       'a missing unit keeps decimals rather than assuming a rule');

// --- isBuyableNow (availability window, not component availability) --------

$active = ['is_active' => 1, 'available_from' => null, 'available_until' => null];
$draft  = ['is_active' => 0, 'available_from' => null, 'available_until' => null];

okv_test_ok(Combos::isBuyableNow($active, '2026-08-30'), 'an active combo with no window is buyable on any day');
okv_test_ok(!Combos::isBuyableNow($draft,  '2026-08-30'), 'a draft is not buyable, even inside a window');

// A window with only a start date. Today at or after the start is buyable.
$fromToday = ['is_active' => 1, 'available_from' => '2026-08-30', 'available_until' => null];
okv_test_ok(!Combos::isBuyableNow($fromToday, '2026-08-29'), 'a window that starts tomorrow is not buyable today');
okv_test_ok(Combos::isBuyableNow($fromToday,  '2026-08-30'), 'the start date itself is buyable');
okv_test_ok(Combos::isBuyableNow($fromToday,  '2026-09-15'), 'any day after the start is buyable');

// A window with only an end date.
$untilToday = ['is_active' => 1, 'available_from' => null, 'available_until' => '2026-08-30'];
okv_test_ok(Combos::isBuyableNow($untilToday,  '2026-08-29'), 'a day before the end is buyable');
okv_test_ok(Combos::isBuyableNow($untilToday,  '2026-08-30'), 'the end date itself is buyable');
okv_test_ok(!Combos::isBuyableNow($untilToday, '2026-08-31'), 'a day after the end is not buyable');

// A full window from-to.
$window = ['is_active' => 1, 'available_from' => '2026-12-01', 'available_until' => '2026-12-24'];
okv_test_ok(!Combos::isBuyableNow($window, '2026-11-30'), 'a day before the window is not buyable');
okv_test_ok(Combos::isBuyableNow($window,  '2026-12-01'), 'the window opens on the from date');
okv_test_ok(Combos::isBuyableNow($window,  '2026-12-15'), 'a day inside the window is buyable');
okv_test_ok(Combos::isBuyableNow($window,  '2026-12-24'), 'the window closes on the until date, inclusive');
okv_test_ok(!Combos::isBuyableNow($window, '2026-12-25'), 'a day after the window is not buyable');

// An empty-string date is treated as no date, so a form that posted the value
// blank does not accidentally hide every combo.
$blank = ['is_active' => 1, 'available_from' => '', 'available_until' => ''];
okv_test_ok(Combos::isBuyableNow($blank, '2026-08-30'), 'blank date strings are read as no window');
