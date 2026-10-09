<?php
/**
 * scripts/tests/expenses_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Expense module against MySQL 8: recording an expense, the
 * enforced category, the positive-amount rule, the filtered list, supplier
 * typeahead, and void (reverse, never delete). Creates its own rows with a run
 * suffix in external_ref and removes them again in the finally block.
 *
 *   php scripts/tests/expenses_db_test.php
 *
 * SCRATCH DATABASE ONLY. It writes rows. The scratch guard refuses to run
 * against anything that is not a disposable test database.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$t = 0; $p = 0;
function exp_ok($condition, string $label): void
{
    global $t, $p; $t++;
    if ($condition) { $p++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}
function exp_eq($expected, $actual, string $label): void
{
    exp_ok($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

$s     = bin2hex(random_bytes(4));
$staff = 0;
$ids   = [];

try {
    // A staff actor for created_by / voided_by.
    Database::run(
        'INSERT INTO users(first_name,last_name,email,phone,password_hash,user_type,status)
         VALUES(:f,:l,:e,:ph,:h,:ty,:st)',
        [':f' => 'Expense', ':l' => 'Tester' . $s, ':e' => "exp-$s@example.test",
         ':ph' => '+23480' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':ty' => 'staff', ':st' => 'active']
    );
    $staff = (int) Database::getInstance()->getConnection()->lastInsertId();

    // --- create: a stock purchase and an overhead ---------------------------
    $stock = Expenses::create([
        'spent_on' => '2026-09-01', 'category_slug' => 'stock-purchase',
        'supplier_name' => 'Mile 12', 'description' => 'Fresh tomatoes',
        'quantity' => 1, 'unit_cost' => '47000', 'amount' => '47000',
        'external_ref' => "test-$s-1",
    ], $staff);
    $ids[] = $stock['id'];
    exp_eq(4700000, $stock['amount_subunit'], 'amount stored as integer kobo');

    $fuel = Expenses::create([
        'spent_on' => '2026-09-04', 'category_slug' => 'fuel',
        'supplier_name' => 'mile 12', 'amount' => '10000', 'external_ref' => "test-$s-2",
    ], $staff);
    $ids[] = $fuel['id'];

    // --- the category is enforced on the server -----------------------------
    $rejectedCategory = false;
    try {
        Expenses::create(['spent_on' => '2026-09-01', 'category_slug' => 'made-up', 'amount' => '5000', 'external_ref' => "test-$s-x"], $staff);
    } catch (DomainException $e) {
        $rejectedCategory = ($e->getMessage() === 'invalid_category');
    }
    exp_ok($rejectedCategory, 'an unknown category is refused');

    // --- a zero or negative amount is refused -------------------------------
    $rejectedAmount = false;
    try {
        Expenses::create(['spent_on' => '2026-09-01', 'category_slug' => 'other', 'amount' => '0', 'external_ref' => "test-$s-y"], $staff);
    } catch (DomainException $e) {
        $rejectedAmount = ($e->getMessage() === 'invalid_amount');
    }
    exp_ok($rejectedAmount, 'a zero amount is refused');

    // --- list with a category filter ----------------------------------------
    $stockRows = Expenses::listRecent(['month' => '2026-09', 'category_slug' => 'stock-purchase', 'limit' => 50]);
    $mine = array_values(array_filter($stockRows, static fn($r) => in_array((int) $r['id'], $ids, true)));
    exp_eq(1, count($mine), 'the category filter returns only the stock purchase');
    exp_eq('cost_of_goods', (string) $mine[0]['category_kind'], 'the joined row carries its kind');

    // --- supplier typeahead groups the two Mile 12 spellings ----------------
    $suggest = Expenses::supplierSuggestions('mile');
    exp_ok(in_array('Mile 12', $suggest, true) || in_array('mile 12', $suggest, true), 'supplier typeahead finds Mile 12');

    // --- void is a reverse, never a delete ----------------------------------
    Expenses::void($fuel['id'], $staff, 'entered twice');
    $afterVoid = Database::one('SELECT is_void, voided_by FROM expenses WHERE id = :id', [':id' => $fuel['id']]);
    exp_eq(1, (int) $afterVoid['is_void'], 'the voided row is still present, flagged void');
    exp_eq($staff, (int) $afterVoid['voided_by'], 'the voided row records who voided it');

    $live = Expenses::listRecent(['month' => '2026-09', 'limit' => 100]);
    $liveMine = array_values(array_filter($live, static fn($r) => in_array((int) $r['id'], $ids, true)));
    exp_eq(1, count($liveMine), 'a voided expense drops out of the live list');

    // --- voiding twice is refused -------------------------------------------
    $rejectedSecondVoid = false;
    try {
        Expenses::void($fuel['id'], $staff, 'again');
    } catch (DomainException $e) {
        $rejectedSecondVoid = ($e->getMessage() === 'already_void');
    }
    exp_ok($rejectedSecondVoid, 'voiding an already-void expense is refused');

} finally {
    // Remove only this run's rows. Test fixtures may be hard-deleted.
    Database::run("DELETE FROM expenses WHERE external_ref LIKE :ref", [':ref' => "test-$s-%"]);
    if ($staff > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $staff]);
    }
}

fwrite(STDOUT, "\n$p / $t checks passed.\n");
exit($p === $t ? 0 : 1);
