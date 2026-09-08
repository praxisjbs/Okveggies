<?php
/**
 * scripts/tests/KitchenRunsTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run rules that hold without a database (PRD Section 8).
 * The persistence and HTTP halves live beside this file in
 * kitchen_runs_db_test.php and kitchen_runs_http_test.php.
 *
 * The first block below is unusual and deliberate. The first attempt at this
 * milestone shipped six public rules with forty passing assertions between
 * them, and not one of the six was called by any production path: the upload
 * whitelist, the state map, the quote expiry, the submission validator, the
 * customer-edit rule and the notification list were all tested and all inert.
 * The suite was green and the feature did not work. So before anything else is
 * asserted, this file proves every public rule is actually wired to something.
 * A rule nothing calls is not covered by a test, it is decorated by one.
 * -----------------------------------------------------------------------------
 */

$okvRoot = dirname(__DIR__, 2);

// ---------------------------------------------------------------------------
// 0. Every rule is wired. Nothing here exists only to be asserted against.
// ---------------------------------------------------------------------------

/** Every shipped PHP file except the tests themselves. */
$okvSource = static function (string $root): string {
    $text = '';
    $dirs = ['/includes', '/api', '/admin', '/pro', '/public', '/scripts'];
    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || str_contains($path, '/scripts/tests/')) {
                continue;
            }
            $text .= (string) file_get_contents($path);
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $path) {
        $text .= (string) file_get_contents($path);
    }
    return $text;
};

$okvShipped = $okvSource($okvRoot);
$okvRules = new ReflectionClass(KitchenRuns::class);
$okvWiredCount = 0;

foreach ($okvRules->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
    $name = $method->getName();
    // A declaration reads "function name(" and a call reads "::name(", so
    // counting call sites never counts the declaration and one is enough.
    $uses = substr_count($okvShipped, 'KitchenRuns::' . $name . '(')
          + substr_count($okvShipped, 'self::' . $name . '(');
    okv_test_ok(
        $uses > 0,
        "KitchenRuns::$name() is called by real code, not only by a test"
    );
    $okvWiredCount++;
}
okv_test_ok($okvWiredCount >= 15, 'the rule surface is the whole public surface, not a sample of it');

// ---------------------------------------------------------------------------
// 1. The four ways a list starts (PRD 8.1), and what each one needs.
// ---------------------------------------------------------------------------

$catalogue = KitchenRuns::validateSubmission('catalogue', 'by_us', [['product_id' => 7, 'quantity' => '2.500']]);
okv_test_ok($catalogue['ok'], 'a shop list needs a product and how much of it, not a price we already know');

$byUs = KitchenRuns::validateSubmission('custom', 'by_us', [['item_name' => 'Pomo', 'quantity' => '10.000', 'unit_id' => 1]]);
okv_test_ok($byUs['ok'], 'a typed list we price needs a quantity and a unit, and no price at all');

$byCustomer = KitchenRuns::validateSubmission('custom', 'by_customer', [['item_name' => 'Pomo', 'target_price_subunit' => 4000000]]);
okv_test_ok($byCustomer['ok'], 'a customer-priced line is a target figure with the quantity left to us');

$alreadyPriced = KitchenRuns::validateSubmission('mixed', 'already_priced', [
    ['item_name' => 'Pomo', 'quantity' => '10.000', 'unit_id' => 1, 'unit_price_subunit' => 400000],
]);
okv_test_ok($alreadyPriced['ok'], 'an already-priced line is complete and we are only confirming it');

$mixed = KitchenRuns::validateSubmission('mixed', 'by_us', [
    ['product_id' => 7, 'quantity' => '2.000'],
    ['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => 1],
]);
okv_test_ok($mixed['ok'], 'a shop item and a free-text item may sit on one list, because a kitchen list does');

// What each mode refuses, and the reason it gives back.
okv_test_eq('bad_mode', KitchenRuns::validateSubmission('telepathy', 'by_us', [['item_name' => 'Pomo', 'quantity' => '1', 'unit_id' => 1]])['error'], 'an unknown input mode is named as the problem');
okv_test_eq('bad_pricing_mode', KitchenRuns::validateSubmission('custom', 'guesswork', [['item_name' => 'Pomo']])['error'], 'an unknown pricing mode is named as the problem');
okv_test_eq('no_items', KitchenRuns::validateSubmission('custom', 'by_us', [])['error'], 'an empty list is refused, not saved as an empty request');
okv_test_eq('invalid_line', KitchenRuns::validateSubmission('custom', 'by_us', [['quantity' => '1.000', 'unit_id' => 1]])['error'], 'a line with no name and no product is not a line');
okv_test_eq('quantity_unit_required', KitchenRuns::validateSubmission('custom', 'by_us', [['item_name' => 'Pomo', 'quantity' => '10']])['error'], 'a list we price needs the unit, or we do not know what to buy');
okv_test_eq('price_required', KitchenRuns::validateSubmission('custom', 'by_customer', [['item_name' => 'Pomo']])['error'], 'a customer-priced line without a figure is refused');
okv_test_eq('price_required', KitchenRuns::validateSubmission('custom', 'already_priced', [['item_name' => 'Pomo', 'quantity' => '2', 'unit_id' => 1]])['error'], 'an already-priced line without its price is refused');

$tooMany = array_fill(0, KitchenRuns::MAX_LINES + 1, ['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => 1]);
okv_test_eq('too_many_items', KitchenRuns::validateSubmission('custom', 'by_us', $tooMany)['error'], 'a list has an upper bound, so one request cannot become a denial of service');

foreach ([['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.0000'], ['quantity' => 'abc'], ['quantity' => '1e3']] as $bad) {
    $line = ['item_name' => 'Pomo', 'unit_id' => 1] + $bad;
    okv_test_ok(empty(KitchenRuns::validateSubmission('custom', 'by_us', [$line])['ok']), 'a quantity of ' . $bad['quantity'] . ' is refused before it reaches storage');
}
foreach ([0, -1, '100.5', '999999999999999999999999', '0x10'] as $badPrice) {
    $result = KitchenRuns::validateSubmission('custom', 'by_customer', [['item_name' => 'Pomo', 'target_price_subunit' => $badPrice]]);
    okv_test_ok(empty($result['ok']), 'a kobo figure of ' . var_export($badPrice, true) . ' is refused');
}

// ---------------------------------------------------------------------------
// 2. Uploads. The whitelist the controller runs before a byte is saved.
// ---------------------------------------------------------------------------

foreach ([['list.jpg', 'image/jpeg'], ['list.jpeg', 'image/jpeg'], ['list.png', 'image/png'], ['market list.pdf', 'application/pdf']] as [$name, $mime]) {
    okv_test_ok(KitchenRuns::allowedUpload($name, $mime, 1024), "$name is an accepted Kitchen Run upload");
}
okv_test_ok(!KitchenRuns::allowedUpload('list.php.jpg', 'application/x-php', 1024), 'a spoofed executable is refused on its type');
okv_test_ok(!KitchenRuns::allowedUpload('list.php', 'image/jpeg', 1024), 'a lying MIME header cannot smuggle a .php name past the filename rule');
okv_test_ok(!KitchenRuns::allowedUpload('../list.pdf', 'application/pdf', 1024), 'a traversal filename is refused');
okv_test_ok(!KitchenRuns::allowedUpload('list.pdf', 'application/pdf', 0), 'an empty file is refused');
okv_test_ok(!KitchenRuns::allowedUpload('big.pdf', 'application/pdf', KitchenRuns::UPLOAD_MAX_BYTES + 1), 'a file over the cap is refused');
okv_test_ok(KitchenRuns::allowedUpload('exact.pdf', 'application/pdf', KitchenRuns::UPLOAD_MAX_BYTES), 'a file exactly at the cap is allowed');

// The whitelist and what Uploads will actually store must agree, or the
// controller accepts a type the storage layer then refuses with an exception.
okv_test_eq(['image/jpeg', 'image/png', 'application/pdf'], KitchenRuns::UPLOAD_MIME, 'the accepted upload types are the three PRD 8.1 names them');

// ---------------------------------------------------------------------------
// 3. The state map. This is the only map, and transition() is its only reader.
// ---------------------------------------------------------------------------

$legal = [
    ['submitted', 'quoted'], ['submitted', 'declined'], ['submitted', 'cancelled'],
    ['quoted', 'quoted'], ['quoted', 'approved'], ['quoted', 'declined'],
    ['quoted', 'cancelled'], ['quoted', 'submitted'], ['approved', 'converted'],
];
foreach ($legal as [$from, $to]) {
    okv_test_ok(KitchenRuns::mayTransition($from, $to), "$from to $to is a move the lifecycle allows");
}
foreach ([
    ['submitted', 'approved'],   // nobody approves a price nobody has set
    ['submitted', 'converted'],  // no order without an approved quote
    ['quoted', 'converted'],     // the customer has to say yes first
    ['approved', 'quoted'],      // re-pricing an approval behind the customer
    ['converted', 'quoted'],     // an order exists; cancel the order instead
    ['converted', 'cancelled'],
    ['declined', 'quoted'],
    ['cancelled', 'submitted'],
] as [$from, $to]) {
    okv_test_ok(!KitchenRuns::mayTransition($from, $to), "$from to $to is refused");
}

// Every status the map names is a status the class admits to having.
foreach (KitchenRuns::STATUSES as $status) {
    okv_test_ok(KitchenRuns::statusLabel($status) !== '', "$status has words a customer can read");
}

// ---------------------------------------------------------------------------
// 4. The quote window. Produce prices move, so a quote does not stand forever.
// ---------------------------------------------------------------------------

okv_test_ok(!KitchenRuns::quoteExpired('2026-09-04 10:00:00', 7, '2026-09-11 09:59:59'), 'a quote stands right up to its last second');
okv_test_ok(KitchenRuns::quoteExpired('2026-09-04 10:00:00', 7, '2026-09-11 10:00:00'), 'a quote expires exactly on its boundary');
okv_test_ok(KitchenRuns::quoteExpired('2026-09-04 10:00:00', 1, '2026-09-06 00:00:00'), 'a one-day window is a real window');
okv_test_ok(!KitchenRuns::quoteExpired('2026-09-04 10:00:00', 0, '2027-01-01 00:00:00'), 'a window of zero days is treated as no window, never as instant expiry');
okv_test_ok(!KitchenRuns::quoteExpired('not a date', 7, '2026-09-11 10:00:00'), 'an unreadable stamp never expires a quote by accident');

okv_test_ok(KitchenRuns::canCustomerEdit('submitted'), 'a customer may change a list nobody has priced yet');
foreach (['quoted', 'approved', 'converted', 'declined', 'cancelled'] as $status) {
    okv_test_ok(!KitchenRuns::canCustomerEdit($status), "a customer cannot edit a $status list underneath the price on it");
}
okv_test_ok(KitchenRuns::canCustomerCancel('submitted'), 'a customer may withdraw a list nobody has priced');
okv_test_ok(KitchenRuns::canCustomerCancel('quoted'), 'a customer may withdraw after reading the price');
foreach (['approved', 'converted', 'declined', 'cancelled'] as $status) {
    okv_test_ok(!KitchenRuns::canCustomerCancel($status), "a $status run is past the point a customer withdraws it");
}

// ---------------------------------------------------------------------------
// 5. Exact arithmetic. Kobo integers, never a float, never a formatted string.
// ---------------------------------------------------------------------------

$quote = KitchenRuns::quoteLines([
    ['item_name' => 'Tomatoes', 'quantity' => '2.500', 'unit_price_subunit' => 270000],
    ['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_price_subunit' => 300000],
]);
okv_test_eq(675000, $quote['lines'][0]['line_total_subunit'], 'a fractional quantity keeps its line total exact in kobo');
okv_test_eq(975000, $quote['total_subunit'], 'a quote total sums exact line values, not formatted strings');
okv_test_ok(is_int($quote['total_subunit']), 'a quote total is an integer, because money on this platform always is');

$third = KitchenRuns::quoteLines([['item_name' => 'Yam', 'quantity' => '0.333', 'unit_price_subunit' => 100000]]);
okv_test_eq(33300, $third['total_subunit'], 'a third of a unit rounds once, in the Money helper, and stays exact after');

foreach ([
    [[], 'no_items'],
    [[['item_name' => '', 'quantity' => '1', 'unit_price_subunit' => 100]], 'invalid_line'],
    [[['item_name' => 'Pomo', 'quantity' => '0', 'unit_price_subunit' => 100]], 'invalid_line'],
    [[['item_name' => 'Pomo', 'quantity' => '1']], 'invalid_line'],
] as [$lines, $expected]) {
    try {
        KitchenRuns::quoteLines($lines);
        okv_test_ok(false, "quoting refuses with $expected");
    } catch (DomainException $e) {
        okv_test_eq($expected, $e->getMessage(), "quoting refuses with $expected");
    }
}

okv_test_ok(KitchenRuns::withinCap(500000, 500000), 'a quote exactly at the agreed cap is allowed');
okv_test_ok(!KitchenRuns::withinCap(500001, 500000), 'a cap is a hard limit, one kobo over is over');
okv_test_ok(KitchenRuns::withinCap(999999999, null), 'an uncapped open-budget request has no ceiling to breach');
okv_test_eq(400000, KitchenRuns::remainingBalance(1000000, 600000), 'the balance after a deposit is exact kobo arithmetic');
okv_test_eq(0, KitchenRuns::remainingBalance(1000000, 1000000), 'a deposit covering the whole quote leaves nothing owing');

// ---------------------------------------------------------------------------
// 6. Who may settle a converted run, and how (PRD 8.2, 9.3).
// ---------------------------------------------------------------------------

okv_test_ok(KitchenRuns::paymentAllowed('deposit', 'household', true, false), 'an open-budget run takes the deposit staff judged right');
okv_test_ok(KitchenRuns::paymentAllowed('deposit', 'household', false, false), 'a standard run may take a deposit');
okv_test_ok(KitchenRuns::paymentAllowed('pay_in_full', 'household', false, false), 'a standard run may be paid in full');
okv_test_ok(!KitchenRuns::paymentAllowed('pay_in_full', 'household', true, false), 'an open budget cannot be paid in full, because nobody yet knows what full is');
okv_test_ok(KitchenRuns::paymentAllowed('on_account', 'business', true, true), 'approved business credit settles an open-budget run');
okv_test_ok(!KitchenRuns::paymentAllowed('on_account', 'business', true, false), 'a business without approved credit cannot go on account');
okv_test_ok(!KitchenRuns::paymentAllowed('on_account', 'household', false, true), 'a household never goes on account, whatever a credit row says');
okv_test_ok(!KitchenRuns::paymentAllowed('pay_on_delivery', 'household', false, false), 'pay on delivery is not a Kitchen Run option, so it is refused rather than ignored');
okv_test_ok(!KitchenRuns::paymentAllowed('free', 'household', false, false), 'an invented payment option is refused');

// ---------------------------------------------------------------------------
// 7. Value rules. Every one refuses rather than coerces.
// ---------------------------------------------------------------------------

okv_test_eq('2.500', KitchenRuns::quantity('2.500'), 'a quantity stays a string, so 3 decimal places survive to the column');
okv_test_eq('2.5', KitchenRuns::quantity('  2.5  '), 'a quantity is trimmed, not rejected for whitespace');
foreach (['0', '-1', '1.0001', '', 'abc', '1e3', '١٢'] as $bad) {
    okv_test_eq(null, KitchenRuns::quantity($bad), var_export($bad, true) . ' is not a quantity');
}
okv_test_eq(0, KitchenRuns::nonNegativeInt('0'), 'zero kobo is a real amount, not an empty one');
okv_test_eq(0, KitchenRuns::nonNegativeInt('000'), 'leading zeroes are stripped, not misread as octal');
okv_test_eq(null, KitchenRuns::nonNegativeInt('99999999999999999999999'), 'a figure wider than the column is refused, never silently truncated');
okv_test_eq(null, KitchenRuns::nonNegativeInt('-5'), 'negative kobo is refused');
okv_test_eq(null, KitchenRuns::nonNegativeInt(1.5), 'a float is never money on this platform');
okv_test_eq(null, KitchenRuns::optionalMoney(''), 'an empty money field is absent, not zero');
okv_test_eq(0, KitchenRuns::optionalMoney('0'), 'a money field a person typed 0 into is zero, not absent');

$long = str_repeat('a', KitchenRuns::NOTE_MAX + 1);
try {
    KitchenRuns::note($long);
    okv_test_ok(false, 'an over-long note is refused rather than truncated into the column');
} catch (DomainException $e) {
    okv_test_eq('note_too_long', $e->getMessage(), 'an over-long note is refused rather than truncated into the column');
}
okv_test_eq(null, KitchenRuns::note('   '), 'a whitespace-only note is no note');

// A price a person typed is money or it is refused. It is never quietly read
// as something else: Money::toSubunit() reads "1e3" as 13 naira, which on a
// hand-priced Kitchen Run would be a real mispricing on a real order.
okv_test_eq(400000, KitchenRuns::nairaToKobo('4,000'), 'thousands separators are what people type, and they parse');
okv_test_eq(400000, KitchenRuns::nairaToKobo("\u{20A6}4,000"), 'a naira sign in the field parses rather than breaking it');
okv_test_eq(1099, KitchenRuns::nairaToKobo('10.99'), 'kobo after the point survive exactly');
okv_test_eq(0, KitchenRuns::nairaToKobo('0'), 'a price of zero is a price, not an empty field');
okv_test_eq(null, KitchenRuns::nairaToKobo('   '), 'an empty field is absent, not zero');
foreach (['abc', '1e3', '-5', '10.999', '4.0.0', '0x10'] as $bad) {
    okv_test_eq(false, KitchenRuns::nairaToKobo($bad), var_export($bad, true) . ' is refused rather than read as some other number');
}

// ---------------------------------------------------------------------------
// 8. The words. Every refusal has a sentence, and it is a sentence.
// ---------------------------------------------------------------------------

$codes = [
    'bad_mode', 'bad_pricing_mode', 'bad_address', 'bad_customer', 'open_budget_pricing',
    'attachment_required', 'attachment_rejected', 'no_items', 'too_many_items', 'invalid_line',
    'invalid_catalogue_item', 'quantity_unit_required', 'price_required', 'budget_not_open',
    'note_too_long', 'deposit_required', 'deposit_above_total', 'cap_exceeded', 'delivery_required',
    'delivery_unavailable', 'zone_unavailable', 'quote_expired', 'total_moved', 'reason_required',
    'stale', 'stale_or_not_owned', 'illegal_transition', 'payment_not_allowed', 'not_found',
    'budget_not_a_number', 'deposit_not_a_number',
];
foreach ($codes as $code) {
    $message = KitchenRuns::message($code);
    okv_test_ok($message !== '' && $message !== 'We could not save that Kitchen Run. Please try again.', "$code has words of its own, not the catch-all");
    okv_test_ok(!str_contains($message, '_'), "$code speaks English to the customer, not a code name");
    okv_test_ok(!str_contains($message, "\u{2014}"), "$code carries no em dash");
}
okv_test_ok(KitchenRuns::message('something_new') !== '', 'an unmapped code still gets a sentence rather than a blank screen');
okv_test_eq(404, KitchenRuns::statusCode('not_found'), 'a missing request is a 404');
okv_test_eq(409, KitchenRuns::statusCode('stale'), 'a request that moved under the caller is a conflict, not a bad request');
okv_test_eq(422, KitchenRuns::statusCode('no_items'), 'a refused submission is unprocessable, not a server error');

// ---------------------------------------------------------------------------
// 9. Labels, and the four moments somebody is told about.
// ---------------------------------------------------------------------------

foreach (KitchenRuns::MODES as $mode) {
    okv_test_ok(KitchenRuns::modeLabel($mode) !== $mode, "$mode has words, not a database value, on the screen");
}
foreach (KitchenRuns::PRICING_MODES as $pricing) {
    okv_test_ok(KitchenRuns::pricingLabel($pricing) !== $pricing, "$pricing has words, not a database value, on the screen");
}
// Each announced moment has an announcement to make, and each is registered.
okv_test_ok(method_exists(Notifications::class, 'announceKitchenRunSubmitted'), 'a new list tells the team, per PRD Section 14');
okv_test_ok(method_exists(Notifications::class, 'announceKitchenRunQuoted'), 'a quote reaches the customer');
okv_test_ok(method_exists(Notifications::class, 'announceKitchenRunApproved'), 'an approval tells the team to convert it');
okv_test_ok(method_exists(Notifications::class, 'announceKitchenRunDeclined'), 'a decline reaches the customer with its reason');
foreach (['kitchen_run_quoted', 'kitchen_run_declined', 'admin_new_kitchen_run', 'admin_kitchen_run_approved'] as $event) {
    okv_test_ok(isset(Notifications::EVENTS[$event]), "$event is a registered notification event, not a template nothing can send");
}

// ---------------------------------------------------------------------------
// 10. The writes live in one class, and it is the only one that writes.
// ---------------------------------------------------------------------------

okv_test_ok(class_exists(KitchenRunWorkflow::class), 'the write path is its own class, as Settings and SettingsEditor are');
foreach (['submit', 'quote', 'approve', 'decline', 'cancel', 'convert'] as $write) {
    okv_test_ok(method_exists(KitchenRunWorkflow::class, $write), "KitchenRunWorkflow::$write() is the way a request is $write" . 'd');
}
foreach (['submit', 'quote', 'approve', 'decline', 'cancel', 'convert'] as $write) {
    okv_test_ok(!method_exists(KitchenRuns::class, $write), "KitchenRuns has no $write(), so there is one way in and not two");
}

// A converted run has to look like a checkout order downstream, which means
// conversion needs the same writers checkout uses. If these stop being shared,
// a Kitchen Run order quietly loses its address or its trail token again.
foreach (['writeAddress', 'writePayments', 'freshTrailToken', 'hashToken'] as $shared) {
    $method = new ReflectionMethod(Checkout::class, $shared);
    okv_test_ok($method->isPublic(), "Checkout::$shared() is shared with conversion, so an order is written one way only");
}
$workflowSource = (string) file_get_contents($okvRoot . '/includes/classes/KitchenRunWorkflow.php');
okv_test_ok(str_contains($workflowSource, 'Checkout::writeAddress('), 'conversion writes the delivery address snapshot the manifest and the documents read');
okv_test_ok(str_contains($workflowSource, 'Checkout::freshTrailToken('), 'conversion mints the Order Trail token, so a Kitchen Run order can be followed like any other');
okv_test_ok(str_contains($workflowSource, 'Checkout::writePayments('), 'conversion writes the money rows the same shape as checkout');
okv_test_ok(str_contains($workflowSource, 'Delivery::isEligible('), 'conversion checks the delivery day against the same rules as checkout');
