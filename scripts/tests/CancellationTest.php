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
