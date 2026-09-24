<?php
/**
 * scripts/tests/zone_picker_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The delivery-area picker against a real database.
 *
 *   1. The picker's zones are whatever delivery_zones says: a zone added now
 *      appears with its note and in its sort order, and one switched off
 *      disappears. Nothing about the list is written in code.
 *   2. The remembered area: the last order's zone and the last Kitchen Run's
 *      zone are read back, and preferredZoneId never prefills one that has
 *      been switched off since.
 *   3. A zone switched off after the page was drawn is still refused on the
 *      server, with the existing zone_unavailable code, by the phone order
 *      and by both Kitchen Run write paths (customer submit and staff edit).
 *      Checkout's refusal is in checkout_db_test.php.
 *
 *   php scripts/tests/zone_picker_db_test.php
 *
 * SCRATCH DATABASES ONLY. It writes throwaway zones, users, an order and a
 * Kitchen Run, and removes them again.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';
require_once dirname(__DIR__, 2) . '/includes/components/shop/delivery_picker.php';

$tests = 0;
$passed = 0;
function zp_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}
function zp_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    zp_ok($same, $label . ($same ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}
function zp_throws(callable $run, string $code, string $label): void
{
    try {
        $run();
        zp_ok(false, $label . ' (nothing was refused)');
    } catch (DomainException $e) {
        zp_eq($code, $e->getMessage(), $label);
    }
}

$suffix     = substr(bin2hex(random_bytes(5)), 0, 10);
$zoneIds    = [];
$users      = [];
$orderIds   = [];
$requestIds = [];

try {
    // --- Fixtures --------------------------------------------------------------
    $top = Delivery::nextZoneSortOrder();
    $makeZone = static function (string $name, ?string $note, int $sort) use (&$zoneIds): int {
        Database::run(
            'INSERT INTO delivery_zones (name, slug, area_note, is_active, sort_order) VALUES (:n, :s, :a, 1, :o)',
            [':n' => $name, ':s' => Delivery::uniqueZoneSlug($name), ':a' => $note, ':o' => $sort]
        );
        $id = (int) Database::getInstance()->getConnection()->lastInsertId();
        $zoneIds[] = $id;
        return $id;
    };
    // Inserted out of order on purpose: the picker must follow sort_order.
    $zoneB = $makeZone("ZP Second $suffix", null, $top + 2);
    $zoneA = $makeZone("ZP First $suffix", "Quay Road & (North) <gate> $suffix", $top + 1);
    $zoneC = $makeZone("ZP Third $suffix", 'Lane 3', $top + 3);

    foreach (['staff', 'household'] as $type) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'Zone\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':last'  => ucfirst($type),
                ':email' => "zp-$type-$suffix@example.test",
                ':phone' => '+23474' . random_int(10000000, 99999999),
                ':hash'  => password_hash('zone-db-777', PASSWORD_BCRYPT),
                ':type'  => $type,
            ]
        );
        $users[$type] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users['staff']]);

    $product = Database::one(
        'SELECT p.id FROM products p WHERE p.is_active = 1 AND p.current_price_subunit > 0 ORDER BY p.id LIMIT 1'
    );
    $unitId = (int) Database::one('SELECT id FROM units_of_measurement WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $date   = (string) (Delivery::nextEligibleDates('household', 1)[0]['date'] ?? '');
    zp_ok($product !== null && $date !== '', 'the seed gives us a product to sell and a day to deliver');

    $address = [
        'recipient_name' => 'Zone Tester', 'recipient_phone' => '08031234567',
        'address_line_1' => '3 Test Close', 'city' => 'Lagos', 'state' => 'Lagos',
    ];

    // --- 1. The list is the database's --------------------------------------------
    $active = Delivery::zonesActive();
    $mine = array_values(array_filter($active, static fn(array $z): bool => in_array((int) $z['id'], [$zoneA, $zoneB, $zoneC], true)));
    zp_eq([$zoneA, $zoneB, $zoneC], array_map(static fn(array $z): int => (int) $z['id'], $mine), 'new zones appear straight away, in sort order, not insert order');
    zp_eq("Quay Road & (North) <gate> $suffix", (string) $mine[0]['area_note'], 'the note comes back exactly as stored, for the search to match');
    zp_ok($mine[1]['area_note'] === null, 'a zone with no note has none');

    ob_start();
    okv_zone_picker($active);
    $html = (string) ob_get_clean();
    zp_ok(str_contains($html, 'value="' . $zoneA . '" data-note="Quay Road &amp; (North) &lt;gate&gt; ' . $suffix . '"'), 'the rendered picker carries the stored note, escaped');
    zp_eq(count($active), substr_count($html, '<option value="') - 1, 'the rendered picker lists every active zone and nothing else');

    Database::run('UPDATE delivery_zones SET is_active = 0 WHERE id = :id', [':id' => $zoneC]);
    $afterOff = array_map(static fn(array $z): int => (int) $z['id'], Delivery::zonesActive());
    zp_ok(!in_array($zoneC, $afterOff, true), 'a zone switched off leaves the picker');
    ob_start();
    okv_zone_picker(Delivery::zonesActive());
    zp_ok(!str_contains((string) ob_get_clean(), 'value="' . $zoneC . '"'), 'and is not rendered');

    // --- 2. The remembered area -----------------------------------------------------
    zp_eq(0, Delivery::lastOrderZoneId($users['household']), 'a customer with no orders has no remembered area');
    zp_eq(0, Delivery::lastRunZoneId($users['household']), 'or Kitchen Run area');

    $order = ManualOrder::create($address + [
        'user_id' => $users['household'], 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
        'delivery_zone_id' => $zoneA, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
    ], $users['staff']);
    $orderIds[] = (int) $order['id'];
    zp_eq($zoneA, Delivery::lastOrderZoneId($users['household']), 'the last order\'s area is remembered');

    $run = KitchenRunWorkflow::submit($users['household'], 'household', $address + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneB,
        'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
    ]);
    $requestIds[] = (int) $run['id'];
    zp_eq($zoneB, Delivery::lastRunZoneId($users['household']), 'the last Kitchen Run\'s area is remembered');

    zp_eq($zoneA, Delivery::preferredZoneId(Delivery::zonesActive(), [0, Delivery::lastOrderZoneId($users['household'])]), 'checkout falls back to the last order\'s area');
    zp_eq($zoneB, Delivery::preferredZoneId(Delivery::zonesActive(), [$zoneC, $zoneB]), 'a switched-off area is skipped for the next remembered one');
    zp_eq("ZP Third $suffix", Delivery::zoneNameById($zoneC), 'a switched-off area can still be named in the notice');
    zp_ok(Delivery::zoneNameById(0) === null, 'no id, no name');

    // --- 3. Switched off after the page loaded: refused on the server ----------------
    Database::run('UPDATE delivery_zones SET is_active = 0 WHERE id = :id', [':id' => $zoneA]);
    zp_eq(0, Delivery::preferredZoneId(Delivery::zonesActive(), [Delivery::lastOrderZoneId($users['household'])]), 'the last order\'s area is no longer prefilled once switched off');

    zp_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $users['household'], 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneA, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $users['staff']),
        'zone_unavailable',
        'a phone order for an area switched off after the page loaded is refused'
    );
    zp_ok(str_contains(ManualOrder::message('zone_unavailable'), 'area'), 'with the existing plain sentence');

    zp_throws(
        static fn() => KitchenRunWorkflow::submit($users['household'], 'household', $address + [
            'input_mode' => 'custom', 'pricing_mode' => 'by_us',
            'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneA,
            'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
        ]),
        'zone_unavailable',
        'a Kitchen Run sent for an area switched off after the page loaded is refused'
    );
    zp_eq('That delivery area is not available. Pick another one.', KitchenRuns::message('zone_unavailable'), 'with the existing clear message');

    zp_throws(
        static fn() => KitchenRunWorkflow::submit($users['household'], 'household', $address + [
            'input_mode' => 'custom', 'pricing_mode' => 'by_us',
            'preferred_delivery_date' => $date, 'delivery_zone_id' => "ZP First $suffix",
            'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
        ]),
        'delivery_required',
        'a zone name posted in place of the id is not an identity'
    );
} finally {
    foreach ($requestIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
    }
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($zoneIds as $id) {
        Database::run('DELETE FROM delivery_zones WHERE id = :id', [':id' => $id]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id OR entity_id = :entity', [':id' => $id, ':entity' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests zone picker database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
