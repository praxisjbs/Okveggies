<?php
/**
 * api/v1/shortages.php
 * -----------------------------------------------------------------------------
 * OK Veggies. An order line that could not be sourced.
 *
 * Two audiences, one controller.
 *
 *   The customer answers how they want their money back (decide). They prove it
 *   is their shortage one of two ways, and never a third: the signed-in owner of
 *   the order, or the unguessable token in the emailed link (only its hash is
 *   stored). The token opens exactly one shortage and offers exactly two
 *   choices, so there is nothing else to reach with it.
 *
 *   Staff mark a line short, choose for a customer, or undo a mistake, each
 *   behind orders.shortage.record. Paying a refund is in payments.php, behind
 *   payments.refund, because that is money leaving the business.
 *
 * Every action is POST and checks CSRF. A fetch gets JSON; a plain form post
 * gets a 303 back to the screen, so it all works without JavaScript.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

$action = okv_action();

function shortages_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

// -----------------------------------------------------------------------------
// The customer's choice.
// -----------------------------------------------------------------------------
if ($action === 'decide') {
    $token  = trim((string) okv_input('t', ''));
    $userId = Customer::id() === null ? null : (int) Customer::id();

    $limitKey = $userId !== null ? 'shortage_decide:' . $userId : 'shortage_decide:ip:' . substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (!RateLimiter::hit($limitKey, 15, 300)) {
        okv_error('Too many attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }

    $shortage = null;
    $back     = '/public/shortage.php';
    if ($token !== '') {
        $shortage = Shortages::byToken($token);
        $back = '/public/shortage.php?t=' . rawurlencode($token);
    } elseif ($userId !== null) {
        $id = (int) okv_input('id', 0);
        $shortage = $id > 0 ? Shortages::forOwner($id, $userId) : null;
        $back = '/public/shortage.php?id=' . $id;
    }
    if ($shortage === null) {
        // Never says which it was: a wrong token and someone else's shortage look the same.
        okv_error('That link could not be found.', 404, 'not_found');
    }

    $choice = (string) okv_input('choice', '');
    $bank = [
        'bank_name'      => okv_input('bank_name', ''),
        'account_number' => okv_input('account_number', ''),
        'account_name'   => okv_input('account_name', ''),
    ];
    try {
        $result = Shortages::decide((int) $shortage['id'], $choice, $bank, 'customer', $userId);
    } catch (Throwable $e) {
        error_log('shortages.decide failed: ' . $e->getMessage());
        okv_error('We could not save your choice. Please try again.', 500, 'failed');
    }
    if (!empty($result['ok']) && ($result['code'] ?? '') === 'decided') {
        try {
            Notifications::announceShortageDecided((int) $shortage['id']);
        } catch (Throwable $e) {
            error_log('shortages.decide announce failed: ' . $e->getMessage());
        }
    }
    if (shortages_is_fetch()) {
        if (empty($result['ok'])) {
            okv_error((string) $result['message'], 422, (string) $result['code']);
        }
        okv_json(['status' => 'ok', 'code' => $result['code'], 'message' => $result['message'], 'resolution' => $result['resolution'] ?? null]);
    }
    okv_redirect($back . (str_contains($back, '?') ? '&' : '?') . (empty($result['ok'])
        ? 'error=' . rawurlencode((string) $result['code']) . '&choice=' . rawurlencode($choice)
        : 'done=' . rawurlencode((string) ($result['resolution'] ?? 'decided'))), 303);
}

// -----------------------------------------------------------------------------
// Staff.
// -----------------------------------------------------------------------------

/** Where a colleague lands after an action on an order. */
function shortages_staff_back(int $orderId, string $flag, bool $isError): string
{
    return '/admin/orders.php?order=' . $orderId . '&' . ($isError ? 'shortage_error=' : 'shortage=') . rawurlencode($flag) . '#shortages';
}

if ($action === 'record') {
    Rbac::requirePermission('orders.shortage.record');
    $staffId = (int) Rbac::userId();
    $orderId = (int) okv_input('order_id', 0);
    $itemId  = (int) okv_input('item_id', 0);
    $whole   = (string) okv_input('whole', '') !== '';
    $quantity = $whole ? 'all' : trim((string) okv_input('quantity', ''));
    if (!RateLimiter::hit('shortage_record:' . $staffId, 60, 300)) {
        okv_error('Too many attempts. Wait a few minutes and try again.', 429, 'rate_limited');
    }
    try {
        $result = Shortages::record($orderId, $itemId, $quantity, (string) okv_input('reason', ''), $staffId);
    } catch (Throwable $e) {
        error_log('shortages.record failed: ' . $e->getMessage());
        okv_error('We could not mark that item short. Please try again.', 500, 'failed');
    }
    if (!empty($result['ok'])) {
        try {
            Notifications::announceShortage((int) $result['shortage_id'], $result['token'], $staffId);
        } catch (Throwable $e) {
            error_log('shortages.record announce failed: ' . $e->getMessage());
        }
    }
    if (shortages_is_fetch()) {
        if (empty($result['ok'])) {
            okv_error((string) $result['message'], 422, (string) $result['code']);
        }
        unset($result['token']);
        okv_json(['status' => 'ok'] + $result);
    }
    okv_redirect(shortages_staff_back($orderId, (string) $result['code'], empty($result['ok'])), 303);
}

if ($action === 'decide_for_customer') {
    Rbac::requirePermission('orders.shortage.record');
    $staffId    = (int) Rbac::userId();
    $shortageId = (int) okv_input('shortage_id', 0);
    $row = Database::one('SELECT order_id FROM order_shortages WHERE id = :id', [':id' => $shortageId]);
    if ($row === null) {
        okv_error('That shortage could not be found.', 404, 'not_found');
    }
    $bank = [
        'bank_name'      => okv_input('bank_name', ''),
        'account_number' => okv_input('account_number', ''),
        'account_name'   => okv_input('account_name', ''),
    ];
    try {
        $result = Shortages::decide($shortageId, (string) okv_input('choice', ''), $bank, 'staff', $staffId);
    } catch (Throwable $e) {
        error_log('shortages.decide_for_customer failed: ' . $e->getMessage());
        okv_error('We could not save that choice. Please try again.', 500, 'failed');
    }
    if (!empty($result['ok']) && ($result['code'] ?? '') === 'decided') {
        try {
            Notifications::announceShortageDecided($shortageId, $staffId);
        } catch (Throwable $e) {
            error_log('shortages.decide_for_customer announce failed: ' . $e->getMessage());
        }
    }
    if (shortages_is_fetch()) {
        if (empty($result['ok'])) {
            okv_error((string) $result['message'], 422, (string) $result['code']);
        }
        okv_json(['status' => 'ok', 'code' => $result['code'], 'message' => $result['message']]);
    }
    okv_redirect(shortages_staff_back((int) $row['order_id'], empty($result['ok']) ? (string) $result['code'] : 'decided', empty($result['ok'])), 303);
}

if ($action === 'withdraw') {
    Rbac::requirePermission('orders.shortage.record');
    $staffId    = (int) Rbac::userId();
    $shortageId = (int) okv_input('shortage_id', 0);
    $row = Database::one('SELECT order_id FROM order_shortages WHERE id = :id', [':id' => $shortageId]);
    if ($row === null) {
        okv_error('That shortage could not be found.', 404, 'not_found');
    }
    try {
        $result = Shortages::withdraw($shortageId, $staffId);
    } catch (Throwable $e) {
        error_log('shortages.withdraw failed: ' . $e->getMessage());
        okv_error('We could not undo that. Please try again.', 500, 'failed');
    }
    if (shortages_is_fetch()) {
        if (empty($result['ok'])) {
            okv_error((string) $result['message'], 422, (string) $result['code']);
        }
        okv_json(['status' => 'ok', 'code' => $result['code'], 'message' => $result['message']]);
    }
    okv_redirect(shortages_staff_back((int) $row['order_id'], empty($result['ok']) ? (string) $result['code'] : 'withdrawn', empty($result['ok'])), 303);
}

okv_error('That action is not available.', 400, 'unknown_action');
