<?php
/**
 * scripts/tests/OrderRescheduleTest.php
 * Reschedule delivery date: eligibility, cutoff, max changes, and wiring.
 */

$lagos = new DateTimeZone('Africa/Lagos');
$at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when, $lagos);

// ----------------------------------------------------------------------------
// Who may reschedule
// ----------------------------------------------------------------------------
okv_test_ok(OrderReschedule::customerMayReschedule('pending', true, true), 'customer may reschedule pending inside cutoff');
okv_test_ok(OrderReschedule::customerMayReschedule('confirmed', true, true), 'customer may reschedule confirmed inside cutoff');
okv_test_ok(!OrderReschedule::customerMayReschedule('packed', true, true), 'customer may not reschedule packed even inside cutoff');
okv_test_ok(!OrderReschedule::customerMayReschedule('dispatched', true, true), 'customer may not reschedule dispatched');
okv_test_ok(!OrderReschedule::customerMayReschedule('delivered', true, true), 'customer may not reschedule delivered');
okv_test_ok(!OrderReschedule::customerMayReschedule('cancelled', true, true), 'customer may not reschedule cancelled');
okv_test_ok(!OrderReschedule::customerMayReschedule('pending', false, true), 'customer may not reschedule after cutoff');
okv_test_ok(!OrderReschedule::customerMayReschedule('pending', true, false), 'customer reschedule can be switched off');

okv_test_ok(OrderReschedule::staffMayReschedule('pending'), 'staff may reschedule pending');
okv_test_ok(OrderReschedule::staffMayReschedule('confirmed'), 'staff may reschedule confirmed');
okv_test_ok(OrderReschedule::staffMayReschedule('packed'), 'staff may reschedule packed');
okv_test_ok(!OrderReschedule::staffMayReschedule('dispatched'), 'staff may not reschedule dispatched');
okv_test_ok(!OrderReschedule::staffMayReschedule('delivered'), 'staff may not reschedule delivered');
okv_test_ok(!OrderReschedule::staffMayReschedule('cancelled'), 'staff may not reschedule cancelled');
okv_test_ok(!OrderReschedule::staffMayReschedule('refunded'), 'staff may not reschedule refunded');

// ----------------------------------------------------------------------------
// Policy line
// ----------------------------------------------------------------------------
$line = OrderReschedule::policyLine('18:00', '2026-09-10', 2, 0);
okv_test_ok(str_contains($line, '18:00'), 'policy line names cutoff');
okv_test_ok(str_contains($line, 'Wednesday 9th'), 'policy line names deadline day');
okv_test_ok(str_contains($line, '2 times'), 'policy line names max');
okv_test_ok(!str_contains($line, "\u{2014}"), 'policy line has no em dash');

$lineUsed = OrderReschedule::policyLine('18:00', '2026-09-10', 2, 1);
okv_test_ok(str_contains($lineUsed, '1 of 2'), 'policy line shows remaining when used');

$lineMax = OrderReschedule::policyLine('18:00', '2026-09-10', 2, 2);
okv_test_ok(str_contains($lineMax, 'used all'), 'policy line says used all when max reached');

// ----------------------------------------------------------------------------
// Constants and pure checks
// ----------------------------------------------------------------------------
okv_test_ok(in_array('pending', OrderReschedule::CUSTOMER_STATUSES, true), 'customer statuses include pending');
okv_test_ok(in_array('confirmed', OrderReschedule::CUSTOMER_STATUSES, true), 'customer statuses include confirmed');
okv_test_ok(!in_array('packed', OrderReschedule::CUSTOMER_STATUSES, true), 'customer statuses exclude packed');

okv_test_ok(in_array('packed', OrderReschedule::STAFF_STATUSES, true), 'staff statuses include packed');
okv_test_ok(in_array('dispatched', OrderReschedule::BLOCKED_STATUSES, true), 'blocked includes dispatched');

// ----------------------------------------------------------------------------
// Wiring checks: controller uses same service, RBAC, CSRF, FOR UPDATE, etc.
// ----------------------------------------------------------------------------
$ordersApi = file_get_contents(dirname(__DIR__, 2) . '/api/v1/orders.php');
okv_test_ok(str_contains($ordersApi, 'reschedule_customer'), 'orders API handles customer reschedule');
okv_test_ok(str_contains($ordersApi, 'reschedule_staff'), 'orders API handles staff reschedule');
okv_test_ok(str_contains($ordersApi, 'OrderReschedule::rescheduleForCustomer'), 'customer path uses OrderReschedule service');
okv_test_ok(str_contains($ordersApi, 'OrderReschedule::rescheduleForStaff'), 'staff path uses OrderReschedule service');
okv_test_ok(str_contains($ordersApi, 'orders.reschedule'), 'staff reschedule checks orders.reschedule permission');
okv_test_ok(str_contains($ordersApi, 'Csrf::validate'), 'reschedule validates CSRF');
okv_test_ok(str_contains($ordersApi, 'okv_is_post'), 'reschedule requires POST');
okv_test_ok(str_contains($ordersApi, 'expected_delivery_date'), 'reschedule uses optimistic concurrency token');
okv_test_ok(str_contains($ordersApi, 'announceReschedule'), 'reschedule announces after commit');
okv_test_ok(str_contains($ordersApi, 'already_rescheduled'), 'reschedule handles idempotent already case');

$service = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/OrderReschedule.php');
okv_test_ok(str_contains($service, 'FOR UPDATE'), 'reschedule locks order row with FOR UPDATE');
okv_test_ok(str_contains($service, 'preferred_delivery_date'), 'reschedule updates preferred_delivery_date');
okv_test_ok(str_contains($service, 'delivery_schedules'), 'reschedule updates delivery_schedules');
okv_test_ok(str_contains($service, 'order_reschedules'), 'reschedule writes append-only history');
okv_test_ok(str_contains($service, 'reschedule_max_changes'), 'reschedule enforces max changes setting');
okv_test_ok(str_contains($service, 'cancellation_cutoff_time'), 'reschedule reuses cancellation cutoff setting');
okv_test_ok(str_contains($service, 'Delivery::isEligible'), 'reschedule checks new date via Delivery::isEligible');
okv_test_ok(str_contains($service, 'isWithinCutoff'), 'reschedule checks old date cutoff');
okv_test_ok(str_contains($service, 'already_rescheduled'), 'reschedule returns already_rescheduled when new equals current');
okv_test_ok(str_contains($service, 'stale'), 'reschedule returns stale on optimistic mismatch');
okv_test_ok(str_contains($service, 'Audit::record'), 'reschedule writes audit log');
okv_test_ok(str_contains($service, 'DUPLICATE_WINDOW'), 'reschedule guards duplicate window for idempotency');

// ----------------------------------------------------------------------------
// Notification catalogue
// ----------------------------------------------------------------------------
okv_test_ok(isset(Notifications::EVENTS['order_rescheduled']), 'customer reschedule event exists');
okv_test_ok(isset(Notifications::EVENTS['admin_order_rescheduled']), 'staff reschedule event exists');
okv_test_eq('customer', Notifications::EVENTS['order_rescheduled']['audience'], 'customer reschedule audience is customer');
okv_test_eq('staff', Notifications::EVENTS['admin_order_rescheduled']['audience'], 'staff reschedule audience is staff');
okv_test_ok(isset(Notifications::TOKENS['order_rescheduled']), 'customer reschedule tokens listed');
okv_test_ok(isset(Notifications::TOKENS['admin_order_rescheduled']), 'staff reschedule tokens listed');
okv_test_ok(in_array('order_number', Notifications::TOKENS['order_rescheduled'], true), 'customer reschedule tokens include order_number');
okv_test_ok(in_array('old_delivery_day', Notifications::TOKENS['order_rescheduled'], true), 'customer reschedule tokens include old date');
okv_test_ok(in_array('new_delivery_day', Notifications::TOKENS['order_rescheduled'], true), 'customer reschedule tokens include new date');
okv_test_ok(in_array('customer_name', Notifications::TOKENS['admin_order_rescheduled'], true), 'staff reschedule tokens include customer_name');
okv_test_ok(in_array('admin_url', Notifications::TOKENS['admin_order_rescheduled'], true), 'staff reschedule tokens include admin_url');
okv_test_ok(in_array('reason', Notifications::TOKENS['admin_order_rescheduled'], true), 'staff reschedule tokens include reason');

$adminEvents = AdminNotifications::EVENTS;
okv_test_ok(isset($adminEvents['admin_order_rescheduled']), 'admin bell includes rescheduled event');
okv_test_eq('orders.view', $adminEvents['admin_order_rescheduled']['permission'] ?? '', 'staff reschedule bell permission is orders.view');

okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php'), 'rescheduleSourceLine'), 'reschedule source line helper exists');
okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php'), 'rescheduleReasonLine'), 'reschedule reason helper exists');
okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php'), 'announceReschedule'), 'announceReschedule method exists');
okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php'), 'order_rescheduled'), 'notifications dispatch handles order_rescheduled');
okv_test_ok(str_contains(file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php'), 'admin_order_rescheduled'), 'notifications dispatch handles admin_order_rescheduled');

$notifService = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');
okv_test_ok(str_contains($notifService, 'alreadyAnnounced') && str_contains($notifService, 'order_rescheduled'), 'reschedule announcement checks already announced for idempotency');
okv_test_ok(str_contains($notifService, 'staffRecipients') && strpos($notifService, 'orders.view') !== false, 'staff reschedule uses orders.view recipients');

// ----------------------------------------------------------------------------
// Settings registry
// ----------------------------------------------------------------------------
$settingsFields = file_get_contents(dirname(__DIR__, 2) . '/includes/config/settings_fields.php');
okv_test_ok(str_contains($settingsFields, 'reschedule_customer_allowed'), 'settings registry includes customer allowed toggle');
okv_test_ok(str_contains($settingsFields, 'reschedule_max_changes'), 'settings registry includes max changes');

// ----------------------------------------------------------------------------
// Migration idempotency
// ----------------------------------------------------------------------------
$migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/062_order_reschedule.sql');
okv_test_ok(str_contains($migration, 'CREATE TABLE IF NOT EXISTS'), 'migration creates table idempotently');
okv_test_ok(str_contains($migration, 'order_reschedules'), 'migration creates order_reschedules table');
okv_test_ok(str_contains($migration, 'reschedule_max_changes'), 'migration seeds max changes setting');
okv_test_ok(str_contains($migration, 'reschedule_customer_allowed'), 'migration seeds customer allowed setting');
okv_test_ok(str_contains($migration, 'orders.reschedule'), 'migration seeds reschedule permission');
okv_test_ok(str_contains($migration, 'order_rescheduled'), 'migration seeds customer template');
okv_test_ok(str_contains($migration, 'admin_order_rescheduled'), 'migration seeds staff template');
okv_test_ok(str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'migration uses idempotent upsert for settings/templates');
okv_test_ok(str_contains($migration, 'INSERT IGNORE'), 'migration uses INSERT IGNORE for permissions');

// ----------------------------------------------------------------------------
// UI: customer order page and account page
// ----------------------------------------------------------------------------
$orderPage = file_get_contents(dirname(__DIR__, 2) . '/public/order.php');
okv_test_ok(str_contains($orderPage, 'reschedule_customer'), 'customer order page posts to reschedule_customer');
okv_test_ok(str_contains($orderPage, 'expected_delivery_date'), 'customer order page carries expected delivery date token');
okv_test_ok(str_contains($orderPage, 'new_delivery_date'), 'customer order page posts new delivery date');
okv_test_ok(str_contains($orderPage, 'policy_line'), 'customer order page shows policy line');
okv_test_ok(str_contains($orderPage, 'eligible_dates'), 'customer order page shows eligible dates');
okv_test_ok(str_contains($orderPage, 'Past moves'), 'customer order page shows history');
okv_test_ok(str_contains($orderPage, 'Move delivery'), 'customer order page has reschedule action');

$accountPage = file_get_contents(dirname(__DIR__, 2) . '/account.php');
okv_test_ok(str_contains($accountPage, 'reschedule_count'), 'account page shows reschedule count');
okv_test_ok(str_contains($accountPage, 'Reschedule'), 'account page links to reschedule');

$adminOrders = file_get_contents(dirname(__DIR__, 2) . '/admin/orders.php');
okv_test_ok(str_contains($adminOrders, 'Reschedule history'), 'admin order detail shows reschedule history');
okv_test_ok(str_contains($adminOrders, 'reschedule_staff'), 'admin order detail posts to reschedule_staff');
okv_test_ok(str_contains($adminOrders, 'Move delivery to another day'), 'admin order detail has staff reschedule form');
okv_test_ok(str_contains($adminOrders, 'eligible_dates'), 'admin order detail shows eligible dates for staff');

$adminCustomers = file_get_contents(dirname(__DIR__, 2) . '/admin/customers.php');
okv_test_ok(str_contains($adminCustomers, 'Rescheduled'), 'admin customer trail shows rescheduled column');

// ----------------------------------------------------------------------------
// Bootstrap and permissions
// ----------------------------------------------------------------------------
$bootstrap = file_get_contents(dirname(__DIR__, 2) . '/includes/bootstrap.php');
okv_test_ok(str_contains($bootstrap, 'OrderReschedule'), 'bootstrap loads OrderReschedule class');

$perms = file_get_contents(dirname(__DIR__, 2) . '/includes/config/permissions.php');
okv_test_ok(str_contains($perms, 'orders.reschedule'), 'permissions catalogue includes orders.reschedule');

// ----------------------------------------------------------------------------
// No hardcoded secrets: templates come from DB, not controller
// ----------------------------------------------------------------------------
okv_test_ok(!preg_match('/noreply@|@okveggies\.com.*reschedule/i', $ordersApi), 'orders API does not hardcode email for reschedule');
okv_test_ok(!preg_match('/https?:\/\/.*reschedule/i', $ordersApi), 'orders API does not hardcode URL for reschedule');
