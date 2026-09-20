<?php
/** Customer notification feed. Every row is scoped to the signed-in customer. */
require_once __DIR__ . '/../../includes/bootstrap.php';
Customer::requireLoginApi();

$action = okv_action();
$userId = (int) Customer::id();

if ($action === '' || $action === 'list') {
    if (okv_is_post()) {
        okv_error('Use GET to read your updates.', 405, 'method_not_allowed');
    }
    try {
        okv_json(['status' => 'ok'] + CustomerNotifications::payload($userId));
    } catch (Throwable $e) {
        error_log('customer notifications list: ' . $e->getMessage());
        okv_error('Your updates could not be loaded. Try again.', 500, 'load_failed');
    }
}

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

try {
    if ($action === 'mark_read') {
        $changed = CustomerNotifications::markRead($userId, (int) okv_input('delivery_id', 0));
        okv_json(['status' => 'ok', 'changed' => $changed, 'unread_count' => CustomerNotifications::unreadCount($userId)]);
    }
    if ($action === 'mark_all_read') {
        $changed = CustomerNotifications::markAllRead($userId);
        okv_json(['status' => 'ok', 'changed' => $changed, 'unread_count' => 0]);
    }
} catch (Throwable $e) {
    error_log('customer notifications write: ' . $e->getMessage());
    okv_error('That update could not be changed. Try again.', 500, 'update_failed');
}

okv_error('That action is not available.', 400, 'unknown_action');
