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

if ($action === 'browse') {
    // The live search behind the "Record a payment" box on
    // /admin/payments.php. A read, so a GET, and gated on the same permission
    // the screen opens with. The markup comes from the one component the
    // screen renders on a plain load, so typing and reloading agree exactly.
    if (okv_is_post()) {
        okv_error('Use GET for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('payments.view');

    $search      = mb_substr(trim((string) okv_input('q', okv_input('order', ''))), 0, 100);
    $openOrderId = (int) okv_input('order_id', 0);
    $matches     = $search !== '' ? Payments::searchOrders($search) : [];

    require_once __DIR__ . '/../../includes/components/admin/payment_search_results.php';
    ob_start();
    okv_admin_payment_search_results($matches, $search, $openOrderId);
    $html = (string) ob_get_clean();

    // The auto-fill suggestions: the order number goes into the box, because
    // it is the one spelling that identifies exactly one order, and the name
    // and the day ride alongside so a colleague can check it against the
    // caller before they take it.
    $suggestions = array_slice(array_map(static function (array $match): array {
        return [
            'value' => (string) $match['order_number'],
            'label' => okv_payments_customer_name($match),
            'sub'   => (string) $match['order_number'] . ' . ' . date('j M Y', strtotime((string) $match['created_at'])),
        ];
    }, $matches), 0, 7);

    okv_json([
        'status'      => 'ok',
        'html'        => $html,
        'summary'     => $search === '' ? '' : count($matches) . ' order' . (count($matches) === 1 ? '' : 's') . ' match' . (count($matches) === 1 ? 'es' : ''),
        'suggestions' => $suggestions,
    ]);
}

/**
 * Start a Paystack charge for a payment the caller has already been proved to
 * own, then answer: JSON for a fetch, otherwise a 303 to Paystack. One tail for
 * every way of starting a payment, so the errors and the response cannot differ.
 */
function payments_start_charge(int $paymentId): void
{
    try {
        $callback = rtrim((string) APP_URL, '/') . '/public/payment/callback.php';
        $result   = Payments::beginCharge($paymentId, $callback);
    } catch (Throwable $e) {
        error_log('payments.initialise failed: ' . $e->getMessage());
        okv_error('We could not start that payment. Please try again.', 500, 'failed');
    }

    if (!$result['ok']) {
        $status = $result['code'] === 'gateway_unreachable' ? 503 : 422;
        // A person tapped Pay and the gateway did not answer. Send them back to
        // their order, where the page already says so in plain words and the
        // button is still there, rather than leaving them on a page of JSON.
        if (!payments_is_fetch() && in_array($result['code'], ['gateway_unreachable', 'gateway_refused'], true)) {
            $row = Database::one('SELECT order_id FROM payments WHERE id = :id', [':id' => $paymentId]);
            if ($row) {
                okv_redirect('/public/order.php?order=' . (int) $row['order_id'] . '&payment=unavailable', 303);
            }
        }
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

if ($action === 'initialise' || $action === 'initialize') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }

    // Two ways to prove this payment is yours, and never a third. A signed-in
    // customer owns the order. A guest order has no account to sign in to
    // (PRD 9.2), so its Order Trail token is the only credential it has: the
    // token is unguessable, stored only as a hash, and it opens nothing but
    // this one order. A token is refused the moment the order has an account
    // behind it, so it can never be used to pay around a sign in.
    $token = trim((string) okv_input('token', ''));
    if ($token === '') {
        Customer::requireLoginApi();
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }

    $userId    = Customer::id() === null ? null : (int) Customer::id();
    $paymentId = (int) okv_input('payment_id', 0);
    if ($paymentId < 1) {
        okv_error('That payment could not be found.', 422, 'bad_payment');
    }

    // A customer who hammers this makes a Paystack transaction each time, so it
    // is capped per account, and per order for a guest who has none.
    $limitKey = $userId !== null ? 'payment_init:' . $userId : 'payment_init:guest:' . $paymentId;
    if (!RateLimiter::hit($limitKey, 10, 300)) {
        okv_error('Too many payment attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }

    // Ownership, on the server, every time. The join is the gate.
    $owned = $token !== '' && OrderTrail::isValidToken($token)
        ? Database::one(
            'SELECT p.id
               FROM payments p
               JOIN orders o ON o.id = p.order_id
              WHERE p.id = :id AND o.user_id IS NULL AND o.order_trail_token_hash = :token',
            [':id' => $paymentId, ':token' => OrderTrail::hashToken($token)]
        )
        : ($userId === null ? null : Database::one(
            'SELECT p.id
               FROM payments p
               JOIN orders o ON o.id = p.order_id
              WHERE p.id = :id AND o.user_id = :user',
            [':id' => $paymentId, ':user' => $userId]
        ));
    if (!$owned) {
        okv_error('That payment could not be found.', 404, 'not_found');
    }
    if ($token !== '') {
        // Keep it for the trip back from Paystack, which carries no token.
        $orderRow = Database::one('SELECT order_id FROM payments WHERE id = :id', [':id' => $paymentId]);
        OrderTrail::remember((int) ($orderRow['order_id'] ?? 0), $token);
    }

    payments_start_charge($paymentId);
}

if ($action === 'start_deposit') {
    // A pay on delivery order needs its deposit before it can be sourced. The
    // deposit row is opened here (idempotently), then charged exactly like any
    // other Paystack payment. Ownership is proved the same two ways as above.
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    $token = trim((string) okv_input('token', ''));
    if ($token === '') {
        Customer::requireLoginApi();
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    $userId  = Customer::id() === null ? null : (int) Customer::id();
    $orderId = (int) okv_input('order_id', 0);
    if ($orderId < 1) {
        okv_error('That order could not be found.', 422, 'bad_order');
    }
    $limitKey = $userId !== null ? 'payment_init:' . $userId : 'payment_init:guest:o' . $orderId;
    if (!RateLimiter::hit($limitKey, 10, 300)) {
        okv_error('Too many payment attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }
    $owned = $token !== '' && OrderTrail::isValidToken($token)
        ? Database::one(
            'SELECT id FROM orders WHERE id = :id AND user_id IS NULL AND order_trail_token_hash = :token',
            [':id' => $orderId, ':token' => OrderTrail::hashToken($token)]
        )
        : ($userId === null ? null : Database::one(
            'SELECT id FROM orders WHERE id = :id AND user_id = :user',
            [':id' => $orderId, ':user' => $userId]
        ));
    if (!$owned) {
        okv_error('That order could not be found.', 404, 'not_found');
    }
    if ($token !== '') {
        OrderTrail::remember($orderId, $token);
    }
    try {
        $opened = Payments::openDepositPayment($orderId, null, $userId);
    } catch (Throwable $e) {
        error_log('payments.start_deposit failed: ' . $e->getMessage());
        okv_error('We could not start that payment. Please try again.', 500, 'failed');
    }
    if (!$opened['ok']) {
        okv_error($opened['message'], $opened['code'] === 'not_found' ? 404 : 422, $opened['code']);
    }
    payments_start_charge((int) $opened['payment_id']);
}

if ($action === 'use_wallet') {
    // Pay an order, or as much of it as the wallet holds, from the customer's
    // wallet. Only a signed-in account has a wallet, so there is no guest path.
    // When the wallet cannot cover the whole amount and the form says so
    // (then=card), what is left goes straight on to Paystack.
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Customer::requireLoginApi();
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
    $userId  = (int) Customer::id();
    $orderId = (int) okv_input('order_id', 0);
    if ($orderId < 1) {
        okv_error('That order could not be found.', 422, 'bad_order');
    }
    if (!RateLimiter::hit('wallet_pay:' . $userId, 10, 300)) {
        okv_error('Too many attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }
    $order = Database::one(
        'SELECT id, order_status, payment_option FROM orders WHERE id = :id AND user_id = :user',
        [':id' => $orderId, ':user' => $userId]
    );
    if (!$order) {
        okv_error('That order could not be found.', 404, 'not_found');
    }

    $walletBack = static function (string $code, string $message) use ($orderId): void {
        if (payments_is_fetch()) {
            okv_error($message, 422, $code);
        }
        okv_redirect('/public/order.php?order=' . $orderId . '&wallet_error=' . rawurlencode($code), 303);
    };

    try {
        // A pay on delivery order has no online row until its deposit is opened.
        if ((string) $order['payment_option'] === 'pay_on_delivery'
            && (string) $order['order_status'] === 'pending'
            && Payments::pendingOnlinePayment($orderId) === null
        ) {
            $opened = Payments::openDepositPayment($orderId, $userId, $userId);
            if (!$opened['ok']) {
                $walletBack($opened['code'], $opened['message']);
            }
        }
        $result = Wallet::payOrder($orderId, $userId, $userId);
    } catch (Throwable $e) {
        error_log('payments.use_wallet failed: ' . $e->getMessage());
        okv_error('We could not use your wallet just now. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        $walletBack($result['code'], $result['message']);
    }

    try {
        Notifications::announceWalletPayment($result, $orderId);
    } catch (Throwable $e) {
        error_log('payments.use_wallet announce failed: ' . $e->getMessage());
    }
    if ((string) okv_input('then', '') === 'card' && (int) $result['remaining_subunit'] > 0) {
        payments_start_charge((int) $result['payment_id']);
    }
    if (payments_is_fetch()) {
        okv_json([
            'status'            => 'ok',
            'code'              => $result['code'],
            'message'           => $result['message'],
            'amount_subunit'    => $result['amount_subunit'],
            'remaining_subunit' => $result['remaining_subunit'],
        ]);
    }
    okv_redirect('/public/order.php?order=' . $orderId . '&wallet=' . rawurlencode($result['code']), 303);
}

/**
 * Send a customer back to their order after a receipt, with what happened. A
 * guest comes back by the trail token they hold, a signed-in customer by their
 * account.
 */
function payments_receipt_back(int $orderId, string $token, string $flag, string $code = ''): void
{
    $target = $token !== '' && OrderTrail::isValidToken($token)
        ? '/public/order.php?token=' . rawurlencode($token)
        : '/public/order.php?order=' . $orderId;
    okv_redirect($target . '&payment=' . rawurlencode($flag) . ($code !== '' ? '&receipt_error=' . rawurlencode($code) : ''), 303);
}

/** Refuse a receipt: JSON for a fetch, back to the order with the reason otherwise. */
function payments_receipt_refused(int $orderId, string $token, string $code, int $status = 422): void
{
    if (payments_is_fetch()) {
        okv_error(TransferProofs::receiptProblemMessage($code), $status, 'receipt_' . $code);
    }
    payments_receipt_back($orderId, $token, 'receipt_refused', $code);
}

// -----------------------------------------------------------------------------
// A customer hands in a bank transfer receipt (PRD 9.3a). Nothing is credited:
// it waits for a member of staff to verify it against the bank.
// -----------------------------------------------------------------------------
if ($action === 'submit_transfer') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    $orderId = (int) okv_input('order_id', 0);
    $token   = trim((string) okv_input('token', ''));
    if (TransferProofs::postWasTooLarge()) {
        payments_receipt_refused($orderId, $token, 'too_large', 413);
    }
    // The same two credentials as starting a Paystack charge, and never a third:
    // the signed-in owner, or the trail token of a guest order.
    if ($token === '') {
        Customer::requireLoginApi();
    }
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }

    $userId = Customer::id() === null ? null : (int) Customer::id();
    if (!RateLimiter::hit('transfer_receipt:' . ($userId ?? 'guest:' . $orderId), 8, 3600)) {
        okv_error('Too many receipts sent. Wait a little and try again, or message us on WhatsApp.', 429, 'rate_limited');
    }
    if (!TransferProofs::customerMayActOn($orderId, $userId, $token)) {
        okv_error('That order could not be found.', 404, 'not_found');
    }
    if ($token !== '') {
        // Keep it for the trip back, which carries no token of its own.
        OrderTrail::remember($orderId, $token);
    }

    $next = TransferProofs::nextTransferPayment($orderId);
    if ($next === null) {
        okv_error('There is nothing to send a receipt for on this order, or a receipt is already with our team.', 422, 'nothing_to_submit');
    }

    $check = TransferProofs::validateReceiptUpload($_FILES['receipt'] ?? []);
    if (!$check['ok']) {
        payments_receipt_refused($orderId, $token, $check['code']);
    }

    $stored = '';
    try {
        $stored = TransferProofs::storeReceipt($_FILES['receipt']);
        $result = TransferProofs::submit((int) $next['id'], [
            'proof_url'      => $stored,
            'bank_reference' => (string) okv_input('bank_reference', ''),
            'payer_name'     => (string) okv_input('payer_name', ''),
        ]);
    } catch (Throwable $e) {
        error_log('payments.submit_transfer failed: ' . $e->getMessage());
        if ($stored !== '') {
            Uploads::removeStoredFile($stored, TransferProofs::SUBDIR);
        }
        okv_error('We could not keep that receipt. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        Uploads::removeStoredFile($stored, TransferProofs::SUBDIR);
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    Notifications::announceTransferSubmitted($result);

    if (payments_is_fetch()) {
        okv_json(['status' => 'ok', 'code' => 'submitted', 'message' => $result['message']]);
    }
    payments_receipt_back($orderId, $token, 'awaiting');
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
    Notifications::announceManualPayment($result, $staffId);
    Notifications::announceManualPaymentProof($result, $staffId);
    payments_staff_done($result, 'recorded');
}

if ($action === 'verify_transfer') {
    // Verifying credits the order, so it carries the same gate and the same
    // server side confirmation as recording money by hand.
    $staffId = payments_staff_guard('payments.record');
    if (!okv_input('confirmed', '')) {
        okv_error('Tick the confirmation before verifying a payment.', 422, 'not_confirmed');
    }
    try {
        $result = TransferProofs::verify(
            (int) okv_input('proof_id', 0),
            Money::toSubunit((string) okv_input('amount', '')),
            (string) okv_input('note', ''),
            $staffId
        );
    } catch (Throwable $e) {
        error_log('payments.verify_transfer failed: ' . $e->getMessage());
        okv_error('We could not verify that payment. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    Notifications::announceTransferVerified($result, $staffId);
    payments_staff_done($result, 'verified');
}

if ($action === 'decline_transfer') {
    $staffId = payments_staff_guard('payments.record');
    try {
        $result = TransferProofs::decline(
            (int) okv_input('proof_id', 0),
            (string) okv_input('reason', ''),
            $staffId
        );
    } catch (Throwable $e) {
        error_log('payments.decline_transfer failed: ' . $e->getMessage());
        okv_error('We could not decline that receipt. Please try again.', 500, 'failed');
    }
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    Notifications::announceTransferDeclined($result, $staffId);
    payments_staff_done($result, 'declined');
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
    // Paystack may answer with a terminal result immediately. Announce that
    // result now, after the refund row is committed, because a later webhook
    // can correctly report that the row was already final.
    Notifications::announceRefund($result);
    if (!$result['ok']) {
        okv_error($result['message'], $result['code'] === 'not_found' ? 404 : 422, $result['code']);
    }
    payments_staff_done($result, 'refunded');
}

okv_error('That action is not available.', 400, 'unknown_action');
