<?php
/** Pure Task C workflowC workflow contract checks. */

okv_test_eq(['open', 'in_progress', 'resolved', 'declined'], IssueReports::STATUSES, 'issue lifecycle names are fixed');
okv_test_eq('Open', IssueReports::statusLabel('open'), 'open has an approved staff label');
okv_test_eq('Being handled', IssueReports::statusLabel('in_progress'), 'in progress is shown as being handled');
okv_test_eq('Resolved', IssueReports::statusLabel('resolved'), 'resolved has an approved label');
okv_test_eq('Declined', IssueReports::statusLabel('declined'), 'declined remains a distinct terminal state');
okv_test_eq(25, IssueReports::PER_PAGE, 'the staff queue uses 25 reports per page');
okv_test_eq(['refund', 'credit', 'replacement'], array_keys(IssueReports::RESOLUTION_TYPES), 'only the three PRD resolution types are offered');
okv_test_ok(!empty(IssueReports::validateResolutionNote('We could not verify the missing item.')['ok']), 'a clear customer-facing outcome note passes');
okv_test_eq('resolution_note_too_short', IssueReports::validateResolutionNote('Too short')['code'], 'a terminal note below 10 characters is refused');
okv_test_eq('resolution_note_too_long', IssueReports::validateResolutionNote(str_repeat('a', 1001))['code'], 'a terminal note above 1,000 characters is refused');
okv_test_ok(IssueReports::validDate('2026-09-09'), 'a real filter date passes');
okv_test_ok(!IssueReports::validDate('2026-02-30'), 'an impossible filter date is refused');

$admin = file_get_contents(dirname(__DIR__, 2) . '/admin/make_it_right.php');
okv_test_ok(str_contains($admin, "Rbac::requirePermission('issues.view')"), 'every staff queue read requires issues.view');
okv_test_ok(str_contains($admin, 'Oldest first'), 'the queue states its oldest-first order');
okv_test_ok(str_contains($admin, 'Verified paid'), 'the detail identifies the authoritative paid amount');
okv_test_ok(str_contains($admin, '/admin/orders.php?order='), 'the report links to its admin order');
okv_test_ok(str_contains($admin, 'name="expected_status"'), 'staff action forms carry expected state');
okv_test_ok(str_contains($admin, 'Csrf::field()'), 'every staff action form carries CSRF');

$api = file_get_contents(dirname(__DIR__, 2) . '/api/v1/make_it_right.php');
okv_test_ok(str_contains($api, "Rbac::requirePermission('issues.resolve')"), 'every workflow write requires issues.resolve');
okv_test_ok(str_contains($api, 'Notifications::announceIssueReportResolved'), 'a committed decline tells the customer after the write');

$orders = file_get_contents(dirname(__DIR__, 2) . '/admin/orders.php');
okv_test_ok(str_contains($orders, 'IssueReports::forOrderStaff'), 'the admin order reads related reports through the shared service');
okv_test_ok(str_contains($orders, '/admin/make_it_right.php?status=all'), 'the admin order links back to its report');
