<?php
/** Task C saved Kitchen List CRUD, conversion and business isolation on MySQL 8. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function kldb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function kldb_eq($expected, $actual, string $label): void { kldb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function kldb_refuses(callable $work, string $code, string $label): void { try { $work(); kldb_ok(false, $label); } catch (DomainException $e) { kldb_eq($code, $e->getMessage(), $label); } }

$suffix = bin2hex(random_bytes(5));
$users = []; $businesses = []; $lists = []; $runId = 0;
$product = Database::one('SELECT id, name, unit_id, is_active FROM products WHERE is_active = 1 ORDER BY id LIMIT 1');
$unitId = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];
$zoneId = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];

try {
    foreach (['First', 'Second'] as $index => $name) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (:first_name, :last_name, :email, :phone, :password_hash, :user_type, :status, NOW())',
            [':first_name' => $name, ':last_name' => 'Kitchen', ':email' => "kldb-$index-$suffix@example.test",
             ':phone' => '+23471' . random_int(10000000, 99999999), ':password_hash' => password_hash('test-only', PASSWORD_BCRYPT),
             ':user_type' => 'business', ':status' => 'active']
        );
        $users[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
        Database::run(
            'INSERT INTO business_customers (user_id, business_name, contact_person) VALUES (:user_id, :business_name, :contact_person)',
            [':user_id' => $users[$index], ':business_name' => "$name Kitchen $suffix", ':contact_person' => "$name Kitchen"]
        );
        $businesses[$index] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    $created = KitchenLists::create($users[0], 'Tuesday restock', 'Main kitchen', [
        ['product_id' => $product['id'], 'quantity' => '2.500', 'note' => 'Firm only'],
        ['item_name' => 'Pomo', 'quantity' => '10', 'unit_id' => $unitId, 'note' => 'Soft cuts'],
    ]);
    $lists[] = (int) $created['id'];
    kldb_eq(2, (int) $created['item_count'], 'a mixed saved list keeps both lines');

    $stored = KitchenLists::findForCustomer((int) $created['id'], $users[0]);
    kldb_eq('Tuesday restock', (string) $stored['name'], 'the owner reads the saved list by name');
    kldb_eq('Main kitchen', (string) $stored['note'], 'the overall list note is retained');
    kldb_eq((int) $product['id'], (int) $stored['items'][0]['product_id'], 'the catalogue product link is retained');
    kldb_eq('Firm only', (string) $stored['items'][0]['note'], 'the catalogue line note is retained');
    kldb_eq('Pomo', (string) $stored['items'][1]['item_name'], 'the free-text line is retained');
    kldb_eq('Soft cuts', (string) $stored['items'][1]['note'], 'the free-text line note is retained');
    kldb_eq(null, KitchenLists::findForCustomer((int) $created['id'], $users[1]), 'another business cannot read the saved list');
    kldb_eq(0, count(KitchenLists::allForCustomer($users[1])), 'another business list view stays empty');

    kldb_refuses(
        static fn() => KitchenLists::create($users[0], 'tuesday RESTOCK', null, [['item_name' => 'Oil', 'quantity' => '1', 'unit_id' => $unitId]]),
        'duplicate_name',
        'duplicate names are refused case-insensitively within one business'
    );
    kldb_refuses(
        static fn() => KitchenLists::update((int) $created['id'], $users[1], 'Stolen', null, [['item_name' => 'Oil', 'quantity' => '1', 'unit_id' => $unitId]]),
        'not_found',
        'another business cannot update the saved list'
    );

    KitchenLists::update((int) $created['id'], $users[0], 'Friday restock', 'Cold room', [
        ['item_name' => 'Pomo', 'quantity' => '8', 'unit_id' => $unitId, 'note' => 'First'],
        ['product_id' => $product['id'], 'quantity' => '3', 'note' => 'Second'],
    ]);
    $updated = KitchenLists::findForCustomer((int) $created['id'], $users[0]);
    kldb_eq(['Pomo', (string) $product['name']], array_column($updated['items'], 'item_name'), 'updating preserves the posted item order');

    $businessDate = (string) Delivery::nextEligibleDates('business', 1)[0]['date'];
    $run = KitchenRunWorkflow::submit($users[0], 'business', [
        'input_mode' => 'mixed', 'pricing_mode' => 'by_us', 'customer_note' => 'Use the rear entrance',
        'preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId,
        'recipient_name' => 'First Kitchen', 'recipient_phone' => '08031234567',
        'address_line_1' => '12 Test Street', 'city' => 'Lagos', 'state' => 'Lagos',
        'items' => [
            ['product_id' => $product['id'], 'quantity' => '1', 'note' => 'Green ones'],
            ['item_name' => 'Stock fish', 'quantity' => '4', 'unit_id' => $unitId, 'note' => 'Large pieces'],
        ],
    ]);
    $runId = (int) $run['id'];
    $fromRun = KitchenLists::saveFromRun($runId, $users[0], 'Soup list', 'Use the rear entrance');
    $lists[] = (int) $fromRun['id'];
    $runList = KitchenLists::findForCustomer((int) $fromRun['id'], $users[0]);
    kldb_eq('Use the rear entrance', (string) $runList['note'], 'saving a run keeps its overall note');
    kldb_eq(['Green ones', 'Large pieces'], array_column($runList['items'], 'note'), 'saving a run keeps every line note in order');

    Database::run('UPDATE products SET is_active = :inactive WHERE id = :id', [':inactive' => 0, ':id' => (int) $product['id']]);
    $prefill = KitchenLists::forRun((int) $fromRun['id'], $users[0]);
    kldb_eq(null, $prefill['items'][0]['product_id'], 'an inactive product link becomes free text for a new run');
    kldb_eq((string) $product['name'], (string) $prefill['items'][0]['item_name'], 'an inactive product line keeps its item name');
    kldb_eq('Green ones', (string) $prefill['items'][0]['note'], 'an inactive product line keeps its note');
    Database::run('UPDATE products SET is_active = :active WHERE id = :id', [':active' => (int) $product['is_active'], ':id' => (int) $product['id']]);

    kldb_refuses(static fn() => KitchenLists::delete((int) $created['id'], $users[1]), 'not_found', 'another business cannot delete the saved list');
    KitchenLists::delete((int) $created['id'], $users[0]);
    $lists = array_values(array_filter($lists, static fn(int $id): bool => $id !== (int) $created['id']));
    kldb_eq(null, KitchenLists::findForCustomer((int) $created['id'], $users[0]), 'deleting removes the template and its lines');
} finally {
    Database::run('UPDATE products SET is_active = :active WHERE id = :id', [':active' => (int) $product['is_active'], ':id' => (int) $product['id']]);
    foreach ($lists as $id) { Database::run('DELETE FROM kitchen_run_templates WHERE id = :id', [':id' => $id]); }
    if ($runId > 0) { Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $runId]); }
    foreach ($businesses as $id) { Database::run('DELETE FROM business_customers WHERE id = :id', [':id' => $id]); }
    foreach ($users as $id) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]); }
}

fwrite(STDOUT, "\n$passed / $tests saved Kitchen List database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
