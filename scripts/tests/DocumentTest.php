<?php
/**
 * scripts/tests/DocumentTest.php
 * The printed document is where money and an order number leave the building.
 * A rounding slip or an invented reference on an invoice is not a display bug,
 * it is a dispute, so the pure helpers behind the document are pinned here.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/classes/Money.php';
require_once $root . '/includes/classes/OrderNumber.php';
require_once $root . '/includes/functions/helpers.php';
require_once $root . '/includes/components/documents/document.php';

// 1. Money on a document always shows the kobo, and always through Money.
okv_test_eq('₦8,000.00', okv_document_money(800000), 'document money forces the kobo on a round amount');
okv_test_eq('₦8,000.50', okv_document_money(800050), 'document money keeps a part naira');
okv_test_eq('₦0.00', okv_document_money(0), 'document money renders zero rather than an empty cell');
okv_test_eq('-₦1,250.00', okv_document_money(-125000), 'document money shows a reversal as negative');
okv_test_eq(Money::format(1690000, true), okv_document_money(1690000), 'document money is Money::format, not a second formatter');

// 2. A document never invents an order number.
okv_test_eq('OKV26001', okv_document_reference('OKV26001'), 'a real order number prints as issued');
okv_test_eq('Not issued yet', okv_document_reference(null), 'no order number says so plainly');
okv_test_eq('Not issued yet', okv_document_reference('   '), 'a blank order number says so plainly');
okv_test_eq(
    OrderNumber::format('OKV', 26, 1),
    okv_document_reference(OrderNumber::format('OKV', 26, 1)),
    'the reference is whatever OrderNumber issued, unchanged'
);

// 3. Line totals round the same way the rest of the system does.
okv_test_eq(500000, okv_document_line_total(250000, 2.0), 'two units at ₦2,500 is ₦5,000');
okv_test_eq(375000, okv_document_line_total(250000, 1.5), 'a decimal quantity multiplies exactly');
okv_test_eq(83333, okv_document_line_total(250000, 0.333333), 'a repeating quantity rounds to the nearest kobo');
okv_test_eq(0, okv_document_line_total(250000, 0.0), 'a zero quantity is a zero line');
okv_test_eq(1, okv_document_line_total(1, 1.0), 'one kobo stays one kobo');

// 4. The totals block, including the balance a customer actually owes.
$rows = okv_document_totals_rows(1690000);
okv_test_eq(2, count($rows), 'items and total only, when there is no delivery fee and nothing paid');
okv_test_eq('Items', $rows[0]['label'], 'the first row is the items');
okv_test_eq('Total', $rows[1]['label'], 'the last row is the total');
okv_test_eq('₦16,900.00', $rows[1]['amount'], 'the total is the subtotal when nothing else applies');
okv_test_ok($rows[1]['is_total'], 'the total row is marked as the total');

$rows = okv_document_totals_rows(1690000, 250000);
okv_test_eq(3, count($rows), 'a delivery fee adds one row');
okv_test_eq('Delivery', $rows[1]['label'], 'delivery sits between the items and the total');
okv_test_eq('₦19,400.00', $rows[2]['amount'], 'the total carries the delivery fee');

$rows = okv_document_totals_rows(1690000, 250000, 582000);
okv_test_eq(5, count($rows), 'a part payment shows items, delivery, total, paid and the balance');
okv_test_eq('Balance due', $rows[4]['label'], 'the balance is the last thing read');
okv_test_eq('₦13,580.00', $rows[4]['amount'], 'the balance is the total less what was paid');
okv_test_ok($rows[4]['is_total'], 'the balance is the row that carries the rule');
okv_test_eq('₦5,820.00', $rows[3]['amount'], 'the deposit is shown as it was taken');

$rows = okv_document_totals_rows(1690000, 0, 1690000);
okv_test_eq('₦0.00', $rows[3]['amount'], 'a settled order shows a zero balance, not an empty one');

// 5. The letterhead uses the single-ink mark, per bible 3.7a.
$component = (string) file_get_contents($root . '/includes/components/documents/document.php');
okv_test_ok(str_contains($component, 'lockup-mono-green.svg'), 'the letterhead carries the single-ink mono-green lockup');
okv_test_ok(!str_contains($component, 'seal-640.png'), 'the letterhead does not put the photographic seal on a printed page');
okv_test_ok(str_contains($component, 'okv_head_meta'), 'a document page still emits the brand head partial');
okv_test_ok(!str_contains($component, "\u{2014}"), 'no em dash in the document component');
