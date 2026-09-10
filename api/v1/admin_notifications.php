<?php
/** Staff notification feed. Every row is scoped to the signed-in user. */
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requireAuth();

$action = okv_action('list');
$userId = (int) Rbac::userId();

if ($action === 'list') {
    if (okv_is_post()) {
        okv_error('Use GET to read notifications.', 405, 'method_not_allowed');
    }
    try {
        okv_json(['status' => 'ok'] + AdminNotifications::payload($userId));
    } catch (Throwable $e) {
        error_log('admin notifications list: ' . $e->getMessage());
        okv_error('Notifications could not be loaded. Try again.', 500, 'load_failed');
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
        $changed = AdminNotifications::markRead($userId, (int) okv_input('delivery_id', 0));
        okv_json(['status' => 'ok', 'changed' => $changed, 'unread_count' => AdminNotifications::unreadCount($userId)]);
    }
    if ($action === 'mark_all_read') {
        $changed = AdminNotifications::markAllRead($userId);
        okv_json(['status' => 'ok', 'changed' => $changed, 'unread_count' => 0]);
    }
} catch (Throwable $e) {
    error_log('admin notifications write: ' . $e->getMessage());
    okv_error('That notification could not be updated. Try again.', 500, 'update_failed');
}

okv_error('That action is not available.', 400, 'unknown_action');
