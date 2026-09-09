<?php
/**
 * scripts/tests/ManualOrderTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The rules a phone order obeys before any database is touched
 * (PRD Sections 9 and 17.2). The persistence half lives beside this file in
 * manual_operations_db_test.php, and the HTTP contract in
 * manual_operations_http_test.php.
 *
 * The first block is deliberate and copied from KitchenRunsTest, for the reason
 * that file gives: the first attempt at that milestone shipped six public rules
 * with forty passing assertions between them and not one of the six was called
 * by any production path. A rule nothing calls is not covered by a test, it is
 * decorated by one. So before anything else is asserted, every public rule here
 * is proved to be wired to something that ships.
 * -----------------------------------------------------------------------------
 */

require_once $appRoot . '/includes/classes/ManualOrder.php';

// ---------------------------------------------------------------------------
// 0. Every rule is wired.
// ---------------------------------------------------------------------------

/** Every shipped PHP file except the tests themselves. */
$okvManualSource = static function (string $root): string {
    $text = '';
    foreach (['/includes', '/api', '/admin'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || str_contains($path, '/scripts/tests/')) {
                continue;
            }
            $text .= (string) file_get_contents($path);
        }
    }
    return $text;
};

$okvManualShipped = $okvManualSource($appRoot);

foreach ([
    'cleanLines', 'parseItem', 'resolveLines', 'total', 'paymentAllowed',
    'overrideNote', 'channelLabel', 'rowIsBlank', 'create', 'message',
] as $rule) {
    okv_test_ok(
        substr_count($okvManualShipped, 'ManualOrder::' . $rule) >= 1
            || substr_count($okvManualShipped, 'self::' . $rule) >= 1,
        'ManualOrder::' . $rule . '() is called by something that ships'
    );
}

// ---------------------------------------------------------------------------
// 1. One control says what a line is and which row it points at.
// ---------------------------------------------------------------------------

okv_test_eq(
    ['type' => 'product', 'reference_id' => 12],
    ManualOrder::parseItem('product:12'),
    'a product line carries its own id'
);
okv_test_eq(
    ['type' => 'combo', 'reference_id' => 3],
    ManualOrder::parseItem('combo:3'),
    'a combo line carries its own id'
);
okv_test_eq(
    ['type' => 'custom', 'reference_id' => null],
    ManualOrder::parseItem('custom'),
    'a typed line points at nothing'
);
okv_test_eq(null, ManualOrder::parseItem(''),            'an empty value is not a line');
okv_test_eq(null, ManualOrder::parseItem('product:0'),   'id zero is not a row');
okv_test_eq(null, ManualOrder::parseItem('product:-1'),  'a negative id is not a row');
okv_test_eq(null, ManualOrder::parseItem('product:1x'),  'an id has to be digits');
okv_test_eq(null, ManualOrder::parseItem('users:1'),     'only products and combos are catalogue lines');
okv_test_eq(null, ManualOrder::parseItem('custom:1'),    'a typed line takes no id');

// ---------------------------------------------------------------------------
// 2. Reading the posted rows.
// ---------------------------------------------------------------------------

$blank = ['item' => '', 'item_name' => '', 'quantity' => '', 'unit_price' => '', 'unit_name' => ''];
okv_test_ok(ManualOrder::rowIsBlank($blank), 'an untouched row is blank');
okv_test_ok(!ManualOrder::rowIsBlank(['item' => 'product:1'] + $blank), 'a chosen item makes a row real');
okv_test_ok(!ManualOrder::rowIsBlank(['quantity' => '2'] + $blank), 'a typed quantity makes a row real');

$lines = ManualOrder::cleanLines([
    ['item' => 'product:4', 'quantity' => '2.5', 'unit_price' => ''],
    $blank,
    ['item' => 'custom', 'item_name' => 'Pomo', 'unit_name' => 'kg', 'quantity' => '10', 'unit_price' => '3,500'],
    $blank,
]);
okv_test_eq(2, count($lines), 'blank rows are dropped and the real ones survive');
okv_test_eq('product', $lines[0]['type'], 'the first line is a catalogue product');
okv_test_eq('2.5', $lines[0]['quantity'], 'a fractional quantity is kept exactly, as a string');
okv_test_eq(null, $lines[0]['unit_price_subunit'], 'an empty price means our price, not zero');
okv_test_eq(4, $lines[0]['reference_id'], 'the product id comes off the one control');
okv_test_eq('Pomo', $lines[2 - 1]['item_name'], 'a typed line keeps the name a colleague typed');
okv_test_eq('kg', $lines[1]['unit_name'], 'a typed line keeps its unit');
okv_test_eq(350000, $lines[1]['unit_price_subunit'], 'naira with a comma reads as kobo');

$refuses = static function (array $rows, string $code, string $label): void {
    try {
        ManualOrder::cleanLines($rows);
        okv_test_ok(false, $label . ' (nothing was refused)');
    } catch (DomainException $e) {
        okv_test_eq($code, $e->getMessage(), $label);
    }
};

$refuses([], 'no_lines', 'an order with no lines is refused');
$refuses([$blank, $blank], 'no_lines', 'an order of blank rows is refused');
$refuses(
    array_fill(0, ManualOrder::MAX_LINES + 1, ['item' => 'product:1', 'quantity' => '1']),
    'too_many_lines',
    'more lines than an order can carry is refused'
);
$refuses([['item' => 'wrong:1', 'quantity' => '1']], 'bad_line_type', 'a line that is not a kind we sell is refused');
$refuses([['item' => 'product:1', 'quantity' => '']], 'bad_quantity', 'a catalogue line with no quantity is refused');
$refuses([['item' => 'product:1', 'quantity' => '0']], 'bad_quantity', 'a quantity of nothing is refused');
$refuses([['item' => 'product:1', 'quantity' => '1.2345']], 'bad_quantity', 'more than three decimal places is refused');
$refuses([['item' => 'product:1', 'quantity' => '1', 'unit_price' => 'abc']], 'bad_price', 'a price that is not money is refused');
$refuses([['item' => 'product:1', 'quantity' => '1', 'unit_price' => '1e3']], 'bad_price', '1e3 is refused rather than read as 13 naira');
$refuses([['item' => 'custom', 'quantity' => '1', 'unit_price' => '500']], 'name_required', 'a typed line with no name is refused');
$refuses([['item' => 'custom', 'item_name' => 'Pomo', 'quantity' => '1']], 'price_required', 'a typed line with no price is refused, because we have none of our own');

// A half-filled row is refused rather than dropped, so a line a colleague began
// and did not finish never falls silently off the order.
$refuses([['item' => '', 'quantity' => '3']], 'item_required', 'a row with a quantity but no item chosen is refused, never dropped');

// A typed line with no unit still has one, because a packing list has to read.
$unitless = ManualOrder::cleanLines([['item' => 'custom', 'item_name' => 'Pomo', 'quantity' => '1', 'unit_price' => '500']]);
okv_test_eq('each', $unitless[0]['unit_name'], 'a typed line with no unit falls back to something readable');

// ---------------------------------------------------------------------------
// 3. The total, and what may be paid.
// ---------------------------------------------------------------------------

okv_test_eq(0, ManualOrder::total([]), 'nothing sums to nothing');
okv_test_eq(
    950000,
    ManualOrder::total([
        ['line_total_subunit' => 800000],
        ['line_total_subunit' => 150000],
    ]),
    'the order total is the sum of its lines'
);

okv_test_ok(ManualOrder::paymentAllowed('pay_in_full', 'household', false), 'anyone may pay in full');
okv_test_ok(ManualOrder::paymentAllowed('deposit', 'household', false), 'anyone may leave a deposit');
okv_test_ok(!ManualOrder::paymentAllowed('on_account', 'household', true), 'a household never orders on account, approved or not');
okv_test_ok(!ManualOrder::paymentAllowed('on_account', 'business', false), 'a business without approved credit cannot order on account');
okv_test_ok(ManualOrder::paymentAllowed('on_account', 'business', true), 'an approved business may order on account');
okv_test_ok(!ManualOrder::paymentAllowed('barter', 'household', false), 'a payment choice we do not offer is refused');

// The one deliberate difference from the storefront rule. Activation exists
// because a stranger placing an unpaid order from a browser is the flow most
// exposed to abuse (PRD 10.2). A colleague who has spoken to the caller is the
// check that rule stands in for, so requiring it here would block the only
// thing this screen is for.
okv_test_ok(
    ManualOrder::paymentAllowed('pay_on_delivery', 'household', false),
    'a colleague may take a pay-on-delivery order from a caller whose account is not activated'
);
okv_test_ok(
    !Checkout::paymentAllowed('pay_on_delivery', 'household', false),
    'and the storefront still refuses the same choice to an unactivated customer, which is the rule this one departs from'
);

// ---------------------------------------------------------------------------
// 4. A price changed on the phone is never invisible.
// ---------------------------------------------------------------------------

okv_test_eq('', ManualOrder::overrideNote([]), 'no override, no note');
okv_test_eq(
    'Price changed on this order: Tomatoes at ₦7,500 rather than ₦8,000.',
    ManualOrder::overrideNote([['item_name' => 'Tomatoes', 'charged' => 750000, 'listed' => 800000]]),
    'one discount reads as a sentence with both figures in it'
);
okv_test_ok(
    str_contains(
        ManualOrder::overrideNote([
            ['item_name' => 'Tomatoes', 'charged' => 750000, 'listed' => 800000],
            ['item_name' => 'Ugu', 'charged' => 120000, 'listed' => 100000],
        ]),
        '; '
    ),
    'two changes are both named on one line'
);

okv_test_eq('by phone', ManualOrder::channelLabel('phone'), 'a phone order says so');
okv_test_eq('on WhatsApp', ManualOrder::channelLabel('whatsapp'), 'a WhatsApp order says so');
okv_test_eq('in person', ManualOrder::channelLabel('walk_in'), 'a walk-in says so');
okv_test_eq('by phone', ManualOrder::channelLabel('carrier pigeon'), 'an unknown channel falls back rather than printing itself');

// ---------------------------------------------------------------------------
// 5. Every refusal has words, and none of them is an exception message.
// ---------------------------------------------------------------------------

foreach ([
    'bad_customer', 'payment_not_allowed', 'credit_not_approved', 'address_required',
    'delivery_unavailable', 'zone_unavailable', 'no_lines', 'too_many_lines',
    'bad_line_type', 'item_required', 'bad_quantity', 'bad_price', 'name_required',
    'price_required', 'product_missing', 'combo_missing', 'no_price', 'no_value',
    'deposit_required', 'note_too_long',
] as $code) {
    $message = ManualOrder::message($code);
    okv_test_ok(
        $message !== '' && $message !== ManualOrder::message('a_code_that_does_not_exist'),
        'the refusal ' . $code . ' has words of its own'
    );
    okv_test_ok(!str_contains($message, '_'), 'the refusal ' . $code . ' reads as a sentence, not as a code');
}

// The house laws apply to a refusal as much as to a headline (CLAUDE.md). The
// banned words are read out of scripts/brand-check.sh rather than listed again
// here, for two reasons: a second copy of the list drifts from the first, and
// the guard greps this repository for those words, so a test that spelled them
// out would fail the very check it is testing for.
$okvBrandGuard = (string) file_get_contents($appRoot . '/scripts/brand-check.sh');
preg_match('/^JARGON=\'(.*)\'$/m', $okvBrandGuard, $okvJargonLine);
// The words, not the lookarounds either side of them: the pattern opens with
// (?<![\w$]), so the first parenthesised group is a boundary rather than a list.
preg_match('/\(([a-z][a-z| -]+)\)/', $okvJargonLine[1] ?? '', $okvJargonWords);
$okvBanned = array_filter(explode('|', $okvJargonWords[1] ?? ''));

okv_test_ok(count($okvBanned) > 5, 'the banned word list was read out of the brand guard, so the two cannot drift apart');

$okvAllRefusals = '';
foreach ([
    'bad_customer', 'payment_not_allowed', 'credit_not_approved', 'address_required',
    'delivery_unavailable', 'zone_unavailable', 'no_lines', 'too_many_lines',
    'bad_line_type', 'item_required', 'bad_quantity', 'bad_price', 'name_required',
    'price_required', 'product_missing', 'combo_missing', 'no_price', 'no_value',
    'deposit_required', 'note_too_long',
] as $code) {
    $okvAllRefusals .= ' ' . ManualOrder::message($code);
}
foreach ($okvBanned as $jargon) {
    okv_test_ok(
        stripos($okvAllRefusals, $jargon) === false,
        'no refusal on a phone order uses a word the brand guard bans'
    );
}

// And the same for the em dash, which is banned everywhere, copy included.
okv_test_ok(!str_contains($okvAllRefusals, "\u{2014}"), 'no refusal carries an em dash');
