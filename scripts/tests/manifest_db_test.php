<?php
/** M6 delivery manifest integration against a migrated scratch database. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function mf_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function mf_eq($expected, $actual, string $label): void { mf_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10); $date = date('Y-m-d', strtotime('+8 days'));
$userId = 0; $zoneIds = []; $orderIds = [];
try {
    Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) VALUES (\'Manifest\', \'Buyer\', :email, :phone, :hash, \'household\', \'active\')', [':email' => "manifest-$suffix@example.test", ':phone' => '+23475' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]);
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    foreach (['Alpha ' . $suffix, 'Zulu ' . $suffix] as $i => $name) {
        Database::run('INSERT INTO delivery_zones (name, slug, sort_order, is_active) VALUES (:name, :slug, :sort, 1)', [':name' => $name, ':slug' => strtolower(str_replace(' ', '-', $name)), ':sort' => 900 + $i]);
        $zoneIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    foreach ([['confirmed', $zoneIds[1]], ['delivered', $zoneIds[0]], ['cancelled', $zoneIds[0]]] as $i => [$status, $zone]) {
        Database::run('INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date, delivery_zone_id) VALUES (:number, :user, \'household\', :status, \'pay_on_delivery\', \'unpaid\', 100000, 100000, 100000, :date, :zone)', [':number' => "ZZ-MF-$suffix-$i", ':user' => $userId, ':status' => $status, ':date' => $date, ':zone' => $zone]);
        $orderId = (int) Database::getInstance()->getConnection()->lastInsertId(); $orderIds[] = $orderId;
        Database::run('INSERT INTO order_addresses (order_id, recipient_name, recipient_phone, address_line_1, city, state) VALUES (:order, :name, :phone, :line, \'Lagos\', \'Lagos\')', [':order' => $orderId, ':name' => "Buyer $i", ':phone' => '+23480000000' . $i, ':line' => ($i + 1) . ' Test Road']);
        Database::run('INSERT INTO delivery_schedules (order_id, delivery_date, status) VALUES (:order, :date, :status)', [':order' => $orderId, ':date' => $date, ':status' => $status === 'delivered' ? 'delivered' : 'scheduled']);
    }
    Database::run('INSERT INTO order_items (order_id, item_type, combo_package_id, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit) VALUES (:order, \'combo\', NULL, \'Soup basket\', :sku, \'basket\', 2, 50000, 100000)', [':order' => $orderIds[0], ':sku' => "MF-$suffix"]);
    $itemId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO order_item_components (order_item_id, product_name, quantity, unit_name) VALUES (:item, \'Ugu\', 3, \'Bunch\')', [':item' => $itemId]);

    $manifest = DeliveryManifest::forDate($date);
    mf_eq(2, $manifest['order_count'], 'manifest includes active and delivered orders but excludes cancelled orders');
    mf_eq('Alpha ' . $suffix, $manifest['zones'][0]['name'], 'zones are grouped in display order');
    $zulu = $manifest['zones'][1]['orders'][0];
    mf_eq('6', $zulu['packing_lines'][0]['quantity'], 'combo snapshots are multiplied into packing quantities');
    mf_eq('Ugu', $zulu['packing_lines'][0]['name'], 'manifest uses the recorded component snapshot');

    // The per-zone totals on the real path, not just the pure one. This is the
    // figure a packer checks a crate against.
    $zuluTotals = $manifest['zones'][1]['packing_totals'];
    mf_eq(1, count($zuluTotals), 'the zone totals one line per product');
    mf_eq('Ugu', $zuluTotals[0]['name'], 'the zone total names the product');
    mf_eq('6', $zuluTotals[0]['quantity'], 'the zone total is the combo quantity multiplied out');
    mf_eq('Bunch', $zuluTotals[0]['unit'], 'the zone total carries the unit');
    mf_eq([], $manifest['zones'][0]['packing_totals'], 'a zone whose only order has no items totals nothing');
} finally {
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($zoneIds as $id) { Database::run('DELETE FROM delivery_zones WHERE id = :id', [':id' => $id]); }
    if ($userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
}
fwrite(STDOUT, "\n$passed / $tests manifest database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
