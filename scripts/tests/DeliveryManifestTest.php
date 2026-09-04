<?php
/** Pure packing-list shaping and delivery configuration validation tests. */

okv_test_ok(Delivery::validCutoff('00:00'), 'midnight is a valid cutoff');
okv_test_ok(Delivery::validCutoff('18:30'), 'a normal 24 hour cutoff is valid');
okv_test_ok(!Delivery::validCutoff('24:00'), '24:00 is not a valid cutoff');
okv_test_ok(!Delivery::validCutoff('6pm'), 'free-form cutoff text is refused');
okv_test_ok(Delivery::validDate('2028-02-29'), 'a real leap date is valid');
okv_test_ok(!Delivery::validDate('2027-02-29'), 'an impossible calendar date is refused');

$tz = new DateTimeZone('Africa/Lagos');
$atCutoff = new DateTimeImmutable('2026-09-07 16:00:00', $tz);
$target = '2026-09-08';
$rule = [(int) (new DateTimeImmutable($target, $tz))->format('N') => [
    'is_active' => 1, 'cutoff_time' => '16:00:00', 'minimum_lead_days' => 1,
]];
okv_test_ok(!Delivery::eligibleFromRules($target, $rule, null, $atCutoff)['eligible'], 'delivery closes at the exact configured cutoff');

$rows = [
    ['order_id' => 1, 'zone_id' => 2, 'zone_name' => 'Yaba'],
    ['order_id' => 2, 'zone_id' => 1, 'zone_name' => 'Ikeja'],
    ['order_id' => 3, 'zone_id' => 2, 'zone_name' => 'Yaba'],
    ['order_id' => 4, 'zone_id' => null, 'zone_name' => null],
];
$groups = DeliveryManifest::groupByZone($rows);
okv_test_eq(['Ikeja', 'Yaba', 'Zone not assigned'], array_column($groups, 'name'), 'manifest groups are named and consistently sorted');
okv_test_eq(2, count($groups[1]['orders']), 'orders in the same zone stay together');

$lines = DeliveryManifest::packingLines([
    ['item_type' => 'product', 'item_name' => 'Tomatoes', 'quantity' => '2.000', 'unit_name' => 'Kilogramme', 'components' => []],
    ['item_type' => 'combo', 'item_name' => 'Soup basket', 'quantity' => '2.000', 'unit_name' => 'basket', 'components' => [
        ['product_name' => 'Ugu', 'quantity' => '3.000', 'unit_name' => 'Bunch'],
        ['product_name' => 'Pepper', 'quantity' => '0.500', 'unit_name' => 'Kilogramme'],
    ]],
]);
okv_test_eq('2', $lines[0]['quantity'], 'normal product quantity is retained');
okv_test_eq('6', $lines[1]['quantity'], 'combo component quantity is multiplied by basket quantity');
okv_test_eq('1', $lines[2]['quantity'], 'decimal combo component totals are preserved');

$page = file_get_contents(dirname(__DIR__, 2) . '/admin/delivery-manifest.php');
okv_test_ok(str_contains($page, "Rbac::requirePermission('delivery.manifest.view')"), 'manifest page is permission protected');
okv_test_ok(str_contains($page, '@media print'), 'manifest has a dedicated print layout');
okv_test_ok(str_contains($page, 'data-print-control'), 'interactive manifest controls are marked for print removal');

// Per-zone packing totals. These are the numbers a packer checks a crate
// against, so they are tested as arithmetic, not as a rendering detail.
$zoneOrders = [
    ['packing_lines' => DeliveryManifest::packingLines([
        ['item_type' => 'product', 'item_name' => 'Tomatoes', 'quantity' => '2.500', 'unit_name' => 'Kilogramme', 'components' => []],
        ['item_type' => 'combo', 'item_name' => 'Soup basket', 'quantity' => '2.000', 'unit_name' => 'basket', 'components' => [
            ['product_name' => 'Ugu', 'quantity' => '3.000', 'unit_name' => 'Bunch'],
        ]],
    ])],
    ['packing_lines' => DeliveryManifest::packingLines([
        ['item_type' => 'product', 'item_name' => 'Tomatoes', 'quantity' => '1.500', 'unit_name' => 'Kilogramme', 'components' => []],
        ['item_type' => 'product', 'item_name' => 'Ugu', 'quantity' => '4.000', 'unit_name' => 'Bunch', 'components' => []],
    ])],
];
$totals = DeliveryManifest::totals($zoneOrders);
okv_test_eq(['Tomatoes', 'Ugu'], array_column($totals, 'name'), 'zone totals are one line per product, sorted by name');
okv_test_eq('4', $totals[0]['quantity'], 'the same product across two orders is added into one figure');
okv_test_eq('10', $totals[1]['quantity'], 'a combo component and a loose product of the same name add together');
okv_test_eq('Kilogramme', $totals[0]['unit'], 'the total carries its unit, because a number without one is useless to a packer');

// The same product in two different units is two different things to buy.
$mixedUnits = DeliveryManifest::totals([
    ['packing_lines' => DeliveryManifest::packingLines([
        ['item_type' => 'product', 'item_name' => 'Pepper', 'quantity' => '2.000', 'unit_name' => 'Kilogramme', 'components' => []],
        ['item_type' => 'product', 'item_name' => 'Pepper', 'quantity' => '3.000', 'unit_name' => 'Bunch', 'components' => []],
    ])],
]);
okv_test_eq(2, count($mixedUnits), 'kilogrammes and bunches of the same product are never added together');

// Naming is matched case insensitively, so one product does not split in two.
$caseTotals = DeliveryManifest::totals([
    ['packing_lines' => DeliveryManifest::packingLines([
        ['item_type' => 'product', 'item_name' => 'Ugu', 'quantity' => '1.000', 'unit_name' => 'Bunch', 'components' => []],
        ['item_type' => 'product', 'item_name' => 'ugu', 'quantity' => '2.000', 'unit_name' => 'bunch', 'components' => []],
    ])],
]);
okv_test_eq(1, count($caseTotals), 'a difference of case does not split one product into two lines');
okv_test_eq('3', $caseTotals[0]['quantity'], 'both spellings are added into the one figure');

// The regression this rounding fix exists for. Three orders of a third of a
// kilogramme are one kilogramme. Rounding each line to three places first and
// then adding gives 0.999, which is a total no one can check against a scale.
$thirds = DeliveryManifest::totals([
    ['packing_lines' => [
        ['name' => 'Ginger', 'quantity' => '0.333', 'quantity_exact' => 1 / 3, 'unit' => 'Kilogramme', 'from_combo' => null],
        ['name' => 'Ginger', 'quantity' => '0.333', 'quantity_exact' => 1 / 3, 'unit' => 'Kilogramme', 'from_combo' => null],
        ['name' => 'Ginger', 'quantity' => '0.333', 'quantity_exact' => 1 / 3, 'unit' => 'Kilogramme', 'from_combo' => null],
    ]],
]);
okv_test_eq('1', $thirds[0]['quantity'], 'totals add the exact quantities and round once at the end');

okv_test_eq([], DeliveryManifest::totals([]), 'a zone with no orders totals nothing rather than failing');
