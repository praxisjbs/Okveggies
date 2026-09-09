<?php
/**
 * scripts/tests/manual_operations_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. What the back office can now start by itself, proved against a
 * real database:
 *
 *   php scripts/tests/manual_operations_db_test.php
 *
 * Three things the admin panel could not do before this, and the reason each of
 * them is tested here rather than only in a unit test:
 *
 *   An order taken on the phone. The whole promise is that it comes out an
 *   ordinary order, so this checks the rows a checkout order writes, one at a
 *   time: the address snapshot, the trail token, the status history, the
 *   delivery schedule, the payment rows and the line snapshot with a combo
 *   fanned out into its parts. A unit test can prove the arithmetic. Only this
 *   can prove the order reaches the day manifest.
 *
 *   A customer made by staff. The account has to be real enough to own an order
 *   and useless enough that nobody can sign into it, and users.email is NOT NULL
 *   UNIQUE, so the caller with no email is the case that decides the design.
 *
 *   A kitchen list typed in for somebody. It has to be indistinguishable from
 *   one the customer sent, except in the trail, which has to say plainly that
 *   we typed it in.
 *
 * Everything this file creates, it removes in the finally block, including on a
 * failure, so the suite can be run twice against the same database.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0;
$passed = 0;

function mo_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function mo_eq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    mo_ok($ok, $label . ($ok ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}

function mo_throws(callable $run, string $code, string $label): void
{
    try {
        $run();
        mo_ok(false, $label . ' (nothing was refused)');
    } catch (DomainException $e) {
        mo_eq($code, $e->getMessage(), $label);
    }
}

$suffix    = substr(bin2hex(random_bytes(5)), 0, 10);
$users     = [];
$orderIds  = [];
$requestIds = [];
$zoneIds   = [];
$staffId   = 0;

try {
    // --- Fixtures -----------------------------------------------------------
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (\'Manual\', \'Manager\', :email, :phone, :hash, \'staff\', \'active\', NOW())',
        [
            ':email' => "mo-staff-$suffix@example.test",
            ':phone' => '+23475' . random_int(10000000, 99999999),
            ':hash'  => password_hash('manual-db-777', PASSWORD_BCRYPT),
        ]
    );
    $staffId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $staffId]);

    $zoneId  = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $product = Database::one(
        'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
           FROM products p JOIN units_of_measurement u ON u.id = p.unit_id
          WHERE p.is_active = 1 AND p.current_price_subunit > 0 ORDER BY p.id LIMIT 1'
    );
    $combo   = Database::one('SELECT id, name, price_subunit FROM combo_packages WHERE is_active = 1 AND price_subunit > 0 ORDER BY id LIMIT 1');
    $unitId  = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];

    mo_ok($product !== null, 'the product seed gives us something to sell');
    mo_ok($combo !== null, 'the combo seed gives us a combo to sell');

    // -----------------------------------------------------------------------
    // 1. A customer a colleague makes on the phone.
    // -----------------------------------------------------------------------
    $householdId = StaffCustomers::create(StaffCustomers::validateNew([
        'first_name' => 'Adaeze', 'last_name' => 'Phone',
        'phone' => '0803' . random_int(1000000, 9999999),
        'email' => "mo-cust-$suffix@example.test",
    ]), $staffId);
    $users[] = $householdId;
    mo_ok($householdId > 0, 'a colleague can make a customer account');

    $made = Database::one('SELECT * FROM users WHERE id = :id', [':id' => $householdId]);
    mo_eq('household', (string) $made['user_type'], 'a staff-made customer is a customer, never staff');
    mo_eq('active', (string) $made['status'], 'the account is usable straight away, because an order is about to hang off it');
    mo_eq(null, $made['email_verified_at'], 'nobody has verified anything, and the row says so honestly');
    mo_ok(
        !password_verify('', (string) $made['password_hash']) && !password_verify('password', (string) $made['password_hash']),
        'the password is bytes nobody holds, so the account cannot be signed into until the customer sets one'
    );
    mo_ok(
        Database::one('SELECT id FROM audit_logs WHERE action = \'customer.create\' AND entity_id = :id', [':id' => $householdId]) !== null,
        'making a customer by hand is on the audit log'
    );

    // The caller with no email. This is the case the whole design turns on.
    $noEmailId = StaffCustomers::create(StaffCustomers::validateNew([
        'first_name' => 'Chidi', 'last_name' => 'NoEmail',
        'phone' => '0805' . random_int(1000000, 9999999), 'email' => '',
    ]), $staffId);
    $users[] = $noEmailId;
    $noEmail = Database::one('SELECT email FROM users WHERE id = :id', [':id' => $noEmailId]);
    mo_ok(
        StaffCustomers::isPlaceholderEmail((string) $noEmail['email']),
        'a caller with no email address still gets an account, on an address that is visibly ours'
    );

    // A business gets the profile row the Pro Portal and credit both read.
    $businessId = StaffCustomers::create(StaffCustomers::validateNew([
        'first_name' => 'Ngozi', 'last_name' => 'Restaurant',
        'phone' => '0807' . random_int(1000000, 9999999),
        'email' => "mo-biz-$suffix@example.test",
        'customer_type' => 'business', 'business_name' => 'Mama Put Kitchen',
    ]), $staffId);
    $users[] = $businessId;
    $profile = Database::one('SELECT business_name, contact_person, credit_status FROM business_customers WHERE user_id = :id', [':id' => $businessId]);
    mo_ok($profile !== null, 'a business customer gets the profile row credit and the Pro Portal both read');
    mo_eq('Mama Put Kitchen', (string) $profile['business_name'], 'the trading name is kept');
    mo_eq('not_requested', (string) $profile['credit_status'], 'a new business has not asked for credit, and the row says so');

    // The same phone number twice is one customer, not two.
    $duplicate = Database::one('SELECT phone FROM users WHERE id = :id', [':id' => $householdId]);
    mo_throws(
        static fn() => StaffCustomers::create(
            StaffCustomers::validateNew([
                'first_name' => 'Someone', 'last_name' => 'Else',
                'phone' => (string) $duplicate['phone'], 'email' => "mo-dupe-$suffix@example.test",
            ]),
            $staffId
        ),
        'customer_exists',
        'a phone number that already belongs to a customer is refused rather than made twice'
    );

    // Search finds them however the number was said.
    $found = StaffCustomers::search('Adaeze');
    mo_ok(
        in_array($householdId, array_map(static fn(array $r): int => (int) $r['id'], $found), true),
        'the search finds a customer by name'
    );
    $nationalForm = '0' . substr((string) $duplicate['phone'], 4);
    $byPhone = StaffCustomers::search($nationalForm);
    mo_ok(
        in_array($householdId, array_map(static fn(array $r): int => (int) $r['id'], $byPhone), true),
        'and by the phone number typed the way a caller says it, not the way we store it'
    );
    mo_ok(
        !in_array($staffId, array_map(static fn(array $r): int => (int) $r['id'], StaffCustomers::search('Manual')), true),
        'the search never offers a staff account, because an order belongs to a buyer'
    );
    mo_eq([], StaffCustomers::search(''), 'an empty search returns nothing rather than everybody');

    // -----------------------------------------------------------------------
    // 2. The order a colleague builds on the phone.
    // -----------------------------------------------------------------------
    $date = (string) Delivery::nextEligibleDates('household', 5)[0]['date'];
    $address = [
        'recipient_name' => 'Adaeze Phone', 'recipient_phone' => '08031234567',
        'address_line_1' => '12 Glover Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'landmark' => 'Behind the pharmacy',
    ];

    $listed = (int) $product['current_price_subunit'];
    $created = ManualOrder::create($address + [
        'user_id' => $householdId,
        'payment_option' => 'pay_on_delivery',
        'channel' => 'phone',
        'delivery_date' => $date,
        'delivery_zone_id' => $zoneId,
        'customer_note' => 'Ripe plantain please.',
        'lines' => [
            ['item' => 'product:' . (int) $product['id'], 'quantity' => '2', 'unit_price' => ''],
            ['item' => 'combo:' . (int) $combo['id'], 'quantity' => '1', 'unit_price' => ''],
            ['item' => 'custom', 'item_name' => 'Pomo', 'unit_name' => 'kg', 'quantity' => '3', 'unit_price' => '4000'],
        ],
    ], $staffId);
    $orderIds[] = (int) $created['id'];

    $expected = ($listed * 2) + (int) $combo['price_subunit'] + (400000 * 3);
    mo_eq($expected, (int) $created['total_subunit'], 'the total is the catalogue price times quantity, plus the combo, plus the typed line');
    mo_ok(str_starts_with((string) $created['order_number'], 'OKV'), 'the order number comes from the one helper, never built by hand');
    mo_ok((string) $created['trail_token'] !== '', 'the order gets a trail token, so the customer has a link to follow');

    $order = Database::one('SELECT * FROM orders WHERE id = :id', [':id' => (int) $created['id']]);
    mo_eq($householdId, (int) $order['user_id'], 'the order belongs to the customer, not to the colleague who took it');
    mo_eq($staffId, (int) $order['created_by'], 'and the record says which colleague took it');
    mo_eq('pending', (string) $order['order_status'], 'a new order starts pending, exactly like a checkout order');
    mo_eq('unpaid', (string) $order['payment_status'], 'creating an order takes no money');
    mo_eq($expected, (int) $order['balance_due_subunit'], 'the whole total is outstanding');
    mo_eq($date, (string) $order['preferred_delivery_date'], 'the delivery day is the one that was chosen');
    mo_ok(
        Checkout::hashToken((string) $created['trail_token']) === (string) $order['order_trail_token_hash'],
        'only the hash of the trail token is stored, so a leaked row yields no working link'
    );

    // Every row a checkout order writes.
    $snapshot = Database::one('SELECT * FROM order_addresses WHERE order_id = :id', [':id' => (int) $created['id']]);
    mo_ok($snapshot !== null, 'the order carries an address snapshot, so it reaches the day manifest with a recipient on it');
    mo_eq('12 Glover Road', (string) $snapshot['address_line_1'], 'the address is the one that was read out');
    mo_eq('+2348031234567', (string) $snapshot['recipient_phone'], 'the phone lands on the canonical form the rest of the system stores');

    $schedule = Database::one('SELECT delivery_date, status FROM delivery_schedules WHERE order_id = :id', [':id' => (int) $created['id']]);
    mo_ok($schedule !== null, 'the order is on the delivery schedule, which is what the day manifest reads');
    mo_eq($date, (string) $schedule['delivery_date'], 'on the day that was chosen');

    $history = Database::all('SELECT * FROM order_status_history WHERE order_id = :id ORDER BY id', [':id' => (int) $created['id']]);
    mo_ok(count($history) >= 1, 'the order opens its own history');
    mo_eq('admin', (string) $history[0]['source'], 'the first line says this came from the back office, not from a basket');
    mo_ok(str_contains((string) $history[0]['note'], 'by phone'), 'and it says how the order reached us');

    $payments = Database::all('SELECT payment_type, expected_amount_subunit, status FROM payments WHERE order_id = :id ORDER BY id', [':id' => (int) $created['id']]);
    mo_eq(1, count($payments), 'a pay-on-delivery order has one payment row');
    mo_eq('pay_on_delivery', (string) $payments[0]['payment_type'], 'and it is the choice that was made');
    mo_eq($expected, (int) $payments[0]['expected_amount_subunit'], 'expecting the whole total');

    $items = Database::all('SELECT * FROM order_items WHERE order_id = :id ORDER BY id', [':id' => (int) $created['id']]);
    mo_eq(3, count($items), 'all three lines are on the order');
    mo_eq($listed, (int) $items[0]['unit_price_subunit'], 'a catalogue line is priced from the sheet, never from the form');
    mo_eq((string) $product['unit_name'], (string) $items[0]['unit_name'], 'and carries the catalogue unit');
    mo_eq('Pomo', (string) $items[2]['item_name'], 'the typed line keeps the name a colleague typed');
    mo_eq(ManualOrder::CUSTOM_SKU, (string) $items[2]['sku'], 'and carries a marker sku, because order_items.sku is NOT NULL');
    mo_eq('product', (string) $items[2]['item_type'], 'a typed line is an ordinary product line with no product behind it, the same shape a free-text Kitchen Run line takes');

    $components = Database::all(
        'SELECT product_name FROM order_item_components WHERE order_item_id = :id',
        [':id' => (int) $items[1]['id']]
    );
    mo_ok(count($components) > 0, 'the combo fans out into its parts, so the packing list reads without the combo definition');

    // A price agreed on the call is honoured, and never invisible.
    $discounted = ManualOrder::create($address + [
        'user_id' => $householdId,
        'payment_option' => 'pay_in_full',
        'channel' => 'whatsapp',
        'delivery_date' => $date,
        'delivery_zone_id' => $zoneId,
        'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1', 'unit_price' => Money::format($listed - 50000, false, false)]],
    ], $staffId);
    $orderIds[] = (int) $discounted['id'];
    mo_eq($listed - 50000, (int) $discounted['total_subunit'], 'a price typed over ours is the price charged');

    $discountHistory = Database::all('SELECT note FROM order_status_history WHERE order_id = :id ORDER BY id', [':id' => (int) $discounted['id']]);
    $noteText = implode(' ', array_map(static fn(array $r): string => (string) $r['note'], $discountHistory));
    mo_ok(str_contains($noteText, 'Price changed on this order'), 'and the discount is written into the order history, never silent');
    mo_ok(str_contains($noteText, Money::format($listed)), 'with the price it would otherwise have been');

    // A deposit order writes both money rows, the same as at checkout.
    $depositOrder = ManualOrder::create($address + [
        'user_id' => $householdId,
        'payment_option' => 'deposit',
        'delivery_date' => $date,
        'delivery_zone_id' => $zoneId,
        'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '4', 'unit_price' => '']],
    ], $staffId);
    $orderIds[] = (int) $depositOrder['id'];
    $depositRows = Database::all('SELECT payment_type, expected_amount_subunit FROM payments WHERE order_id = :id ORDER BY id', [':id' => (int) $depositOrder['id']]);
    mo_eq(2, count($depositRows), 'a deposit order gets the deposit and the balance, exactly as checkout writes them');
    mo_eq('deposit', (string) $depositRows[0]['payment_type'], 'the deposit is due now');
    mo_eq('balance', (string) $depositRows[1]['payment_type'], 'and the balance is due on the day');
    mo_eq(
        (int) $depositOrder['total_subunit'],
        (int) $depositRows[0]['expected_amount_subunit'] + (int) $depositRows[1]['expected_amount_subunit'],
        'the two together are the whole order, with nothing lost to rounding'
    );

    // What the order is refused for.
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $staffId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'bad_customer',
        'an order cannot be built for a staff account'
    );
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $householdId, 'payment_option' => 'on_account', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'payment_not_allowed',
        'a household cannot order on account'
    );
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $businessId, 'payment_option' => 'on_account',
            'delivery_date' => (string) Delivery::nextEligibleDates('business', 5)[0]['date'],
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'credit_not_approved',
        'a business without approved credit cannot order on account, whatever the screen offered'
    );
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => '1999-01-01',
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'delivery_unavailable',
        'a day we do not run is refused on the server, not only greyed out on the screen'
    );
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => 999999999, 'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'zone_unavailable',
        'an area that is not active is refused'
    );
    mo_throws(
        static fn() => ManualOrder::create([
            'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneId, 'recipient_name' => 'Adaeze', 'recipient_phone' => '08031234567',
            'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1']],
        ], $staffId),
        'address_required',
        'an order with nowhere to go is refused'
    );
    mo_throws(
        static fn() => ManualOrder::create($address + [
            'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:999999999', 'quantity' => '1']],
        ], $staffId),
        'product_missing',
        'a product that is not on sale cannot be sold, whatever the form posted'
    );

    // A refused order writes nothing. It is a transaction or it is not an order.
    $before = (int) Database::one('SELECT COUNT(*) AS n FROM orders')['n'];
    try {
        ManualOrder::create($address + [
            'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
            'delivery_zone_id' => $zoneId, 'lines' => [['item' => 'product:999999999', 'quantity' => '1']],
        ], $staffId);
    } catch (DomainException $e) {
        // Expected.
    }
    mo_eq($before, (int) Database::one('SELECT COUNT(*) AS n FROM orders')['n'], 'a refused order leaves no half-written order behind');

    // -----------------------------------------------------------------------
    // 3. Money recorded in the same breath as the order.
    // -----------------------------------------------------------------------
    $payNow = ManualOrder::create($address + [
        'user_id' => $householdId, 'payment_option' => 'pay_in_full', 'delivery_date' => $date,
        'delivery_zone_id' => $zoneId,
        'lines' => [['item' => 'product:' . (int) $product['id'], 'quantity' => '1', 'unit_price' => '']],
    ], $staffId);
    $orderIds[] = (int) $payNow['id'];

    $paymentRow = Database::one(
        'SELECT id FROM payments WHERE order_id = :order AND payment_type = :type',
        [':order' => (int) $payNow['id'], ':type' => 'pay_in_full']
    );
    mo_ok($paymentRow !== null, 'the payment row the order screen records against is findable by its type');

    $recorded = ManualPayments::record([
        'payment_id'     => (int) $paymentRow['id'],
        'amount_subunit' => (int) $payNow['total_subunit'],
        'method'         => 'transfer',
        'record_token'   => ManualPayments::newToken(),
        'bank_reference' => 'GTB-' . $suffix,
    ], $staffId);
    mo_ok(!empty($recorded['ok']), 'money taken on the call is recorded through the ordinary manual path');

    $settled = Database::one('SELECT payment_status, amount_paid_subunit, balance_due_subunit FROM orders WHERE id = :id', [':id' => (int) $payNow['id']]);
    mo_eq((int) $payNow['total_subunit'], (int) $settled['amount_paid_subunit'], 'and the order is credited straight away');
    mo_eq(0, (int) $settled['balance_due_subunit'], 'with nothing left outstanding');
    mo_ok(
        Database::one(
            'SELECT mp.id FROM manual_payment_proofs mp
               JOIN payment_transactions t ON t.id = mp.payment_transaction_id
               JOIN payments p ON p.id = t.payment_id
              WHERE p.order_id = :id AND mp.status = :pending',
            [':id' => (int) $payNow['id'], ':pending' => ManualPayments::PROOF_PENDING]
        ) !== null,
        'and it waits in the proof queue for review, exactly like money recorded on the Payments screen'
    );

    // -----------------------------------------------------------------------
    // 4. A kitchen list typed in for a customer.
    // -----------------------------------------------------------------------
    $typed = KitchenRunWorkflow::submit(
        $householdId,
        'household',
        [
            'input_mode' => 'mixed', 'pricing_mode' => 'by_us',
            'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
            'arrived_by' => 'whatsapp',
            'customer_note' => 'Sent on WhatsApp at 6am.',
            'items' => [
                ['item_name' => 'Pomo', 'quantity' => '6.000', 'unit_id' => $unitId],
                ['product_id' => (int) $product['id'], 'quantity' => '2.000'],
            ],
        ] + $address,
        null,
        $staffId
    );
    $requestIds[] = (int) $typed['id'];

    mo_eq('submitted', (string) $typed['status'], 'a typed-in list lands as submitted, so the ordinary quote path runs unchanged');
    mo_eq(2, (int) $typed['line_count'], 'both lines are on it');

    $request = Database::one('SELECT * FROM kitchen_run_requests WHERE id = :id', [':id' => (int) $typed['id']]);
    mo_eq($householdId, (int) $request['user_id'], 'the run belongs to the customer whose list it is');
    mo_eq($staffId, (int) $request['created_by'], 'and the record says which colleague typed it in');
    mo_ok(str_starts_with((string) $request['request_number'], 'KR') || (string) $request['request_number'] !== '', 'it gets a request number of its own');

    $runHistory = Database::all('SELECT source, changed_by, note FROM kitchen_run_status_history WHERE request_id = :id ORDER BY id', [':id' => (int) $typed['id']]);
    mo_eq('admin', (string) $runHistory[0]['source'], 'the trail says our team recorded it, rather than pretending the customer pressed a button');
    mo_eq($staffId, (int) $runHistory[0]['changed_by'], 'and names who');
    mo_ok(str_contains((string) $runHistory[0]['note'], 'WhatsApp'), 'and says how the list reached us');

    // A customer's own list still records as theirs. The staff path is an
    // addition, not a change to what was already there.
    $ownList = KitchenRunWorkflow::submit($householdId, 'household', [
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'items' => [['item_name' => 'Ugu', 'quantity' => '2.000', 'unit_id' => $unitId]],
    ] + $address);
    $requestIds[] = (int) $ownList['id'];
    $ownHistory = Database::one('SELECT source, changed_by FROM kitchen_run_status_history WHERE request_id = :id ORDER BY id LIMIT 1', [':id' => (int) $ownList['id']]);
    mo_eq('customer', (string) $ownHistory['source'], 'a list the customer sent is still recorded as theirs');
    mo_eq($householdId, (int) $ownHistory['changed_by'], 'in their own name');

    // A typed-in list prices and converts like any other, which is the point.
    $version = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => (int) $typed['id']])['state_version'];
    $quoted = KitchenRunWorkflow::quote((int) $typed['id'], $staffId, $version, [
        'preferred_delivery_date' => $date,
        'delivery_zone_id' => $zoneId,
        'items' => [
            ['item_name' => 'Pomo', 'quantity' => '6.000', 'unit_id' => $unitId, 'unit_price_subunit' => 400000],
            ['product_id' => (int) $product['id'], 'item_name' => (string) $product['name'],
             'quantity' => '2.000', 'unit_id' => $unitId, 'unit_price_subunit' => (int) $product['current_price_subunit']],
        ],
    ]);
    mo_ok((int) $quoted['total_subunit'] > 0, 'a typed-in list prices exactly like a list a customer sent');

    // -----------------------------------------------------------------------
    // 5. Delivery zones a colleague adds and renames.
    // -----------------------------------------------------------------------
    $name = 'Test Zone ' . $suffix;
    $slug = Delivery::uniqueZoneSlug($name);
    Database::run(
        'INSERT INTO delivery_zones (name, slug, area_note, is_active, sort_order) VALUES (:n, :s, :a, 1, :o)',
        [':n' => $name, ':s' => $slug, ':a' => 'Somewhere on the island', ':o' => Delivery::nextZoneSortOrder()]
    );
    $zoneIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();

    mo_ok(
        in_array($slug, array_map(static fn(array $z): string => (string) $z['slug'], Delivery::zonesActive()), true),
        'a zone a colleague adds appears in the checkout area picker straight away'
    );

    $second = Delivery::uniqueZoneSlug($name);
    mo_ok($second !== $slug, 'a second zone by the same name gets a slug of its own, because slug is unique');
    mo_ok(str_starts_with($second, $slug), 'and it is still recognisably the same name');
    mo_eq($slug, Delivery::uniqueZoneSlug($name, $zoneIds[0]), 'renaming a zone to what it is already called keeps its own slug');

    Database::run('UPDATE delivery_zones SET is_active = 0 WHERE id = :id', [':id' => $zoneIds[0]]);
    mo_ok(
        !in_array($slug, array_map(static fn(array $z): string => (string) $z['slug'], Delivery::zonesActive()), true),
        'switching a zone off takes it out of the picker, which is what removing one actually means here'
    );
    mo_ok(
        Database::one('SELECT id FROM delivery_zones WHERE id = :id', [':id' => $zoneIds[0]]) !== null,
        'and the zone itself survives, because past orders point at it'
    );

    mo_ok(Delivery::nextZoneSortOrder() > 0, 'a new zone goes on the end rather than reordering a list a colleague already knows');
} finally {
    foreach ($requestIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
    }
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM order_item_components WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payment_reversals WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM manual_payment_proofs WHERE payment_transaction_id IN (SELECT t.id FROM payment_transactions t JOIN payments p ON p.id = t.payment_id WHERE p.order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payment_transactions WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($zoneIds as $id) {
        Database::run('DELETE FROM delivery_zones WHERE id = :id', [':id' => $id]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM business_customers WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id OR entity_id = :entity', [':id' => $id, ':entity' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    if ($staffId) {
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id', [':id' => $staffId]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $staffId]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $staffId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests manual operations database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
