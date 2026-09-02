<?php
/**
 * scripts/tests/delivery_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery reads against a real database. The eligibility rule is
 * covered by DeliveryTest.php; this checks the reads it depends on line up with
 * the reference seed: the household delivery days (Mon, Wed, Thu, Sat), the
 * business days (Tue, Fri), the active Lagos zones, and that the next eligible
 * dates come back only on allowed weekdays.
 *
 *   php scripts/tests/delivery_db_test.php
 *
 * Read-only. It never writes, so it is safe to run against any migrated and
 * seeded database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Settings.php';
require_once $root . '/includes/classes/Delivery.php';
require_once $root . '/includes/functions/helpers.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0;
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}

// --- Seeded allowed days -----------------------------------------------------
$household = Delivery::rulesByDay('household');
t_ok(!empty($household[1]) && !empty($household[1]['is_active']), 'household delivers on Monday');
t_ok(!empty($household[3]), 'household delivers on Wednesday');
t_ok(!empty($household[4]), 'household delivers on Thursday');
t_ok(!empty($household[6]), 'household delivers on Saturday');
t_ok(empty($household[2]), 'household does not deliver on Tuesday');

$business = Delivery::rulesByDay('business');
t_ok(!empty($business[2]) && !empty($business[5]), 'business delivers on Tuesday and Friday');

// --- Active zones ------------------------------------------------------------
$zones = Delivery::zonesActive();
t_ok(count($zones) > 0, 'at least one Lagos zone is active');
$allActive = true;
foreach ($zones as $zone) {
    if (!Database::one('SELECT id FROM delivery_zones WHERE id = :id AND is_active = 1', [':id' => (int) $zone['id']])) {
        $allActive = false;
    }
}
t_ok($allActive, 'every zone returned is active');

// --- Next eligible dates land only on allowed weekdays -----------------------
$dates = Delivery::nextEligibleDates('household', 4);
t_ok(count($dates) > 0, 'the picker offers at least one household delivery day');
$onlyAllowed = true;
foreach ($dates as $date) {
    $iso = (int) (new DateTimeImmutable($date['date'], new DateTimeZone('Africa/Lagos')))->format('N');
    if (!in_array($iso, [1, 3, 4, 6], true)) {
        $onlyAllowed = false;
    }
}
t_ok($onlyAllowed, 'every offered day is a seeded household delivery weekday');

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} database assertions passed.\n");
exit($GLOBALS['p'] === $GLOBALS['t'] ? 0 : 1);
