<?php
/**
 * SourcingGate: may this order move from Placed to Sourced? Pure rules. The locked
 * transaction that enforces them is covered by sourcing_gate_db_test.php.
 *
 * The Owner's rules (23 Sep review, answers of 29 Sep 2026):
 *   households pay what checkout asked for; businesses with credit are charged;
 *   businesses without credit pay like households; a Kitchen Run and an order a
 *   colleague typed in are exempt; pay on delivery needs its deposit too.
 */

$mk = static fn(array $over = []): array => $over + [
    'order_status'             => 'pending',
    'payment_option'           => 'pay_in_full',
    'order_total_subunit'      => 1000000,
    'amount_paid_subunit'      => 0,
    'deposit_required_subunit' => 0,
];
$facts = static fn(array $over = []): array => $over + ['exempt' => '', 'credit' => null, 'deposit_percentage' => 30.0];

// --- what checkout asked for ----------------------------------------------------------
okv_test_eq(1000000, SourcingGate::requiredCashSubunit('pay_in_full', 1000000, 0, 30.0), 'pay in full needs the whole total');
okv_test_eq(300000, SourcingGate::requiredCashSubunit('deposit', 1000000, 300000, 30.0), 'a deposit order needs its stored deposit');
okv_test_eq(300000, SourcingGate::requiredCashSubunit('deposit', 1000000, 0, 30.0), 'a deposit order with no stored figure needs the standard percentage');
okv_test_eq(1000000, SourcingGate::requiredCashSubunit('deposit', 1000000, 9999999, 30.0), 'a stored deposit above the total is capped at the total');
okv_test_eq(300000, SourcingGate::requiredCashSubunit('pay_on_delivery', 1000000, 0, 30.0), 'pay on delivery needs the deposit too');
okv_test_eq(250000, SourcingGate::requiredCashSubunit('pay_on_delivery', 1000000, 250000, 30.0), 'a pay on delivery order that has its deposit opened uses that figure');
okv_test_eq(0, SourcingGate::requiredCashSubunit('on_account', 1000000, 0, 30.0), 'an on-account order needs no cash');
okv_test_eq(0, SourcingGate::requiredCashSubunit('pay_in_full', -5, 0, 30.0), 'a negative total needs nothing rather than a negative figure');

// --- only Placed orders are gated -------------------------------------------------------
foreach (['confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'] as $stage) {
    $r = SourcingGate::evaluate($mk(['order_status' => $stage]), $facts());
    okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_NOT_APPLICABLE, "the gate does not apply to a $stage order");
}

// --- households and businesses without credit --------------------------------------------
$r = SourcingGate::evaluate($mk(), $facts());
okv_test_ok(!$r['allowed'], 'an unpaid pay in full order cannot be sourced');
okv_test_eq(SourcingGate::BLOCK_PAYMENT, $r['code'], 'and the reason is payment required');
okv_test_ok(str_contains($r['message'], '₦10,000') && str_contains($r['message'], '₦0'), 'the message names what is needed and what has arrived');
okv_test_eq(1000000, $r['required_subunit'], 'the result carries the required figure');

$r = SourcingGate::evaluate($mk(['amount_paid_subunit' => 999999]), $facts());
okv_test_ok(!$r['allowed'], 'a pay in full order one kobo short cannot be sourced');
$r = SourcingGate::evaluate($mk(['amount_paid_subunit' => 1000000]), $facts());
okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_PAID, 'a pay in full order that is paid can be sourced');
$r = SourcingGate::evaluate($mk(['amount_paid_subunit' => 1200000]), $facts());
okv_test_ok($r['allowed'], 'an overpaid order can be sourced');

// --- deposit orders ------------------------------------------------------------------------
$dep = ['payment_option' => 'deposit', 'deposit_required_subunit' => 300000];
$r = SourcingGate::evaluate($mk($dep), $facts());
okv_test_ok(!$r['allowed'] && $r['code'] === SourcingGate::BLOCK_DEPOSIT, 'a deposit order without its deposit is blocked as deposit required');
okv_test_ok(str_contains($r['message'], 'The deposit has not been paid'), 'and the message says the deposit is what is missing');
$r = SourcingGate::evaluate($mk($dep + ['amount_paid_subunit' => 299999]), $facts());
okv_test_ok(!$r['allowed'], 'a deposit one kobo short is blocked');
$r = SourcingGate::evaluate($mk($dep + ['amount_paid_subunit' => 300000]), $facts());
okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_PAID, 'a deposit order with its deposit can be sourced');

// --- pay on delivery is not a way round the gate -----------------------------------------------
$pod = ['payment_option' => 'pay_on_delivery'];
$r = SourcingGate::evaluate($mk($pod), $facts());
okv_test_ok(!$r['allowed'] && $r['code'] === SourcingGate::BLOCK_DEPOSIT, 'pay on delivery with nothing paid is blocked for its deposit');
$r = SourcingGate::evaluate($mk($pod + ['amount_paid_subunit' => 300000]), $facts());
okv_test_ok($r['allowed'], 'pay on delivery with the standard deposit paid can be sourced');
$r = SourcingGate::evaluate($mk($pod + ['amount_paid_subunit' => 100000]), $facts(['deposit_percentage' => 10.0]));
okv_test_ok($r['allowed'], 'the deposit follows the configured percentage');

// --- the credit line ------------------------------------------------------------------------------
$acct = ['payment_option' => 'on_account'];
$r = SourcingGate::evaluate($mk($acct), $facts(['credit' => ['charged_subunit' => 1000000, 'open_subunit' => 1000000]]));
okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_CREDIT, 'an order with a posted credit charge can be sourced');
$r = SourcingGate::evaluate($mk($acct), $facts());
okv_test_ok(!$r['allowed'] && $r['code'] === SourcingGate::BLOCK_CREDIT, 'an on-account order with no charge is blocked');
$r = SourcingGate::evaluate($mk($acct), $facts(['credit' => ['charged_subunit' => 0, 'open_subunit' => 0]]));
okv_test_ok(!$r['allowed'], 'a zero charge does not cover an on-account order');

// --- exemptions --------------------------------------------------------------------------------------
$r = SourcingGate::evaluate($mk(), $facts(['exempt' => 'kitchen_run']));
okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_KITCHEN_RUN, 'an order that came from a Kitchen Run is exempt');
$r = SourcingGate::evaluate($mk(), $facts(['exempt' => 'staff_order']));
okv_test_ok($r['allowed'] && $r['code'] === SourcingGate::OK_STAFF_ORDER, 'an order a colleague entered is exempt');
$r = SourcingGate::evaluate($mk(), $facts(['exempt' => 'something_else']));
okv_test_ok(!$r['allowed'], 'an unknown exemption is not an exemption (closed by default)');
$r = SourcingGate::evaluate($mk(), []);
okv_test_ok(!$r['allowed'], 'missing facts gate the order rather than opening it');

// --- the credit shortcut is offered only when it would work -------------------------------------------
$facility = ['state' => 'approved', 'days' => 7, 'available_subunit' => 2000000];
$unpaidOrder = ['payment_option' => 'pay_in_full', 'amount_paid_subunit' => 0, 'order_total_subunit' => 1000000];
okv_test_ok(SourcingGate::creditShortcutOffered($unpaidOrder, $facility), 'the shortcut is offered when the credit is there');
okv_test_ok(!SourcingGate::creditShortcutOffered($unpaidOrder, ['available_subunit' => 500000] + $facility), 'not offered when the credit is short');
okv_test_ok(!SourcingGate::creditShortcutOffered($unpaidOrder, null), 'not offered without a facility');
okv_test_ok(!SourcingGate::creditShortcutOffered($unpaidOrder, ['state' => 'suspended'] + $facility), 'not offered on a suspended facility');
okv_test_ok(!SourcingGate::creditShortcutOffered(['amount_paid_subunit' => 1] + $unpaidOrder, $facility), 'not offered once anything is paid');
okv_test_ok(!SourcingGate::creditShortcutOffered(['payment_option' => 'on_account'] + $unpaidOrder, $facility), 'not offered on an order already on account');

// --- wiring: the gate lives in the one place that changes the stage ------------------------------------
$root = dirname(__DIR__, 2);
$lifecycle = (string) file_get_contents($root . '/includes/classes/OrderLifecycle.php');
okv_test_ok(str_contains($lifecycle, 'SourcingGate::forOrder($orderId)'), 'the stage change runs the gate inside its transaction');
okv_test_ok(strpos($lifecycle, 'SourcingGate::forOrder') > strpos($lifecycle, 'FOR UPDATE'), 'and only after it holds the order row');
okv_test_ok(str_contains($lifecycle, "'orders.source_override'"), 'an override is written to the audit log');
$api = (string) file_get_contents($root . '/api/v1/orders.php');
okv_test_ok(str_contains($api, "Rbac::can('orders.source.override')"), 'only the Owner permission can pass an override reason');
okv_test_ok(str_contains($api, "Only the Owner can source an order that has not been paid."), 'and anyone else is told so');
$perms = (string) file_get_contents($root . '/includes/config/permissions.php');
okv_test_ok(str_contains($perms, "'orders.source.override'"), 'the override permission is in the catalogue');
okv_test_ok(substr_count($perms, "'orders.source.override'") >= 2, 'and is kept Owner only');
