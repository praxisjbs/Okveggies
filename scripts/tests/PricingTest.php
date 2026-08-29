<?php
/**
 * scripts/tests/PricingTest.php
 * OK Veggies. The repricing maths. Money logic, so it is unit tested before it
 * is wired to anything. The database side (history rows, transactions, import
 * round trip) lives in scripts/tests/pricing_db_test.php.
 */

// Rounding to whole naira. Every price in the catalogue is whole naira, so a
// bulk move lands on a shelf price, not on ₦2,970.63.
okv_test_eq(297000, Pricing::roundToNaira(297040), 'rounds down to the nearest naira');
okv_test_eq(297100, Pricing::roundToNaira(297063), 'rounds up to the nearest naira');
okv_test_eq(270000, Pricing::roundToNaira(270000), 'a whole naira price is left alone');
okv_test_eq(0, Pricing::roundToNaira(49), 'under half a naira rounds to nothing');

// Percentage moves. ₦2,700 is the seeded tomato price.
okv_test_eq(297000, Pricing::adjust(270000, Pricing::MODE_PERCENT, 10), 'ten per cent up on ₦2,700 is ₦2,970');
okv_test_eq(243000, Pricing::adjust(270000, Pricing::MODE_PERCENT, -10), 'ten per cent down on ₦2,700 is ₦2,430');
okv_test_eq(270000, Pricing::adjust(270000, Pricing::MODE_PERCENT, 0), 'no percentage is no change');
okv_test_eq(283500, Pricing::adjust(270000, Pricing::MODE_PERCENT, 5), 'five per cent up on ₦2,700 is ₦2,835');
okv_test_eq(280800, Pricing::adjust(270000, Pricing::MODE_PERCENT, 4), 'four per cent on ₦2,700 is exactly ₦2,808');
okv_test_eq(290300, Pricing::adjust(270000, Pricing::MODE_PERCENT, 7.5), 'a fractional result rounds to whole naira, ₦2,902.50 becomes ₦2,903');
okv_test_eq(800000, Pricing::adjust(400000, Pricing::MODE_PERCENT, 100), 'doubling works');

// Flat moves, in subunits, and they may be negative.
okv_test_eq(280000, Pricing::adjust(270000, Pricing::MODE_FLAT, 10000), 'a flat ₦100 up');
okv_test_eq(260000, Pricing::adjust(270000, Pricing::MODE_FLAT, -10000), 'a flat ₦100 down');
okv_test_eq(270000, Pricing::adjust(270000, Pricing::MODE_FLAT, 0), 'a flat nothing is no change');
okv_test_eq(-30000, Pricing::adjust(270000, Pricing::MODE_FLAT, -300000), 'a flat move may compute a negative, the caller blocks it');

$threw = false;
try {
    Pricing::adjust(270000, 'sideways', 10);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
okv_test_ok($threw, 'an unknown adjustment mode is refused');

// The range a price is allowed to sit in.
okv_test_ok(Pricing::isValidPrice(1), 'one kobo is the floor and is allowed');
okv_test_ok(Pricing::isValidPrice(270000), 'an ordinary price is allowed');
okv_test_ok(Pricing::isValidPrice(Pricing::MAX_PRICE_SUBUNIT), 'the ceiling itself is allowed');
okv_test_ok(!Pricing::isValidPrice(0), 'a product is never free');
okv_test_ok(!Pricing::isValidPrice(-100), 'a negative price is refused');
okv_test_ok(!Pricing::isValidPrice(Pricing::MAX_PRICE_SUBUNIT + 1), 'above the ceiling is refused, it is a typo');

// Parsing what a Manager types or a spreadsheet holds. Money::toSubunit does the
// work; these lock the shapes the pricing screen and the importer actually meet.
okv_test_eq(800000, Money::toSubunit('₦8,000'), 'a naira string with a symbol and commas');
okv_test_eq(800050, Money::toSubunit('8000.50'), 'a decimal price keeps its kobo');
okv_test_eq(270000, Money::toSubunit(2700), 'a plain integer is naira, not kobo');
okv_test_eq(270000, Money::toSubunit('2700 '), 'trailing space is ignored');
okv_test_eq(0, Money::toSubunit(''), 'an empty cell is nothing, and isValidPrice then refuses it');
