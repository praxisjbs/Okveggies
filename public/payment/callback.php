<?php
/**
 * public/payment/callback.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Where Paystack sends a customer back after they pay.
 *
 * This page renders nothing. It verifies, applies and redirects to the order,
 * so the customer sees their own order page rather than a bare receipt.
 *
 * The webhook is the reliable path and this is the fast one. Both call the same
 * ledger and the ledger admits exactly one of them, so whichever arrives first
 * credits and the other is recorded as a duplicate. A customer who closes the
 * tab here loses nothing: the webhook, and failing that the sweep, still settle
 * the order.
 *
 * There is no CSRF token on this request because it is a redirect from
 * Paystack, not a form of ours. That is safe: the only thing this can do is
 * record a payment that Paystack has independently confirmed against a
 * reference we minted ourselves, which is idempotent and harmless to repeat.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

// Paystack appends both; trxref is the older name and they carry the same value.
$reference = trim((string) (okv_input('reference', '') ?: okv_input('trxref', '')));

/** Send the customer to their order, or to the shop when we cannot place them. */
function payment_callback_land(?int $orderId, string $outcome): void
{
    if ($orderId === null) {
        okv_redirect('/shop.php?payment=' . rawurlencode($outcome), 303);
    }
    okv_redirect('/public/order.php?order=' . $orderId . '&payment=' . rawurlencode($outcome), 303);
}

if ($reference === '' || !Paystack::isValidReference($reference)) {
    payment_callback_land(null, 'missing');
}

// Resolve the order from our own row, never from anything Paystack sent back.
$row = Database::one(
    'SELECT t.id AS txn_id, p.order_id
       FROM payment_transactions t
       JOIN payments p ON p.id = t.payment_id
      WHERE t.reference = :ref',
    [':ref' => $reference]
);
$orderId = $row ? (int) $row['order_id'] : null;

try {
    $verified = Paystack::verifyTransaction($reference);
} catch (Throwable $e) {
    error_log('payment callback: verify threw for ' . $reference . ': ' . $e->getMessage());
    payment_callback_land($orderId, 'pending');
}

if (!$verified['ok']) {
    // We do not know, or Paystack said no. Either way the webhook and the sweep
    // are still coming, so the customer is told it is being confirmed rather
    // than being told it failed.
    payment_callback_land($orderId, $verified['reason'] === 'network' ? 'pending' : 'failed');
}

$status = (string) ($verified['data']['status'] ?? '');
if ($status !== 'success') {
    payment_callback_land($orderId, $status === 'abandoned' ? 'abandoned' : 'failed');
}

try {
    $result = Payments::applyVerifiedCharge($reference, $verified['data'], 'callback');
} catch (Throwable $e) {
    error_log('payment callback: apply threw for ' . $reference . ': ' . $e->getMessage());
    payment_callback_land($orderId, 'pending');
}

if ($result['ok']) {
    payment_callback_land((int) $result['order_id'], $result['mismatch'] === 'exact' ? 'paid' : 'review');
}
if ($result['code'] === 'duplicate') {
    // The webhook beat us here. That is a success from where the customer sits.
    payment_callback_land($orderId, 'paid');
}
if ($result['code'] === 'overpayment') {
    payment_callback_land($orderId, 'review');
}
payment_callback_land($orderId, 'failed');
