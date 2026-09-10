<?php
/** M10 Task G notification wiring and template contract. */
$root = dirname(__DIR__, 2);
$notifications = (string) file_get_contents($root . '/includes/classes/Notifications.php');
$controller = (string) file_get_contents($root . '/api/v1/make_it_right.php');
$migration = (string) file_get_contents($root . '/migrations/048_make_it_right_notification_copy.sql');

foreach (['issue_report_received', 'issue_report_resolved', 'admin_new_issue_report'] as $event) {
    okv_test_ok(isset(Notifications::EVENTS[$event]), 'G: ' . $event . ' uses the M6 dispatcher');
    okv_test_ok(isset(Notifications::TOKENS[$event]), 'G: ' . $event . ' is editable with an allowlisted token set');
}
foreach (['category', 'description_preview'] as $token) {
    okv_test_ok(in_array($token, Notifications::TOKENS['issue_report_received'], true), 'G: acknowledgement allows ' . $token);
}
okv_test_ok(str_contains($notifications, "staffRecipientsForPermission('issues.view')"), 'G: report alerts follow issues.view rather than role names');
okv_test_ok(str_contains($controller, 'Notifications::announceIssueReportReceived'), 'G: a committed report raises its notices');
okv_test_ok(strpos($controller, 'IssueReports::submit') < strpos($controller, 'Notifications::announceIssueReportReceived'), 'G: the report is saved before notification delivery');
okv_test_ok(str_contains($controller, 'Notifications::announceIssueReportResolved'), 'G: resolved and declined reports notify the customer');
okv_test_ok(str_contains($controller, 'Notifications::announceRefund'), 'G: terminal M5 refund states retain their follow-up notices');
okv_test_ok(!str_contains($controller, 'Mail::send'), 'G: the controller never bypasses the M6 dispatcher');
okv_test_ok(str_contains($migration, "body_template = 'Hello {{customer_name}}"), 'G: the richer customer default is deployed');
okv_test_ok(str_contains($migration, "AND body_template = 'Hello {{customer_name}}"), 'G: migration 048 preserves staff-edited copy');
