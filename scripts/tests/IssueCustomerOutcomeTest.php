<?php
/** M10 Task E customer outcome and private trail contract. */
$root = dirname(__DIR__, 2);
$reports = (string) file_get_contents($root . '/includes/classes/IssueReports.php');
$page = (string) file_get_contents($root . '/public/order.php');
$trail = (string) file_get_contents($root . '/includes/classes/OrderTrail.php');

okv_test_eq('Received', IssueReports::customerStatusLabel('open'), 'E: open reports have plain customer status');
okv_test_eq('In progress', IssueReports::customerStatusLabel('in_progress'), 'E: assigned reports have plain customer status');
okv_test_eq('Resolved', IssueReports::customerStatusLabel('resolved'), 'E: completed reports have plain customer status');
okv_test_eq('Declined', IssueReports::customerStatusLabel('declined'), 'E: declined reports have an exact label');
okv_test_ok(str_contains(IssueReports::customerNextStep(['status' => 'resolved', 'resolution_type' => 'refund']), 'latest status'), 'E: refund copy promises a live status');
okv_test_ok(str_contains(IssueReports::customerNextStep(['status' => 'declined']), 'reason'), 'E: decline copy points to the customer-facing reason');
okv_test_ok(str_contains($reports, 'ORDER BY i.created_at DESC, i.id DESC'), 'E: private history is newest first');
okv_test_ok(!str_contains(substr($reports, strpos($reports, 'function historyForCustomer'), strpos($reports, 'function customerStatusLabel') - strpos($reports, 'function historyForCustomer')), 'handled_by'), 'E: customer history does not expose handler data');
okv_test_ok(str_contains($page, '$reportIndex === 0'), 'E: newest report starts expanded');
okv_test_ok(str_contains($page, '/pro/credit.php'), 'E: eligible business credit outcome links to Pro Credit');
okv_test_ok(str_contains($page, '/public/order.php?order='), 'E: replacement outcome links to an authenticated order view');
okv_test_ok(str_contains($trail, 'r.issue_report_id IS NULL'), 'E: Make It Right refunds stay off the public token trail');
