<?php
/**
 * scripts/tests/seed_visual_fixture.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The fixture the browser pass drives.
 *
 * Creates one business with a live credit facility and open charges in all four
 * ageing buckets, a few confirmed payments the repayment picker can offer, a
 * saved kitchen list, a second business with a pending application, and a
 * household to prove the Pro gate turns one away.
 *
 *   php scripts/migrate.php
 *   php scripts/tests/seed_visual_fixture.php
 *   npm run test:visual
 *
 * SCRATCH DATABASES ONLY. It writes customers and orders, and it deletes and
 * rewrites its own fixture rows on every run. Never point it at production.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (env('APP_ENV', 'production') === 'production') {
    fwrite(STDERR, "Refusing to seed fixture data with APP_ENV=production.\n");
    exit(2);
}

$pw = 'm8-visual-pass-123';
$emails = ['biz' => 'm8biz@example.test', 'house' => 'm8house@example.test'];

// Clean any earlier run.
foreach ($emails as $email) {
    $row = Database::one('SELECT id FROM users WHERE email = :e', [':e' => $email]);
    if (!$row) { continue; }
    $uid = (int) $row['id'];
    Database::run('DELETE ct FROM credit_transactions ct JOIN business_customers bc ON bc.id = ct.business_customer_id WHERE bc.user_id = :u', [':u' => $uid]);
    Database::run('DELETE ct FROM credit_transactions ct JOIN orders o ON o.id = ct.order_id WHERE o.user_id = :u', [':u' => $uid]);
    Database::run('DELETE ca FROM credit_applications ca JOIN business_customers bc ON bc.id = ca.business_customer_id WHERE bc.user_id = :u', [':u' => $uid]);
    Database::run('DELETE t FROM payment_transactions t JOIN payments p ON p.id = t.payment_id JOIN orders o ON o.id = p.order_id WHERE o.user_id = :u', [':u' => $uid]);
    Database::run('DELETE p FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.user_id = :u', [':u' => $uid]);
    foreach (['order_items', 'order_status_history', 'order_addresses', 'delivery_schedules'] as $table) {
        Database::run("DELETE t FROM $table t JOIN orders o ON o.id = t.order_id WHERE o.user_id = :u", [':u' => $uid]);
    }
    Database::run('DELETE FROM orders WHERE user_id = :u', [':u' => $uid]);
    Database::run('DELETE i FROM kitchen_run_template_items i JOIN kitchen_run_templates t ON t.id = i.template_id WHERE t.user_id = :u', [':u' => $uid]);
    Database::run('DELETE FROM kitchen_run_templates WHERE user_id = :u', [':u' => $uid]);
    Database::run('DELETE FROM business_customers WHERE user_id = :u', [':u' => $uid]);
    Database::run('DELETE FROM customer_addresses WHERE user_id = :u', [':u' => $uid]);
    Database::run('DELETE FROM users WHERE id = :u', [':u' => $uid]);
}

/** A customer of the given type. */
$makeUser = function (string $email, string $type, string $first) use ($pw): int {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, :l, :e, :p, :h, :t, \'active\', NOW())',
        [':f' => $first, ':l' => 'Visual', ':e' => $email, ':p' => '+23481' . random_int(10000000, 99999999),
         ':h' => password_hash($pw, PASSWORD_BCRYPT), ':t' => $type]
    );
    return (int) Database::getInstance()->getConnection()->lastInsertId();
};

$bizId   = $makeUser($emails['biz'], 'business', 'Amaka');
$houseId = $makeUser($emails['house'], 'household', 'Tunde');

Database::run(
    'INSERT INTO business_customers (user_id, business_name, contact_person, business_type, credit_requested,
                                     credit_status, credit_days, credit_limit_subunit)
     VALUES (:u, :n, :c, :bt, 1, \'approved\', 10, :lim)',
    [':u' => $bizId, ':n' => 'Mama Chidi Kitchen', ':c' => 'Amaka Visual', ':bt' => 'restaurant', ':lim' => 100000000]
);
$businessId = (int) Database::getInstance()->getConnection()->lastInsertId();

$zoneId = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
$unit   = Database::one('SELECT id, name FROM units_of_measurement ORDER BY id LIMIT 1');
$prod   = Database::one('SELECT id, name, current_price_subunit FROM products WHERE is_active = 1 AND current_price_subunit IS NOT NULL ORDER BY id LIMIT 1');

/** One on-account order with a charge aged by the given number of days. */
$makeCreditOrder = function (int $daysLate, int $amount, string $status) use ($bizId, $businessId, $zoneId, $prod, $unit): int {
    $due = date('Y-m-d', strtotime($daysLate > 0 ? "-$daysLate days" : '+7 days'));
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date,
                             delivery_zone_id, order_trail_token_hash)
         VALUES (:n, :u, \'business\', :os, \'on_account\', \'unpaid\', :a, :a2, :a3, :d, :z, :tok)',
        [':n' => OrderNumber::nextOrderNumber(Database::getInstance()->getConnection()), ':u' => $bizId, ':os' => $status, ':a' => $amount, ':a2' => $amount,
         ':a3' => $amount, ':d' => date('Y-m-d', strtotime('+3 days')), ':z' => $zoneId,
         ':tok' => hash('sha256', 'visual-' . random_int(1, 1000000))]
    );
    $orderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO order_items (order_id, item_type, product_id, item_name, sku, quantity, unit_name,
                                  unit_price_subunit, line_total_subunit)
         VALUES (:o, \'product\', :p, :n, :sku, 4, :un, :up, :lt)',
        [':o' => $orderId, ':p' => (int) $prod['id'], ':n' => (string) $prod['name'],
         ':sku' => 'VIS-' . (int) $prod['id'], ':un' => (string) $unit['name'],
         ':up' => intdiv($amount, 4), ':lt' => $amount]
    );
    Database::run(
        'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by)
         VALUES (:o, NULL, :s, \'customer\', :u)',
        [':o' => $orderId, ':s' => $status, ':u' => $bizId]
    );
    Database::run(
        'INSERT INTO credit_transactions (business_customer_id, order_id, transaction_type, source_key,
                                          amount_subunit, due_date, status)
         VALUES (:b, :o, \'charge\', :k, :a, :d, \'posted\')',
        [':b' => $businessId, ':o' => $orderId, ':k' => 'order:' . $orderId . ':charge',
         ':a' => $amount, ':d' => $due]
    );
    return $orderId;
};

// One charge in each ageing bucket, so the admin table has something to show.
$makeCreditOrder(0,  1200000, 'confirmed');
$makeCreditOrder(4,   850000, 'delivered');
$makeCreditOrder(20, 2300000, 'delivered');
$makeCreditOrder(52,  600000, 'delivered');

// Prepaid orders with confirmed money on them: the one thing the manual
// repayment picker is allowed to offer.
foreach ([['Kitchen top up', 380000, 11], ['Weekend order', 520000, 17]] as [$note, $extra, $ago]) {
    Database::run(
        'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                             subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
                             preferred_delivery_date, delivery_zone_id, order_trail_token_hash)
         VALUES (:n, :u, \'business\', \'delivered\', \'full\', \'paid\', :a, :a2, :a3, 0, :d, :z, :tok)',
        [':n' => OrderNumber::nextOrderNumber(Database::getInstance()->getConnection()), ':u' => $bizId,
         ':a' => $extra, ':a2' => $extra, ':a3' => $extra,
         ':d' => date('Y-m-d', strtotime("-$ago days")), ':z' => $zoneId,
         ':tok' => hash('sha256', 'visual-extra-' . random_int(1, 1000000))]
    );
    $extraOrderId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type,
                               expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
         VALUES (:n, :u, :o, \'manual\', \'full\', :a, :a2, \'paid\', :when)',
        [':n' => 'VIS-PAY-' . random_int(1000, 9999), ':u' => $bizId, ':o' => $extraOrderId,
         ':a' => $extra, ':a2' => $extra, ':when' => date('Y-m-d H:i:s', strtotime("-$ago days"))]
    );
}

Database::run(
    'INSERT INTO orders (order_number, user_id, customer_type, order_status, payment_option, payment_status,
                         subtotal_subunit, order_total_subunit, amount_paid_subunit, balance_due_subunit,
                         preferred_delivery_date, delivery_zone_id, order_trail_token_hash)
     VALUES (:n, :u, \'business\', \'delivered\', \'full\', \'paid\', 450000, 450000, 450000, 0, :d, :z, :tok)',
    [':n' => OrderNumber::nextOrderNumber(Database::getInstance()->getConnection()), ':u' => $bizId, ':d' => date('Y-m-d', strtotime('-6 days')),
     ':z' => $zoneId, ':tok' => hash('sha256', 'visual-prepaid-' . random_int(1, 1000000))]
);
$prepaidId = (int) Database::getInstance()->getConnection()->lastInsertId();
Database::run(
    'INSERT INTO payments (payment_number, user_id, order_id, provider, payment_type,
                           expected_amount_subunit, paid_amount_subunit, status, confirmed_at)
     VALUES (:n, :u, :o, \'manual\', \'full\', 450000, 450000, \'paid\', NOW())',
    [':n' => 'VIS-PAY-' . random_int(1000, 9999), ':u' => $bizId, ':o' => $prepaidId]
);

// A saved kitchen list, so the Pro screens are not empty.
Database::run(
    'INSERT INTO kitchen_run_templates (user_id, name, note) VALUES (:u, :n, :note)',
    [':u' => $bizId, ':n' => 'Monday market run', ':note' => 'Standing order for the Ikeja kitchen.']
);
$templateId = (int) Database::getInstance()->getConnection()->lastInsertId();
foreach ([['Tomatoes', '6', 'kg'], ['Pomo', '10', 'kg'], ['Scotch bonnet', '2', 'kg']] as $i => [$name, $qty, $unitName]) {
    Database::run(
        'INSERT INTO kitchen_run_template_items (template_id, item_name, quantity, unit_label, sort_order)
         VALUES (:t, :n, :q, :un, :p)',
        [':t' => $templateId, ':n' => $name, ':q' => $qty, ':un' => $unitName, ':p' => $i + 1]
    );
}

// A pending application from a second business, so the queue is not empty.
$otherId = Database::one('SELECT id FROM users WHERE email = :e', [':e' => 'm8other@example.test']);
if (!$otherId) {
    $other = $makeUser('m8other@example.test', 'business', 'Bisi');
    Database::run(
        'INSERT INTO business_customers (user_id, business_name, contact_person, credit_requested, credit_status)
         VALUES (:u, :n, :c, 0, \'not_requested\')',
        [':u' => $other, ':n' => 'Bisi Bites Mart', ':c' => 'Bisi Visual']
    );
    Credit::apply($other, [
        'requested_days'  => '10',
        'requested_limit' => '750,000',
        'reason'          => 'We buy for three outlets every week and would rather settle on terms than carry cash.',
    ]);
}

fwrite(STDOUT, "business: {$emails['biz']}\nhousehold: {$emails['house']}\npassword: $pw\n");
