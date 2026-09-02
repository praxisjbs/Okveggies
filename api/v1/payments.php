<?php
/**
 * api/v1/payments.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Starting a Paystack charge. See docs/PRD.md Section 11.
 *
 * Two audiences, one controller. A signed-in customer starts a Paystack charge
 * against a payment row on their own order. Staff record money that arrived
 * outside Paystack, review the evidence behind it, and ask for and approve a
 * reversal when something was recorded wrongly.
 *
 * Ownership is re-checked on the server on every customer call, and every staff
 * action is gated on its own permission rather than on a role name, so a new
 * role tomorrow can be granted any one of them without touching this file. An
 * id in a form field proves nothing.
 *
 * Refunds, where money really does travel back to a customer, are PR3.
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

// -----------------------------------------------------------------------------
// Staff actions. Each one gates on its own permission.
// -----------------------------------------------------------------------------

/** The gate every staff payment write passes: POST, permission, CSRF. */
function payments_staff_guard(string $permission): int
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    return (int) Rbac::userId();
}

/** Answer a staff write: JSON for a fetch, a 303 back to the screen otherwise. */
function payments_staff_done(array $result, string $flag): void
{
    if (payments_is_fetch()) {
        okv_json(['status' => 'ok', 'message' => $result['message'], 'code' => $result['code']]);
    }
    okv_redirect('/admin/payments.php?payments=' . rawurlencode($flag), 303);
}

if ($action === 'record_manual') {
    $staffId = payments_staff_guard('payments.record');

    // The confirmation is a real gate, not just a tick on the form. A staff
    // action that credits an order immediately should not be reachable by
    // replaying a request without it.
    if (!okv_input('confirmed', '')) {
        okv_error('Tick the confirmation before recording a payment.', 422, 'not_confirmed');
    }

    $input = [
        'payment_id'     => (int) okv_input('payment_id', 0),
        'amount_subunit' => Money::toSubunit((string) okv_input('amount', '')),
        'method'         => (string) okv_input('method', ''),
        'record_token'   => (string) okv_input('record_token', ''),
        'bank_reference' => (string) okv_input('bank_reference', ''),
        'payer_name'     => (string) okv_input('payer_name', ''),
        'customer_email' => (string) okv_input('customer_email', ''),
    ];

    // An uploaded screenshot or PDF receipt. Optional for cash, and one of the
    // two accepted forms of evidence for a transfer.
    if (!empty($_FILES['proof']['name'] ?? '')) {
        try {
            $input['proof_url'] = Uploads::saveUploadedFile($_FILES['proof'], 'payment_proofs');
        } catch (Throwable $e) {
            okv_error($e->getMessage(), 422, 'bad_upload');
        }
    }

    try {
        $result = ManualPayments::record($input, $staffId);
    } catch (Throwable $e) {
        error_log('payments.record_manual failed: ' . $e->getMessage());
        okv_error('We could not record that payment. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'recorded');
}

if ($action === 'review_proof') {
    $staffId = payments_staff_guard('payments.proof.review');
    try {
        $result = ManualPayments::reviewProof(
            (int) okv_input('proof_id', 0),
            (string) okv_input('decision', ''),
            (string) okv_input('note', ''),
            $staffId
        );
    } catch (Throwable $e) {
        error_log('payments.review_proof failed: ' . $e->getMessage());
        okv_error('We could not record that review. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'reviewed');
}

if ($action === 'request_reversal') {
    $staffId = payments_staff_guard('payments.reversal.request');
    try {
        $result = ManualPayments::requestReversal(
            (int) okv_input('transaction_id', 0),
            (string) okv_input('reason', ''),
            $staffId
        );
    } catch (Throwable $e) {
        error_log('payments.request_reversal failed: ' . $e->getMessage());
        okv_error('We could not raise that reversal. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'reversal_requested');
}

if ($action === 'decide_reversal') {
    $staffId = payments_staff_guard('payments.reversal.approve');
    // The Owner may approve their own request, because at launch the Owner can
    // be the only staff account and a one person business still has to be able
    // to fix a typo. Everyone else needs a second pair of eyes.
    $isOwner = in_array('owner', Rbac::roles(), true);
    try {
        $result = ManualPayments::decideReversal(
            (int) okv_input('reversal_id', 0),
            (string) okv_input('decision', ''),
            (string) okv_input('note', ''),
            $staffId,
            $isOwner
        );
    } catch (Throwable $e) {
        error_log('payments.decide_reversal failed: ' . $e->getMessage());
        okv_error('We could not decide that reversal. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'reversal_' . $result['code']);
}

if ($action === 'refund_quote') {
    // Everything at stake, for the confirmation. Read only, so no CSRF, but
    // still permission gated: what a customer paid is not public.
    Rbac::requirePermission('payments.refund');
    $quote = Refunds::quote((int) okv_input('transaction_id', 0));
    if (!$quote['ok']) {
        okv_error($quote['message'], 404, $quote['code']);
    }
    $quote['paid']       = Money::format($quote['paid_subunit']);
    $quote['refunded']   = Money::format($quote['refunded_subunit']);
    $quote['refundable'] = Money::format($quote['refundable_subunit']);
    okv_json(['status' => 'ok'] + $quote);
}

if ($action === 'request_refund') {
    $staffId = payments_staff_guard('payments.refund');

    // A refund cannot be undone, so the confirmation is a server side gate and
    // not merely a dialog the browser drew.
    if (!okv_input('confirmed', '')) {
        okv_error('Confirm the refund details before sending money back.', 422, 'not_confirmed');
    }

    try {
        $result = Refunds::request(
            (int) okv_input('transaction_id', 0),
            Money::toSubunit((string) okv_input('amount', '')),
            (string) okv_input('customer_note', ''),
            (string) okv_input('merchant_note', ''),
            $staffId
        );
    } catch (Throwable $e) {
        error_log('payments.request_refund failed: ' . $e->getMessage());
        okv_error('We could not raise that refund. Check the Paystack dashboard before trying again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'refunded');
}

okv_error('That action is not available.', 400, 'unknown_action');
