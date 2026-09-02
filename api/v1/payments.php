<?php
/**
 * api/v1/payments.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Starting a Paystack charge. See docs/PRD.md Section 11.
 *
 * PR1 opens one action: a signed-in customer starts a charge against a payment
 * row on their own order and is handed the Paystack URL. Recording cash and
 * transfers, reviewing proofs and issuing refunds are staff actions and belong
 * to later PRs in this milestone.
 *
 * Ownership is re-checked on the server on every call. A payment id in a form
 * field proves nothing.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** True when the caller wants JSON rather than a redirect. */
function payments_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

if ($action === 'initialise' || $action === 'initialize') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Customer::requireLoginApi();
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }

    $userId = (int) Customer::id();

    // A customer who hammers this makes a Paystack transaction each time, so it
    // is capped per account rather than per payment.
    if (!RateLimiter::hit('payment_init:' . $userId, 10, 300)) {
        okv_error('Too many payment attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }

    $paymentId = (int) okv_input('payment_id', 0);
    if ($paymentId < 1) {
        okv_error('That payment could not be found.', 422, 'bad_payment');
    }

    // Ownership, on the server, every time. The join is the gate.
    $owned = Database::one(
        'SELECT p.id
           FROM payments p
           JOIN orders o ON o.id = p.order_id
          WHERE p.id = :id AND o.user_id = :user',
        [':id' => $paymentId, ':user' => $userId]
    );
    if (!$owned) {
        okv_error('That payment could not be found.', 404, 'not_found');
    }

    try {
        $callback = rtrim((string) APP_URL, '/') . '/public/payment/callback.php';
        $result   = Payments::beginCharge($paymentId, $callback);
    } catch (Throwable $e) {
        error_log('payments.initialise failed: ' . $e->getMessage());
        okv_error('We could not start that payment. Please try again.', 500, 'failed');
    }

    if (!$result['ok']) {
        $status = $result['code'] === 'gateway_unreachable' ? 503 : 422;
        okv_error($result['message'], $status, $result['code']);
    }

    if (payments_is_fetch()) {
        okv_json([
            'status'            => 'ok',
            'authorization_url' => $result['authorization_url'],
            'reference'         => $result['reference'],
            'amount_subunit'    => $result['amount_subunit'],
            'amount'            => Money::format($result['amount_subunit']),
        ]);
    }
    okv_redirect($result['authorization_url'], 303);
}

okv_error('That action is not available.', 400, 'unknown_action');
