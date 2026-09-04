<?php
/**
 * scripts/tests/CancellationTest.php
 * When an order may be cancelled and what happens to the money. The flow is M6;
 * these are the rules it will read, and they are money rules, so they are
 * pinned here in M5.
 */

$lagos = new DateTimeZone('Africa/Lagos');
$at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when, $lagos);

// -----------------------------------------------------------------------------
// The deadline: the cutoff time on the day BEFORE delivery, in Lagos time
// -----------------------------------------------------------------------------
$deadline = Cancellation::deadline('2026-09-10', '18:00');
okv_test_ok($deadline instanceof DateTimeImmutable,        'a valid date and time yields a deadline');
okv_test_eq('2026-09-09 18:00', $deadline->format('Y-m-d H:i'), 'the deadline is the cutoff on the day before delivery');
okv_test_eq('Africa/Lagos', $deadline->getTimezone()->getName(), 'the deadline is in Lagos time, not the server default');

okv_test_eq(null, Cancellation::deadline('not-a-date', '18:00'), 'an unreadable date has no deadline');
okv_test_eq(null, Cancellation::deadline('2026-09-10', '25:00'), 'an impossible time has no deadline');
okv_test_eq(null, Cancellation::deadline('2026-09-10', 'six'),   'a non-time has no deadline');

// -----------------------------------------------------------------------------
// Inside and outside the cutoff
// -----------------------------------------------------------------------------
okv_test_ok(Cancellation::isWithinCutoff('2026-09-10', '18:00', $at('2026-09-09 17:59')),  'a minute before the cutoff is inside');
okv_test_ok(!Cancellation::isWithinCutoff('2026-09-10', '18:00', $at('2026-09-09 18:00')), 'the cutoff moment itself is outside');
okv_test_ok(!Cancellation::isWithinCutoff('2026-09-10', '18:00', $at('2026-09-09 18:01')), 'a minute after the cutoff is outside');
okv_test_ok(Cancellation::isWithinCutoff('2026-09-10', '18:00', $at('2026-09-01 09:00')),  'a week ahead is comfortably inside');
okv_test_ok(!Cancellation::isWithinCutoff('2026-09-10', '18:00', $at('2026-09-10 08:00')), 'the morning of delivery is outside');

// An unreadable date must never read as a licence to cancel late.
okv_test_ok(!Cancellation::isWithinCutoff('rubbish', '18:00', $at('2026-09-01 09:00')), 'a bad date refuses rather than allows');

// -----------------------------------------------------------------------------
// Who may cancel
// -----------------------------------------------------------------------------
okv_test_ok(Cancellation::customerMayCancel('pending', 'unpaid', true, true),   'an unpaid order before the cutoff is the customer to cancel');
okv_test_ok(!Cancellation::customerMayCancel('pending', 'unpaid', false, true), 'after the cutoff it stops being self service');
okv_test_ok(!Cancellation::customerMayCancel('pending', 'paid', true, true),    'once money has been paid it is a staff decision');
okv_test_ok(!Cancellation::customerMayCancel('pending', 'part_paid', true, true), 'a deposit already paid is a staff decision');
okv_test_ok(!Cancellation::customerMayCancel('pending', 'unpaid', true, false), 'the setting can switch self service off entirely');
okv_test_ok(!Cancellation::customerMayCancel('delivered', 'unpaid', true, true), 'a delivered order cannot be cancelled');
okv_test_ok(!Cancellation::customerMayCancel('cancelled', 'unpaid', true, true), 'a cancelled order cannot be cancelled again');

okv_test_ok(Cancellation::staffMayCancel('pending'),    'staff may cancel a pending order');
okv_test_ok(Cancellation::staffMayCancel('confirmed'),  'staff may cancel a confirmed order');
okv_test_ok(!Cancellation::staffMayCancel('delivered'), 'nobody cancels a delivered order');
okv_test_ok(!Cancellation::staffMayCancel('cancelled'), 'nobody cancels it twice');

// -----------------------------------------------------------------------------
// What happens to the money. The commercial rule.
// -----------------------------------------------------------------------------
$deposit = 507000;   // 30 percent of 16,900
$full    = 1690000;

$before = Cancellation::moneyOutcome($deposit, $deposit, true, true);
okv_test_eq($deposit, $before['refund_subunit'],  'cancelling before the cutoff returns the deposit in full');
okv_test_eq(0,        $before['forfeit_subunit'], 'nothing is kept before the cutoff');

$after = Cancellation::moneyOutcome($deposit, $deposit, false, true);
okv_test_eq(0,        $after['refund_subunit'],  'cancelling after the cutoff keeps the deposit');
okv_test_eq($deposit, $after['forfeit_subunit'], 'the whole deposit is what is kept');
okv_test_eq('deposit_kept', $after['reason'],    'the reason names the rule');

// Anything paid beyond the deposit always goes back: we protect the committed
// cost, we do not keep the whole order.
$paidInFull = Cancellation::moneyOutcome($full, $deposit, false, true);
okv_test_eq($full - $deposit, $paidInFull['refund_subunit'],  'a fully paid order gets everything above the deposit back');
okv_test_eq($deposit,         $paidInFull['forfeit_subunit'], 'only the deposit is kept');
okv_test_eq($full, $paidInFull['refund_subunit'] + $paidInFull['forfeit_subunit'], 'refund plus forfeit is exactly what was paid');

// The setting can turn forfeiting off entirely.
$lenient = Cancellation::moneyOutcome($deposit, $deposit, false, false);
okv_test_eq($deposit, $lenient['refund_subunit'], 'with forfeiting off, a late cancellation still returns everything');
okv_test_eq('full_refund', $lenient['reason'],    'and says so');

// Nothing paid, nothing to argue about.
$nothing = Cancellation::moneyOutcome(0, $deposit, false, true);
okv_test_eq(0, $nothing['refund_subunit'],  'nothing paid means nothing refunded');
okv_test_eq(0, $nothing['forfeit_subunit'], 'nothing paid means nothing kept');
okv_test_eq('nothing_paid', $nothing['reason'], 'and the reason says so');

// A deposit larger than what was actually paid cannot forfeit more than exists.
$odd = Cancellation::moneyOutcome(100000, 507000, false, true);
okv_test_eq(0,      $odd['refund_subunit'],  'a part paid deposit is kept in full when it is smaller than the rule');
okv_test_eq(100000, $odd['forfeit_subunit'], 'but never more than was actually paid');

// -----------------------------------------------------------------------------
// The copy, which is what makes the rule fair rather than a nasty surprise
// -----------------------------------------------------------------------------
$strict = Cancellation::policyLine('18:00', true);
okv_test_ok(str_contains($strict, '18:00'),           'the checkout line names the cutoff time');
okv_test_ok(str_contains($strict, 'not returned'),    'the checkout line says a deposit is kept, before the customer pays');

$soft = Cancellation::policyLine('18:00', false);
okv_test_ok(str_contains($soft, 'still return'),      'with forfeiting off the copy promises the money back');

okv_test_ok(str_contains(Cancellation::staffSummary($after), 'keep'),        'the staff summary says what is kept');
okv_test_ok(str_contains(Cancellation::staffSummary($before), 'in full'),    'the staff summary says when it is a full refund');
okv_test_ok(str_contains(Cancellation::staffSummary($nothing), 'nothing to refund'), 'the staff summary handles nothing paid');

// --- Cancelling an order that is already on the van ---------------------------
// The client's rule: a dispatched order can still be cancelled, but the terms
// are not the same as the day before, because the produce is bought and the van
// has run.

// Self service stops the moment an order is packed. A pay-on-delivery order can
// be unpaid and already at the customer's gate, and before this check that
// combination let someone cancel produce that had left the building.
foreach (['pending', 'confirmed'] as $stage) {
    okv_test_ok(
        Cancellation::customerMayCancel($stage, Payments::STATUS_UNPAID, true, true),
        "a customer may still cancel a $stage order themselves"
    );
}
foreach (['packed', 'dispatched'] as $stage) {
    okv_test_ok(
        !Cancellation::customerMayCancel($stage, Payments::STATUS_UNPAID, true, true),
        "a customer may not self cancel a $stage order, even unpaid and inside the cutoff"
    );
}

// Staff may, unless the business has switched it off.
okv_test_ok(Cancellation::staffMayCancel('dispatched'), 'staff may cancel a dispatched order by default');
okv_test_ok(!Cancellation::staffMayCancel('dispatched', false), 'a business can switch off cancelling after dispatch');
okv_test_ok(Cancellation::staffMayCancel('packed', false), 'switching off dispatch cancellation does not touch a packed order');
okv_test_ok(!Cancellation::staffMayCancel('delivered', true), 'a delivered order is still closed to everyone');
okv_test_ok(Cancellation::isDispatched('dispatched'), 'dispatch is recognised');
okv_test_ok(!Cancellation::isDispatched('packed'), 'a packed order has not been dispatched');

// The money. A dispatched cancellation keeps the deposit even inside the cutoff,
// because dispatch is a stronger fact than the clock.
$dispatchedInside = Cancellation::moneyOutcome(1200000, 300000, true, true, 'dispatched', true);
okv_test_eq(900000, $dispatchedInside['refund_subunit'], 'a dispatched cancellation refunds everything above the deposit');
okv_test_eq(300000, $dispatchedInside['forfeit_subunit'], 'a dispatched cancellation keeps the deposit inside the cutoff');
okv_test_eq('deposit_kept_dispatched', $dispatchedInside['reason'], 'the reason names dispatch rather than the cutoff');

$pendingInside = Cancellation::moneyOutcome(1200000, 300000, true, true, 'pending', true);
okv_test_eq(1200000, $pendingInside['refund_subunit'], 'the same order inside the cutoff before dispatch is refunded in full');
okv_test_eq(0, $pendingInside['forfeit_subunit'], 'nothing is kept before dispatch inside the cutoff');

// The dispatch rule is its own setting. A business that has chosen to always
// refund in full is entitled to mean it, so switching the dispatch rule off
// must not be silently overridden.
$dispatchedGenerous = Cancellation::moneyOutcome(1200000, 300000, true, true, 'dispatched', false);
okv_test_eq(1200000, $dispatchedGenerous['refund_subunit'], 'with the dispatch rule off, a dispatched cancellation refunds in full inside the cutoff');
okv_test_eq(0, $dispatchedGenerous['forfeit_subunit'], 'with the dispatch rule off, nothing extra is kept for the dispatch');

// Outside the cutoff the ordinary rule still applies with the dispatch rule off.
$dispatchedLateGenerous = Cancellation::moneyOutcome(1200000, 300000, false, true, 'dispatched', false);
okv_test_eq(300000, $dispatchedLateGenerous['forfeit_subunit'], 'the cutoff rule still runs when the dispatch rule is off');
okv_test_eq('deposit_kept', $dispatchedLateGenerous['reason'], 'that forfeit is attributed to the cutoff, not to dispatch');

// Nothing paid is still nothing to keep, whatever the stage.
$nothingDispatched = Cancellation::moneyOutcome(0, 300000, false, true, 'dispatched', true);
okv_test_eq(0, $nothingDispatched['forfeit_subunit'], 'a dispatched cancellation of an unpaid order keeps nothing');
okv_test_eq('nothing_paid', $nothingDispatched['reason'], 'an unpaid dispatched order says nothing was paid');

// A deposit larger than what was actually paid never keeps more than was taken.
$overDeposit = Cancellation::moneyOutcome(100000, 300000, true, true, 'dispatched', true);
okv_test_eq(0, $overDeposit['refund_subunit'], 'a part payment smaller than the deposit is kept in full, not more');
okv_test_eq(100000, $overDeposit['forfeit_subunit'], 'the forfeit never exceeds what the customer actually paid');

// The words. These are what a customer reads, so each branch is checked.
$terms = Cancellation::termsLine('dispatched', '18:00', true, true, true);
okv_test_ok(str_contains($terms, 'on the way'), 'the dispatched terms say the order is on the way');
okv_test_ok(str_contains($terms, 'deposit is kept'), 'the dispatched terms say the deposit is kept');
okv_test_ok(!str_contains($terms, "\u{2014}"), 'the dispatched terms carry no em dash');

$termsGenerous = Cancellation::termsLine('dispatched', '18:00', true, true, false);
okv_test_ok(str_contains($termsGenerous, 'return anything you have paid'), 'with the dispatch rule off the terms promise the money back');

$termsRefused = Cancellation::termsLine('dispatched', '18:00', true, false, true);
okv_test_ok(str_contains($termsRefused, 'driver'), 'when cancelling after dispatch is off the customer is pointed at the driver');

okv_test_ok(str_contains(Cancellation::termsLine('packed', '18:00', true), 'packed'), 'a packed order says it is packed');
okv_test_ok(str_contains(Cancellation::termsLine('delivered', '18:00', true), 'finished'), 'a delivered order says there is nothing to cancel');
okv_test_ok(str_contains(Cancellation::termsLine('pending', '18:00', true), '18:00'), 'a pending order names the cutoff time');

// The checkout line has to carry the dispatch rule too, or the first a customer
// hears of it is the refund figure.
$checkout = Cancellation::policyLine('18:00', true, true, true);
okv_test_ok(str_contains($checkout, 'dispatched'), 'the checkout policy line covers a cancellation after dispatch');
okv_test_ok(str_contains($checkout, 'deposit is kept'), 'the checkout policy line says the deposit is kept after dispatch');
okv_test_ok(str_contains(Cancellation::policyLine('18:00', true, false, true), 'cannot be cancelled here'), 'with dispatch cancellation off, checkout says so');
okv_test_ok(!str_contains($checkout, "\u{2014}"), 'the checkout policy line carries no em dash');

// Why the deposit was kept, in the customer's words.
okv_test_ok(str_contains(Cancellation::forfeitReason('deposit_kept_dispatched'), 'dispatched'), 'a dispatch forfeit is explained by the dispatch');
okv_test_ok(str_contains(Cancellation::forfeitReason('deposit_kept'), 'cutoff'), 'a late forfeit is explained by the cutoff');

// Staff read the figures with the reason attached.
okv_test_ok(
    str_contains(Cancellation::staffSummary($dispatchedInside), 'already been dispatched'),
    'the staff summary says when the deposit is kept because of dispatch'
);
okv_test_ok(
    !str_contains(Cancellation::staffSummary(Cancellation::moneyOutcome(1200000, 300000, false, true, 'pending', true)), 'dispatched'),
    'an ordinary late forfeit is not described as a dispatch'
);

// The server, not the form, is what enforces the acknowledgement.
$service = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/OrderCancellation.php');
okv_test_ok(str_contains($service, 'dispatch_terms_required'), 'cancelling a dispatched order needs the terms acknowledged');
okv_test_ok(
    strpos($service, 'dispatch_terms_required') > strpos($service, 'FOR UPDATE'),
    'the acknowledgement is checked under the row lock, not only in the form'
);
