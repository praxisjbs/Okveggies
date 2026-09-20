<?php
/** Pure contracts for the shared admin notification bell. */

okv_test_eq('orders.view', AdminNotifications::permissionForEvent('admin_new_order'), 'new orders require Orders visibility');
okv_test_eq('payments.view', AdminNotifications::permissionForEvent('admin_manual_payment_proof'), 'payment proofs require Payments visibility');
okv_test_eq('messages.view', AdminNotifications::permissionForEvent('admin_new_contact'), 'contact alerts require Messages visibility');
okv_test_eq('issues.view', AdminNotifications::permissionForEvent('admin_new_issue_report'), 'issue alerts require Make It Right visibility');
okv_test_eq(null, AdminNotifications::permissionForEvent('customer_only_event'), 'an unknown event is never admitted to the staff feed');

okv_test_eq('/admin/orders.php?order=42', AdminNotifications::hrefFor('order', 42), 'an order alert deep-links to Order 360');
okv_test_eq('/admin/kitchen_runs.php?request=7', AdminNotifications::hrefFor('kitchen_run', 7), 'a Kitchen Run alert deep-links to its request');
okv_test_eq('/admin/content.php?message=9', AdminNotifications::hrefFor('contact_message', 9), 'a contact alert deep-links to its message');
okv_test_eq('/admin/payments.php#queue-heading', AdminNotifications::hrefFor('payment_proof', 3), 'a proof alert opens the review queue');
okv_test_eq('/admin/', AdminNotifications::hrefFor('unknown', 3), 'an unknown relation fails safely to the dashboard');

$js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/admin-notifications.js');
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/src/input.css');
$header = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/admin/header.php');
$api = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/admin_notifications.php');

okv_test_ok(!str_contains($js, 'innerHTML'), 'notification data is never rendered through innerHTML');
okv_test_ok(str_contains($js, 'textContent') && str_contains($js, 'document.createElement'), 'notification rows use safe DOM construction');
okv_test_ok(str_contains($js, '60000') && str_contains($js, "document.visibilityState === 'visible'"), 'the feed refreshes every 60 seconds only for a visible tab');
okv_test_ok(str_contains($js, "action: 'mark_read'") && str_contains($js, "action: 'mark_all_read'"), 'individual and bulk read controls use explicit actions');
okv_test_ok(str_contains($js, "event.key !== 'Tab'") && str_contains($js, "event.shiftKey"), 'the notification dialog traps focus in both directions');
okv_test_ok(str_contains($css, '.okv-notification-backdrop') && str_contains($css, 'md:absolute'), 'notifications use a mobile sheet and desktop panel');
okv_test_ok(strpos($header, "notification_bell.php") < strpos($header, 'View shop'), 'the bell sits before View shop in the shared header');
okv_test_ok(str_contains($api, 'Rbac::requireAuth()') && str_contains($api, 'Csrf::validate()'), 'the feed requires staff auth and read changes require CSRF');
