<?php
/**
 * scripts/tests/pricing_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Repricing against a real database. The maths is covered by
 * PricingTest.php; this covers what only a database can show: that a price
 * change writes exactly one history row and closes the last one, that a failed
 * change writes nothing at all, that a bulk apply is all or nothing, and that a
 * price sheet round trips out and back in.
 *
 *   php scripts/tests/pricing_db_test.php
 *
 * It creates throwaway products in a throwaway category, asserts, then removes
 * them. It never touches a seeded product. Run it after php scripts/migrate.php
 * on a scratch database.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Money.php';
require_once $root . '/includes/classes/Pricing.php';
require_once $root . '/includes/classes/Catalogue.php';
require_once $root . '/includes/classes/Products.php';
require_once $root . '/includes/classes/PriceSheet.php';
require_once $root . '/includes/functions/helpers.php';

$autoload = $root . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0; $GLOBALS['f'] = [];
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; return; }
    $GLOBALS['f'][] = $label;
    fwrite(STDOUT, "  FAIL: $label\n");
}
function t_eq($expected, $actual, string $label): void {
    $same = $expected === $actual;
    if (!$same) {
        $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($same, $label);
}

// --- Fixtures ---------------------------------------------------------------

$suffix  = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$catSlug = 'zz-test-' . strtolower($suffix);

Database::run(
    'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
     VALUES (:name, :slug, :description, 900, 1)',
    [':name' => 'ZZ Test ' . $suffix, ':slug' => $catSlug, ':description' => 'Throwaway category for the pricing test.']
);
$categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

/** Make a throwaway product and return its id. */
function make_product(int $categoryId, string $suffix, string $name, int $priceSubunit): int {
    [$clean, $errors] = Products::validate([
        'name'        => $name . ' ' . $suffix,
        'sku'         => 'ZZ-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name)) . '-' . $suffix,
        'category_id' => $categoryId,
        'unit_id'     => 1,
        'price'       => $priceSubunit > 0 ? (string) Money::toNaira($priceSubunit) : '',
        'minimum_quantity'   => '1',
        'quantity_increment' => '1',
        'is_active'   => 1,
    ]);
    if ($errors) {
        fwrite(STDERR, "fixture failed: " . json_encode($errors) . "\n");
        exit(2);
    }
    return Products::create($clean, null);
}

$tomato = make_product($categoryId, $suffix, 'Test Tomato', 270000); // ₦2,700
$onion  = make_product($categoryId, $suffix, 'Test Onion', 140000);  // ₦1,400
$draft  = make_product($categoryId, $suffix, 'Test Draft', 0);       // no price yet

$cleanup = function () use ($tomato, $onion, $draft, $categoryId) {
    foreach ([$tomato, $onion, $draft] as $id) {
        Database::run('DELETE FROM product_price_history WHERE product_id = :id', [':id' => $id]);
        Database::run('DELETE FROM products WHERE id = :id', [':id' => $id]);
    }
    Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $categoryId]);
};

try {

// --- Creating ---------------------------------------------------------------

t_eq(1, count(Pricing::history($tomato)), 'a product created with a price opens its history with one row');
$opening = Pricing::history($tomato)[0];
t_eq(null, $opening['old_price_subunit'], 'the opening row has no old price');
t_eq(270000, (int) $opening['new_price_subunit'], 'the opening row carries the opening price');
t_eq(null, $opening['effective_to'], 'the opening row is left open');
t_eq(0, count(Pricing::history($draft)), 'a product created without a price writes no history');

// --- A single price change --------------------------------------------------

$result = Pricing::change($tomato, 300000, 'Supplier raised tomatoes', null);
t_ok($result['changed'], 'a real price change reports that it changed');
t_eq(270000, $result['old'], 'it reports the old price');
t_eq(300000, $result['new'], 'it reports the new price');

$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $tomato]);
t_eq(300000, (int) $row['current_price_subunit'], 'the product now carries the new price');

$history = Pricing::history($tomato);
t_eq(2, count($history), 'a price change writes exactly one more history row');
t_eq(270000, (int) $history[0]['old_price_subunit'], 'the new row records what the price was');
t_eq(300000, (int) $history[0]['new_price_subunit'], 'the new row records what it became');
t_eq('Supplier raised tomatoes', $history[0]['change_reason'], 'the reason is kept');
t_eq(null, $history[0]['effective_to'], 'the new row is the open one');
t_ok($history[1]['effective_to'] !== null, 'the previous row was closed off');
t_eq(1, count(array_filter($history, static fn($h) => $h['effective_to'] === null)), 'only one history row is ever open');

// --- Setting the same price again -------------------------------------------

$again = Pricing::change($tomato, 300000, 'Same again', null);
t_ok(!$again['changed'], 'setting the price it already has is not a change');
t_eq(2, count(Pricing::history($tomato)), 'and it writes no history row, so a re-import adds no noise');

// --- A refused price writes nothing -----------------------------------------

$before = count(Pricing::history($tomato));
$threw = false;
try {
    Pricing::change($tomato, 0, 'Free tomatoes', null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'invalid_price');
}
t_ok($threw, 'a price of nothing is refused');
t_eq($before, count(Pricing::history($tomato)), 'a refused change writes no history');
$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $tomato]);
t_eq(300000, (int) $row['current_price_subunit'], 'and it leaves the price alone');

$threw = false;
try {
    Pricing::change(0, 100000, null, null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'not_found');
}
t_ok($threw, 'pricing a product that does not exist is refused');

// --- Bulk apply -------------------------------------------------------------

$preview = Pricing::previewBulk($categoryId, Pricing::MODE_PERCENT, 10);
t_eq(2, count($preview['rows']), 'the preview covers the priced products in the category');
t_eq(1, count($preview['skipped']), 'the unpriced draft is skipped, not counted as a failure');
t_eq(0, count($preview['blocked']), 'nothing is blocked by a ten per cent rise');
t_ok(str_contains($preview['skipped'][0]['reason'], 'no price yet'), 'and the preview says why it was skipped');

$applied = Pricing::applyBulk($categoryId, Pricing::MODE_PERCENT, 10, 'Weekly reprice', null);
t_eq(2, $applied['changed'], 'the two priced products moved');
t_eq(1, $applied['skipped'], 'and the unpriced draft was passed over');

$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $tomato]);
t_eq(330000, (int) $row['current_price_subunit'], 'ten per cent on ₦3,000 is ₦3,300');
$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $onion]);
t_eq(154000, (int) $row['current_price_subunit'], 'ten per cent on ₦1,400 is ₦1,540');
t_eq('Weekly reprice', Pricing::history($tomato)[0]['change_reason'], 'the bulk reason lands on every row it wrote');

// A flat move down, and the same maths in the other direction.
Pricing::applyBulk($categoryId, Pricing::MODE_FLAT, -30000, 'Flat cut', null);
$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $tomato]);
t_eq(300000, (int) $row['current_price_subunit'], 'a flat ₦300 off ₦3,300 is ₦3,000');

// A move that would take a product below zero is refused, and refused for all.
$onionBefore = (int) Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $onion])['current_price_subunit'];
$threw = false;
try {
    Pricing::applyBulk($categoryId, Pricing::MODE_FLAT, -900000, 'Too far', null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'blocked');
}
t_ok($threw, 'a bulk move that would take a price below ₦1 is blocked');
$row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $onion]);
t_eq($onionBefore, (int) $row['current_price_subunit'], 'and it is all or nothing, so no product moved');

$threw = false;
try {
    Pricing::applyBulk($categoryId, Pricing::MODE_PERCENT, 5, '   ', null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'reason_required');
}
t_ok($threw, 'a bulk apply without a reason is refused');

// --- Export and import round trip -------------------------------------------

if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    $file = sys_get_temp_dir() . '/okv-prices-' . $suffix . '.xlsx';
    PriceSheet::export($file);
    t_ok(is_file($file) && filesize($file) > 0, 'the price list exports to a real xlsx file');

    $rows = PriceSheet::read($file);
    t_ok(count($rows) >= 3, 'the export reads back with at least our three products');

    $bySku = [];
    foreach ($rows as $r) { $bySku[$r['sku']] = $r; }
    $tomatoSku = Database::one('SELECT sku FROM products WHERE id = :id', [':id' => $tomato])['sku'];
    t_ok(isset($bySku[$tomatoSku]), 'our test product is in the exported sheet');
    t_eq(300000, Money::toSubunit($bySku[$tomatoSku]['price']), 'and its exported price round trips to the same subunits');

    // Unchanged sheet: nothing to do, and nothing written.
    $preview = PriceSheet::preview($rows);
    t_ok($preview['ok'], 'an untouched export imports cleanly');
    t_eq(0, count($preview['changes']), 'and asks for no changes');
    t_ok(count($preview['skipped']) >= 1, 'the unpriced draft comes back as skipped, not as a problem');
    $historyBefore = count(Pricing::history($tomato));
    PriceSheet::apply($preview, 'round-trip.xlsx', null);
    t_eq($historyBefore, count(Pricing::history($tomato)), 're-importing an unchanged sheet writes no history');

    // Change one price in the sheet and import it.
    foreach ($rows as $i => $r) {
        if ($r['sku'] === $tomatoSku) { $rows[$i]['price'] = '3,450'; }
    }
    $preview = PriceSheet::preview($rows);
    t_ok($preview['ok'], 'the edited sheet is clean');
    t_eq(1, count($preview['changes']), 'exactly one row would change');
    t_eq(345000, $preview['changes'][0]['new'], 'and it would set ₦3,450');

    $applied = PriceSheet::apply($preview, 'round-trip.xlsx', null);
    t_eq(1, $applied, 'one price was applied');
    $row = Database::one('SELECT current_price_subunit FROM products WHERE id = :id', [':id' => $tomato]);
    t_eq(345000, (int) $row['current_price_subunit'], 'the product carries the imported price');
    t_ok(str_contains((string) Pricing::history($tomato)[0]['change_reason'], 'round-trip.xlsx'), 'the history says which file it came from');

    // An unknown SKU is reported and never created.
    $productsBefore = (int) Database::one('SELECT COUNT(*) AS c FROM products')['c'];
    $bad = PriceSheet::preview([['line' => 2, 'sku' => 'ZZ-NOT-A-REAL-SKU', 'price' => '1,000']]);
    t_ok(!$bad['ok'], 'a sheet with an unknown SKU is not ok');
    t_eq(1, count($bad['problems']), 'the unknown SKU is reported');
    t_ok(str_contains($bad['problems'][0]['reason'], 'No product'), 'with a plain reason');
    t_eq($productsBefore, (int) Database::one('SELECT COUNT(*) AS c FROM products')['c'], 'and no product was invented');

    $threw = false;
    try {
        PriceSheet::apply($bad, 'bad.xlsx', null);
    } catch (DomainException $e) {
        $threw = ($e->getMessage() === 'has_problems');
    }
    t_ok($threw, 'applying a sheet with problems is refused outright');

    // Unreadable and out of range prices are caught, not guessed at.
    $messy = PriceSheet::preview([
        ['line' => 2, 'sku' => $tomatoSku, 'price' => 'about three thousand'],
        ['line' => 3, 'sku' => $tomatoSku, 'price' => '1000'],
    ]);
    t_ok(!$messy['ok'], 'an unreadable price is a problem');
    t_eq(2, count($messy['problems']), 'and so is the same SKU appearing twice');

    // An empty price says "leave this one alone", which is not an error.
    $blank = PriceSheet::preview([['line' => 2, 'sku' => $tomatoSku, 'price' => '']]);
    t_ok($blank['ok'], 'a blank price is not a problem');
    t_eq(0, count($blank['changes']), 'and changes nothing');
    t_eq(1, count($blank['skipped']), 'it is reported as skipped');

    @unlink($file);
} else {
    fwrite(STDOUT, "  skipped: PhpSpreadsheet is not installed, the round trip did not run\n");
}

// --- Removing a product -----------------------------------------------------

$refs = Products::referenceCount($tomato);
t_ok($refs['prices'] > 0, 'a priced product is held by its price history');
$threw = false;
try {
    Products::delete($tomato);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'in_use');
}
t_ok($threw, 'a priced product cannot be deleted, only switched off');
t_ok(Database::one('SELECT id FROM products WHERE id = :id', [':id' => $tomato]) !== null, 'and it is still there');

Products::setActive($tomato, false);
t_eq(0, (int) Database::one('SELECT is_active FROM products WHERE id = :id', [':id' => $tomato])['is_active'], 'switching it off works');

// The draft never carried a price, so nothing is holding it.
t_eq(0, Products::referenceCount($draft)['total'], 'an unpriced draft is held by nothing');
Products::delete($draft);
t_ok(Database::one('SELECT id FROM products WHERE id = :id', [':id' => $draft]) === null, 'and it can be removed outright');

// --- Availability -----------------------------------------------------------

Products::setAvailability($onion, 'restocking', '2026-09-03', null);
$row = Database::one('SELECT availability_status, restock_date FROM product_availability WHERE product_id = :id', [':id' => $onion]);
t_eq('restocking', $row['availability_status'], 'availability is set');
t_eq('2026-09-03', $row['restock_date'], 'and the restock date with it');

Products::setAvailability($onion, 'available', '2026-09-03', null);
$row = Database::one('SELECT availability_status, restock_date FROM product_availability WHERE product_id = :id', [':id' => $onion]);
t_eq('available', $row['availability_status'], 'availability goes back');
t_eq(null, $row['restock_date'], 'and a restock date is dropped when it no longer means anything');

$threw = false;
try {
    Products::setAvailability($onion, 'on-a-boat', null, null);
} catch (DomainException $e) {
    $threw = ($e->getMessage() === 'bad_status');
}
t_ok($threw, 'an availability status we do not know is refused');

// --- Validation -------------------------------------------------------------

[, $errors] = Products::validate(['name' => '', 'sku' => 'zz bad sku', 'category_id' => 0, 'unit_id' => 0, 'price' => '-5']);
t_ok(isset($errors['name']), 'a product needs a name');
t_ok(isset($errors['sku']), 'a SKU with spaces is refused');
t_ok(isset($errors['category_id']), 'a product needs a real category');
t_ok(isset($errors['unit_id']), 'a product needs a real unit');

[$clean] = Products::validate([
    'name' => 'Bunched Herb', 'sku' => 'ZZ-HERB-' . $suffix, 'category_id' => $categoryId,
    'unit_id' => 2, 'price' => '500', 'minimum_quantity' => '1.5', 'quantity_increment' => '0.5',
]);
t_eq(2.0, $clean['minimum_quantity'], 'a unit that takes no decimal rounds the minimum up to a whole bunch');
t_eq(1.0, $clean['quantity_increment'], 'and rounds the step up too');

} finally {
    $cleanup();
}

fwrite(STDOUT, "\n{$GLOBALS['p']} / {$GLOBALS['t']} assertions passed.\n");
if ($GLOBALS['f']) {
    fwrite(STDOUT, count($GLOBALS['f']) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
