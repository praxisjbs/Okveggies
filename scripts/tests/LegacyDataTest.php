<?php
/**
 * scripts/tests/LegacyDataTest.php
 * The cleaned September and October import data (scripts/import/legacy_data.json)
 * must reconcile to the business's spreadsheet before any of it is written to a
 * database. These checks run with no database: they prove the extraction, the
 * invoice grouping and the mappings, so a wrong total is caught here, not after
 * the import. The expense half is also carried by migrations/075_legacy_expenses.sql.
 */

$path = dirname(__DIR__) . '/import/legacy_data.json';
okv_test_ok(is_file($path), 'the legacy import data file exists');

$data = json_decode((string) file_get_contents($path), true);
okv_test_ok(is_array($data), 'the legacy import data parses as JSON');

$r = $data['reconciliation'] ?? [];

// The three headline figures match the spreadsheet Dashboard (floored to naira).
okv_test_eq(825872589, (int) ($r['expenses_total_subunit'] ?? 0), 'expenses reconcile to NGN 8,258,725');
okv_test_eq(9227545, intdiv((int) ($r['sales_total_subunit'] ?? 0), 100), 'sales reconcile to NGN 9,227,545');
okv_test_eq(1240040, intdiv((int) ($r['outstanding_subunit'] ?? 0), 100), 'outstanding reconciles to NGN 1,240,040');
okv_test_eq(69, (int) ($r['invoice_count'] ?? 0), 'there are sixty-nine invoices');

// Seven canonical businesses, Citysubs carrying two branches.
okv_test_eq(7, count($data['customers'] ?? []), 'seven canonical businesses');
$citysubs = null;
foreach (($data['customers'] ?? []) as $c) {
    if ($c['business'] === 'Citysubs') { $citysubs = $c; }
}
okv_test_ok($citysubs !== null && count($citysubs['branches']) === 2, 'Citysubs folds its Yaba and Lekki branches into one business');

// Every product alias is either a match to the catalogue or a create, never both.
$aliases = $data['product_aliases'] ?? [];
okv_test_ok(count($aliases) > 0, 'the product alias map is present');
$cleanAliases = true;
foreach ($aliases as $legacy => $a) {
    $hasMatch = isset($a['match']);
    $hasCreate = isset($a['create']);
    if ($hasMatch === $hasCreate) { $cleanAliases = false; }
}
okv_test_ok($cleanAliases, 'every alias is exactly one of match or create');

// The invoice totals sum to the sales total, so no invoice is lost or double counted.
$sum = 0;
$orphanLine = false;
foreach (($data['invoices'] ?? []) as $inv) {
    if ($inv['total_subunit'] !== null) { $sum += (int) $inv['total_subunit']; }
    foreach ($inv['lines'] as $line) {
        if (empty($line['is_delivery']) && !isset($aliases[$line['product']])) {
            $orphanLine = true; // a product line with no mapping would lose a sale
        }
    }
}
okv_test_eq((int) ($r['sales_total_subunit'] ?? -1), $sum, 'the invoice totals sum to the sales total');
okv_test_ok(!$orphanLine, 'every product line maps to a catalogue product or a create, so no sale is lost');

// The monthly split matches the spreadsheet.
$byMonth = $r['sales_by_month'] ?? [];
okv_test_eq(7161480, intdiv((int) ($byMonth['2026-09'] ?? 0), 100), 'September sales reconcile');
okv_test_eq(2066065, intdiv((int) ($byMonth['2026-10'] ?? 0), 100), 'October sales reconcile');
