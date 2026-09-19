<?php
/** Pure contracts for the customer notification bell. */

// The feed boundary is the customer-audience events, taken from the one
// catalogue, so a staff alert can never reach a customer's bell.
$events = CustomerNotifications::events();
okv_test_ok(in_array('order_placed', $events, true), 'a customer sees their own order updates');
okv_test_ok(in_array('kitchen_run_quoted', $events, true), 'a customer sees a Kitchen Run quote');
okv_test_ok(in_array('issue_report_resolved', $events, true), 'a customer sees a Make It Right outcome');
okv_test_ok(!in_array('admin_new_order', $events, true), 'a staff order alert never reaches the customer feed');
okv_test_ok(!in_array('admin_new_issue_report', $events, true), 'a staff issue alert never reaches the customer feed');
okv_test_ok(!in_array('refund_failed', $events, true), 'a staff-only refund failure never reaches the customer feed');

// Every customer-audience event is one the storefront can actually open.
foreach ($events as $event) {
    okv_test_ok(($DEF = Notifications::EVENTS[$event]['audience'] ?? '') === 'customer', $event . ' is a customer-audience event');
}

okv_test_eq('/public/order.php?order=42', CustomerNotifications::hrefFor('order', 42), 'an order update opens the signed-in order');
okv_test_eq('/kitchen-runs.php?request=7', CustomerNotifications::hrefFor('kitchen_run', 7), 'a Kitchen Run update opens its request');
okv_test_eq('/pro/credit.php', CustomerNotifications::hrefFor('credit_application', 5), 'a credit update opens Pro Credit');
okv_test_eq('/account.php', CustomerNotifications::hrefFor('issue_report', 9), 'a report update falls back to the account page');
okv_test_eq('/account.php', CustomerNotifications::hrefFor('order', 0), 'an order update with no id falls back safely');
okv_test_eq('/account.php', CustomerNotifications::hrefFor('unknown', 3), 'an unknown relation falls back safely');

$js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/notifications.js');
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/src/input.css');
$shopHeader = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/shop/header.php');
$proHeader = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/pro/header.php');
$component = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/components/shop/notification_bell.php');
$api = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/notifications.php');
$account = (string) file_get_contents(dirname(__DIR__, 2) . '/account.php');

okv_test_ok(!str_contains($js, 'innerHTML'), 'notification data is never rendered through innerHTML');
okv_test_ok(str_contains($js, 'textContent') && str_contains($js, 'document.createElement'), 'notification rows use safe DOM construction');
okv_test_ok(!str_contains($js, 'setInterval') && !str_contains($js, '60000'), 'the customer feed does not poll, it loads on open');
okv_test_ok(str_contains($js, "event.key === 'Escape'"), 'the customer dialog closes on Escape');
okv_test_ok(str_contains($js, "action: 'mark_read'") && str_contains($js, "action: 'mark_all_read'"), 'individual and bulk read controls use explicit actions');
okv_test_ok(str_contains($js, "event.key !== 'Tab'") && str_contains($js, 'event.shiftKey'), 'the customer dialog traps focus in both directions');
okv_test_ok(str_contains($js, "'/api/v1/notifications.php'"), 'the customer feed talks to the customer endpoint');
okv_test_ok(str_contains($css, '.okv-notification-backdrop'), 'the bell reuses the shared mobile-sheet and desktop-panel styles');

okv_test_ok(str_contains($component, 'Customer::isLoggedIn()') && str_contains($component, 'return;'), 'the bell renders only for a signed-in customer');
okv_test_ok(str_contains($shopHeader, 'notification_bell.php'), 'the storefront header carries the bell');
okv_test_ok(str_contains($proHeader, 'notification_bell.php'), 'the Pro shell carries the bell');

okv_test_ok(str_contains($api, 'Customer::requireLoginApi()') && str_contains($api, 'Csrf::validate()'), 'the feed requires a signed-in customer and write actions require CSRF');
okv_test_ok(str_contains($api, "okv_error('Use GET"), 'the list read refuses a POST');

okv_test_ok(str_contains($account, 'CustomerNotifications::recent'), 'the account updates list reads the scoped customer feed');
okv_test_ok(!str_contains($account, 'markInboxRead'), 'the account page no longer marks everything read on view, so the badge stays honest');
