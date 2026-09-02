<?php
/**
 * api/v1/cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket controller. A read (state) returns JSON. Every change
 * is POST plus a valid CSRF token. A fetch request gets the whole basket state
 * back so the page never has to guess what changed; a plain form post gets a
 * 303 back to where it came from with a notice code, so the basket works with
 * JavaScript switched off.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** True when the caller wants JSON (a fetch), not a redirect (a plain form). */
function cart_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** The page to return a no-JavaScript post to, sanitised against open redirects. */
function cart_return_to(): string
{
    return okv_safe_path((string) okv_input('return_to', '/cart.php'), '/cart.php');
}

/** Send a plain form post back where it came from with a single basket notice. */
function cart_redirect_notice(string $notice): void
{
    [$path, $query] = array_pad(explode('?', cart_return_to(), 2), 2, '');
    parse_str($query, $params);
    unset($params['basket']);
    $params['basket'] = $notice;
    okv_redirect($path . '?' . http_build_query($params), 303);
}

/** Map a failure to a status, a notice code and a client error code. */
function cart_fail(Throwable $e, string $context): void
{
    $reason = $e instanceof DomainException ? $e->getMessage() : 'failed';
    $known = [
        'not_found'        => ['We could not find that basket item.', 404, 'missing', 'not_found'],
        'unavailable'      => ['That item is no longer available.', 409, 'unavailable', 'unavailable'],
        'invalid_quantity' => ['Use the minimum and quantity steps shown for this item.', 422, 'quantity', 'invalid_quantity'],
    ];
    [$message, $status, $notice, $clientCode] = $known[$reason]
        ?? ['We could not update your basket. Please try again.', 500, 'error', 'failed'];

    if (!($e instanceof DomainException)) {
        error_log('cart.' . $context . ' failed: ' . $e->getMessage());
    }
    if (!cart_is_fetch()) {
        cart_redirect_notice($notice);
    }
    okv_error($message, $status, $clientCode);
}

if ($action === 'state') {
    okv_json(['status' => 'ok'] + Basket::state());
}

$allowed = ['add_product', 'add_combo', 'update_product', 'remove_product', 'update_combo', 'remove_combo'];
if (!in_array($action, $allowed, true)) {
    okv_error('This action is not available.', 400, 'unknown_action');
}
if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    if (!cart_is_fetch()) {
        cart_redirect_notice('expired');
    }
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

$notice = 'updated';
$message = 'Basket updated.';
try {
    switch ($action) {
        case 'add_product':
            $id = (int) okv_input('product_id', 0);
            if ($id < 1) {
                throw new DomainException('not_found');
            }
            $result = Basket::addProduct($id);
            $notice  = !empty($result['repriced']) ? 'repriced' : 'added';
            $message = !empty($result['repriced'])
                ? 'The latest amount was added at ' . Money::format((int) $result['new_price_subunit']) . '. Your earlier amount keeps its price.'
                : 'Added to your basket.';
            break;
        case 'add_combo':
            $id = (int) okv_input('combo_id', 0);
            if ($id < 1) {
                throw new DomainException('not_found');
            }
            $result = Basket::addCombo($id);
            $notice  = !empty($result['repriced']) ? 'repriced' : 'added';
            $message = !empty($result['repriced'])
                ? 'The latest basket was added at ' . Money::format((int) $result['new_price_subunit']) . '. Your earlier basket keeps its price.'
                : 'Added to your basket.';
            break;
        case 'update_product':
            Basket::updateProduct((int) okv_input('line_id', 0), (string) okv_input('quantity', ''));
            $notice = 'updated';
            $message = 'Basket updated.';
            break;
        case 'update_combo':
            Basket::updateCombo((int) okv_input('line_id', 0), (string) okv_input('quantity', ''));
            $notice = 'updated';
            $message = 'Basket updated.';
            break;
        case 'remove_product':
            Basket::removeProduct((int) okv_input('line_id', 0));
            $notice = 'removed';
            $message = 'Item removed from your basket.';
            break;
        default: // remove_combo
            Basket::removeCombo((int) okv_input('line_id', 0));
            $notice = 'removed';
            $message = 'Basket removed from your order.';
            break;
    }
} catch (Throwable $e) {
    cart_fail($e, $action);
}

if (!cart_is_fetch()) {
    cart_redirect_notice($notice);
}
okv_json([
    'status'       => 'ok',
    'message'      => $message,
    'repriced'     => $notice === 'repriced',
    'basket_count' => Basket::count(),
] + Basket::state());
