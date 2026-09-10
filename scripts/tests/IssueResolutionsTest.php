<?php
/** Pure M10 Task D outcome rules and wiring. */

okv_test_eq(['refund', 'credit', 'replacement'], IssueResolutions::TYPES, 'Task D offers exactly the three PRD outcomes');
okv_test_ok(IssueResolutions::amountIsValid(270000, [300000, 200000], 400000), 'an adjustment inside both selected-line and refundable caps passes');
okv_test_ok(!IssueResolutions::amountIsValid(500001, [300000, 200000], 900000), 'an adjustment above the selected-line cap is refused');
okv_test_ok(!IssueResolutions::amountIsValid(400001, [900000], 400000), 'a refund above the payment cap is refused');
okv_test_ok(!IssueResolutions::amountIsValid(0, [900000], 900000), 'a zero-value money outcome is refused');

$service = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/IssueResolutions.php');
okv_test_ok(str_contains($service, 'Refunds::request('), 'refund resolution delegates to the M5 engine');
okv_test_ok(str_contains($service, 'Credit::grantIssueCredit('), 'credit resolution delegates to the M8 engine');
okv_test_ok(str_contains($service, 'replacement_order_id'), 'replacement resolution keeps its order link');
okv_test_ok(str_contains($service, 'FOR UPDATE'), 'resolution paths use row locks');
okv_test_ok(str_contains($service, "'issue_reports.resolve.' . \$type"), 'every terminal outcome is audited by type');

$api = file_get_contents(dirname(__DIR__, 2) . '/api/v1/make_it_right.php');
okv_test_ok(str_contains($api, "Rbac::requirePermission('payments.refund')"), 'refund requires its finance permission');
okv_test_ok(str_contains($api, "Rbac::requirePermission('credit.grant')"), 'credit requires its finance permission');
okv_test_ok(str_contains($api, "in_array('owner', Rbac::roles(), true)"), 'only an Owner may take over another handler');
okv_test_ok(str_contains($api, "!okv_input('confirmed', '')"), 'the final outcome requires server-side confirmation');

$refunds = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Refunds.php');
okv_test_ok(str_contains($refunds, 'issue_report_id'), 'the M5 refund row carries the durable issue link');
okv_test_ok(str_contains($refunds, "'already_raised'"), 'a repeated issue refund returns the original row');
