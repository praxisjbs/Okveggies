<?php
/**
 * scripts/tests/customers_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Task I against MySQL 8. The customer list, the search, the type
 * filter, paging, and every slice the profile shows. Creates its own records
 * with a run suffix and removes them again in the finally block.
 *
 *   php scripts/tests/customers_db_test.php
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$t = 0; $p = 0;
function cus_ok($condition, string $label): void
{
    global $t, $p; $t++;
    if ($condition) { $p++; } else { fwrite(STDERR, " FAIL: $label\n"); }
}
function cus_eq($expected, $actual, string $label): void
{
    cus_ok($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}
function cus_id(): int { return (int) Database::getInstance()->getConnection()->lastInsertId(); }

$s = bin2hex(random_bytes(4));
$users = []; $businessId = 0; $orderIds = []; $runIds = [];

try {
    // A household, a business and a staff account. Staff are not customers.
    foreach ([['Household', 'household'], ['Business', 'business'], ['Staff', 'staff']] as $i => [$name, $type]) {
        Database::run(
            'INSERT INTO users(first_name,last_name,email,phone,password_hash,user_type,status)
             VALUES(:f,:l,:e,:p,:h,:t,:s)',
            [':f' => $name, ':l' => 'Test' . $s, ':e' => "cus-$i-$s@example.test",
             ':p' => '+23470' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
             ':t' => $type, ':s' => 'active']
        );
        $users[$i] = cus_id();
    }
    Database::run(
        'INSERT INTO business_customers(user_id,business_name,business_type,contact_person,credit_status,credit_days,credit_limit_subunit)
         VALUES(:u,:n,:bt,:c,:cs,:cd,:cl)',
        [':u' => $users[1], ':n' => "Mama Chidi Kitchen $s", ':bt' => 'Restaurant', ':c' => 'Chidi Obi',
         ':cs' => 'approved', ':cd' => 10, ':cl' => 50000000]
    );
    $businessId = cus_id();

    // -------------------------------------------------------------------------
    // Search. Name, email, phone number and business name all find the account.
    // -------------------------------------------------------------------------
    $byName = Customers::listing(['search' => 'Test' . $s], 1);
    cus_eq(2, $byName['count'], 'the surname finds the household and the business, and nobody else');
    cus_ok(!in_array($users[2], array_map(static fn(array $r): int => (int) $r['id'], $byName['customers']), true),
        'a staff account never appears in the customer list');

    cus_eq(1, Customers::listing(['search' => "cus-1-$s@example.test"], 1)['count'], 'an email address finds one customer');
    cus_eq(1, Customers::listing(['search' => "Mama Chidi Kitchen $s"], 1)['count'], 'a business name finds the business');
    $phone = (string) Database::one('SELECT phone FROM users WHERE id = :id', [':id' => $users[0]])['phone'];
    cus_eq(1, Customers::listing(['search' => $phone], 1)['count'], 'a phone number finds the household');
    cus_eq(0, Customers::listing(['search' => 'no such customer ' . $s], 1)['count'], 'an unknown search finds nobody');
    cus_eq(0, Customers::listing(['search' => "'; DROP TABLE users; --"], 1)['count'], 'a hostile search term is just a search term');
    cus_ok(Database::one('SELECT COUNT(*) AS n FROM users WHERE id = :id', [':id' => $users[0]])['n'] > 0, 'the users table is still standing');

    $byType = Customers::listing(['search' => 'Test' . $s, 'type' => 'business'], 1);
    cus_eq(1, $byType['count'], 'the account type filter narrows the list');
    cus_eq('business', (string) $byType['customers'][0]['user_type'], 'the business is the row that survives the filter');
    cus_eq("Mama Chidi Kitchen $s", Customers::displayName($byType['customers'][0]), 'the list shows the business name');

    $listed = Customers::listing(['search' => 'Test' . $s], 9);
    cus_eq(1, $listed['page'], 'a page beyond the last one falls back to the last page');
    cus_eq(1, $listed['lastPage'], 'two customers fit on one page of 25');

    // -------------------------------------------------------------------------
    // One customer.
    // -------------------------------------------------------------------------
    $household = Customers::find($users[0]);
    cus_ok($household !== null, 'the household profile opens');
    cus_eq(null, $household['business'], 'a household carries no business profile, so no credit can be invented for it');
    cus_ok(Customers::find($users[2]) === null, 'a staff account cannot be opened as a customer');
    cus_ok(Customers::find(0) === null, 'an unknown customer number opens nothing');

    $business = Customers::find($users[1]);
    cus_eq("Mama Chidi Kitchen $s", (string) $business['business']['business_name'], 'the business profile carries the business name');
    cus_eq($businessId, (int) $business['business']['id'], 'the business profile points at the right facility');

    // -------------------------------------------------------------------------
    // Addresses.
    // -------------------------------------------------------------------------
    foreach ([['Home', 1], ['Shop', 0]] as [$label, $isDefault]) {
        Database::run(
            'INSERT INTO customer_addresses(user_id,label,recipient_name,recipient_phone,address_line_1,city,state,is_default)
             VALUES(:u,:l,:r,:p,:a,:c,:s,:d)',
            [':u' => $users[1], ':l' => $label, ':r' => 'Chidi Obi', ':p' => '+2348030000000',
             ':a' => '12 Adeniyi Jones Avenue', ':c' => 'Ikeja', ':s' => 'Lagos', ':d' => $isDefault]
        );
    }
    $addresses = Customers::addresses($users[1]);
    cus_eq(2, count($addresses), 'both saved addresses are listed');
    cus_eq('Home', (string) $addresses[0]['label'], 'the default address is listed first');
    cus_eq(0, count(Customers::addresses($users[0])), 'a customer with no address gets an empty list, not an error');

    // -------------------------------------------------------------------------
    // Orders, payments, Kitchen Runs.
    // -------------------------------------------------------------------------
    for ($i = 0; $i < 12; $i++) {
        Database::run(
            'INSERT INTO orders(order_number,user_id,customer_type,order_status,payment_option,payment_status,
                                subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date)
             VALUES(:n,:u,:ct,:os,:po,:ps,:st,:ot,:ap,:bd,:dd)',
            [':n' => "CUS-$s-$i", ':u' => $users[1], ':ct' => 'business', ':os' => 'confirmed',
             ':po' => 'on_account', ':ps' => 'unpaid', ':st' => 1000000, ':ot' => 1000000,
             ':ap' => 0, ':bd' => 1000000, ':dd' => date('Y-m-d', strtotime('+3 days'))]
        );
        $orderIds[] = cus_id();
    }
    $orders = Customers::orders($users[1]);
    cus_eq(10, count($orders), 'the profile shows the last 10 orders out of 12');
    cus_eq("CUS-$s-11", (string) $orders[0]['order_number'], 'the newest order is at the top');
    cus_eq(3, count(Customers::orders($users[1], 3)), 'a smaller slice is honoured');

    Database::run(
        'INSERT INTO payments(payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,
                              paid_amount_subunit,refunded_amount_subunit,status,confirmed_at)
         VALUES(:n,:u,:o,:pr,:t,:e,:a,:r,:s,NOW())',
        [':n' => "CUS-P-$s", ':u' => $users[1], ':o' => $orderIds[0], ':pr' => 'paystack', ':t' => 'full',
         ':e' => 1000000, ':a' => 1000000, ':r' => 250000, ':s' => 'paid']
    );
    $payments = Customers::payments($users[1]);
    cus_eq(1, count($payments), 'the payment is listed against the customer');
    cus_eq("CUS-$s-0", (string) $payments[0]['order_number'], 'the payment carries the order it belongs to');

    Database::run(
        'INSERT INTO kitchen_run_requests(request_number,user_id,customer_type,input_mode,pricing_mode,status,
                                          quoted_total_subunit,preferred_delivery_date)
         VALUES(:n,:u,:ct,:im,:pm,:st,:q,:dd)',
        [':n' => "KR-CUS-$s", ':u' => $users[1], ':ct' => 'business', ':im' => 'typed_list', ':pm' => 'by_us',
         ':st' => 'submitted', ':q' => 2500000, ':dd' => date('Y-m-d', strtotime('+4 days'))]
    );
    $runIds[] = cus_id();
    cus_eq(1, count(Customers::kitchenRuns($users[1])), 'the Kitchen Run is listed against the customer');
    cus_eq(0, count(Customers::kitchenRuns($users[0])), 'the household has sent no Kitchen Run');

    // -------------------------------------------------------------------------
    // Totals, read from the same records the order and payment screens read.
    // -------------------------------------------------------------------------
    $totals = Customers::totals($users[1]);
    cus_eq(12, $totals['order_count'], 'every order is counted');
    cus_eq(12000000, $totals['ordered_subunit'], 'the ordered total adds up in kobo');
    cus_eq(12000000, $totals['outstanding_subunit'], 'the outstanding total adds up in kobo');
    cus_eq(750000, $totals['paid_subunit'], 'a refund is taken off what the customer has paid');
    cus_eq(0, Customers::totals($users[0])['order_count'], 'a customer with no order totals nothing');

    // -------------------------------------------------------------------------
    // Credit. Figures come from the signed journal through the shared class.
    // -------------------------------------------------------------------------
    foreach ([[3000000, 'charge', '+10 days'], [-1000000, 'repayment', null]] as [$amount, $type, $due]) {
        Database::run(
            'INSERT INTO credit_transactions(business_customer_id,order_id,transaction_type,amount_subunit,due_date,status)
             VALUES(:b,:o,:t,:a,:d,:s)',
            [':b' => $businessId, ':o' => $orderIds[0], ':t' => $type, ':a' => $amount,
             ':d' => $due === null ? null : date('Y-m-d', strtotime($due)), ':s' => 'posted']
        );
    }
    $entries = Customers::creditEntries($businessId);
    cus_eq(2, count($entries), 'both journal entries are listed');
    cus_eq('repayment', (string) $entries[0]['transaction_type'], 'the newest journal entry is at the top');
    cus_eq("CUS-$s-0", (string) $entries[1]['order_number'], 'a charge shows the order it came from');

    $account = Credit::customerAccount($users[1]);
    cus_eq(2000000, (int) $account['summary']['outstanding_subunit'], 'outstanding credit is the sum of the signed journal');
    cus_eq(48000000, (int) $account['summary']['available_subunit'], 'available credit is the limit less what is outstanding');
    cus_ok(Credit::customerAccount($users[0]) === null, 'a household has no credit account to read');

} finally {
    Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id', [':id' => $businessId]);
    foreach ($orderIds as $id) {
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($runIds as $id) { Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]); }
    foreach ($users as $id) { Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]); }
    if ($businessId > 0) { Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $businessId]); }
    foreach ($users as $id) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
}

fwrite(STDOUT, "\n$p / $t customer assertions passed.\n");
exit($p === $t ? 0 : 1);
