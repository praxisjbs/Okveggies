<?php
/**
 * scripts/tests/reference_seed_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The reference seed, asserted against a migrated database.
 *
 * This closes the M2 audit carry-forward (PROGRESS.md M2, 20 September 2026):
 * the seed checks used to be regular expressions run over the SQL files, which
 * proved the text of a migration rather than the rows a customer shops
 * against. Here the same truth is read from the database the migrations
 * actually produced: the 5 categories, the 4 units, the 24 launch products,
 * the Garlic kilogramme correction, and the delivery-day decisions, including
 * Monday as a business day (3 September decision, migration 051).
 *
 * It also pins the second M2 decision: a category with nothing available is
 * shown and labelled Being sourced, never hidden. That one needs a row nobody
 * sells, so the suite creates a ZZ test category and removes it afterwards.
 *
 *   php scripts/tests/reference_seed_db_test.php
 *
 * Scratch databases only. The seed assertions are read-only; the empty
 * category check writes one row and deletes it in a finally block.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0;
function rs_ok($condition, string $label): void {
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function rs_eq($expected, $actual, string $label): void {
    global $tests, $passed;
    $tests++;
    if ($expected === $actual) { $passed++; return; }
    fwrite(STDOUT, '  FAIL: ' . $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
}

// --- Categories: the 5 fixed shelves, by identity, not by text ---------------
$seedCategories = [
    'vegetables' => 'Vegetables',
    'herbs-spices' => 'Herbs & Spices',
    'tubers-roots' => 'Tubers & Roots',
    'fruits' => 'Fruits',
    'grains-cereals' => 'Grains & Cereals',
];
$rows = Database::all(
    'SELECT slug, name, is_active FROM product_categories WHERE slug IN (:a, :b, :c, :d, :e)',
    [':a' => 'vegetables', ':b' => 'herbs-spices', ':c' => 'tubers-roots', ':d' => 'fruits', ':e' => 'grains-cereals']
);
$bySlug = [];
foreach ($rows as $row) {
    $bySlug[(string) $row['slug']] = $row;
}
rs_eq(5, count($bySlug), 'the 5 seeded categories are present');
foreach ($seedCategories as $slug => $name) {
    rs_eq($name, ($bySlug[$slug]['name'] ?? ''), "the seeded $slug category carries its name");
    rs_eq(1, (int) ($bySlug[$slug]['is_active'] ?? 0), "the seeded $slug category is active");
}

// --- Units: kg, bunch, head, tuber, with the decimal rule on kg only ---------
$units = [];
foreach (Database::all('SELECT symbol, allows_decimal FROM units_of_measurement') as $unit) {
    $units[(string) $unit['symbol']] = (bool) $unit['allows_decimal'];
}
rs_eq(true, $units['kg'] ?? null, 'the kilogramme allows decimal quantities');
foreach (['bunch', 'head', 'tuber'] as $symbol) {
    rs_eq(false, $units[$symbol] ?? null, "the $symbol is sold whole, never in decimals");
}
rs_eq(4, count($units), 'exactly 4 units are seeded');

// --- Products: the 24 launch items, with Garlic on the kilogramme -----------
$seedProducts = Database::one(
    'SELECT COUNT(*) AS n FROM products WHERE id BETWEEN 1 AND 24 AND is_active = 1'
);
rs_eq(24, (int) $seedProducts['n'], 'the 24 seeded products are present and active');
$garlic = Database::one(
    'SELECT p.name, u.symbol, u.name AS unit_name
       FROM products p JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.slug = :slug',
    [':slug' => 'garlic']
);
rs_eq('Garlic', (string) ($garlic['name'] ?? ''), 'Garlic is in the seeded catalogue');
rs_eq('kg', (string) ($garlic['symbol'] ?? ''), 'Garlic is sold by the kilogramme, the corrected unit');
$tomatoes = Database::one(
    'SELECT source_region FROM products WHERE slug = :slug',
    [':slug' => 'fresh-tomatoes']
);
rs_eq('Ogun State', (string) ($tomatoes['source_region'] ?? ''), 'Fresh Tomatoes keeps its known Ogun State source region');
$seedImages = Database::one('SELECT COUNT(*) AS n FROM product_images WHERE product_id BETWEEN 1 AND 24');
rs_eq(24, (int) $seedImages['n'], 'every seeded product carries its image row');
$seedAvailability = Database::one('SELECT COUNT(*) AS n FROM product_availability WHERE product_id BETWEEN 1 AND 24');
rs_eq(24, (int) $seedAvailability['n'], 'every seeded product carries an availability row');

// --- Zones: the 30 seeded Lagos starting points ------------------------------
$zones = Database::one('SELECT COUNT(*) AS n FROM delivery_zones');
rs_eq(30, (int) $zones['n'], 'the 30 seeded Lagos zones are present');
$lekki = Database::one('SELECT id FROM delivery_zones WHERE slug = :slug', [':slug' => 'lekki-phase-1']);
rs_ok($lekki !== null, 'Lekki Phase 1 is among them');

// --- Delivery days: households Mon/Wed/Thu/Sat, businesses Mon/Tue/Fri ------
$activeDays = function (string $type): array {
    $days = [];
    foreach (Database::all(
        'SELECT day_of_week FROM allowed_delivery_days WHERE customer_type = :type AND is_active = 1 ORDER BY day_of_week',
        [':type' => $type]
    ) as $row) {
        $days[] = (int) $row['day_of_week'];
    }
    return $days;
};
rs_eq([1, 3, 4, 6], $activeDays('household'), 'households deliver on Monday, Wednesday, Thursday and Saturday');
rs_eq([1, 2, 5], $activeDays('business'), 'businesses deliver on Monday, Tuesday and Friday, the 3 September decision seeded and migrated');

// --- An empty category is labelled, not hidden (M2 decision, 20 Sep 2026) ---
$suffix = bin2hex(random_bytes(4));
$zzCategoryId = 0;
try {
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:name, :slug, :description, 99, 1)',
        [':name' => 'ZZ Seed Test ' . $suffix, ':slug' => 'zz-seed-' . $suffix, ':description' => 'Reference seed test fixture. Not a real shelf.']
    );
    $zzCategoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $visible = null;
    foreach (Catalogue::categories() as $category) {
        if ((int) $category['id'] === $zzCategoryId) {
            $visible = $category;
            break;
        }
    }
    rs_ok($visible !== null, 'a category with nothing to sell is still shown, not hidden');
    rs_eq(0, (int) ($visible['product_count'] ?? -1), 'it reports 0 items, which the homepage labels Being sourced');
} finally {
    if ($zzCategoryId > 0) {
        Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $zzCategoryId]);
    }
}
rs_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM product_categories WHERE slug = :slug', [':slug' => 'zz-seed-' . $suffix])['n'], 'the empty-category fixture is removed again');

fwrite(STDOUT, "\n$passed / $tests reference seed database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
