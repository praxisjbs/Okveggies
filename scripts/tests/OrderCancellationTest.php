<?php
/** Pure allocation and wiring checks for the M6 cancellation flow. */

$plan = OrderCancellation::refundPlan([
    ['id' => 31, 'provider' => 'paystack', 'refundable_subunit' => 900000],
    ['id' => 22, 'provider' => 'paystack', 'refundable_subunit' => 500000],
], 1100000);
okv_test_eq(2, count($plan['paystack']), 'a refund can span more than one Paystack transaction');
okv_test_eq(900000, $plan['paystack'][0]['amount_subunit'], 'the newest transaction is unwound first');
okv_test_eq(200000, $plan['paystack'][1]['amount_subunit'], 'only the remainder reaches the older transaction');
okv_test_eq(0, $plan['manual_subunit'], 'a Paystack-only plan has no manual money');
okv_test_eq(0, $plan['unmatched_subunit'], 'a fully funded plan has no unmatched money');

$mixed = OrderCancellation::refundPlan([
    ['id' => 8, 'provider' => 'manual', 'refundable_subunit' => 300000],
    ['id' => 7, 'provider' => 'paystack', 'refundable_subunit' => 400000],
], 600000);
okv_test_eq(300000, $mixed['manual_subunit'], 'manual money is separated for staff return');
okv_test_eq(300000, $mixed['paystack'][0]['amount_subunit'], 'Paystack receives only the amount left after newer manual money');

$short = OrderCancellation::refundPlan([
    ['id' => 1, 'provider' => 'paystack', 'refundable_subunit' => 100000],
], 150000);
okv_test_eq(50000, $short['unmatched_subunit'], 'missing transaction money is flagged instead of claimed as refunded');
okv_test_ok(
    str_contains(OrderCancellation::refundStatusLine(Refunds::STATUS_REQUESTED, 'failed'), 'could not confirm'),
    'an uncertain gateway attempt is not described as already on its way'
);
okv_test_eq(
    Refunds::customerStatusLine(Refunds::STATUS_PENDING),
    OrderCancellation::refundStatusLine(Refunds::STATUS_PENDING, 'pending'),
    'a confirmed pending request keeps the M5 customer copy'
);

$controller = file_get_contents(dirname(__DIR__, 2) . '/api/v1/orders.php');
$service = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/OrderCancellation.php');
okv_test_ok(str_contains($controller, "Rbac::requirePermission('orders.cancel')"), 'staff cancellation is permission gated in the controller');
okv_test_ok(str_contains($controller, 'Csrf::validate()'), 'the cancellation controller validates CSRF');
okv_test_ok(str_contains($controller, 'okv_is_post()'), 'the cancellation controller enforces POST');
okv_test_ok(str_contains($service, 'o.user_id = :user'), 'customer ownership is checked in the locked database query');
okv_test_ok(str_contains($service, 'Refunds::request('), 'Paystack cancellation money goes through the M5 refund engine');
okv_test_ok(str_contains($service, 'FOR UPDATE'), 'submission rechecks under a database row lock');
okv_test_ok(str_contains($service, 'already_cancelled'), 'repeat submissions have an idempotent result');
