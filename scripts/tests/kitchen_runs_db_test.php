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

    // What a customer's form posts beside the list: the day they want it and
    // the area it goes to. Both are theirs to choose now, and both are checked
    // against the delivery rules on the way in.
    $askFor = ['preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId];
    $askForBusiness = ['preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId];

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
    $submitted = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
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
    $cheat = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
        'input_mode' => 'catalogue',
        'pricing_mode' => 'by_us',
        'items' => [['product_id' => $product['id'], 'quantity' => '1.000', 'unit_price_subunit' => 1]],
    ]);
    $requestIds[] = (int) $cheat['id'];
    $cheatLine = Database::one('SELECT unit_price_subunit FROM kitchen_run_items WHERE request_id = :id', [':id' => (int) $cheat['id']]);
    krdb_eq((int) $product['current_price_subunit'], (int) $cheatLine['unit_price_subunit'], 'a price posted against a shop item is overwritten by the real one');

    // A submission with no address is refused, because it becomes an order.
    krdb_refuses(
        static fn() => KitchenRunWorkflow::submit($users[0], 'household', $askFor + ['input_mode' => 'custom', 'pricing_mode' => 'by_us', 'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]]]),
        'bad_address',
        'a list with nowhere to deliver it is refused at submission'
    );

    // --- The day and the area belong to the customer now (item 3) ----------
    krdb_eq($deliveryDate, (string) $request['preferred_delivery_date'], 'the day the customer picked is stored with their list, not filled in for them later');
    krdb_eq($zoneId, (int) $request['delivery_zone_id'], 'the area the customer picked is stored with their list');
    krdb_refuses(
        static fn() => KitchenRunWorkflow::submit($users[0], 'household', $address + ['input_mode' => 'custom', 'pricing_mode' => 'by_us', 'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]]]),
        'delivery_required',
        'a list with no day and no area on it is refused rather than saved for somebody to guess at'
    );
    krdb_refuses(
        static fn() => KitchenRunWorkflow::submit($users[0], 'household', $address + ['preferred_delivery_date' => '2020-01-01', 'delivery_zone_id' => $zoneId, 'input_mode' => 'custom', 'pricing_mode' => 'by_us', 'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]]]),
        'delivery_unavailable',
        'a customer cannot ask for a day the shop does not run, the same rule checkout applies'
    );
    krdb_refuses(
        static fn() => KitchenRunWorkflow::submit($users[0], 'household', $address + ['preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => 999999, 'input_mode' => 'custom', 'pricing_mode' => 'by_us', 'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]]]),
        'zone_unavailable',
        'a delivery area we do not serve is refused at submission'
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

    // The unit, not only the name and the price. "4 palm oil" is not a packing
    // list; "4 litres" is. A Kitchen Run line resolves its unit through
    // COALESCE(u.name, i.unit_label, 'unit'), and this proves that resolution
    // reaches the order line rather than stopping at the request.
    $unitName = (string) Database::one('SELECT name FROM units_of_measurement WHERE id = :id', [':id' => $unitId])['name'];
    krdb_eq($unitName, (string) $orderItems[2]['unit_name'], 'a free-text line carries its unit onto the order line, so the packing list can be used');
    foreach ($orderItems as $index => $orderLine) {
        krdb_ok(trim((string) $orderLine['unit_name']) !== '', 'order line ' . ($index + 1) . ' names a unit, because a quantity with no unit cannot be packed');
    }
    krdb_eq('4.000', (string) $orderItems[2]['quantity'], 'the quantity is the quantity that was quoted, exactly');
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
    // 4b. The balance after delivery (PRD 8.2), on the path that already
    //     exists. No pay-on-delivery option is added to a Kitchen Run: we buy
    //     this produce at the market with our own cash before anybody has paid,
    //     so a zero-deposit run is the one shape of this service that can lose
    //     real money. What PRD 8.2 asks for is that the balance is reconciled
    //     after delivery, and a deposit conversion already writes a manual
    //     balance row due on the delivery date. This proves M5's manual payment
    //     flow settles it, through the same query the payments screen runs.
    // -----------------------------------------------------------------------
    $balanceRow = null;
    foreach (Database::all(
        'SELECT id, payment_number, payment_type, provider, expected_amount_subunit,
                paid_amount_subunit, status, due_at
           FROM payments WHERE order_id = :id ORDER BY id',
        [':id' => $orderId]
    ) as $row) {
        if ((string) $row['payment_type'] === 'balance') {
            $balanceRow = $row;
        }
    }
    krdb_ok($balanceRow !== null, 'the payments screen finds a balance row on a converted Kitchen Run');
    krdb_eq('manual', (string) $balanceRow['provider'], 'the balance is settled by a person, which is what after delivery means');
    krdb_eq(4140000, (int) $balanceRow['expected_amount_subunit'], 'the balance row asks for the total less the deposit');
    krdb_eq($deliveryDate . ' 00:00:00', (string) $balanceRow['due_at'], 'the balance falls due on the delivery day, not at conversion');
    krdb_eq('unpaid', (string) $balanceRow['status'], 'nothing about converting marks the balance as settled');

    // The deposit first, the way it would really happen, then the balance.
    $depositRow = Database::one('SELECT id FROM payments WHERE order_id = :id AND payment_type = \'deposit\'', [':id' => $orderId]);
    $depositTaken = ManualPayments::record([
        'payment_id' => (int) $depositRow['id'],
        'amount_subunit' => 1000000,
        'method' => 'transfer',
        'bank_reference' => 'KR-DEP-' . $suffix,
        'record_token' => bin2hex(random_bytes(8)),
    ], $staffId);
    krdb_ok(!empty($depositTaken['ok']), 'the deposit is recorded by hand, the way a transfer to the shop account arrives');

    $balanceTaken = ManualPayments::record([
        'payment_id' => (int) $balanceRow['id'],
        'amount_subunit' => 4140000,
        'method' => 'cash',
        'record_token' => bin2hex(random_bytes(8)),
    ], $staffId);
    krdb_ok(!empty($balanceTaken['ok']), 'the balance is recorded on delivery, through the ordinary manual payment flow');

    $settled = Database::one('SELECT payment_status, amount_paid_subunit, balance_due_subunit FROM orders WHERE id = :id', [':id' => $orderId]);
    krdb_eq('paid', (string) $settled['payment_status'], 'once the balance is in, the Kitchen Run order reads as paid');
    krdb_eq(5140000, (int) $settled['amount_paid_subunit'], 'what was taken is the deposit plus the balance, exactly');
    krdb_eq(0, (int) $settled['balance_due_subunit'], 'nothing is left owing after the balance is settled');
    krdb_eq('paid', (string) Database::one('SELECT status FROM payments WHERE id = :id', [':id' => (int) $balanceRow['id']])['status'], 'the balance row itself is closed, not just the order total');

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
    $capped = KitchenRunWorkflow::submit($users[2], 'business', $address + $askForBusiness + [
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
    $reprice = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
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
    $toCancel = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
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

    $toDecline = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
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

    // The queue tabs. Milestone 6/7: the filter never reached the query at all,
    // so every tab showed every request. These check the two halves the SQL is
    // responsible for: a status filter that narrows, and an expired quote that
    // is counted and listed apart from a live one.
    $everything = KitchenRuns::allForStaff('', 200);
    foreach (KitchenRuns::STATUSES as $status) {
        foreach (KitchenRuns::allForStaff($status, 200) as $row) {
            krdb_eq($status, (string) $row['status'], "the $status tab returns only $status requests");
        }
    }
    krdb_ok(count(KitchenRuns::allForStaff('submitted', 200)) <= count($everything), 'a filtered tab is never larger than All');

    foreach (KitchenRuns::allForStaff(KitchenRuns::FILTER_EXPIRED, 200) as $row) {
        krdb_eq('quoted', (string) $row['status'], 'the expired tab only ever holds quoted requests');
        krdb_ok((bool) $row['is_expired'], 'and every one of them really has expired');
    }
    foreach (KitchenRuns::allForStaff('quoted', 200) as $row) {
        krdb_ok(!$row['is_expired'], 'the Quote sent tab holds live quotes only, so an expired one cannot hide among them');
    }

    $counts = KitchenRuns::statusCounts();
    krdb_ok(array_keys($counts) === KitchenRuns::FILTERS, 'every tab has a count, in tab order');
    krdb_eq(count($everything), $counts[''], 'the All count matches what All lists');
    foreach (KitchenRuns::FILTERS as $tab) {
        if ($tab === '') {
            continue;
        }
        krdb_eq(count(KitchenRuns::allForStaff($tab, 200)), $counts[$tab], "the $tab count matches what the $tab tab lists");
    }
    krdb_eq(0, KitchenRuns::statusCounts('nobody-by-this-name-' . $suffix)[''], 'the counts respect the customer search, so they never contradict the list under them');

    // -----------------------------------------------------------------------
    // 11. The internal note, staff approval, and withdrawing after approving.
    // -----------------------------------------------------------------------

    // --- Item 22: two notes, and only one of them is the customer's --------
    KitchenRunWorkflow::saveStaffNote($requestId, $staffId, 'Third late change this month. Watch the pomo price on the next one.');
    $staffView = KitchenRuns::findForStaff($requestId);
    krdb_eq('Third late change this month. Watch the pomo price on the next one.', (string) $staffView['staff_note'], 'the team read of a request carries the internal note');
    $customerView = KitchenRuns::findForCustomer($requestId, $users[0]);
    krdb_ok(!array_key_exists('staff_note', $customerView), 'the customer read of the same request does not carry the internal note at all');
    foreach (KitchenRuns::allForCustomer($users[0]) as $row) {
        krdb_ok(!array_key_exists('staff_note', $row), 'no row in a customer\'s own list carries the internal note');
    }
    $emailed = Notifications::kitchenRunContext($requestId);
    krdb_ok(!in_array('Third late change this month. Watch the pomo price on the next one.', array_map('strval', $emailed['vars']), true), 'no email variable carries the internal note');
    krdb_eq('Palm oil added, you asked for it on the phone.', (string) $customerView['admin_note'], 'the note written for the customer still reaches the customer, which is what admin_note is for');
    KitchenRunWorkflow::saveStaffNote($requestId, $staffId, '   ');
    krdb_eq(null, Database::one('SELECT staff_note FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['staff_note'], 'clearing the internal note empties the column rather than storing whitespace');

    // --- Item 23: staff record an approval a customer gave on the phone ----
    $phoned = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['item_name' => 'Crayfish', 'quantity' => '2.000', 'unit_id' => $unitId]],
    ]);
    $phonedId = (int) $phoned['id'];
    $requestIds[] = $phonedId;
    $phonedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $phonedId])['state_version'];

    krdb_refuses(
        static fn() => KitchenRunWorkflow::approveForCustomer($phonedId, $staffId, $phonedVersion, 'Mrs Adeyemi said yes.'),
        'not_quoted',
        'staff cannot approve a request nobody has priced, whoever rang up'
    );

    $phonedQuote = KitchenRunWorkflow::quote($phonedId, $staffId, $phonedVersion, [
        'items' => [['item_name' => 'Crayfish', 'quantity' => '2.000', 'unit_id' => $unitId, 'unit_price_subunit' => 350000]],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
    ]);
    krdb_refuses(
        static fn() => KitchenRunWorkflow::approveForCustomer($phonedId, $staffId, (int) $phonedQuote['version'], '  '),
        'authorisation_required',
        'staff cannot approve for a customer without writing down who authorised it'
    );

    $onBehalf = KitchenRunWorkflow::approveForCustomer($phonedId, $staffId, (int) $phonedQuote['version'], 'Mrs Adeyemi approved it by phone at 09:20.');
    krdb_eq('approved', (string) $onBehalf['status'], 'staff may record an approval the customer gave them on the phone');
    $phonedTrail = KitchenRuns::history($phonedId);
    $lastEvent = $phonedTrail[count($phonedTrail) - 1];
    krdb_eq('admin', (string) $lastEvent['source'], 'the trail shows it as a staff action, never as the customer pressing the button');
    krdb_eq($staffId, (int) $lastEvent['changed_by'], 'the colleague who recorded it is named on the record');
    krdb_ok(str_contains((string) $lastEvent['note'], 'Mrs Adeyemi'), 'who gave the approval, and how, is on the record with it');

    // --- Item 26: withdrawing after approving (PRD 8.3) --------------------
    $withdrawn = KitchenRunWorkflow::cancel($phonedId, $users[0], (int) $onBehalf['version']);
    krdb_eq('cancelled', (string) $withdrawn['status'], 'a customer may withdraw a run they have already approved, because it is not an order yet');
    krdb_eq('approved', (string) $withdrawn['from'], 'the state it was withdrawn from comes back, so the team can be told about that one');
    krdb_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM orders WHERE id IN (SELECT converted_order_id FROM kitchen_run_requests WHERE id = :id AND converted_order_id IS NOT NULL)', [':id' => $phonedId])['n'], 'withdrawing an approved run leaves no order behind, so there is no money to reverse');
    krdb_refuses(
        static fn() => KitchenRunWorkflow::convert($phonedId, $staffId, (int) $withdrawn['version'], 'deposit'),
        'stale',
        'a withdrawn run cannot then be converted into an order'
    );

    // --- Item 12: the priced mode round-trips ------------------------------
    $pricedRun = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
        'input_mode' => 'priced', 'pricing_mode' => 'already_priced',
        'items' => [
            ['item_name' => 'Dried fish', 'quantity' => '3.000', 'unit_id' => $unitId, 'unit_price_subunit' => 250000],
            ['product_id' => $product['id'], 'quantity' => '1.000'],
        ],
    ]);
    $pricedId = (int) $pricedRun['id'];
    $requestIds[] = $pricedId;
    $pricedRow = Database::one('SELECT input_mode, pricing_mode FROM kitchen_run_requests WHERE id = :id', [':id' => $pricedId]);
    krdb_eq('priced', (string) $pricedRow['input_mode'], 'an already-priced list is recorded as its own input mode, so a report can group by it');
    krdb_eq('already_priced', (string) $pricedRow['pricing_mode'], 'the pricing mode still records who put the prices on it');
    $pricedLines = KitchenRuns::lines($pricedId);
    krdb_eq(250000, (int) $pricedLines[0]['unit_price_subunit'], 'the price the customer put on their own line is kept');
    krdb_eq((int) $product['current_price_subunit'], (int) $pricedLines[1]['unit_price_subunit'], 'a shop item on a priced list is still priced from the shop, on the server');

    // --- What a browser posts, which is not what curl posts ----------------
    // A form posts every field it renders, so an empty hidden product_id
    // arrives as '' rather than as an absent key. Bound into a BIGINT column
    // that is "Incorrect integer value: ''", and pricing an ordinary free-text
    // list failed on the admin screen while every curl-shaped test passed.
    $browserish = KitchenRunWorkflow::submit($users[0], 'household', $address + $askFor + [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'items' => [['product_id' => '', 'item_name' => 'Ata rodo', 'quantity' => '4.000', 'unit_id' => $unitId, 'unit_label' => '', 'note' => '']],
    ]);
    $browserId = (int) $browserish['id'];
    $requestIds[] = $browserId;
    $browserVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $browserId])['state_version'];

    $browserQuote = KitchenRunWorkflow::quote($browserId, $staffId, $browserVersion, [
        'items' => [[
            'product_id' => '',            // the hidden field on a free-text line
            'item_name' => 'Ata rodo',
            'quantity' => '4.000',
            'unit_id' => (string) $unitId, // a select posts a string
            'unit_label' => '',
            'note' => 'Two baskets, the small hot ones.',
            'unit_price_subunit' => 180000,
        ]],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
        'admin_note' => 'Priced from Mile 12 this morning.',
    ]);
    krdb_eq(720000, (int) $browserQuote['total_subunit'], 'a line posted the way a browser posts it prices without a database error');
    $browserLines = KitchenRuns::lines($browserId);
    krdb_eq(null, $browserLines[0]['product_id'], 'an empty product id is stored as nothing, not refused and not stored as zero');
    krdb_eq($unitId, (int) $browserLines[0]['unit_id'], 'the unit a select posted as a string is stored as the unit');
    krdb_eq('Two baskets, the small hot ones.', (string) $browserLines[0]['note'], 'the note staff typed on the line is stored, and the customer reads it');
    krdb_eq(null, $browserLines[0]['unit_label'], 'an empty unit label is nothing rather than an empty string');

    // A note longer than the column is cut to fit rather than throwing.
    $longNote = str_repeat('long ', 80);
    KitchenRunWorkflow::quote($browserId, $staffId, (int) $browserQuote['version'], [
        'items' => [['item_name' => 'Ata rodo', 'quantity' => '4.000', 'unit_id' => $unitId, 'unit_price_subunit' => 180000, 'note' => $longNote]],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
    ]);
    krdb_ok(mb_strlen((string) KitchenRuns::lines($browserId)[0]['note']) <= 255, 'a line note longer than the column is cut to fit rather than failing the quote');

    // --- Lines keep the order staff put them in (item 21) ------------------
    $ordered = KitchenRunWorkflow::quote($browserId, $staffId, (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $browserId])['state_version'], [
        'items' => [
            ['item_name' => 'Third', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100000],
            ['item_name' => 'First', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100000],
            ['item_name' => 'Second', 'quantity' => '1.000', 'unit_id' => $unitId, 'unit_price_subunit' => 100000],
        ],
        'preferred_delivery_date' => $deliveryDate, 'delivery_zone_id' => $zoneId,
    ]);
    krdb_eq(300000, (int) $ordered['total_subunit'], 'the reordered set prices as one quote');
    krdb_eq(
        ['Third', 'First', 'Second'],
        array_map(static fn(array $line): string => (string) $line['item_name'], KitchenRuns::lines($browserId)),
        'lines are stored and read back in the order they were posted, which is what moving a row up or down changes'
    );
    krdb_eq([0, 1, 2], array_map(static fn(array $line): int => (int) $line['sort_order'], KitchenRuns::lines($browserId)), 'sort_order is the position in the posted list, so no second mechanism can disagree with it');

    // --- Item 17: the staff queue filters by customer ----------------------
    $ada = Database::one('SELECT email FROM users WHERE id = :id', [':id' => $users[0]]);
    $byEmail = KitchenRuns::allForStaff('', 100, (string) $ada['email']);
    krdb_ok(count($byEmail) >= 4, 'the queue filters to one customer by their email address');
    foreach ($byEmail as $row) {
        krdb_eq($users[0], (int) $row['user_id'], 'every row the customer filter returns belongs to that customer');
    }
    $byNumber = KitchenRuns::allForStaff('', 100, (string) $submitted['request_number']);
    krdb_eq(1, count($byNumber), 'the queue finds one run by the request number a customer reads out on the phone');
    krdb_eq($requestId, (int) $byNumber[0]['id'], 'and it is the right run');
    krdb_eq(0, count(KitchenRuns::allForStaff('', 100, 'nobody-by-this-name-' . $suffix)), 'a search that matches nothing returns nothing, rather than everything');
    krdb_ok(count(KitchenRuns::allForStaff('submitted', 100, (string) $ada['email'])) <= count($byEmail), 'the status filter and the customer filter narrow together rather than fighting');
    krdb_eq(0, count(KitchenRuns::allForStaff('', 100, "' OR 1=1 -- ")), 'the customer filter is bound, so an injection attempt is just a search that finds nothing');

    $notified = Notifications::kitchenRunContext($requestId);
    krdb_ok($notified !== null, 'a Kitchen Run can be described to the customer');
    krdb_ok(str_contains((string) $notified['vars']['request_url'], 'kitchen-runs.php'), 'the Kitchen Run email links back to the request, so it is not a dead end');
    krdb_ok((string) $notified['vars']['quote_total'] !== '', 'the quote email carries the figure it is about');
} finally {
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        // A Kitchen Run order is settled through the ordinary manual payment
        // flow, so it leaves the same rows behind an M5 order does.
        Database::run('DELETE FROM payment_reversals WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM manual_payment_proofs WHERE payment_transaction_id IN (SELECT t.id FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
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
