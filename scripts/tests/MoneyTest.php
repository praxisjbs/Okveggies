<?php
/**
 * scripts/tests/MoneyTest.php
 * Money is the one place naira is formatted and parsed. It must never be wrong.
 */

// Formatting
okv_test_eq('₦8,000',    Money::format(800000),            'format whole naira, no kobo');
okv_test_eq('₦8,000.50', Money::format(800050),            'format shows kobo when present');
okv_test_eq('₦8,000.00', Money::format(800000, true),      'format can force kobo');
okv_test_eq('8,000',     Money::format(800000, false, false), 'format without symbol or kobo');
okv_test_eq('-₦500',     Money::format(-50000),            'format handles negatives');
okv_test_eq('₦1,755,000', Money::format(175500000),        'format large amounts with commas');

// Parsing
okv_test_eq(800000, Money::toSubunit('₦8,000'),  'parse a naira string with symbol and comma');
okv_test_eq(800050, Money::toSubunit('8000.50'), 'parse a decimal naira string');
okv_test_eq(800000, Money::toSubunit(8000),      'parse an int as whole naira');
okv_test_eq(8050,   Money::toSubunit(80.5),      'parse a float');
okv_test_eq(0,      Money::toSubunit(''),         'parse empty as zero');

// Conversions
okv_test_eq(8000.5, Money::toNaira(800050), 'subunit to naira float');

// Deposits and balances (the checkout maths)
okv_test_eq(507000,  Money::deposit(1690000, 30),  'deposit is 30 percent of the total');
okv_test_eq(0,       Money::deposit(1000, 0),      'zero percent deposit is zero');
okv_test_eq(1000,    Money::deposit(1000, 100),    'full deposit equals the total');
okv_test_eq(1183000, Money::balance(1690000, 507000), 'balance after a deposit');
okv_test_eq(0,       Money::balance(100, 200),     'balance never goes below zero');

// Sums and line totals
okv_test_eq(6,      Money::sum([1, 2, 3]),           'sum of subunits');
okv_test_eq(200000, Money::lineTotal(0.5, 400000),  'half a kilogramme line total');
okv_test_eq(540000, Money::lineTotal(2, 270000),    'two kilogramme line total');
okv_test_eq(200000, Money::lineTotal(0.25, 800000), 'quarter kilogramme of ginger');
