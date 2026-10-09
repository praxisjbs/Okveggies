<?php
/**
 * scripts/tests/FinancialReportTest.php
 * The Reporting Dashboard's pure maths: profit, gross margin, the month keys,
 * the monthly combine, and the period bounds. No database; these decide every
 * figure the dashboard shows, so they must never be wrong. The revenue and
 * expense queries are covered against MySQL in financial_report_db_test.php.
 */

// ---- Profit and margin, signed -----------------------------------------------
okv_test_eq(300000, FinancialReport::netProfit(1000000, 700000), 'net profit is revenue minus expenses');
okv_test_eq(-200000, FinancialReport::netProfit(500000, 700000), 'net profit is negative on a loss');
okv_test_eq(0, FinancialReport::netProfit(0, 0), 'no revenue and no expense is zero profit');
okv_test_eq(550000, FinancialReport::grossMargin(1000000, 450000), 'gross margin is revenue minus cost of goods');
okv_test_eq(-50000, FinancialReport::grossMargin(400000, 450000), 'gross margin can be negative');

// ---- Period validation -------------------------------------------------------
okv_test_eq('7', FinancialReport::normalisePeriod('7'), 'seven days is a valid period');
okv_test_eq('month', FinancialReport::normalisePeriod('month'), 'this month is a valid period');
okv_test_eq('30', FinancialReport::normalisePeriod('nonsense'), 'an unknown period falls back to 30');
okv_test_eq('30', FinancialReport::normalisePeriod(['30']), 'a non-string period falls back to 30');

// ---- Month keys --------------------------------------------------------------
$now = new DateTimeImmutable('2026-10-09 14:30:00');
$keys = FinancialReport::monthKeys(6, $now);
okv_test_eq(6, count($keys), 'monthKeys returns the count asked for');
okv_test_eq(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], $keys, 'monthKeys is oldest first and ends at the current month');
okv_test_eq(['2026-10'], FinancialReport::monthKeys(1, $now), 'one month is just the current month');

// A year boundary: December back three months lands in the previous year.
$dec = new DateTimeImmutable('2026-12-15 00:00:00');
okv_test_eq(['2026-10', '2026-11', '2026-12'], FinancialReport::monthKeys(3, $dec), 'monthKeys crosses a year end cleanly');

// ---- Combine monthly ---------------------------------------------------------
$series = FinancialReport::combineMonthly(
    ['2026-09' => 1000000, '2026-10' => 500000],
    ['2026-10' => 300000],
    ['2026-09', '2026-10']
);
okv_test_eq(2, count($series), 'the series has one row per month key');
okv_test_eq(1000000, $series[0]['revenue_subunit'], 'September revenue carried through');
okv_test_eq(0, $series[0]['expense_subunit'], 'a month missing from the expense map is zero, not dropped');
okv_test_eq(1000000, $series[0]['profit_subunit'], 'September profit is revenue with no expense');
okv_test_eq(200000, $series[1]['profit_subunit'], 'October profit is revenue minus expense');
okv_test_eq('2026-10', $series[1]['month'], 'the row keeps its month key');

// ---- Period bounds -----------------------------------------------------------
$b30 = FinancialReport::periodBounds('30', $now);
okv_test_eq('2026-09-10', $b30['start_date'], '30 days reaches back 29 days including today');
okv_test_eq('2026-10-10', $b30['end_date_exclusive'], '30 days ends at tomorrow midnight exclusive');
okv_test_eq('Last 30 days', $b30['label'], '30 days is labelled plainly');

$bMonth = FinancialReport::periodBounds('month', $now);
okv_test_eq('2026-10-01', $bMonth['start_date'], 'this month starts on the first');
okv_test_eq('2026-10-10', $bMonth['end_date_exclusive'], 'this month ends at tomorrow midnight exclusive');
okv_test_eq('October 2026', $bMonth['label'], 'this month is labelled by its name');
