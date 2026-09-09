<?php
/** Pure Task B credit arithmetic and customer order labels. */

$approved = [
    'credit_status' => 'approved',
    'credit_limit_subunit' => 50000000,
];
$ledger = [
    'outstanding_subunit' => 12500000,
    'past_due_charges' => 5000000,
    'reductions' => -2500000,
    'earliest_due_date' => '2026-09-10',
];
$credit = ProDashboard::creditSnapshot($approved, $ledger);

okv_test_ok($credit['approved'], 'an approved business receives credit figures');
okv_test_eq(50000000, $credit['limit_subunit'], 'the credit limit remains integer subunits');
okv_test_eq(12500000, $credit['outstanding_subunit'], 'open credit remains integer subunits');
okv_test_eq(37500000, $credit['available_subunit'], 'available credit is limit minus outstanding');
okv_test_eq(2500000, $credit['overdue_subunit'], 'overdue credit remains integer subunits');
okv_test_eq('2026-09-10', $credit['earliest_due_date'], 'the earliest unpaid due date is retained');
okv_test_eq('₦125,000', Money::format($credit['outstanding_subunit']), 'the example outstanding amount uses Money');
okv_test_eq('₦375,000', Money::format($credit['available_subunit']), 'the example available amount uses Money');

$notApproved = ProDashboard::creditSnapshot([
    'credit_status' => 'requested',
    'credit_limit_subunit' => null,
], $ledger);
okv_test_ok(!$notApproved['approved'], 'a requested credit account does not receive financial cards');
okv_test_eq(12500000, $notApproved['outstanding_subunit'], 'facility state never erases the signed journal balance');
okv_test_eq(0, $notApproved['available_subunit'], 'an unapproved account exposes no available draw');

$overLimit = ProDashboard::creditSnapshot($approved, [
    'outstanding_subunit' => 60000000,
    'past_due_charges' => 0,
    'reductions' => 0,
    'earliest_due_date' => null,
]);
okv_test_eq(0, $overLimit['available_subunit'], 'available credit never displays below zero');

okv_test_eq('Placed', OrderLifecycle::customerLabel('pending'), 'pending is labelled Placed for a customer');
okv_test_eq('Sourced', OrderLifecycle::customerLabel('confirmed'), 'confirmed is labelled Sourced for a customer');
okv_test_eq('Order update', OrderLifecycle::customerLabel('not-real'), 'an unknown order stage gets a safe label');
