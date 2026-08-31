<?php
/** Basket API. Reads return JSON; every basket change is POST plus CSRF. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

function cart_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function cart_return_to(): string
{
    return okv_safe_path((string) okv_input('return_to', '/cart.php'), '/cart.php');
}

function cart_redirect_notice(string $notice): void
{
    [$path, $query] = array_pad(explode('?', cart_return_to(), 2), 2, '');
    parse_str($query, $params);
    unset($params['basket']);
    $params['basket'] = $notice;
    okv_redirect($path . '?' . http_build_query($params), 303);
}

function cart_fail(Throwable $e, string $context): void
{
    $code = $e instanceof DomainException ? $e->getMessage() : 'failed';
    $known = [
        'not_found' => ['We could not find that basket item.', 404, 'missing'],
        'unavailable' => ['That item is no longer available.', 409, 'unavailable'],
        'invalid_quantity' => ['Use the minimum and quantity steps shown for this item.', 422, 'quantity'],
    ];
    [$message, $status, $notice] = $known[$code] ?? ['We could not update your basket. Please try again.', 500, 'error'];
    if (!($e instanceof DomainException)) { error_log('cart.' . $context . ' failed: ' . $e->getMessage()); }
    if (!cart_is_fetch()) { cart_redirect_notice($notice); }
    okv_error($message, $status, $code);
}

if ($action === 'state') {
    okv_json(['status' => 'ok'] + Basket::state());
}

$allowed = ['add_product', 'add_combo', 'update_product', 'remove_product', 'update_combo', 'remove_combo'];
if (!in_array($action, $allowed, true)) { okv_error('This action is not available.', 400, 'unknown_action'); }
if (!okv_is_post()) { okv_error('Use POST for this action.', 405, 'method_not_allowed'); }
if (!Csrf::validate()) {
    if (!cart_is_fetch()) { cart_redirect_notice('expired'); }
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

try {
    switch ($action) {
        case 'add_product':
            $id = (int) okv_input('product_id', 0);
            if ($id < 1) { throw new DomainException('not_found'); }
            $result = Basket::addProduct($id);
            $notice = !empty($result['repriced']) ? 'repriced' : 'added';
            $message = !empty($result['repriced'])
                ? 'The latest amount was added at ' . Money::format((int) $result['new_price_subunit']) . '. Your earlier amount keeps its price.'
                : 'Added to your basket.';
            break;
        case 'add_combo':
            $id = (int) okv_input('combo_id', 0);
            if ($id < 1) { throw new DomainException('not_found'); }
            $result = Basket::addCombo($id);
            $notice = !empty($result['repriced']) ? 'repriced' : 'added';
            $message = !empty($result['repriced'])
                ? 'The latest basket was added at ' . Money::format((int) $result['new_price_subunit']) . '. Your earlier basket keeps its price.'
                : 'Added to your basket.';
            break;
        case 'update_product':
            Basket::updateProduct((int) okv_input('line_id', 0), (string) okv_input('quantity', ''));
            $notice = 'updated'; $message = 'Basket updated.'; break;
        case 'remove_product':
            Basket::removeProduct((int) okv_input('line_id', 0));
            $notice = 'removed'; $message = 'Item removed from your basket.'; break;
        case 'update_combo':
            Basket::updateCombo((int) okv_input('line_id', 0), (string) okv_input('quantity', ''));
            $notice = 'updated'; $message = 'Basket updated.'; break;
        default:
            Basket::removeCombo((int) okv_input('line_id', 0));
            $notice = 'removed'; $message = 'Item removed from your basket.'; break;
    }
} catch (Throwable $e) {
    cart_fail($e, $action);
}

if (!cart_is_fetch()) { cart_redirect_notice($notice); }
okv_json(['status' => 'ok', 'message' => $message, 'repriced' => $notice === 'repriced', 'basket_count' => Basket::count()] + Basket::state());
