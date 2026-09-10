<?php
/** Pure M11 dashboard period, money-allocation and aggregation rules. */

okv_test_eq(7, AdminDashboard::normalisePeriod('7'), 'the 7 day preset is accepted');
okv_test_eq(30, AdminDashboard::normalisePeriod('30'), 'the 30 day preset is accepted');
okv_test_eq(90, AdminDashboard::normalisePeriod(90), 'the 90 day preset is accepted');
okv_test_eq(30, AdminDashboard::normalisePeriod('14'), 'an unknown preset becomes 30 days');
okv_test_eq(30, AdminDashboard::normalisePeriod(['30']), 'an array period becomes 30 days');

$now = new DateTimeImmutable('2026-09-10 22:30:00', new DateTimeZone('UTC'));
$bounds = AdminDashboard::periodBounds(7, $now);
okv_test_eq('2026-09-04 00:00:00', $bounds['start'], 'the 7 day period starts 6 Lagos calendar days before today');
okv_test_eq('2026-09-11 00:00:00', $bounds['end'], 'the period ends at the next Lagos midnight');
okv_test_eq('2026-09-04', $bounds['start_date'], 'the period exposes its first date');
okv_test_eq('2026-09-10', $bounds['end_date'], 'the period exposes its inclusive last date');

$series = AdminDashboard::denseSalesSeries(
    $bounds,
    [
        ['day' => '2026-09-04', 'amount_subunit' => 1200],
        ['day' => '2026-09-04', 'amount_subunit' => 300],
        ['day' => '2026-09-10', 'amount_subunit' => 500],
    ],
    [
        ['day' => '2026-09-04', 'amount_subunit' => 2000],
        ['day' => '2026-09-08', 'amount_subunit' => 250],
    ]
);
okv_test_eq(7, count($series), 'a 7 day series contains all 7 calendar days');
okv_test_eq(-500, $series[0]['amount_subunit'], 'a refund-heavy day remains negative');
okv_test_eq(0, $series[1]['amount_subunit'], 'a day without movement is filled with zero');
okv_test_eq(500, $series[6]['amount_subunit'], 'the final day retains its receipt');

$due = AdminDashboard::summariseDuePayments([
    ['expected_amount_subunit' => 10000, 'paid_amount_subunit' => 2500, 'due_at' => '2026-09-09 12:00:00'],
    ['expected_amount_subunit' => 8000, 'paid_amount_subunit' => 0, 'due_at' => '2026-09-10 18:00:00'],
    ['expected_amount_subunit' => 3000, 'paid_amount_subunit' => 3000, 'due_at' => '2026-09-10 09:00:00'],
], '2026-09-10 00:00:00');
okv_test_eq(2, $due['payments_due_count'], 'only obligations with money left are counted');
okv_test_eq(15500, $due['payments_due_subunit'], 'due money sums exact integer balances');
okv_test_eq(1, $due['payments_overdue_count'], 'a due date before today is overdue');
okv_test_eq(7500, $due['payments_overdue_subunit'], 'the overdue amount is the unpaid part only');
okv_test_eq(1, $due['payments_due_today_count'], 'a timestamp inside today is due today');
okv_test_eq(8000, $due['payments_due_today_subunit'], 'the due-today amount stays separate');

$credit = AdminDashboard::sumCreditOutstanding(
    [
        ['id' => 1, 'credit_status' => 'approved', 'credit_limit_subunit' => 50000],
        ['id' => 2, 'credit_status' => 'withdrawn', 'credit_limit_subunit' => 0],
    ],
    [
        ['business_customer_id' => 1, 'amount_subunit' => 12000, 'due_date' => '2026-09-09'],
        ['business_customer_id' => 1, 'amount_subunit' => -5000, 'due_date' => null],
        ['business_customer_id' => 2, 'amount_subunit' => -9000, 'due_date' => null],
    ],
    '2026-09-10'
);
okv_test_eq(7000, $credit, 'each business is floored at zero before global credit is summed');

$allocation = AdminDashboard::allocateRefund([
    ['id' => 2, 'line_total_subunit' => 2],
    ['id' => 1, 'line_total_subunit' => 1],
], 2);
okv_test_eq(2, $allocation['applied_subunit'], 'the exact refund is allocated');
okv_test_eq(0, $allocation['unallocated_subunit'], 'a refund within the line total leaves no unallocated money');
okv_test_eq(1, $allocation['lines'][0]['refund_subunit'], 'the floor remainder goes to the lowest line id');
okv_test_eq(1, $allocation['lines'][1]['refund_subunit'], 'the proportional base remains on the larger line');

$overAllocation = AdminDashboard::allocateRefund([
    ['id' => 1, 'line_total_subunit' => 500],
], 700);
okv_test_eq(500, $overAllocation['applied_subunit'], 'a refund allocation never makes a line negative');
okv_test_eq(200, $overAllocation['unallocated_subunit'], 'refund money beyond line value is reported');
okv_test_eq(0, $overAllocation['lines'][0]['net_subunit'], 'an over-refunded line stops at zero');

$shares = AdminDashboard::categoryShares([
    'vegetables' => 1,
    'fruits' => 1,
    'combos' => 1,
]);
okv_test_eq(10000, array_sum(array_column($shares, 'share_basis_points')), 'category shares total exactly 10000 basis points');
$sharesBySlug = array_column($shares, null, 'category_slug');
okv_test_eq(3334, $sharesBySlug['combos']['share_basis_points'], 'an equal rounding remainder is assigned by stable slug order');
okv_test_eq('foliage', $sharesBySlug['vegetables']['colour_token'], 'the category row carries the canonical token');
okv_test_eq([], AdminDashboard::categoryShares(['vegetables' => 0]), 'zero category value returns an empty series');

$lines = [
    [
        'id' => 1, 'order_id' => 10, 'item_type' => 'product', 'product_id' => 7,
        'combo_package_id' => null, 'item_name' => 'Old Tomato Name', 'unit_name' => 'kg',
        'quantity' => '1.250', 'line_total_subunit' => 6000, 'created_at' => '2026-09-08 09:00:00',
        'category_slug' => 'vegetables', 'is_kitchen_run' => 0,
    ],
    [
        'id' => 2, 'order_id' => 11, 'item_type' => 'product', 'product_id' => 7,
        'combo_package_id' => null, 'item_name' => 'Fresh Tomatoes', 'unit_name' => 'kg',
        'quantity' => '0.750', 'line_total_subunit' => 4000, 'created_at' => '2026-09-09 09:00:00',
        'category_slug' => 'vegetables', 'is_kitchen_run' => 0,
    ],
    [
        'id' => 3, 'order_id' => 11, 'item_type' => 'combo', 'product_id' => null,
        'combo_package_id' => 4, 'item_name' => 'The Stew Combo', 'unit_name' => 'basket',
        'quantity' => '1.000', 'line_total_subunit' => 3000, 'created_at' => '2026-09-09 09:01:00',
        'category_slug' => null, 'is_kitchen_run' => 0,
    ],
    [
        'id' => 4, 'order_id' => 12, 'item_type' => 'product', 'product_id' => 7,
        'combo_package_id' => null, 'item_name' => 'Fresh Tomatoes', 'unit_name' => 'kg',
        'quantity' => '2.000', 'line_total_subunit' => 5000, 'created_at' => '2026-09-10 08:00:00',
        'category_slug' => 'vegetables', 'is_kitchen_run' => 1,
    ],
    [
        'id' => 5, 'order_id' => 13, 'item_type' => 'product', 'product_id' => null,
        'combo_package_id' => null, 'item_name' => 'Market item', 'unit_name' => 'bag',
        'quantity' => '1.000', 'line_total_subunit' => 2000, 'created_at' => '2026-09-10 10:00:00',
        'category_slug' => null, 'is_kitchen_run' => 0,
    ],
];
$analytics = AdminDashboard::aggregateOrderLines($lines, [10 => 3]);
okv_test_eq(4, count($analytics['top_products']), 'different sellable kinds remain separate top lines while matching product units group');
$topByKey = array_column($analytics['top_products'], null, 'key');
okv_test_eq('Fresh Tomatoes', $topByKey['product:7:kg']['label'], 'a stable product identity keeps its newest snapshot label');
okv_test_eq('2.000', $topByKey['product:7:kg']['quantity'], 'same-unit product quantities add without floats');
okv_test_eq(9997, $topByKey['product:7:kg']['amount_subunit'], 'processed refunds reduce the attributed line value exactly');
okv_test_eq('kitchen_run', $topByKey['kitchen-run:fresh tomatoes:kg']['kind'], 'a converted Kitchen Run remains its own sellable kind');
okv_test_eq(2000, $analytics['uncategorised_subunit'], 'an unlinked manual line is reported as uncategorised');
okv_test_eq(10000, array_sum(array_column($analytics['order_share'], 'share_basis_points')), 'aggregated category shares total exactly 10000 basis points');
