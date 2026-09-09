<?php
/**
 * scripts/tests/credit_admin_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. M8 Task G. The staff side of credit against a real MySQL 8
 * database: reviewing applications, granting a facility by hand, moving a
 * facility between its states, recording a repayment, and the ageing view.
 *
 *   php scripts/tests/credit_admin_db_test.php
 *
 * Creates its own users, businesses, orders and payments, asserts, then removes
 * everything it made. Run it after php scripts/migrate.php on a scratch
 * database.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0;
$passed = 0;

function cad_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}

function cad_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    cad_ok($same, $label);
}

function cad_refuses(callable $work, string $code, string $label): void
{
    try { $work(); cad_ok(false, $label . ' (nothing was refused)'); }
    catch (DomainException $e) { cad_eq($code, $e->getMessage(), $label); }
}

$suffix     = bin2hex(random_bytes(4));
$users      = [];
$businesses = [];
$orders     = [];

/** The outstanding figure the screens read, straight from the service. */
$outstanding = static function (int $businessId): int {
    $business = Database::one('SELECT * FROM business_customers WHERE id = :id', [':id' => $businessId]);
    return (int) Credit::summaryForBusiness($business)['outstanding_subunit'];
};

try {
    // Two businesses and one staff member to act on them.
    foreach (['A', 'B', 'Staff'] as $index => $name) {
        $type = $index === 2 ? 'staff' : 'business';
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:f, :l, :e, :p, :h, :t, :s)',
            [':f' => $name, ':l' => 'Credit', ':e' => "cad-$index-$suffix@example.test",
             ':p' => '+23468' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
             ':t' => $type, ':s' => 'active']
        );
        $users[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
        if ($index < 2) {
            Database::run(
                'INSERT INTO business_customers (user_id, business_name, contact_person) VALUES (:u, :n, :c)',
                [':u' => $users[$index], ':n' => "$name Credit $suffix", ':c' => $name]
            );
            $businesses[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
        }
    }

    $input = [
        'requested_days'  => '10',
        'requested_limit' => '500,000',
        'reason'          => 'Produce buying for our restaurant every working week.',
    ];

    // ---- 1. Reviewing an application ---------------------------------------
    $application = Credit::apply($users[0], $input);
    $decision    = Credit::approveApplication($application['id'], $users[2], 10, '400,000');
    cad_ok(!$decision['already'], 'a pending application is approved');
    cad_ok(Credit::approveApplication($application['id'], $users[2], 10, '400,000')['already'],
        'an identical approval retry is idempotent');
    cad_refuses(fn() => Credit::approveApplication($application['id'], $users[2], 7, '300,000'),
        'conflicting_review', 'a conflicting approval retry is refused');
    cad_eq(40000000, (int) Database::one('SELECT credit_limit_subunit FROM business_customers WHERE id = :id',
        [':id' => $businesses[0]])['credit_limit_subunit'], 'the approved limit is what the facility carries');

    // ---- 2. Declining, then granting by hand -------------------------------
    $declined = Credit::apply($users[1], $input);
    Credit::declineApplication($declined['id'], $users[2], 'More trading history is needed.');
    cad_eq('declined', (string) Database::one('SELECT status FROM credit_applications WHERE id = :id',
        [':id' => $declined['id']])['status'], 'decline closes the application');
    cad_eq('More trading history is needed.', (string) Database::one(
        'SELECT decision_reason FROM credit_applications WHERE id = :id',
        [':id' => $declined['id']])['decision_reason'], 'the customer gets the reason they were given');

    $reapplied = Credit::apply($users[1], $input);
    Credit::grant($businesses[1], $users[2], 7, '300,000');
    cad_eq('approved', (string) Database::one('SELECT status FROM credit_applications WHERE id = :id',
        [':id' => $reapplied['id']])['status'], 'manual grant approves a pending application');

    // ---- 3. Moving a facility between its states ---------------------------
    Credit::changeTerms($businesses[1], 8, '350,000');
    Credit::transitionFacility($businesses[1], 'suspended');
    cad_eq('suspended', (string) Database::one('SELECT credit_status FROM business_customers WHERE id = :id',
        [':id' => $businesses[1]])['credit_status'], 'approved credit can be suspended');
    Credit::transitionFacility($businesses[1], 'approved');
    Credit::transitionFacility($businesses[1], 'withdrawn');
    cad_refuses(fn() => Credit::transitionFacility($businesses[1], 'approved'),
        'invalid_transition', 'withdrawn credit is not directly reinstated');
    Credit::grant($businesses[1], $users[2], 8, '350,000');

    // ---- 4. Recording a repayment by hand ----------------------------------
    // A charge on the books, and money that arrived outside any credit order.
    Database::run(
        'INSERT INTO credit_transactions (business_customer_id, transaction_type, amount_subunit, due_date, status)
         VALUES (:b, :t, :a, :d, :s)',
        [':b' => $businesses[1], ':t' => 'charge', ':a' => 10000000,
         ':d' => date('Y-m-d', strtotime('+7 days')), ':s' => 'posted']
    );

    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
                             preferred_delivery_date)
         VALUES (:n, :u, :ct, :os, :po, :ps, :st, :ot, :ap, :bd, :dd)',
        [':n' => "CAD-$suffix", ':u' => $users[1], ':ct' => 'business', ':os' => 'confirmed',
         ':po' => 'full', ':ps' => 'paid', ':st' => 3000000, ':ot' => 3000000, ':ap' => 3000000,
         ':bd' => 0, ':dd' => date('Y-m-d', strtotime('+7 days'))]
    );
    $prepaidId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[]  = $prepaidId;

    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type,
                               expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
         VALUES (:n, :u, :o, :p, :t, :e, :a, :s, NOW())',
        [':n' => "CAD-P-$suffix", ':u' => $users[1], ':o' => $prepaidId, ':p' => 'manual',
         ':t' => 'full', ':e' => 3000000, ':a' => 3000000, ':s' => 'paid']
    );
    $paymentId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $repayment = Credit::recordRepayment($businesses[1], $paymentId);
    cad_eq(3000000, $repayment['amount_subunit'], 'repayment uses the payment net amount');
    cad_refuses(fn() => Credit::recordRepayment($businesses[1], $paymentId),
        'repayment_recorded', 'one payment cannot credit the journal twice');
    cad_eq(7000000, $outstanding($businesses[1]), 'repayment frees the correct credit');

    // An on-account order settles itself, so the manual path stands aside
    // rather than counting the same money a second time.
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
                             preferred_delivery_date)
         VALUES (:n, :u, :ct, :os, :po, :ps, :st, :ot, :ap, :bd, :dd)',
        [':n' => "CAD-A-$suffix", ':u' => $users[1], ':ct' => 'business', ':os' => 'confirmed',
         ':po' => 'on_account', ':ps' => 'part_paid', ':st' => 5000000, ':ot' => 5000000, ':ap' => 1000000,
         ':bd' => 4000000, ':dd' => date('Y-m-d', strtotime('+7 days'))]
    );
    $accountOrderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orders[]       = $accountOrderId;
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type,
                               expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
         VALUES (:n, :u, :o, :p, :t, :e, :a, :s, NOW())',
        [':n' => "CAD-A-P-$suffix", ':u' => $users[1], ':o' => $accountOrderId, ':p' => 'manual',
         ':t' => 'balance', ':e' => 5000000, ':a' => 1000000, ':s' => 'part_paid']
    );
    $accountPaymentId = (int) Database::getInstance()->getConnection()->lastInsertId();
    cad_refuses(fn() => Credit::recordRepayment($businesses[1], $accountPaymentId),
        'settles_itself', 'a payment on a credit order is never credited by hand');

    // ---- 5. The ageing view ------------------------------------------------
    $ageing = Credit::ageing();
    cad_ok(count($ageing) >= 2, 'ageing returns managed credit accounts');

    $row = null;
    foreach ($ageing as $candidate) {
        if ((int) $candidate['id'] === $businesses[1]) { $row = $candidate; }
    }
    cad_ok($row !== null, 'the business with a balance appears in the ageing list');
    cad_eq(7000000, (int) ($row['summary']['buckets']['not_yet_due'] ?? -1),
        'a charge due next week sits in the not-yet-due bucket');
    cad_eq(0, (int) ($row['summary']['overdue_subunit'] ?? -1), 'and nothing on that account is overdue');

    // Age the charge and watch it move through the buckets.
    foreach ([[3, 'due_1_7'], [12, 'due_8_30'], [45, 'due_over_30']] as [$daysLate, $bucket]) {
        Database::run(
            'UPDATE credit_transactions SET due_date = :due WHERE business_customer_id = :b AND transaction_type = :t',
            [':due' => date('Y-m-d', strtotime("-$daysLate days")), ':b' => $businesses[1], ':t' => 'charge']
        );
        $aged = null;
        foreach (Credit::ageing() as $candidate) {
            if ((int) $candidate['id'] === $businesses[1]) { $aged = $candidate; }
        }
        cad_eq(7000000, (int) ($aged['summary']['buckets'][$bucket] ?? -1),
            "a charge $daysLate days past its due date ages into $bucket");
        cad_eq(7000000, (int) ($aged['summary']['overdue_subunit'] ?? -1),
            "and it counts as overdue at $daysLate days late");
    }

    $totals = Credit::ageingTotals(Credit::ageing());
    cad_eq(7000000, (int) $totals['due_over_30'], 'the ageing totals add up the buckets across the book');
    cad_ok((int) $totals['outstanding_subunit'] >= 7000000, 'the ageing totals add up what is outstanding');
} finally {
    foreach ($businesses as $id) {
        Database::run('DELETE FROM credit_transactions WHERE business_customer_id = :id', [':id' => $id]);
        Database::run('DELETE FROM credit_applications WHERE business_customer_id = :id', [':id' => $id]);
    }
    foreach ($orders as $id) {
        Database::run('DELETE FROM credit_transactions WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $id]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $id]);
    }
    foreach ($businesses as $id) {
        Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $id]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
}

fwrite(STDOUT, "\n$passed / $tests credit admin assertions passed.\n");
exit($passed === $tests ? 0 : 1);
