<?php
/** Order cancellation actions for customers and staff. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

function orders_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function orders_write_guard(): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    if (!okv_input('confirmed', '')) {
        okv_error('Confirm that you want to cancel this order.', 422, 'not_confirmed');
    }
}

function orders_status_guard(): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('orders.status.update');
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

function orders_done(array $result, string $redirect): void
{
    if (orders_is_fetch()) {
        okv_json(['status' => 'ok'] + $result);
    }
    $flag = $result['code'] === 'already_cancelled' ? 'already_cancelled' : 'cancelled';
    okv_redirect($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cancellation=' . $flag, 303);
}

if ($action === 'transition') {
    orders_status_guard();
    $orderId = (int) okv_input('order_id', 0);
    try {
        $result = OrderLifecycle::transition(
            $orderId,
            (string) okv_input('expected_status', ''),
            (string) okv_input('target_status', ''),
            (int) Rbac::userId(),
            trim((string) okv_input('note', ''))
        );
    } catch (Throwable $e) {
        error_log('orders.transition failed: ' . $e->getMessage());
        okv_error('The order was not changed. Please reload it and try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        $status = $result['code'] === 'not_found' ? 404 : ($result['code'] === 'stale' ? 409 : 422);
        okv_error($result['message'], $status, $result['code']);
    }
    if ($result['code'] === 'transitioned') {
        // Committed first, announced second. A bounced email never un-packs an
        // order, and the failure shows on the order screen instead.
        Notifications::announceStage($orderId, (string) $result['status'], (int) Rbac::userId());
    }
    if (orders_is_fetch()) {
        okv_json(['status' => 'ok'] + $result);
    }
    okv_redirect('/admin/orders.php?order=' . $orderId . '&status=' . rawurlencode($result['code']), 303);
}

if ($action === 'cancel_customer') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Customer::requireLoginApi();
    orders_write_guard();

    try {
        $result = OrderCancellation::cancelForCustomer(
            (int) okv_input('order_id', 0),
            (int) Customer::id(),
            (string) okv_input('reason_code', ''),
            (string) okv_input('reason_text', '')
        );
    } catch (Throwable $e) {
        error_log('orders.cancel_customer failed: ' . $e->getMessage());
        okv_error('We could not cancel that order. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    if ($result['code'] === 'cancelled') {
        Notifications::announceCancellation((int) okv_input('order_id', 0), $result, (int) Customer::id());
    }
    orders_done($result, '/public/order.php?order=' . (int) okv_input('order_id', 0));
}

if ($action === 'cancel_staff') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('orders.cancel');
    orders_write_guard();

    try {
        $result = OrderCancellation::cancelForStaff(
            (int) okv_input('order_id', 0),
            (int) Rbac::userId(),
            (string) okv_input('reason_code', ''),
            (string) okv_input('reason_text', ''),
            Rbac::can('payments.refund'),
            (bool) okv_input('dispatch_terms', '')
        );
    } catch (Throwable $e) {
        error_log('orders.cancel_staff failed: ' . $e->getMessage());
        okv_error('The order was not changed. Please reload it and try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        $status = $result['code'] === 'not_found' ? 404 : ($result['code'] === 'refund_permission_required' ? 403 : 422);
        okv_error($result['message'], $status, $result['code']);
    }
    if ($result['code'] === 'cancelled') {
        Notifications::announceCancellation((int) okv_input('order_id', 0), $result, (int) Rbac::userId());
    }
    orders_done($result, '/admin/orders.php?order=' . (int) okv_input('order_id', 0));
}

if ($action === 'save_note') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('orders.update');
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    $orderId = (int) okv_input('order_id', 0);
    $note = trim((string) okv_input('staff_note', ''));
    if (mb_strlen($note) > 2000) {
        okv_error('Keep the note to 2,000 characters or fewer.', 422, 'note_too_long');
    }
    try {
        $changed = Database::run(
            'UPDATE orders SET staff_note = :note WHERE id = :id',
            [':note' => $note !== '' ? $note : null, ':id' => $orderId]
        );
        if ($changed === 0 && !Database::one('SELECT id FROM orders WHERE id = :id', [':id' => $orderId])) {
            okv_error('That order could not be found.', 404, 'not_found');
        }
        Audit::record('orders.note.update', 'order', $orderId, null, ['staff_note' => $note]);
    } catch (Throwable $e) {
        error_log('orders.save_note failed: ' . $e->getMessage());
        okv_error('The note was not saved. Please try again.', 500, 'failed');
    }
    if (orders_is_fetch()) {
        okv_json(['status' => 'ok', 'code' => 'note_saved']);
    }
    okv_redirect('/admin/orders.php?order=' . $orderId . '&status=note_saved', 303);
}

if ($action === 'resend_notification') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('notifications.resend');
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    $orderId = (int) okv_input('order_id', 0);
    try {
        $result = Notifications::resend((int) okv_input('delivery_id', 0), (int) Rbac::userId());
    } catch (Throwable $e) {
        error_log('orders.resend_notification failed: ' . $e->getMessage());
        okv_error('That email could not be sent again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    if (orders_is_fetch()) {
        okv_json(['status' => 'ok'] + $result);
    }
    okv_redirect('/admin/orders.php?order=' . $orderId . '&status=resent', 303);
}

okv_error('That action is not available.', 400, 'unknown_action');
