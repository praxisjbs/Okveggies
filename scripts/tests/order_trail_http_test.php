<?php
/** Public Order Trail token access, projection and isolation over real HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0; $passed = 0;
function ot_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function ot_get(string $url): array { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]); $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, $body]; }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10); $orders = [];
try {
    foreach ([['Visible carrots', 'Private staff note'], ['OTHER-ORDER-SECRET', 'Other private note']] as $i => [$item, $note]) {
        $token = OrderTrail::newToken();
        Database::run('INSERT INTO orders (order_number, order_trail_token_hash, customer_type, order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit, preferred_delivery_date, confirmed_at, source_regions_snapshot) VALUES (:number, :token, \'household\', \'confirmed\', \'pay_in_full\', \'paid\', 9900000, 9900000, 9900000, 0, :date, NOW(), \'Ogun State\')', [':number' => "ZZ-TRAIL-$suffix-$i", ':token' => OrderTrail::hashToken($token), ':date' => date('Y-m-d', strtotime('+4 days'))]);
        $orderId = (int) Database::getInstance()->getConnection()->lastInsertId(); $orders[] = $orderId;
        Database::run('INSERT INTO order_items (order_id, item_type, item_name, sku, unit_name, quantity, unit_price_subunit, line_total_subunit) VALUES (:order, \'product\', :name, :sku, \'Kilogramme\', 1, 9900000, 9900000)', [':order' => $orderId, ':name' => $item, ':sku' => "TR-$suffix-$i"]);
        Database::run('INSERT INTO order_status_history (order_id, old_status, new_status, source, note) VALUES (:order, NULL, \'pending\', \'customer\', :note)', [':order' => $orderId, ':note' => $note]);
        Database::run('INSERT INTO order_status_history (order_id, old_status, new_status, source, note) VALUES (:order, \'pending\', \'confirmed\', \'admin\', :note)', [':order' => $orderId, ':note' => $note]);
        if ($i === 0) { $firstToken = $token; }
    }
    [$code, $body] = ot_get($base . '/public/order.php?token=' . rawurlencode($firstToken));
    ot_ok($code === 200, 'a valid trail token opens without login');
    ot_ok(str_contains($body, 'Visible carrots'), 'the linked order item is visible');
    ot_ok(str_contains($body, 'Sourced') && str_contains($body, 'from Ogun State'), 'recorded sourcing appears on the trail');
    ot_ok(!str_contains($body, '₦') && !str_contains($body, '9,900,000'), 'the public trail exposes no money');
    ot_ok(!str_contains($body, 'Private staff note'), 'internal transition notes are not public');
    ot_ok(!str_contains($body, 'OTHER-ORDER-SECRET'), 'another order cannot leak into the response');
    [$badCode, $badBody] = ot_get($base . '/public/order.php?token=invalid');
    ot_ok($badCode === 404 && str_contains($badBody, 'We could not find that order'), 'an invalid token receives the branded not-found response');
} finally {
    foreach ($orders as $id) {
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
}
fwrite(STDOUT, "\n$passed / $tests public trail HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
