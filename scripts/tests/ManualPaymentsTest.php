<?php
/**
 * scripts/tests/ManualPaymentsTest.php
 * Money recorded by a person, which is the one kind nothing outside the
 * building confirms. The evidence rule and the double-submit guard are the two
 * assertions that carry real weight here.
 */

// -----------------------------------------------------------------------------
// Method
// -----------------------------------------------------------------------------
okv_test_ok(ManualPayments::methodIsValid('cash'),     'cash is a method');
okv_test_ok(ManualPayments::methodIsValid('transfer'), 'transfer is a method');
okv_test_ok(!ManualPayments::methodIsValid('card'),    'card is not a manual method, it goes through Paystack');
okv_test_ok(!ManualPayments::methodIsValid(''),        'an empty method is refused');
okv_test_eq(2, count(ManualPayments::METHODS),          'there are exactly two manual methods');

// -----------------------------------------------------------------------------
// Evidence. A transfer always leaves something; cash never does.
// -----------------------------------------------------------------------------
okv_test_ok(ManualPayments::evidenceIsSufficient('transfer', 'FT2609021234', false), 'a transfer with a bank reference is enough');
okv_test_ok(ManualPayments::evidenceIsSufficient('transfer', null, true),            'a transfer with a screenshot is enough');
okv_test_ok(ManualPayments::evidenceIsSufficient('transfer', 'FT2609021234', true),  'a transfer with both is enough');
okv_test_ok(!ManualPayments::evidenceIsSufficient('transfer', null, false),          'a transfer with neither is refused');
okv_test_ok(!ManualPayments::evidenceIsSufficient('transfer', '   ', false),         'whitespace is not a bank reference');
okv_test_ok(ManualPayments::evidenceIsSufficient('cash', null, false),               'cash needs no evidence, a photo of a banknote proves nothing');
okv_test_ok(ManualPayments::evidenceIsSufficient('cash', null, true),                'cash may still carry a note if there is one');

// -----------------------------------------------------------------------------
// The reference, which is also the double-submit guard
// -----------------------------------------------------------------------------
$token = ManualPayments::newToken();
$ref   = ManualPayments::reference('OKV26000123', $token);

okv_test_ok(Paystack::isValidReference($ref), 'a manual reference is legal under the Paystack charset, like every other reference');
okv_test_ok(str_starts_with($ref, 'OKV26000123-M-'), 'the reference says which order it belongs to and that it is manual');
okv_test_eq(
    ManualPayments::reference('OKV26000123', $token),
    ManualPayments::reference('OKV26000123', $token),
    'the same form token yields the same reference, which is what makes a double submit collide rather than credit twice'
);
okv_test_ok(
    ManualPayments::reference('OKV26000123', ManualPayments::newToken())
        !== ManualPayments::reference('OKV26000123', ManualPayments::newToken()),
    'two separate recordings get separate references'
);
okv_test_ok(Paystack::isValidReference(ManualPayments::reference('OKV-26/000 123', $token)), 'a messy order number still mints a legal reference');
okv_test_eq(16, strlen(ManualPayments::newToken()), 'a form token is 16 hex characters');
okv_test_ok(ManualPayments::newToken() !== ManualPayments::newToken(), 'form tokens do not repeat');

// A manual reference must never collide with a Paystack one, or the double
// submit guard would fire on an unrelated charge.
okv_test_ok(
    ManualPayments::reference('OKV26000123', $token) !== Payments::reference('OKV26000123', 1),
    'a manual reference cannot collide with a Paystack reference for the same order'
);

// -----------------------------------------------------------------------------
// What an amount does to the balance
// -----------------------------------------------------------------------------
$outstanding = 1183000; // 11,830 naira, the balance on a 30 percent deposit order

okv_test_eq('part',    ManualPayments::outcomeKind(500000,  $outstanding), 'less than the balance is a part payment');
okv_test_eq('settles', ManualPayments::outcomeKind(1183000, $outstanding), 'exactly the balance settles it');
okv_test_eq('over',    ManualPayments::outcomeKind(1200000, $outstanding), 'more than the balance is an overpayment');
okv_test_eq('settles', ManualPayments::outcomeKind(0, 0),                  'nothing outstanding and nothing paid still reads as settled');

// Part payments have to add up to the whole, with no kobo invented or lost.
$first  = 500000;
$second = Money::balance($outstanding, $first);
okv_test_eq($outstanding, $first + $second, 'two part payments sum to the balance exactly');
okv_test_eq('settles', ManualPayments::outcomeKind($second, $second), 'the second part payment settles the rest');

// -----------------------------------------------------------------------------
// The confirmation copy the recorder has to read before committing
// -----------------------------------------------------------------------------
$partLine = ManualPayments::confirmationLine(500000, $outstanding);
okv_test_ok(str_contains($partLine, '₦5,000'),  'the confirmation names what is being recorded');
okv_test_ok(str_contains($partLine, '₦6,830'),  'the confirmation names what will still be outstanding');

$settleLine = ManualPayments::confirmationLine($outstanding, $outstanding);
okv_test_ok(str_contains($settleLine, 'settles the payment in full'), 'settling in full says so plainly');

$overLine = ManualPayments::confirmationLine(1200000, $outstanding);
okv_test_ok(str_contains($overLine, 'overpaid'),  'an overpayment is called an overpayment before it is recorded');
okv_test_ok(str_contains($overLine, '₦170'),      'an overpayment names the excess');

// -----------------------------------------------------------------------------
// Status vocabularies stay distinct, since both live on different tables
// -----------------------------------------------------------------------------
okv_test_ok(ManualPayments::PROOF_PENDING !== ManualPayments::REVERSAL_REQUESTED, 'a pending proof and a requested reversal are different states');
okv_test_eq('reversed', ManualPayments::PROOF_REVERSED, 'an approved reversal marks the proof reversed, never deletes it');
