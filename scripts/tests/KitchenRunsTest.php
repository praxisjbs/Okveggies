<?php
/**
 * M7 Kitchen Run contract. These are intentionally public-rule tests. Database
 * and HTTP details live beside this file in kitchen_runs_db_test.php and
 * kitchen_runs_http_test.php.
 */

function kr_has(string $method): bool
{
    $exists = method_exists(KitchenRuns::class, $method);
    okv_test_ok($exists, 'KitchenRuns exposes the public rule ' . $method);
    return $exists;
}

// Four starts, including a mixed catalogue and free-text list.
if (kr_has('validateSubmission')) {
    $catalogue = KitchenRuns::validateSubmission('catalogue', 'by_us', [['product_id' => 7, 'quantity' => '2.500', 'unit_id' => 1]]);
    okv_test_ok($catalogue['ok'] ?? false, 'a catalogue list accepts a product quantity and its unit');
    $byUs = KitchenRuns::validateSubmission('custom', 'by_us', [['item_name' => 'Pomo', 'quantity' => '10.000', 'unit_id' => 1]]);
    okv_test_ok($byUs['ok'] ?? false, 'a free-text list priced by us needs quantity and unit, not a price');
    $byCustomer = KitchenRuns::validateSubmission('custom', 'by_customer', [['item_name' => 'Pomo', 'target_price_subunit' => 4000000]]);
    okv_test_ok($byCustomer['ok'] ?? false, 'a customer-priced line accepts a kobo target without a quantity or unit');
    $mixed = KitchenRuns::validateSubmission('mixed', 'by_us', [
        ['product_id' => 7, 'quantity' => '2.000', 'unit_id' => 1],
        ['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => 1],
    ]);
    okv_test_ok($mixed['ok'] ?? false, 'catalogue and free-text lines may be mixed and preserve their sources');

    foreach ([['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.0000'], ['quantity' => 'abc']] as $bad) {
        $line = ['item_name' => 'Pomo', 'unit_id' => 1] + $bad;
        $result = KitchenRuns::validateSubmission('custom', 'by_us', [$line]);
        okv_test_ok(empty($result['ok']), 'invalid quantity is rejected before it reaches storage');
    }
    foreach ([0, -1, '100.5', '999999999999999999999999'] as $badPrice) {
        $result = KitchenRuns::validateSubmission('custom', 'by_customer', [['item_name' => 'Pomo', 'target_price_subunit' => $badPrice]]);
        okv_test_ok(empty($result['ok']), 'zero, negative, fractional or overflowing kobo target is rejected');
    }
} else {
    okv_test_ok(false, 'all four request modes validate server-side before a request is written');
}

if (kr_has('allowedUpload')) {
    foreach ([['list.jpg', 'image/jpeg'], ['list.png', 'image/png'], ['list.pdf', 'application/pdf']] as [$name, $mime]) {
        okv_test_ok(KitchenRuns::allowedUpload($name, $mime, 1024), "$name is an accepted Kitchen Run upload");
    }
    okv_test_ok(!KitchenRuns::allowedUpload('list.php.jpg', 'application/x-php', 1024), 'a spoofed executable upload is rejected by MIME and filename');
    okv_test_ok(!KitchenRuns::allowedUpload('../list.pdf', 'application/pdf', 1024), 'an unsafe filename is rejected');
    okv_test_ok(!KitchenRuns::allowedUpload('large.pdf', 'application/pdf', 6 * 1024 * 1024), 'an over-size upload is rejected');
} else {
    okv_test_ok(false, 'Kitchen Run upload type, filename and size validation is a testable public rule');
}

// State map, quote expiry and immutable customer approval.
if (kr_has('mayTransition')) {
    $legal = [['submitted','quoted'],['quoted','approved'],['approved','converted'],['submitted','declined'],['submitted','cancelled'],['quoted','cancelled'],['quoted','submitted']];
    foreach ($legal as [$from,$to]) okv_test_ok(KitchenRuns::mayTransition($from,$to), "$from to $to is legal");
    foreach ([['submitted','approved'],['quoted','converted'],['approved','cancelled'],['converted','quoted'],['declined','quoted']] as [$from,$to]) okv_test_ok(!KitchenRuns::mayTransition($from,$to), "$from to $to is refused");
}
if (kr_has('quoteExpired')) {
    okv_test_ok(!KitchenRuns::quoteExpired('2026-09-04 10:00:00', 7, '2026-09-11 09:59:59'), 'a quote remains open before its configured expiry');
    okv_test_ok(KitchenRuns::quoteExpired('2026-09-04 10:00:00', 7, '2026-09-11 10:00:00'), 'a quote expires at the configured boundary and returns to Submitted');
}
if (kr_has('canCustomerEdit')) {
    okv_test_ok(KitchenRuns::canCustomerEdit('submitted'), 'a customer may edit a submitted draft');
    okv_test_ok(!KitchenRuns::canCustomerEdit('quoted'), 'a customer cannot edit quoted lines; cancellation and resubmission preserve audit history');
}

// Exact arithmetic. Formatting is deliberately absent from all inputs.
if (kr_has('quoteLines')) {
    $quote = KitchenRuns::quoteLines([
        ['item_name'=>'Tomatoes','quantity'=>'2.500','unit_price_subunit'=>270000],
        ['item_name'=>'Pomo','quantity'=>'1.000','unit_price_subunit'=>300000],
    ]);
    okv_test_eq(675000, $quote['lines'][0]['line_total_subunit'] ?? null, 'a fractional line total stays exact in kobo');
    okv_test_eq(975000, $quote['total_subunit'] ?? null, 'quote total sums exact line values, not formatted strings');
}
if (kr_has('withinCap')) {
    okv_test_ok(KitchenRuns::withinCap(500000, 500000), 'an amount exactly at the agreed cap is allowed');
    okv_test_ok(!KitchenRuns::withinCap(500001, 500000), 'a cap is a hard limit');
    okv_test_ok(KitchenRuns::withinCap(500001, null), 'an uncapped open-budget request remains permitted');
}
if (kr_has('remainingBalance')) okv_test_eq(400000, KitchenRuns::remainingBalance(1000000, 600000), 'remaining balance uses exact kobo arithmetic');
if (kr_has('paymentAllowed')) {
    okv_test_ok(KitchenRuns::paymentAllowed('deposit','household',true,false), 'open-budget household requests may use their staff-set deposit');
    okv_test_ok(KitchenRuns::paymentAllowed('on_account','business',true,true), 'approved business credit may settle an open-budget request');
    okv_test_ok(!KitchenRuns::paymentAllowed('on_account','business',true,false), 'unapproved business credit is refused');
    okv_test_ok(!KitchenRuns::paymentAllowed('pay_on_delivery','household',true,false), 'open-budget trust mode does not bypass payment eligibility');
}

if (kr_has('notificationEvents')) {
    okv_test_eq(['submitted','quoted','approved','converted'], KitchenRuns::notificationEvents(), 'submission, quote, approval and conversion each have a post-commit notification event');
}
