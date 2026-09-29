<?php
/**
 * scripts/tests/PaymentSummaryTest.php
 * What the customer is told about their money: received, still due, waiting.
 * The rules here decide which word leads the green screen, so they are worth
 * pinning. The database half is covered by transfer_proofs_db_test.php.
 */

// -----------------------------------------------------------------------------
// The overall state
// -----------------------------------------------------------------------------
okv_test_eq('paid',      PaymentSummary::state(false, 1000000, 1000000, false, false), 'everything received is paid');
okv_test_eq('paid',      PaymentSummary::state(false, 1000000, 1200000, false, false), 'more than everything received is still paid, an overpayment is not a debt');
okv_test_eq('part_paid', PaymentSummary::state(false, 1000000, 300000,  false, false), 'a deposit received is part paid');
okv_test_eq('unpaid',    PaymentSummary::state(false, 1000000, 0,       false, false), 'nothing received and nothing waiting is unpaid');
okv_test_eq('awaiting',  PaymentSummary::state(false, 1000000, 0,       true,  false), 'a receipt with the team is awaiting verification');
okv_test_eq('awaiting',  PaymentSummary::state(false, 1000000, 300000,  true,  false), 'a balance receipt with the team is awaiting even after a deposit, because that is the thing to say first');
okv_test_eq('declined',  PaymentSummary::state(false, 1000000, 0,       false, true),  'a declined receipt with nothing received says so');
okv_test_eq('part_paid', PaymentSummary::state(false, 1000000, 300000,  false, true),  'a declined balance receipt after a deposit is still part paid, which is the truer word');
okv_test_eq('awaiting',  PaymentSummary::state(false, 1000000, 0,       true,  true),  'a newer receipt in wins over an older declined one');
okv_test_eq('cancelled', PaymentSummary::state(true,  1000000, 1000000, false, false), 'a cancelled order is cancelled whatever was paid');
okv_test_eq('cancelled', PaymentSummary::state(true,  1000000, 0,       true,  false), 'a cancelled order with a receipt waiting is still cancelled');
okv_test_eq('unpaid',    PaymentSummary::state(false, 0,       0,       false, false), 'a zero total order is not paid just because zero is received');

// -----------------------------------------------------------------------------
// Still due
// -----------------------------------------------------------------------------
okv_test_eq(700000, PaymentSummary::stillDue(1000000, 300000),  'a 30% deposit leaves 70% due');
okv_test_eq(0,      PaymentSummary::stillDue(1000000, 1000000), 'nothing due once paid');
okv_test_eq(0,      PaymentSummary::stillDue(1000000, 1500000), 'never a negative amount due');
okv_test_eq(1000000, PaymentSummary::stillDue(1000000, 0),      'everything due when nothing is received');

// -----------------------------------------------------------------------------
// A line's own status
// -----------------------------------------------------------------------------
okv_test_eq('paid',      PaymentSummary::lineStatus(300000, 300000, false, false), 'a settled line is paid');
okv_test_eq('part_paid', PaymentSummary::lineStatus(300000, 100000, false, false), 'a line with part of its amount is part paid');
okv_test_eq('awaiting',  PaymentSummary::lineStatus(300000, 0,      true,  false), 'a line with a receipt waiting is awaiting');
okv_test_eq('declined',  PaymentSummary::lineStatus(300000, 0,      false, true),  'a line whose receipt was declined is declined');
okv_test_eq('unpaid',    PaymentSummary::lineStatus(300000, 0,      false, false), 'an untouched line is unpaid');
okv_test_eq('unpaid',    PaymentSummary::lineStatus(0,      0,      false, false), 'a zero line is not paid');

// -----------------------------------------------------------------------------
// How it was paid
// -----------------------------------------------------------------------------
okv_test_eq('Bank transfer',             PaymentSummary::methodLabel('manual', 'transfer'),       'a manual transfer reads as Bank transfer');
okv_test_eq('Cash',                      PaymentSummary::methodLabel('manual', 'cash'),           'a manual cash payment reads as Cash');
okv_test_eq('Recorded by our team',      PaymentSummary::methodLabel('manual', ''),               'a manual payment with no channel is still explained');
okv_test_eq('Card on Paystack',          PaymentSummary::methodLabel('paystack', 'card'),         'a card charge reads as Card on Paystack');
okv_test_eq('Bank transfer on Paystack', PaymentSummary::methodLabel('paystack', 'bank_transfer'), 'a Paystack transfer is told apart from a direct one');
okv_test_eq('USSD on Paystack',          PaymentSummary::methodLabel('paystack', 'ussd'),         'a USSD charge reads as USSD on Paystack');
okv_test_eq('Paystack',                  PaymentSummary::methodLabel('paystack', ''),             'a Paystack charge with no channel is just Paystack');
okv_test_eq('Paystack',                  PaymentSummary::methodLabel('paystack', 'something_new'), 'a channel Paystack adds later does not show raw');
okv_test_eq('Business credit',           PaymentSummary::methodLabel('account', ''),              'an on-account payment reads as Business credit');

// -----------------------------------------------------------------------------
// Words. The banned-word scan itself is scripts/brand-check.sh, which reads this class.
// -----------------------------------------------------------------------------
okv_test_eq('Payment received',              PaymentSummary::headline('paid'),      'paid leads with Payment received');
okv_test_eq('Payment received',              PaymentSummary::headline('part_paid'), 'part paid also leads with Payment received, because money did arrive');
okv_test_eq('Payment pending verification',  PaymentSummary::headline('awaiting'),  'awaiting says pending verification');
okv_test_eq('We could not confirm your transfer', PaymentSummary::headline('declined'), 'declined says so plainly');

okv_test_eq('Nothing is left to pay on this order.', PaymentSummary::detailLine('paid', 0), 'paid says nothing is left');
okv_test_eq('You still owe ₦7,000 on this order.',   PaymentSummary::detailLine('part_paid', 700000), 'part paid names the amount still owed, with the naira symbol and comma');
okv_test_eq('You owe ₦10,000 on this order.',        PaymentSummary::detailLine('unpaid', 1000000), 'unpaid names the whole amount');
okv_test_ok(str_contains(PaymentSummary::detailLine('awaiting', 0), 'checking it'), 'awaiting says the team is checking it');
okv_test_ok(str_contains(PaymentSummary::detailLine('declined', 0), 'clearer receipt'), 'declined says what to do next');

foreach (['paid', 'part_paid', 'awaiting', 'declined', 'unpaid', 'cancelled'] as $state) {
    $text = PaymentSummary::headline($state) . ' ' . PaymentSummary::detailLine($state, 500000);
    okv_test_ok(!str_contains($text, "\u{2014}"), "the '$state' copy has no em dash");
}
