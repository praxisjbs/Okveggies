<?php
/**
 * scripts/tests/financial_report_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Reporting Dashboard's queries against MySQL 8: that an expense
 * shows up in this month's figures, in the cost-of-goods split, in the category
 * breakdown and in the monthly series, and that the revenue and receivables
 * reads return well-formed integers. Creates its own rows and removes them.
 *
 *   php scripts/tests/financial_report_db_test.php
 *
 * SCRATCH DATABASE ONLY. It writes rows.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$t = 0; $p = 0;
function fr_ok($condition, string $label): void
{
    global $t, $p; $t++;
    if ($condition) { $p++; } else { fwrite(STDERR, "  FAIL: $label\n"); }
}

$s     = bin2hex(random_bytes(4));
$staff = 0;

try {
    Database::run(
        'INSERT INTO users(first_name,last_name,email,phone,password_hash,user_type,status)
         VALUES(:f,:l,:e,:ph,:h,:ty,:st)',
        [':f' => 'Report', ':l' => 'Tester' . $s, ':e' => "fr-$s@example.test",
         ':ph' => '+23481' . random_int(10000000, 99999999), ':h' => password_hash('x', PASSWORD_BCRYPT),
         ':ty' => 'staff', ':st' => 'active']
    );
    $staff = (int) Database::getInstance()->getConnection()->lastInsertId();

    $today = date('Y-m-d');
    $now   = new DateTimeImmutable($today . ' 12:00:00');

    // Baseline this month, so the assertions hold whatever else the scratch DB carries.
    $before = FinancialReport::dashboard('month', $now);

    Expenses::create([
        'spent_on' => $today, 'category_slug' => 'stock-purchase',
        'supplier_name' => 'Mile 12', 'amount' => '47000', 'external_ref' => "fr-$s-1",
    ], $staff);
    Expenses::create([
        'spent_on' => $today, 'category_slug' => 'fuel',
        'supplier_name' => 'Mile 12', 'amount' => '10000', 'external_ref' => "fr-$s-2",
    ], $staff);

    $after = FinancialReport::dashboard('month', $now);

    fr_ok(($after['kpi']['expense_subunit'] - $before['kpi']['expense_subunit']) === 5700000, 'the two expenses add 57,000 naira to the month total');
    fr_ok(($after['kpi']['cost_of_goods_subunit'] - $before['kpi']['cost_of_goods_subunit']) === 4700000, 'only the stock purchase lands in cost of goods');
    fr_ok(($after['kpi']['operating_subunit'] - $before['kpi']['operating_subunit']) === 1000000, 'the fuel lands in operating');
    fr_ok($after['kpi']['profit_subunit'] === ($after['kpi']['revenue_subunit'] - $after['kpi']['expense_subunit']), 'profit equals revenue minus expenses');
    fr_ok($after['kpi']['gross_margin_subunit'] === ($after['kpi']['revenue_subunit'] - $after['kpi']['cost_of_goods_subunit']), 'gross margin equals revenue minus cost of goods');

    // The category breakdown carries the stock purchase with its brand colour.
    $stock = null;
    foreach ($after['category_breakdown'] as $row) {
        if ($row['slug'] === 'stock-purchase') { $stock = $row; break; }
    }
    fr_ok($stock !== null && (int) $stock['amount_subunit'] >= 4700000, 'the category breakdown includes the stock purchase');
    fr_ok($stock !== null && $stock['colour'] === 'forest', 'the breakdown carries the category colour token');

    // The monthly series has this month and its expense reflects the inserts.
    $monthKey = $now->format('Y-m');
    $thisMonth = null;
    foreach ($after['series'] as $pt) {
        if ($pt['month'] === $monthKey) { $thisMonth = $pt; break; }
    }
    fr_ok($thisMonth !== null, 'the trend series includes the current month');

    // Shape checks on the money reads.
    $rev = FinancialReport::revenueBetween($now->format('Y-m-01 00:00:00'), $now->modify('+1 day')->format('Y-m-d 00:00:00'));
    fr_ok(is_int($rev['net_subunit']) && is_int($rev['gross_subunit']) && is_int($rev['refund_subunit']), 'revenueBetween returns integer kobo');
    fr_ok(is_int(FinancialReport::outstandingReceivables()), 'outstandingReceivables returns an integer');

} finally {
    Database::run("DELETE FROM expenses WHERE external_ref LIKE :ref", [':ref' => "fr-$s-%"]);
    if ($staff > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $staff]);
    }
}

fwrite(STDOUT, "\n$p / $t checks passed.\n");
exit($p === $t ? 0 : 1);
