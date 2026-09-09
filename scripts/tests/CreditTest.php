<?php
/** Pure Task F application and signed-journal credit rules. */

$valid = Credit::validateApplication('10', '500,000', 'Weekly market buying for our restaurant kitchen.');
okv_test_eq(10, $valid['requested_days'], 'credit application accepts 10 day terms');
okv_test_eq(50000000, $valid['requested_limit_subunit'], 'requested naira becomes integer kobo');

$creditRefuses = static function (callable $work, string $code, string $label): void {
    try { $work(); okv_test_ok(false, $label); } catch (DomainException $e) { okv_test_eq($code, $e->getMessage(), $label); }
};
$creditRefuses(static fn() => Credit::validateApplication('6', '500,000', str_repeat('a', 20)), 'invalid_days', 'credit terms below 7 days are refused');
$creditRefuses(static fn() => Credit::validateApplication('11', '500,000', str_repeat('a', 20)), 'invalid_days', 'credit terms above 10 days are refused');
$creditRefuses(static fn() => Credit::validateApplication('7', '0', str_repeat('a', 20)), 'invalid_limit', 'a zero requested limit is refused');
$creditRefuses(static fn() => Credit::validateApplication('7', '1e6', str_repeat('a', 20)), 'invalid_limit', 'scientific notation is refused for credit money');
$creditRefuses(static fn() => Credit::validateApplication('7', '500,000', 'Too short'), 'invalid_reason', 'a short application reason is refused');

$snapshot = Credit::snapshot(
    ['credit_status' => 'approved', 'credit_limit_subunit' => 50000000],
    ['outstanding_subunit' => 42000000, 'past_due_charges' => 20000000, 'reductions' => -5000000, 'earliest_due_date' => '2026-09-01']
);
okv_test_eq(42000000, $snapshot['outstanding_subunit'], 'signed journal entries produce the outstanding balance');
okv_test_eq(8000000, $snapshot['available_subunit'], 'available credit is the limit minus journal balance');
okv_test_eq(15000000, $snapshot['overdue_subunit'], 'negative journal entries reduce overdue charges oldest first');

foreach (['suspended', 'withdrawn'] as $state) {
    $restricted = Credit::snapshot(['credit_status' => $state, 'credit_limit_subunit' => 50000000], ['outstanding_subunit' => 10000000]);
    okv_test_eq(0, $restricted['available_subunit'], "$state credit exposes no available draw");
    okv_test_eq(10000000, $restricted['outstanding_subunit'], "$state credit keeps its outstanding balance visible");
}

$afterRepayment = Credit::snapshotFromTransactions(
    ['credit_status' => 'approved', 'credit_limit_subunit' => 50000000],
    [
        ['amount_subunit' => 30000000, 'due_date' => '2026-09-01'],
        ['amount_subunit' => 12000000, 'due_date' => '2026-09-20'],
        ['amount_subunit' => -30000000, 'due_date' => null],
    ],
    '2026-09-08'
);
okv_test_eq(0, $afterRepayment['overdue_subunit'], 'a repayment clears the oldest overdue charge first');
okv_test_eq('2026-09-20', $afterRepayment['earliest_due_date'], 'the next unpaid due date advances after repayment');

// Each staff action asks for its own permission. This reads the controller
// rather than calling it, so it stays a unit test; the match ignores spacing,
// because what matters is the pairing, not how the array is laid out.
$creditController = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/credit.php');
foreach ([
    'approve'          => 'credit.apply.review',
    'decline'          => 'credit.apply.review',
    'grant'            => 'credit.grant',
    'change_terms'     => 'credit.limit.set',
    'suspend'          => 'credit.limit.set',
    'withdraw'         => 'credit.limit.set',
    'reinstate'        => 'credit.limit.set',
    'record_repayment' => 'credit.limit.set',
] as $action => $permission) {
    $pattern = "/'" . preg_quote($action, '/') . "'\s*=>\s*'" . preg_quote($permission, '/') . "'/";
    okv_test_ok(preg_match($pattern, $creditController) === 1, "$action uses its exact credit permission");
}

// -----------------------------------------------------------------------------
// Task H. The one shared rule that decides whether an order may go on account.
// -----------------------------------------------------------------------------

/** A facility on the shape every caller reads, from a limit and an outstanding balance. */
$facility = static function (string $state, int $limit, int $outstanding, int $days = 7): array {
    $business = ['id' => 7, 'business_name' => 'Green Bowl', 'credit_status' => $state,
                 'credit_days' => $days, 'credit_limit_subunit' => $limit];
    return Credit::facility($business, Credit::snapshot($business, ['outstanding_subunit' => $outstanding]));
};

$clear = $facility('approved', 50000000, 0);
okv_test_eq(50000000, $clear['available_subunit'], 'a facility with no charges has its whole limit available');
okv_test_eq('', Credit::drawRefusal($clear, 50000000), 'an order for the whole limit is allowed on a clear account');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($clear, 50000001), 'one kobo above the limit is refused on a clear account');

// The PRD example: a 500,000 naira limit with 420,000 naira of open charges.
$partly = $facility('approved', 50000000, 42000000);
okv_test_eq(8000000, $partly['available_subunit'], 'available credit is the limit minus the journal balance');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($partly, 9000000), 'an order of 90,000 naira against 80,000 naira available is refused');
okv_test_eq('', Credit::drawRefusal($partly, 8000000), 'an order exactly equal to the available credit is allowed');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($partly, 8000001), 'one kobo above the available credit is refused');

// A repayment is a negative journal entry, so it frees the limit again.
$afterRepaying = $facility('approved', 50000000, 42000000 - 3000000);
okv_test_eq(11000000, $afterRepaying['available_subunit'], 'a 30,000 naira repayment frees 30,000 naira of limit');
okv_test_eq('', Credit::drawRefusal($afterRepaying, 11000000), 'the freed credit can be drawn again');

foreach (['suspended', 'withdrawn', 'requested', 'declined', 'not_requested'] as $state) {
    okv_test_eq('credit_not_approved', Credit::drawRefusal($facility($state, 50000000, 0), 100000), "$state credit cannot be drawn on");
}
okv_test_eq('credit_not_approved', Credit::drawRefusal(null, 100000), 'an account with no business profile cannot draw on credit');
okv_test_eq('credit_not_approved', Credit::drawRefusal($facility('approved', 50000000, 0, 6), 100000), 'a term below 7 days is not a usable facility');
okv_test_eq('credit_not_approved', Credit::drawRefusal($facility('approved', 50000000, 0, 11), 100000), 'a term above 10 days is not a usable facility');
okv_test_eq('invalid_charge', Credit::drawRefusal($clear, 0), 'an order with nothing to charge is refused');
okv_test_ok(Credit::mayDraw($clear, 50000000), 'mayDraw agrees with the refusal rule');
okv_test_ok(!Credit::mayDraw($partly, 9000000), 'mayDraw refuses an over-limit order');

okv_test_eq('2026-09-16', Credit::dueDateFor('2026-09-09', 7), 'a 7 day charge falls due 7 days after delivery');
okv_test_eq('2026-09-19', Credit::dueDateFor('2026-09-09', 10), 'a 10 day charge falls due 10 days after delivery');
okv_test_eq('2026-10-01', Credit::dueDateFor('2026-09-24', 7), 'a due date crosses the month end correctly');

// Every on-account path reads the one rule, and the draw is locked and atomic.
$creditClass = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Credit.php');
okv_test_ok(str_contains($creditClass, 'FOR SHARE'), 'the draw reads the journal with a locking read');
okv_test_ok(str_contains($creditClass, 'FROM business_customers WHERE user_id = :user FOR UPDATE'), 'the draw locks the credit facility row');
okv_test_ok(str_contains($creditClass, 'credit draw called outside a transaction'), 'the draw refuses to run outside a transaction');
okv_test_ok(str_contains($creditClass, "'order:' . \$orderId . ':charge'"), 'each order can carry only one keyed charge');

$checkoutClass = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Checkout.php');
okv_test_ok(str_contains($checkoutClass, 'Credit::drawRefusal'), 'ordinary checkout refuses an over-limit order before it writes anything');
okv_test_ok(str_contains($checkoutClass, 'Credit::drawForOrder'), 'ordinary checkout appends the charge through the shared service');

$workflowClass = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/KitchenRunWorkflow.php');
okv_test_ok(!str_contains($workflowClass, '$creditApproved = $paymentOption'), 'Kitchen Run conversion no longer assumes approval from the payment choice');
okv_test_ok(str_contains($workflowClass, 'Credit::facilityForUser'), 'Kitchen Run conversion reads the real facility');
okv_test_ok(str_contains($workflowClass, 'Credit::drawRefusal'), 'Kitchen Run conversion uses the same shared credit rule');

$checkoutPage = (string) file_get_contents(dirname(__DIR__, 2) . '/checkout.php');
okv_test_ok(str_contains($checkoutPage, 'Credit::drawRefusal'), 'the checkout page offers on account from the real facility');

$panel = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/admin/kitchen_run_panel.php');
okv_test_ok(str_contains($panel, 'Credit::drawRefusal'), 'the conversion panel shows staff what the facility can take');

foreach (['credit_not_approved', 'credit_limit_exceeded', 'invalid_charge'] as $code) {
    okv_test_ok(Credit::message($code) !== '' && !str_contains(Credit::message($code), 'Exception'), "$code has plain customer-facing words");
    okv_test_ok(KitchenRuns::message($code) !== 'We could not save that Kitchen Run. Please try again.', "$code is named for staff on a Kitchen Run");
}
