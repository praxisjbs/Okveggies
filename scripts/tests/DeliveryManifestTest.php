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
