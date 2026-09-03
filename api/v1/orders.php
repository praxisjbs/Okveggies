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

function orders_done(array $result, string $redirect): void
{
    if (orders_is_fetch()) {
        okv_json(['status' => 'ok'] + $result);
    }
    $flag = $result['code'] === 'already_cancelled' ? 'already_cancelled' : 'cancelled';
    okv_redirect($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'cancellation=' . $flag, 303);
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
            Rbac::can('payments.refund')
        );
    } catch (Throwable $e) {
        error_log('orders.cancel_staff failed: ' . $e->getMessage());
        okv_error('The order was not changed. Please reload it and try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        $status = $result['code'] === 'not_found' ? 404 : ($result['code'] === 'refund_permission_required' ? 403 : 422);
        okv_error($result['message'], $status, $result['code']);
    }
    orders_done($result, '/admin/orders.php?order=' . (int) okv_input('order_id', 0));
}

okv_error('That action is not available.', 400, 'unknown_action');
