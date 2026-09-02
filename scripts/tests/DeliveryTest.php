<?php
/**
 * scripts/tests/DeliveryTest.php
 * OK Veggies. Delivery-day eligibility: the weekday rule, the minimum lead, the
 * cutoff on the day before, and dated exceptions. Pure, evaluated against a
 * fixed "now" so the result never depends on the day the test is run. The zone
 * and rule reads are covered by scripts/tests/delivery_db_test.php.
 */

$tz = new DateTimeZone('Africa/Lagos');
$isoOf = static function (string $date) use ($tz): int {
    return (int) (new DateTimeImmutable($date, $tz))->format('N');
};

// A morning well before the 16:00 cutoff.
$morning = new DateTimeImmutable('2026-09-07 09:00:00', $tz);
// The same day, after the cutoff.
$evening = new DateTimeImmutable('2026-09-07 17:00:00', $tz);

$today    = '2026-09-07';
$tomorrow = '2026-09-08';
$later    = '2026-09-10';

$activeRule = ['is_active' => 1, 'cutoff_time' => '16:00:00', 'minimum_lead_days' => 1];

// A day that is active, clears the one-day lead and is before the cutoff.
$rules = [$isoOf($later) => $activeRule];
$ok = Delivery::eligibleFromRules($later, $rules, null, $morning);
okv_test_ok($ok['eligible'], 'an active day, past the lead and before the cutoff, is eligible');

// Tomorrow, ordered in the morning, is in time.
$rules = [$isoOf($tomorrow) => $activeRule];
$inTime = Delivery::eligibleFromRules($tomorrow, $rules, null, $morning);
okv_test_ok($inTime['eligible'], 'tomorrow is eligible when ordered before the cutoff today');

// Tomorrow, ordered after the cutoff, is too late.
$late = Delivery::eligibleFromRules($tomorrow, $rules, null, $evening);
okv_test_ok(!$late['eligible'], 'tomorrow is refused once the cutoff has passed');
okv_test_ok(str_contains($late['reason'], 'cutoff'), 'the refusal explains the cutoff');

// Today fails the minimum lead of one day.
$rulesToday = [$isoOf($today) => $activeRule];
$noLead = Delivery::eligibleFromRules($today, $rulesToday, null, $morning);
okv_test_ok(!$noLead['eligible'], 'today is refused because it does not clear the lead time');

// A weekday with no active rule is not offered.
$wrongDay = Delivery::eligibleFromRules($later, [], null, $morning);
okv_test_ok(!$wrongDay['eligible'], 'a day with no delivery rule is refused');
okv_test_ok(str_contains($wrongDay['reason'], 'do not deliver'), 'the refusal says we do not deliver that day');

// A closed exception shuts an otherwise open day and carries a replacement.
$closed = ['is_available' => 0, 'reason' => 'Public holiday', 'replacement_date' => '2026-09-11'];
$blocked = Delivery::eligibleFromRules($later, [$isoOf($later) => $activeRule], $closed, $morning);
okv_test_ok(!$blocked['eligible'], 'a closed exception shuts an open day');
okv_test_eq('Public holiday', $blocked['reason'], 'the exception reason is shown to the customer');
okv_test_eq('2026-09-11', $blocked['replacement_date'], 'a replacement date is carried through');

// A dated exception can open a day the weekday rule keeps closed.
$open = ['is_available' => 1, 'reason' => '', 'replacement_date' => null];
$forced = Delivery::eligibleFromRules($later, [], $open, $morning);
okv_test_ok($forced['eligible'], 'a forced-open exception opens a normally closed day');

// A malformed date is refused before anything else.
$bad = Delivery::eligibleFromRules('not-a-date', $rules, null, $morning);
okv_test_ok(!$bad['eligible'], 'a malformed date is refused');
okv_test_ok(str_contains($bad['reason'], 'valid delivery date'), 'the refusal asks for a valid date');
