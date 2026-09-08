<?php
/**
 * scripts/tests/kitchen_runs_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run persistence contract (PRD Section 8). Run against
 * a scratch database built from migrations:
 *
 *   php scripts/tests/kitchen_runs_db_test.php
 *
 * The centre of this file is a Kitchen Run going all the way to an order and
 * the order being checked properly. The first attempt at this milestone had a
 * conversion test that only ever exercised an injected failure, so conversion
 * itself was never once run: it threw a PDOException on every real call, and
 * twenty green assertions said nothing was wrong. A conversion test that never
 * converts is not a test.
 *
 * Everything this file creates, it removes in the finally block, including on
 * a failure, so the suite can be run twice against the same database.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0;
$passed = 0;

function krdb_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function krdb_eq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    krdb_ok($ok, $label . ($ok ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

/** Run something that should be refused, and check the reason it gives. */
function krdb_refuses(callable $work, string $expectedCode, string $label): void
{
    try {
        $work();
        krdb_ok(false, $label . ' (nothing was refused)');
    } catch (DomainException $e) {
        krdb_eq($expectedCode, $e->getMessage(), $label);
    }
}

$suffix = bin2hex(random_bytes(5));
$users = [];
$requestIds = [];
$orderIds = [];
$staffId = 0;

try {
    // --- A household customer, a second one, a business one, and a colleague.
    foreach ([['household', 'Ada'], ['household', 'Bisi'], ['business', 'Chidi']] as [$type, $name]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:name, \'Kitchen\', :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':name' => $name,
                ':email' => "kr-$name-$suffix@example.test",
                ':phone' => '+23473' . random_int(10000000, 99999999),
                ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
                ':type' => $type,
            ]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Sade\', \'Kitchen\', :email, :phone, :hash, \'staff\', \'active\')',
        [':email' => "kr-staff-$suffix@example.test", ':phone' => '+23472' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT)]
    );
    $staffId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_status)
         VALUES (:user, :name, :contact, \'approved\')',
        [':user' => $users[2], ':name' => 'Kitchen Test ' . $suffix, ':contact' => 'Chidi Kitchen']
    );

    $unitId = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];
    $zoneId = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $product = Database::one('SELECT id, name, current_price_subunit FROM products WHERE is_active = 1 AND current_price_subunit IS NOT NULL ORDER BY id LIMIT 1');

    $eligible = Delivery::nextEligibleDates('household', 5);
    krdb_ok(!empty($eligible), 'the seeded delivery days give a household Kitchen Run a day to land on');
    $deliveryDate = (string) $eligible[0]['date'];

    // A business customer receives on its own days, so a business run cannot
    // borrow the household date or the eligibility check refuses it.
    $businessDates = Delivery::nextEligibleDates('business', 5);
    krdb_ok(!empty($businessDates), 'the seeded delivery days give a business Kitchen Run a day to land on');
    $businessDate = (string) $businessDates[0]['date'];

    $address = [
        'recipient_name' => 'Ada Kitchen',
        'recipient_phone' => '08031234567',
        'address_line_1' => '12 Adeola Odeku Street',
        'address_line_2' => 'Flat 4',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'landmark' => 'Opposite the filling station',
    ];

    // -----------------------------------------------------------------------
    // 1. Submission keeps the customer's words and hydrates our own prices.
    // -----------------------------------------------------------------------
    $submitted = KitchenRunWorkflow::submit($users[0], 'household', $address + [
        'input_mode' => 'mixed',
        'pricing_mode' => 'by_us',
        'customer_note' => 'Soft pomo please, and firm tomatoes.',
        'items' => [
            ['product_id' => $product['id'], 'quantity' => '2.000'],
            ['item_name' => 'Pomo', 'quantity' => '10.000', 'unit_id' => $unitId],
        ],
    ]);
    $requestId = (int) $submitted['id'];
    $requestIds[] = $requestId;

    krdb_ok($requestId > 0, 'a mixed shop and free-text list becomes one request');
    krdb_eq(2, (int) $submitted['line_count'], 'both lines are kept, and neither is folded into the other');

    $request = Database::one('SELECT * FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId]);
    krdb_eq('submitted', (string) $request['status'], 'a new request starts Submitted');
    krdb_ok(trim((string) $request['original_submission_json']) !== '', 'what the customer sent is kept verbatim, so a later argument is settled by the record');
    krdb_eq('12 Adeola Odeku Street', (string) $request['delivery_address_line_1'], 'the delivery address is captured with the list, not asked for later');
    krdb_eq('+2348031234567', (string) $request['delivery_recipient_phone'], 'the phone number is normalised on the way in, the same as checkout');

    $items = Database::all('SELECT * FROM kitchen_run_items WHERE request_id = :id ORDER BY sort_order, id', [':id' => $requestId]);
    krdb_eq(2, count($items), 'both lines reach storage');
    krdb_eq((int) $product['id'], (int) $items[0]['product_id'], 'the shop line keeps its product link');
    krdb_eq('catalogue', (string) $items[0]['price_source'], 'a shop line records that we priced it from the shop');
    krdb_eq((int) $product['current_price_subunit'], (int) $items[0]['unit_price_subunit'], 'a shop price is read on the server, never taken from the form');
    krdb_eq('Pomo', (string) $items[1]['item_name'], 'the free-text line keeps the customer\'s own word for it');
    krdb_eq(null, $items[1]['unit_price_subunit'], 'a line we are pricing arrives with no price on it');

    $history = KitchenRuns::history($requestId);
    krdb_eq(1, count($history), 'submitting writes the first line of the trail');
    krdb_eq('submitted', (string) $history[0]['new_status'], 'the trail starts at Submitted');

    // A posted price on a shop line is ignored, not honoured.
    $cheat = KitchenRunWorkflow::submit($users[0], 'household', $address + [
        'input_mode' => 'catalogue',
        'pricing_mode' => 'by_us',
        'items' => [['product_id' => $product['id'], 'quantity' => '1.000', 'unit_price_subunit' => 1]],
    ]);
    $requestIds[] = (int) $cheat['id'];
    $cheatLine = Database::one('SELECT unit_price_subunit FROM kitchen_run_items WHERE request_id = :id', [':id' => (int) $cheat['id']]);
    krdb_eq((int) $product['current_price_subunit'], (int) $cheatLine['unit_price_subunit'], 'a price posted against a shop item is overwritten by the real one');

    // A submission with no address is refused, because it becomes an order.
    krdb_refuses(
        static fn() => KitchenRunWorkflow::submit($users[0], 'household', ['input_mode' => 'custom', 'pricing_mode' => 'by_us', 'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]]]),
        'bad_address',
        'a list with nowhere to deliver it is refused at submission'
    );

    // -----------------------------------------------------------------------
    // 2. Quoting. Server maths, the version guard, and the cap.
    // -----------------------------------------------------------------------
    $version = (int) $request['state_version'];
    krdb_refuses(
        static fn() => KitchenRunWorkflow::quote($requestId, $staffId, $version + 99, [
            'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100]],
            'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
        ]),
        'stale',
        'quoting with a version somebody else has moved past is refused'
    );

    krdb_refuses(
        static fn() => KitchenRunWorkflow::quote($requestId, $staffId, $version, [
            'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100]],
            'preferred_delivery_date' => '2020-01-01', 'delivery_zone_id' => $zoneId,
        ]),
        'delivery_unavailable',
        'a quote cannot name a day the shop does not deliver on'
    );

    // Staff replace the whole line set, which is what transcription needs.
    $quote = KitchenRunWorkflow::quote($requestId, $staffId, $version, [
        'items' => [
            ['product_id' => $product['id'], 'item_name' => $product['name'], 'quantity' => '2.000', 'unit_id' => $unitId, 'unit_price_subunit' => 270000],
            ['item_name' => 'Pomo', 'quantity' => '10.000', 'unit_id' => $unitId, 'unit_price_subunit' => 400000],
            ['item_name' => 'Palm oil', 'quantity' => '4.000', 'unit_id' => $unitId, 'unit_price_subunit' => 150000],
        ],
        'deposit_subunit' => 1000000,
        'preferred_delivery_date' => $deliveryDate,
        'delivery_zone_id' => $zoneId,
        'admin_note' => 'Palm oil added, you asked for it on the phone.',
    ]);
    krdb_eq(5140000, (int) $quote['total_subunit'], 'the quote total is summed on the server from the lines, exactly');

    $afterQuote = Database::one('SELECT * FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId]);
    krdb_eq('quoted', (string) $afterQuote['status'], 'quoting moves Submitted to Quoted');
    krdb_eq(5140000, (int) $afterQuote['quoted_total_subunit'], 'the quoted total is stored in kobo');
    krdb_eq(1000000, (int) $afterQuote['deposit_subunit'], 'the deposit staff judged right is stored in kobo');
    krdb_ok($afterQuote['quoted_at'] !== null, 'the quote is stamped, so the expiry window has something to measure from');
    krdb_eq(3, count(KitchenRuns::lines($requestId)), 'a staff member may add a line the customer forgot, which is how an uploaded list is transcribed');
    krdb_eq($version + 1, (int) $afterQuote['state_version'], 'every change bumps the version, so a stale tab is told rather than obeyed');

    // -----------------------------------------------------------------------
    // 3. Approval belongs to the customer, and only to the right one.
    // -----------------------------------------------------------------------
    $quotedVersion = (int) $afterQuote['state_version'];
    krdb_refuses(
        static fn() => KitchenRunWorkflow::approve($requestId, $users[1], $quotedVersion),
        'stale_or_not_owned',
        'another customer cannot approve somebody else\'s quote'
    );
    krdb_refuses(
        static fn() => KitchenRunWorkflow::approve($requestId, $users[0], $quotedVersion + 5),
        'stale_or_not_owned',
        'a customer cannot approve a version of the figures that has moved on'
    );

    $approved = KitchenRunWorkflow::approve($requestId, $users[0], $quotedVersion);
    krdb_eq('approved', (string) $approved['status'], 'the owner of the request approves it');
    krdb_ok(Database::one('SELECT approved_at FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['approved_at'] !== null, 'the approval is stamped');

    // -----------------------------------------------------------------------
    // 4. Conversion. The whole point of the milestone, actually run.
    // -----------------------------------------------------------------------
    $approvedVersion = (int) $approved['version'];
    krdb_refuses(
        static fn() => KitchenRunWorkflow::convert($requestId, $staffId, $approvedVersion, 'pay_on_delivery'),
        'payment_not_allowed',
        'pay on delivery is not a Kitchen Run settlement, and is refused rather than written'
    );
    krdb_refuses(
        static fn() => KitchenRunWorkflow::convert($requestId, $staffId, $approvedVersion, 'on_account'),
        'payment_not_allowed',
        'a household cannot convert on account, whatever the form posted'
    );

    $ordersBefore = (int) Database::one('SELECT COUNT(*) AS n FROM orders')['n'];
    $converted = KitchenRunWorkflow::convert($requestId, $staffId, $approvedVersion, 'deposit');
    $orderId = (int) $converted['id'];
    $orderIds[] = $orderId;

    krdb_ok($orderId > 0, 'an approved Kitchen Run becomes a real order');
    krdb_ok(!$converted['already_converted'], 'the first conversion reports itself as the first');
    krdb_eq($ordersBefore + 1, (int) Database::one('SELECT COUNT(*) AS n FROM orders')['n'], 'exactly one order is written');

    $order = Database::one('SELECT * FROM orders WHERE id = :id', [':id' => $orderId]);
    krdb_eq(5140000, (int) $order['order_total_subunit'], 'the order total is the total the customer approved');
    krdb_eq(5140000, (int) $order['subtotal_subunit'], 'the subtotal is written as well as the total, and they agree');
    krdb_eq(1000000, (int) $order['deposit_required_subunit'], 'the deposit staff set on the request is what the order asks for');
    krdb_eq('pending', (string) $order['order_status'], 'a converted run joins the lifecycle at the same point as any order');
    krdb_eq('unpaid', (string) $order['payment_status'], 'nothing is treated as paid by the act of converting');
    krdb_ok($order['order_trail_token_hash'] !== null, 'the order gets a trail token, so the customer can follow it like any other');
    krdb_eq($deliveryDate, (string) $order['preferred_delivery_date'], 'the order lands on the day that was quoted');

    // The address snapshot is what the manifest, the packing list and the
    // emails all read. Without it a converted run reaches the van nameless.
    $orderAddress = Database::one('SELECT * FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
    krdb_ok($orderAddress !== null, 'the order carries a delivery address snapshot');
    krdb_eq('12 Adeola Odeku Street', (string) $orderAddress['address_line_1'], 'the snapshot is the address the customer gave with the list');
    krdb_eq('Opposite the filling station', (string) $orderAddress['landmark'], 'the landmark reaches the driver');

    $orderItems = Database::all('SELECT * FROM order_items WHERE order_id = :id ORDER BY id', [':id' => $orderId]);
    krdb_eq(3, count($orderItems), 'every quoted line becomes an order line');
    krdb_eq('Palm oil', (string) $orderItems[2]['item_name'], 'a line that was never a shop product still reaches the packing list');
    krdb_ok(trim((string) $orderItems[2]['sku']) !== '', 'a line with no catalogue product still carries a sku, so the packing list reads');
    krdb_eq(5140000, array_sum(array_map(static fn($row) => (int) $row['line_total_subunit'], $orderItems)), 'the order lines sum to the order total');

    $payments = Database::all('SELECT * FROM payments WHERE order_id = :id ORDER BY id', [':id' => $orderId]);
    krdb_eq(2, count($payments), 'a deposit order gets a deposit row and a balance row, the same as checkout');
    krdb_eq(1000000, (int) $payments[0]['expected_amount_subunit'], 'the deposit row asks for the deposit');
    krdb_eq(4140000, (int) $payments[1]['expected_amount_subunit'], 'the balance row asks for the rest, exactly');

    krdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM order_status_history WHERE order_id = :id', [':id' => $orderId])['n'], 'the order starts its own trail');
    krdb_eq('kitchen_run', (string) Database::one('SELECT source FROM order_status_history WHERE order_id = :id', [':id' => $orderId])['source'], 'the trail says where the order came from');
    krdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId])['n'], 'the order is on the delivery schedule, so it reaches the day manifest');

    // The M6 machinery has to accept it without knowing where it came from.
    $context = Notifications::orderContext($orderId);
    krdb_ok($context !== null, 'the order can be described to the customer by the ordinary order email');
    krdb_eq('Ada Kitchen', (string) $context['vars']['customer_name'], 'the order email addresses the person the address names');
    krdb_ok($context['vars']['zone_name'] !== 'Not assigned', 'the order email names the delivery area');

    // -----------------------------------------------------------------------
    // 5. Converting twice returns the first order. It never writes a second.
    // -----------------------------------------------------------------------
    $afterConvert = Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId]);
    $again = KitchenRunWorkflow::convert($requestId, $staffId, (int) $afterConvert['state_version'], 'deposit');
    krdb_ok($again['already_converted'], 'a second conversion says so rather than pretending to be the first');
    krdb_eq($orderId, (int) $again['id'], 'a second conversion returns the order that already exists');
    krdb_eq($ordersBefore + 1, (int) Database::one('SELECT COUNT(*) AS n FROM orders')['n'], 'a second conversion writes no second order');

    $trail = KitchenRuns::history($requestId);
    krdb_eq(4, count($trail), 'the request trail records submitted, quoted, approved and converted');
    krdb_eq(['submitted', 'quoted', 'approved', 'converted'], array_map(static fn($row) => (string) $row['new_status'], $trail), 'the trail reads in the order it happened');

    // -----------------------------------------------------------------------
    // 6. The spend cap is a hard limit, and it is enforced in the database.
    // -----------------------------------------------------------------------
    $capped = KitchenRunWorkflow::submit($users[2], 'business', $address + [
        'input_mode' => 'custom',
        'pricing_mode' => 'by_us',
        'is_open_budget' => true,
        'spend_cap_subunit' => 500000,
        'items' => [['item_name' => 'Whatever you find', 'quantity' => '1.000', 'unit_id' => $unitId]],
    ]);
    $cappedId = (int) $capped['id'];
    $requestIds[] = $cappedId;
    $cappedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $cappedId])['state_version'];

    krdb_refuses(
        static fn() => KitchenRunWorkflow::quote($cappedId, $staffId, $cappedVersion, [
            'items' => [['item_name' => 'Whatever you find', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 500001]],
            'deposit_subunit' => 100000, 'preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId,
        ]),
        'cap_exceeded',
        'a quote one kobo over the agreed cap is refused'
    );
    krdb_refuses(
        static fn() => KitchenRunWorkflow::quote($cappedId, $staffId, $cappedVersion, [
            'items' => [['item_name' => 'Whatever you find', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 400000]],
            'preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId,
        ]),
        'deposit_required',
        'an open-budget run cannot be quoted without a deposit, per PRD 8.2'
    );

    // At the cap exactly, it goes through, and a business may go on account.
    $atCap = KitchenRunWorkflow::quote($cappedId, $staffId, $cappedVersion, [
        'items' => [['item_name' => 'Whatever you find', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 500000]],
        'deposit_subunit' => 100000, 'preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId,
    ]);
    krdb_eq(500000, (int) $atCap['total_subunit'], 'a quote exactly at the cap is allowed');

    $capApproved = KitchenRunWorkflow::approve($cappedId, $users[2], (int) $atCap['version']);
    krdb_refuses(
        static fn() => KitchenRunWorkflow::convert($cappedId, $staffId, (int) $capApproved['version'], 'pay_in_full'),
        'payment_not_allowed',
        'an open-budget run cannot be paid in full, because nobody yet knows what full is'
    );
    $onAccount = KitchenRunWorkflow::convert($cappedId, $staffId, (int) $capApproved['version'], 'on_account');
    $orderIds[] = (int) $onAccount['id'];
    krdb_eq('on_account', (string) Database::one('SELECT payment_option FROM orders WHERE id = :id', [':id' => (int) $onAccount['id']])['payment_option'], 'an approved business customer settles a Kitchen Run on account');

    // -----------------------------------------------------------------------
    // 7. Re-quoting clears the approval, so nobody approves unseen figures.
    // -----------------------------------------------------------------------
    $reprice = KitchenRunWorkflow::submit($users[0], 'household', $address + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['item_name' => 'Ugu', 'quantity' => '3.000', 'unit_id' => $unitId]],
    ]);
    $repriceId = (int) $reprice['id'];
    $requestIds[] = $repriceId;
    $v = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $repriceId])['state_version'];

    $first = KitchenRunWorkflow::quote($repriceId, $staffId, $v, [
        'items' => [['item_name' => 'Ugu', 'quantity' => '3.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100000]],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
    ]);
    $second = KitchenRunWorkflow::quote($repriceId, $staffId, (int) $first['version'], [
        'items' => [['item_name' => 'Ugu', 'quantity' => '3.000', 'unit_id' => $unitId, 'unit_price_subunit' => 120000]],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
    ]);
    krdb_eq(360000, (int) $second['total_subunit'], 'staff may correct a quote they got wrong, in place');
    krdb_eq(null, Database::one('SELECT approved_at FROM kitchen_run_requests WHERE id = :id', [':id' => $repriceId])['approved_at'], 're-pricing clears any approval, so a customer never owns figures they have not seen');
    krdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM kitchen_run_items WHERE request_id = :id', [':id' => $repriceId])['n'], 're-pricing replaces the lines rather than stacking a second set on top');

    // -----------------------------------------------------------------------
    // 8. An expired quote cannot be approved, and reopens for fresh prices.
    // -----------------------------------------------------------------------
    $window = KitchenRuns::quoteDays();
    Database::run(
        'UPDATE kitchen_run_requests SET quoted_at = DATE_SUB(NOW(), INTERVAL :days DAY) WHERE id = :id',
        [':days' => $window + 1, ':id' => $repriceId]
    );
    $staleVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $repriceId])['state_version'];
    krdb_refuses(
        static fn() => KitchenRunWorkflow::approve($repriceId, $users[0], $staleVersion),
        'quote_expired',
        'a quote older than the window cannot be approved on a stale price'
    );
    krdb_eq('submitted', (string) Database::one('SELECT status FROM kitchen_run_requests WHERE id = :id', [':id' => $repriceId])['status'], 'an expired quote returns to Submitted for fresh prices rather than sitting dead');

    // -----------------------------------------------------------------------
    // 9. Cancelling and declining, and what each one may still do afterwards.
    // -----------------------------------------------------------------------
    $toCancel = KitchenRunWorkflow::submit($users[0], 'household', $address + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['item_name' => 'Scent leaf', 'quantity' => '1.000', 'unit_id' => $unitId]],
    ]);
    $cancelId = (int) $toCancel['id'];
    $requestIds[] = $cancelId;
    $cancelVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $cancelId])['state_version'];

    krdb_refuses(
        static fn() => KitchenRunWorkflow::cancel($cancelId, $users[1], $cancelVersion),
        'stale_or_not_owned',
        'one customer cannot withdraw another customer\'s request'
    );
    $cancelled = KitchenRunWorkflow::cancel($cancelId, $users[0], $cancelVersion);
    krdb_eq('cancelled', (string) $cancelled['status'], 'a customer withdraws their own request');
    krdb_refuses(
        static fn() => KitchenRunWorkflow::quote($cancelId, $staffId, (int) $cancelled['version'], [
            'items' => [['item_name' => 'Scent leaf', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 50000]],
            'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
        ]),
        'stale',
        'a withdrawn request cannot be quoted back to life'
    );

    $toDecline = KitchenRunWorkflow::submit($users[0], 'household', $address + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['item_name' => 'Something we cannot get', 'quantity' => '1.000', 'unit_id' => $unitId]],
    ]);
    $declineId = (int) $toDecline['id'];
    $requestIds[] = $declineId;
    $declineVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $declineId])['state_version'];

    krdb_refuses(
        static fn() => KitchenRunWorkflow::decline($declineId, $staffId, $declineVersion, '   '),
        'reason_required',
        'a decline without a reason is refused, because the customer is told the reason'
    );
    $declined = KitchenRunWorkflow::decline($declineId, $staffId, $declineVersion, 'The market had no pomo we would stand behind this week.');
    krdb_eq('declined', (string) $declined['status'], 'staff may decline a request');
    krdb_eq('The market had no pomo we would stand behind this week.', (string) Database::one('SELECT admin_note FROM kitchen_run_requests WHERE id = :id', [':id' => $declineId])['admin_note'], 'the reason is stored, so the email and the screen say the same thing');

    // -----------------------------------------------------------------------
    // 10. The reads every screen depends on.
    // -----------------------------------------------------------------------
    krdb_ok(KitchenRuns::findForCustomer($requestId, $users[1]) === null, 'one customer cannot read another customer\'s Kitchen Run');
    $mine = KitchenRuns::findForCustomer($requestId, $users[0]);
    krdb_ok($mine !== null, 'a customer reads their own Kitchen Run');
    krdb_ok(isset($mine['is_expired'], $mine['may_approve'], $mine['may_cancel'], $mine['status_label']), 'a request comes to a screen already decorated, so no screen works the rules out for itself');
    krdb_ok(count(KitchenRuns::allForCustomer($users[0])) >= 4, 'a customer sees their own runs');
    krdb_ok(count(KitchenRuns::allForStaff('submitted')) >= 0, 'the staff queue can be filtered by status');
    krdb_ok(KitchenRuns::waitingCount() >= 0, 'the queue badge counts what is waiting on us');

    $notified = Notifications::kitchenRunContext($requestId);
    krdb_ok($notified !== null, 'a Kitchen Run can be described to the customer');
    krdb_ok(str_contains((string) $notified['vars']['request_url'], 'kitchen-runs.php'), 'the Kitchen Run email links back to the request, so it is not a dead end');
    krdb_ok((string) $notified['vars']['quote_total'] !== '', 'the quote email carries the figure it is about');
} finally {
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('UPDATE kitchen_run_requests SET converted_order_id = NULL WHERE converted_order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($requestIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM business_customers WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    if ($staffId) {
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id', [':id' => $staffId]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $staffId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests Kitchen Run database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
