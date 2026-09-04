<?php
/**
 * scripts/tests/RefundsTest.php
 * Money going back to a customer. A refund cannot be undone, so the amount
 * guard and the state machine are the assertions that carry the weight.
 */

// -----------------------------------------------------------------------------
// What is left to refund
// -----------------------------------------------------------------------------
okv_test_eq(1690000, Refunds::refundableAmount(1690000, 0),       'nothing refunded yet leaves the whole payment');
okv_test_eq(1183000, Refunds::refundableAmount(1690000, 507000),  'a part refund leaves the rest');
okv_test_eq(0,       Refunds::refundableAmount(1690000, 1690000), 'a full refund leaves nothing');
okv_test_eq(0,       Refunds::refundableAmount(1690000, 1800000), 'over refunding never goes negative');
okv_test_eq(0,       Refunds::refundableAmount(0, 0),             'nothing paid is nothing refundable');

// -----------------------------------------------------------------------------
// The amount guard, which is what stops a customer being paid twice
// -----------------------------------------------------------------------------
okv_test_ok(Refunds::amountIsValid(507000, 1690000),   'a part refund inside the balance is allowed');
okv_test_ok(Refunds::amountIsValid(1690000, 1690000),  'refunding exactly what is left is allowed');
okv_test_ok(!Refunds::amountIsValid(1690001, 1690000), 'one kobo more than is left is refused');
okv_test_ok(!Refunds::amountIsValid(0, 1690000),       'a zero refund is refused');
okv_test_ok(!Refunds::amountIsValid(-500, 1690000),    'a negative refund is refused');
okv_test_ok(!Refunds::amountIsValid(100, 0),           'nothing can be refunded when nothing is left');

// Partial refunds must sum to no more than the payment, however they are split.
$paid  = 1690000;
$first = 500000;
okv_test_ok(Refunds::amountIsValid($first, $paid), 'the first partial is allowed');
$left  = Refunds::refundableAmount($paid, $first);
okv_test_eq(1190000, $left, 'the balance after the first partial');
okv_test_ok(Refunds::amountIsValid($left, $left),      'the second partial can take exactly the rest');
okv_test_ok(!Refunds::amountIsValid($left + 1, $left), 'the second partial cannot exceed the rest');
okv_test_eq($paid, $first + $left, 'two partials sum to the payment exactly, no kobo invented');

// -----------------------------------------------------------------------------
// Paystack's four states, mapped from the event names in their events table
// -----------------------------------------------------------------------------
okv_test_eq('pending',    Refunds::statusFromEvent('refund.pending'),    'refund.pending maps to pending');
okv_test_eq('processing', Refunds::statusFromEvent('refund.processing'), 'refund.processing maps to processing');
okv_test_eq('processed',  Refunds::statusFromEvent('refund.processed'),  'refund.processed maps to processed');
okv_test_eq('failed',     Refunds::statusFromEvent('refund.failed'),     'refund.failed maps to failed');
okv_test_eq(null,         Refunds::statusFromEvent('charge.success'),    'a charge event is not a refund event');
okv_test_eq(null,         Refunds::statusFromEvent('transfer.success'),  'a transfer event is not a refund event');
okv_test_eq(4, count(Refunds::EVENT_STATUS),                             'all four documented refund events are handled');

// -----------------------------------------------------------------------------
// Terminal states. A refund that has landed does not move again.
// -----------------------------------------------------------------------------
okv_test_ok(Refunds::isTerminal('processed'),   'a processed refund is finished');
okv_test_ok(Refunds::isTerminal('failed'),      'a failed refund is finished');
okv_test_ok(!Refunds::isTerminal('pending'),    'a pending refund is still moving');
okv_test_ok(!Refunds::isTerminal('processing'), 'a processing refund is still moving');
okv_test_ok(!Refunds::isTerminal('requested'),  'a requested refund is still moving');

// -----------------------------------------------------------------------------
// What the customer reads. The failed case is the one that matters.
// -----------------------------------------------------------------------------
okv_test_ok(str_contains(Refunds::customerStatusLine('processed'), 'has been sent'),  'a processed refund says it has been sent');
okv_test_ok(str_contains(Refunds::customerStatusLine('processing'), 'your bank'),     'a processing refund says where it is');
okv_test_ok(str_contains(Refunds::customerStatusLine('failed'), 'did not go through'), 'a failed refund says so plainly');
okv_test_ok(str_contains(Refunds::customerStatusLine('failed'), 'be in touch'),        'a failed refund says what happens next, rather than leaving the customer waiting');

// No gateway vocabulary reaches the customer.
foreach (['requested', 'pending', 'processing', 'processed', 'failed'] as $state) {
    $line = Refunds::customerStatusLine($state);
    okv_test_ok(!str_contains(strtolower($line), 'paystack'), 'the customer line for ' . $state . ' does not name the gateway');
    okv_test_ok($line !== '', 'every refund state has customer copy: ' . $state);
}

// -----------------------------------------------------------------------------
// Staff attention
// -----------------------------------------------------------------------------
okv_test_ok(Refunds::needsAttention('failed'),      'a failed refund needs a human');
okv_test_ok(!Refunds::needsAttention('processed'),  'a processed refund needs nobody');
okv_test_ok(!Refunds::needsAttention('processing'), 'a refund still moving needs nobody yet');

// --- Reading the transaction reference out of a refund webhook ----------------
// Paystack sends `transaction` as an object, not a string. Casting it produced
// the literal "Array", so the fallback lookup searched for a refund whose
// reference was "Array" and never matched. It only bites when the gateway id
// lookup misses, which is the one case the fallback exists for.
$reference = static function (array $payload): string {
    $method = new ReflectionMethod('Refunds', 'referenceFromWebhook');
    $method->setAccessible(true);
    return (string) $method->invoke(null, $payload);
};

okv_test_eq('OKV123', $reference(['transaction' => ['reference' => 'OKV123']]), 'the reference is read out of the transaction object Paystack actually sends');
okv_test_eq('OKV123', $reference(['transaction' => 'OKV123']), 'a plain string transaction is still read');
okv_test_eq('OKV123', $reference(['transaction_reference' => 'OKV123']), 'an explicit transaction_reference wins');
okv_test_eq('OKV123', $reference(['reference' => 'OKV123']), 'a top level reference is read');
okv_test_eq('OKV123', $reference(['transaction_reference' => '  OKV123  ']), 'a padded reference is trimmed');
okv_test_eq('', $reference(['transaction' => ['id' => 42]]), 'a transaction object with no reference yields nothing, not a guess');
okv_test_eq('', $reference([]), 'an empty payload yields no reference');
okv_test_eq('', $reference(['transaction' => ['reference' => ['nested']]]), 'a nested array is refused rather than stringified');
okv_test_ok(
    $reference(['transaction' => ['reference' => 'OKV123']]) !== 'Array',
    'the reference is never the string Array'
);
