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

$creditController = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/credit.php');
foreach (['approve' => 'credit.apply.review', 'decline' => 'credit.apply.review', 'grant' => 'credit.grant', 'change_terms' => 'credit.limit.set', 'record_repayment' => 'credit.limit.set'] as $action => $permission) {
    okv_test_ok(str_contains($creditController, "'$action'=>'$permission'"), "$action uses its exact credit permission");
}
