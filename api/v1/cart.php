<?php
/** Minimal basket write seam used by catalogue add controls. */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

function cart_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
}

function cart_return_to(): string
{
    return okv_safe_path((string) okv_input('return_to', '/shop.php'), '/shop.php');
}

function cart_redirect_with_notice(string $notice): void
{
    // Strip any basket notice already on the target so a repeated add does not
    // stack "?basket=added&basket=added" onto the URL.
    [$path, $query] = array_pad(explode('?', cart_return_to(), 2), 2, '');
    parse_str($query, $params);
    unset($params['basket']);
    $params['basket'] = $notice;
    okv_redirect($path . '?' . http_build_query($params), 303);
}

if (!in_array($action, ['add_product', 'add_combo'], true)) {
    okv_error('This action is not available.', 400, 'unknown_action');
}
if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    if (!cart_is_fetch()) {
        cart_redirect_with_notice('expired');
    }
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

$isCombo = $action === 'add_combo';
$itemId = (int) okv_input($isCombo ? 'combo_id' : 'product_id', 0);
if ($itemId < 1) {
    okv_error($isCombo ? 'Choose a combo to add.' : 'Choose a product to add.', 422, $isCombo ? 'missing_combo' : 'missing_product');
}

try {
    $result = $isCombo ? Basket::addCombo($itemId) : Basket::addProduct($itemId);
} catch (DomainException $e) {
    $unavailable = $e->getMessage() === 'unavailable';
    $message = $unavailable
        ? ($isCombo ? 'That combo is not on the shop right now.' : 'That item is not available yet. Check its restock status and try again later.')
        : ($isCombo ? 'That combo was not found.' : 'That product was not found.');
    if (!cart_is_fetch()) {
        cart_redirect_with_notice($unavailable ? 'unavailable' : 'missing');
    }
    okv_error($message, $unavailable ? 409 : 404, $e->getMessage());
} catch (Throwable $e) {
    error_log('cart.' . $action . ' failed: ' . $e->getMessage());
    if (!cart_is_fetch()) {
        cart_redirect_with_notice('error');
    }
    okv_error('We could not add that item. Please try again.', 500, 'add_failed');
}

if (!cart_is_fetch()) {
    cart_redirect_with_notice('added');
}
okv_json([
    'status' => 'ok',
    'message' => 'Added to your basket.',
    'basket_count' => $result['count'],
    'quantity' => $result['quantity_added'],
]);
