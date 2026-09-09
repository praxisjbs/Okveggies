<?php
/** Customer-owned Pro order actions. */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!okv_is_post()) { okv_error('Use POST for this action.', 405, 'method_not_allowed'); }
if (!Csrf::validate()) { okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired'); }
Customer::requireLoginApi();
if (!Customer::isBusiness()) { okv_error('This action is for business accounts.', 403, 'forbidden'); }
if (okv_action() !== 'create_trail_link') { okv_error('That action is not available.', 400, 'unknown_action'); }

$orderId = (int) okv_input('order_id', 0);
try {
    $token = OrderTrail::issueForCustomer($orderId, (int) Customer::id());
    if ($token === null) { okv_error('That order is not available.', 404, 'not_found'); }
    Audit::record('order_trail.share_link.create', 'order', $orderId, null, null, (int) Customer::id());
    $redirect = '/public/order.php?token=' . rawurlencode($token);
    $wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if ($wantsJson) { okv_json(['status' => 'ok', 'redirect' => $redirect]); }
    okv_redirect($redirect, 303);
} catch (Throwable $e) {
    error_log('pro order trail link failed: ' . $e->getMessage());
    okv_error('We could not open a share link. Please try again.', 500, 'failed');
}
