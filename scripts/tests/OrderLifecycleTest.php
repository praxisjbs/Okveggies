<?php
/** Pure M6 order lifecycle and customer trail projection tests. */

$legal = [
    ['pending', 'confirmed'],
    ['pending', 'cancelled'],
    ['confirmed', 'packed'],
    ['confirmed', 'cancelled'],
    ['packed', 'dispatched'],
    ['packed', 'cancelled'],
    ['dispatched', 'delivered'],
    ['dispatched', 'cancelled'],
];
$statuses = ['pending', 'confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'];
foreach ($statuses as $from) {
    foreach ($statuses as $to) {
        $expected = in_array([$from, $to], $legal, true);
        okv_test_eq($expected, OrderLifecycle::mayTransition($from, $to), "$from to $to follows the lifecycle map");
    }
}

okv_test_eq(['confirmed'], OrderLifecycle::staffTargets('pending'), 'staff confirmation is the only forward action from pending');
okv_test_eq(['packed'], OrderLifecycle::staffTargets('confirmed'), 'staff can pack a confirmed order');
okv_test_eq(['dispatched'], OrderLifecycle::staffTargets('packed'), 'staff can dispatch a packed order');
okv_test_eq(['delivered'], OrderLifecycle::staffTargets('dispatched'), 'staff can deliver a dispatched order');
okv_test_eq([], OrderLifecycle::staffTargets('delivered'), 'delivered orders have no staff transition');
okv_test_eq([], OrderLifecycle::staffTargets('cancelled'), 'cancelled orders have no staff transition');

$history = [
    ['new_status' => 'pending', 'created_at' => '2026-09-01 08:00:00'],
    ['new_status' => 'confirmed', 'created_at' => '2026-09-01 10:00:00'],
    ['new_status' => 'packed', 'created_at' => '2026-09-02 07:00:00'],
];
$trail = OrderLifecycle::customerTrail($history);
okv_test_eq(['Placed', 'Sourced', 'Packed'], array_column($trail, 'label'), 'public trail maps only approved customer milestones');
okv_test_ok(!str_contains(json_encode($trail), 'confirmed'), 'the internal confirmed name is not exposed in the public trail');

$cancelled = OrderLifecycle::customerTrail([
    ['new_status' => 'pending', 'created_at' => '2026-09-01 08:00:00'],
    ['new_status' => 'cancelled', 'created_at' => '2026-09-01 09:00:00'],
]);
okv_test_eq(['Placed', 'Cancelled'], array_column($cancelled, 'label'), 'a cancellation is represented plainly on the public trail');

$controller = file_get_contents(dirname(__DIR__, 2) . '/api/v1/orders.php');
okv_test_ok(str_contains($controller, "Rbac::requirePermission('orders.status.update')"), 'status writes require the lifecycle permission');
okv_test_ok(str_contains($controller, 'Csrf::validate()'), 'status writes validate CSRF');
okv_test_ok(str_contains($controller, 'okv_is_post()'), 'status writes require POST');
