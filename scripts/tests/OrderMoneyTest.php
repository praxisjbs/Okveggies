<?php
/**
 * OrderMoney: how an order's money reads to a person. Pure rules only. The
 * ledger read is covered by credit_line_existing_order_db_test.php.
 *
 * The rule under test is the hybrid the Owner asked for on 29 Sep 2026: an order
 * on the credit line reads as PAID WITH THE CREDIT LINE (order layer) with a
 * repayment on the credit layer, and never as unpaid, while cash fields stay
 * cash only.
 */

$mkOrder = static fn(array $over = []): array => $over + [
    'order_status'        => 'pending',
    'payment_option'      => 'pay_in_full',
    'payment_status'      => 'unpaid',
    'order_total_subunit' => 800000,
    'amount_paid_subunit' => 0,
    'balance_due_subunit' => 800000,
];

// --- cash orders --------------------------------------------------------------
$unpaid = OrderMoney::describe($mkOrder());
okv_test_eq(OrderMoney::KIND_UNPAID, $unpaid['kind'], 'an unpaid card order reads as unpaid');
okv_test_eq('Nothing has been paid yet.', $unpaid['headline'], 'the unpaid headline says so');
okv_test_eq('₦8,000 still to pay', $unpaid['badge'], 'the unpaid badge carries the amount');
okv_test_eq('warn', $unpaid['tone'], 'unpaid is a warning tone');
okv_test_eq(800000, $unpaid['owed_subunit'], 'the whole balance is owed in cash');
okv_test_ok(OrderMoney::needsPayment($unpaid), 'an unpaid order needs payment');

$part = OrderMoney::describe($mkOrder(['payment_status' => 'part_paid', 'amount_paid_subunit' => 300000, 'balance_due_subunit' => 500000]));
okv_test_eq(OrderMoney::KIND_PART_PAID, $part['kind'], 'a part paid order reads as part paid');
okv_test_eq('₦5,000 still to pay', $part['badge'], 'part paid shows what is left');

$paid = OrderMoney::describe($mkOrder(['payment_status' => 'paid', 'amount_paid_subunit' => 800000, 'balance_due_subunit' => 0]));
okv_test_eq(OrderMoney::KIND_PAID, $paid['kind'], 'a paid order reads as paid');
okv_test_eq('Paid in full.', $paid['headline'], 'the paid headline');
okv_test_ok($paid['settled'] && $paid['owed_subunit'] === 0, 'a paid order is settled and owes nothing');
okv_test_ok(!OrderMoney::needsPayment($paid), 'a paid order needs no payment');

$onDelivery = OrderMoney::describe($mkOrder(['payment_option' => 'pay_on_delivery']));
okv_test_eq(OrderMoney::KIND_ON_DELIVERY, $onDelivery['kind'], 'an unpaid pay on delivery order reads as pay on delivery');
okv_test_eq('Pay on delivery', $onDelivery['badge'], 'and its badge is not an alarm');
okv_test_eq('neutral', $onDelivery['tone'], 'pay on delivery is a neutral tone');

$cancelled = OrderMoney::describe($mkOrder(['order_status' => 'cancelled']));
okv_test_eq(OrderMoney::KIND_CANCELLED, $cancelled['kind'], 'a cancelled order reads as cancelled');
okv_test_eq(0, $cancelled['owed_subunit'], 'a cancelled order owes nothing');
okv_test_ok(!OrderMoney::needsPayment($cancelled), 'a cancelled order offers no payment');

// --- the credit line -----------------------------------------------------------
$credit = ['charged_subunit' => 800000, 'open_subunit' => 800000, 'due_date' => '2026-10-02'];
$onCredit = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), $credit);
okv_test_eq(OrderMoney::KIND_CREDIT, $onCredit['kind'], 'an order with a posted charge reads as on the credit line');
okv_test_eq('Paid with your credit line.', $onCredit['headline'], 'the order layer says paid with the credit line');
okv_test_eq('On your credit line', $onCredit['badge'], 'the badge names the credit line');
okv_test_eq('good', $onCredit['tone'], 'a credit order is a good tone, not a warning');
okv_test_ok($onCredit['settled'] && $onCredit['on_credit'], 'a credit order is settled for the customer');
okv_test_eq(0, $onCredit['owed_subunit'], 'no cash is owed on a credit order');
okv_test_eq(800000, $onCredit['credit_open_subunit'], 'the credit layer carries what is open');
okv_test_eq('2026-10-02', $onCredit['credit_due_date'], 'and when it is due');
okv_test_eq('Repay ₦8,000 by ' . date('l jS F', strtotime('2026-10-02 12:00:00')) . '.', $onCredit['credit_line'], 'the credit layer says what to repay and by when');
okv_test_ok(!OrderMoney::needsPayment($onCredit), 'a credit order never asks for a payment now');

// The regression the Owner reported: nothing on a credit order may read as unpaid.
$words = strtolower(implode(' ', [$onCredit['headline'], $onCredit['badge'], $onCredit['credit_line']]));
foreach (['unpaid', 'still to pay', 'nothing has been paid', 'pay now'] as $forbidden) {
    okv_test_ok(!str_contains($words, $forbidden), "a credit order never reads '$forbidden'");
}

// Cash figures on the order row are never changed by describing it.
$row = $mkOrder(['payment_option' => 'on_account']);
$before = $row;
OrderMoney::describe($row, $credit);
okv_test_eq($before, $row, 'describing an order changes none of its cash fields');

// Partly repaid stays on the credit line; fully repaid reads as repaid.
$partRepaid = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), ['charged_subunit' => 800000, 'open_subunit' => 300000, 'due_date' => '2026-10-02']);
okv_test_eq(OrderMoney::KIND_CREDIT, $partRepaid['kind'], 'a part repaid credit order is still on the credit line');
okv_test_eq(300000, $partRepaid['credit_open_subunit'], 'and shows only what is left to repay');
$repaid = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), ['charged_subunit' => 800000, 'open_subunit' => 0, 'due_date' => '2026-10-02']);
okv_test_eq(OrderMoney::KIND_CREDIT_REPAID, $repaid['kind'], 'a repaid credit order reads as repaid');
okv_test_eq('Credit repaid', $repaid['badge'], 'with its own badge');
okv_test_eq('', $repaid['credit_line'], 'and nothing left to repay');
$overAdjusted = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), ['charged_subunit' => 800000, 'open_subunit' => -100, 'due_date' => null]);
okv_test_eq(OrderMoney::KIND_CREDIT_REPAID, $overAdjusted['kind'], 'a negative ledger sum never shows a negative debt');

// An on-account flag with no charge is not a credit order: fail toward the cash reading.
$noCharge = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), null);
okv_test_eq(OrderMoney::KIND_UNPAID, $noCharge['kind'], 'an on-account flag with no ledger charge reads as unpaid');
$zeroCharge = OrderMoney::describe($mkOrder(['payment_option' => 'on_account']), ['charged_subunit' => 0, 'open_subunit' => 0, 'due_date' => null]);
okv_test_eq(OrderMoney::KIND_UNPAID, $zeroCharge['kind'], 'a zero charge is not a credit order');

// A cancelled credit order is cancelled first.
$cancelledCredit = OrderMoney::describe($mkOrder(['payment_option' => 'on_account', 'order_status' => 'cancelled']), $credit);
okv_test_eq(OrderMoney::KIND_CANCELLED, $cancelledCredit['kind'], 'a cancelled credit order reads as cancelled');

// --- the sentence -----------------------------------------------------------------
okv_test_eq('Repay ₦1,250.50 to OK Veggies.', OrderMoney::creditLine(125050, null), 'no due date still says what to repay');
okv_test_eq('Repay ₦1,250.50 to OK Veggies.', OrderMoney::creditLine(125050, 'not a date'), 'a bad due date is ignored');
okv_test_ok(!str_contains(OrderMoney::creditLine(800000, '2026-10-02'), "\u{2014}"), 'the credit line has no em dash');

// --- every screen reads the helper, and none keeps its own unpaid wording -----------
$root = dirname(__DIR__, 2);
foreach (['account.php', 'public/order.php', 'pro/orders.php', 'admin/orders.php', 'admin/customers.php'] as $screen) {
    $src = (string) file_get_contents($root . '/' . $screen);
    okv_test_ok(str_contains($src, 'OrderMoney'), "$screen reads its payment wording from OrderMoney");
}
$account = (string) file_get_contents($root . '/account.php');
okv_test_ok(!str_contains($account, 'still to pay'), 'the account list has no hardcoded "still to pay"');
okv_test_ok(!str_contains($account, 'Paid in full</span>'), 'the account list has no hardcoded paid badge');
$orderPage = (string) file_get_contents($root . '/public/order.php');
okv_test_ok(!str_contains($orderPage, "'unpaid'    => '. Nothing has been paid yet.'"), 'the order page has no hardcoded unpaid sentence');
