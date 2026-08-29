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
    $returnTo = (string) okv_input('return_to', '/shop.php');
    if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//') || preg_match('/[\r\n]/', $returnTo)) {
        return '/shop.php';
    }
    return $returnTo;
}

function cart_redirect_with_notice(string $notice): void
{
    $returnTo = cart_return_to();
    okv_redirect($returnTo . (str_contains($returnTo, '?') ? '&' : '?') . 'basket=' . rawurlencode($notice), 303);
}

if ($action !== 'add_product') {
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

$productId = (int) okv_input('product_id', 0);
if ($productId < 1) {
    okv_error('Choose a product to add.', 422, 'missing_product');
}

try {
    $result = Basket::addProduct($productId);
} catch (DomainException $e) {
    $message = $e->getMessage() === 'unavailable'
        ? 'That item is not available yet. Check its restock status and try again later.'
        : 'That product was not found.';
    if (!cart_is_fetch()) {
        cart_redirect_with_notice('unavailable');
    }
    okv_error($message, $e->getMessage() === 'unavailable' ? 409 : 404, $e->getMessage());
} catch (Throwable $e) {
    error_log('cart.add_product failed: ' . $e->getMessage());
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
