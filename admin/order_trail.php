<?php
/**
 * admin/order_trail.php
 * -----------------------------------------------------------------------------
 * "Open the customer trail" from Order 360. Staff are not the order's owner, so
 * /public/order.php?order=ID rightly answers "Order not found" for them. Instead
 * this mints a fresh share token (only its hash is stored) and sends staff to the
 * exact public trail link a customer would follow, money withheld.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('orders.view');

$orderId = (int) okv_input('order', 0);
$token = $orderId > 0 ? OrderTrail::issueForOrder($orderId, Rbac::userId()) : null;

if ($token === null) {
    okv_redirect('/admin/orders.php' . ($orderId > 0 ? '?order=' . $orderId : ''), 303);
}

// The URL carries a bearer token, so keep it out of caches and Referer headers.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
okv_redirect('/public/order.php?token=' . rawurlencode($token), 303);
