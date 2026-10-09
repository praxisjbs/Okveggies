<?php
/**
 * scripts/tests/ExpensesTest.php
 * The Expense module's money and category logic. No database: these are the
 * pure helpers the dashboard's profit figures are built on, so they must never
 * be wrong. DB-backed create/list/void are covered by expenses_db_test.php.
 */

// ---- The enforced taxonomy is internally sound ------------------------------
$cats = Expenses::CATEGORIES;
okv_test_eq(12, count($cats), 'twelve enforced categories');

$cogCount = 0;
$ids = [];
$allowedKinds  = ['cost_of_goods', 'operating'];
$allowedColour = ['forest', 'foliage', 'gold', 'tomato', 'clay', 'ink'];
$kindsOk = true; $colourOk = true;
foreach ($cats as $slug => $row) {
    $ids[] = $row['id'];
    if ($row['kind'] === 'cost_of_goods') { $cogCount++; }
    if (!in_array($row['kind'], $allowedKinds, true)) { $kindsOk = false; }
    if (!in_array($row['colour'], $allowedColour, true)) { $colourOk = false; }
}
okv_test_eq(1, $cogCount, 'exactly one cost-of-goods category (stock purchase)');
okv_test_ok($kindsOk, 'every category kind is cost_of_goods or operating');
okv_test_ok($colourOk, 'every colour is a brand token name');
okv_test_eq(12, count(array_unique($ids)), 'category ids are unique');
okv_test_eq(range(1, 12), (function () use ($ids) { sort($ids); return $ids; })(), 'category ids are 1 through 12');

// ---- Category lookups -------------------------------------------------------
okv_test_ok(Expenses::isCategory('stock-purchase'), 'stock-purchase is a real category');
okv_test_ok(!Expenses::isCategory('not-a-category'), 'a made-up slug is rejected');
okv_test_eq(1, Expenses::categoryId('stock-purchase'), 'stock-purchase is id 1');
okv_test_eq(0, Expenses::categoryId('nope'), 'an unknown slug has id 0');
okv_test_eq('cost_of_goods', Expenses::kindOf('stock-purchase'), 'stock purchase is cost of goods');
okv_test_eq('operating', Expenses::kindOf('fuel'), 'fuel is operating');
okv_test_eq(null, Expenses::kindOf('nope'), 'unknown slug has no kind');
okv_test_ok(Expenses::isCostOfGoods('stock-purchase'), 'stock purchase counts as cost of goods');
okv_test_ok(!Expenses::isCostOfGoods('fuel'), 'fuel does not count as cost of goods');

// ---- Supplier normalisation (the spend-by-supplier grouping key) ------------
okv_test_eq('mile 12', Expenses::normaliseSupplier('Mile 12 '),  'supplier key lowercases and trims');
okv_test_eq('mile 12', Expenses::normaliseSupplier('mile   12'), 'supplier key collapses inner spaces');
okv_test_eq('mile 12', Expenses::normaliseSupplier('MILE 12'),   'supplier key is case-insensitive');
okv_test_eq('lekki',   Expenses::normaliseSupplier('Lekki'),     'Lekki and lekki group together');
okv_test_eq('',        Expenses::normaliseSupplier('   '),       'a blank supplier yields an empty key');
okv_test_eq('',        Expenses::normaliseSupplier(null),        'a null supplier yields an empty key');

// ---- Date validation --------------------------------------------------------
okv_test_ok(Expenses::isValidDate('2026-09-01'),  'a real date is valid');
okv_test_ok(!Expenses::isValidDate('2026-13-01'), 'month 13 is rejected');
okv_test_ok(!Expenses::isValidDate('2026-02-30'), '30 February is rejected');
okv_test_ok(!Expenses::isValidDate('01/09/2026'), 'the wrong format is rejected');
okv_test_ok(!Expenses::isValidDate(''),           'an empty date is rejected');

// ---- The aggregation the dashboard reads ------------------------------------
$rows = [
    ['amount_subunit' => 6750000, 'category_slug' => 'stock-purchase',      'supplier_key' => 'mile 12'],     // 67,500
    ['amount_subunit' => 4500000, 'category_slug' => 'stock-purchase',      'supplier_key' => 'agrobiotics'], // 45,000
    ['amount_subunit' => 1000000, 'category_slug' => 'fuel',                'supplier_key' => 'mile 12'],     // 10,000
    ['amount_subunit' =>  300000, 'category_slug' => 'airtime-data',        'supplier_key' => ''],            //  3,000
    ['amount_subunit' =>  100000, 'category_slug' => 'not-a-category',      'supplier_key' => ''],            //  1,000 -> other
];
$s = Expenses::summarise($rows);
okv_test_eq(12650000, $s['total'],         'summarise totals every amount');
okv_test_eq(11250000, $s['cost_of_goods'], 'cost of goods is the two stock purchases');
okv_test_eq(1400000,  $s['operating'],     'operating is fuel plus airtime plus the unknown');
okv_test_eq(11250000, $s['by_category']['stock-purchase'], 'stock purchase category total');
okv_test_eq(100000,   $s['by_category']['other'],          'an unknown slug falls into other, no money lost');
okv_test_eq(7750000,  $s['by_supplier']['mile 12'],        'spend by supplier adds Mile 12 across categories');
okv_test_ok(!isset($s['by_supplier']['']), 'a blank supplier is not a supplier row');
okv_test_eq(0, Expenses::summarise([])['total'], 'summarise of nothing is zero');

// ---- Refusal messages are friendly, never a raw code ------------------------
okv_test_eq('Choose a category for this expense.', Expenses::message('invalid_category'), 'friendly category message');
okv_test_eq('Enter an amount greater than zero.',  Expenses::message('invalid_amount'),   'friendly amount message');
