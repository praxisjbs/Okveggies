<?php
/**
 * api/v1/cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket controller. One action per state change, plus one
 * read for the mini-cart.
 *
 *   state           read the basket: lines, subtotal, line count
 *   add_product     add one step of a product
 *   add_combo       add one combo
 *   update_product  set the quantity on a product line
 *   remove_product  take a product line out
 *   update_combo    set the quantity on a combo line
 *   remove_combo    take a combo line out
 *
 * Every write is POST plus a valid CSRF token. Every refusal is a sentence we
 * wrote; a database or PHP message never reaches the browser. A request from
 * fetch gets JSON, a plain form post gets a 303 back to the page it came from
 * with a short notice code, so the basket works with JavaScript switched off.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

$readActions  = ['state'];
$writeActions = ['add_product', 'add_combo', 'update_product', 'remove_product', 'update_combo', 'remove_combo'];

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
    // Strip any basket notice already on the target so a repeated action does
    // not stack "?basket=added&basket=added" onto the URL.
    [$path, $query] = array_pad(explode('?', cart_return_to(), 2), 2, '');
    parse_str($query, $params);
    unset($params['basket']);
    $params['basket'] = $notice;
    okv_redirect($path . '?' . http_build_query($params), 303);
}

/** The notice code a plain form post carries back to the page. */
function cart_notice_for(string $reason): string
{
    switch ($reason) {
        case 'unavailable':      return 'unavailable';
        case 'not_found':        return 'missing';
        case 'below_minimum':    return 'minimum';
        case 'over_ceiling':     return 'ceiling';
        case 'invalid_quantity': return 'invalid';
        default:                 return 'error';
    }
}

/** The HTTP status a refusal answers with. */
function cart_status_for(string $reason): int
{
    switch ($reason) {
        case 'unavailable':      return 409;
        case 'not_found':        return 404;
        case 'below_minimum':
        case 'over_ceiling':
        case 'invalid_quantity': return 422;
        default:                 return 400;
    }
}

/** End the request with a refusal, in whichever shape the caller can read. */
function cart_fail(string $reason, string $message): void
{
    if (!cart_is_fetch()) {
        cart_redirect_with_notice(cart_notice_for($reason));
    }
    okv_error($message, cart_status_for($reason), $reason);
}

/** End the request happily, with the whole basket so the page can re-render. */
function cart_ok(string $message, string $notice, array $extra = []): void
{
    if (!cart_is_fetch()) {
        cart_redirect_with_notice($notice);
    }
    okv_json([
        'status'  => 'ok',
        'message' => $message,
        'basket'  => Basket::state(),
    ] + $extra);
}

/** The line id a write acts on. */
function cart_line_id(): int
{
    $lineId = (int) okv_input('line_id', 0);
    if ($lineId < 1) {
        cart_fail('not_found', 'That item is no longer in your basket.');
    }
    return $lineId;
}

if (!in_array($action, $readActions, true) && !in_array($action, $writeActions, true)) {
    okv_error('This action is not available.', 400, 'unknown_action');
}

// The read. No CSRF, because it changes nothing.
if ($action === 'state') {
    try {
        $state = Basket::state();
    } catch (Throwable $e) {
        error_log('cart.state failed: ' . $e->getMessage());
        okv_error('We could not read your basket. Please reload the page.', 500, 'state_failed');
    }
    okv_json(['status' => 'ok', 'basket' => $state]);
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

try {
    switch ($action) {

        case 'add_product': {
            $productId = (int) okv_input('product_id', 0);
            if ($productId < 1) {
                cart_fail('not_found', 'Choose a product to add.');
            }
            $result = Basket::addProduct($productId);
            cart_ok(
                $result['notice'] !== '' ? $result['notice'] : 'Added to your basket.',
                $result['repriced'] ? 'repriced' : 'added',
                [
                    'basket_count' => $result['count'],
                    'quantity'     => $result['quantity_added'],
                    'repriced'     => $result['repriced'],
                ]
            );
            break;
        }

        case 'add_combo': {
            $comboId = (int) okv_input('combo_id', 0);
            if ($comboId < 1) {
                cart_fail('not_found', 'Choose a combo to add.');
            }
            $result = Basket::addCombo($comboId);
            cart_ok(
                $result['notice'] !== '' ? $result['notice'] : 'Added to your basket.',
                $result['repriced'] ? 'repriced' : 'added',
                [
                    'basket_count' => $result['count'],
                    'quantity'     => $result['quantity_added'],
                    'repriced'     => $result['repriced'],
                ]
            );
            break;
        }

        case 'update_product': {
            $lineId   = cart_line_id();
            $quantity = okv_input('quantity', '');
            // An empty box or a zero is a removal, not an error. It is what a
            // customer means when they clear the field and press Update.
            if (is_string($quantity) && trim($quantity) === '0') {
                $removed = Basket::removeLine($lineId, 'product');
                cart_ok($removed['message'], 'removed', ['line_id' => $removed['line_id'], 'removed' => true]);
            }
            $result = Basket::updateProductLine($lineId, $quantity);
            cart_ok($result['message'], $result['adjusted'] ? 'rounded' : 'updated', [
                'line_id'  => $result['line_id'],
                'quantity' => $result['quantity'],
                'adjusted' => $result['adjusted'],
            ]);
            break;
        }

        case 'update_combo': {
            $lineId   = cart_line_id();
            $quantity = okv_input('quantity', '');
            if (is_string($quantity) && trim($quantity) === '0') {
                $removed = Basket::removeLine($lineId, 'combo');
                cart_ok($removed['message'], 'removed', ['line_id' => $removed['line_id'], 'removed' => true]);
            }
            $result = Basket::updateComboLine($lineId, $quantity);
            cart_ok($result['message'], 'updated', [
                'line_id'  => $result['line_id'],
                'quantity' => $result['quantity'],
                'adjusted' => $result['adjusted'],
            ]);
            break;
        }

        case 'remove_product': {
            $result = Basket::removeLine(cart_line_id(), 'product');
            cart_ok($result['message'], 'removed', ['line_id' => $result['line_id'], 'removed' => true]);
            break;
        }

        case 'remove_combo': {
            $result = Basket::removeLine(cart_line_id(), 'combo');
            cart_ok($result['message'], 'removed', ['line_id' => $result['line_id'], 'removed' => true]);
            break;
        }
    }
} catch (BasketError $e) {
    cart_fail($e->reason(), $e->getMessage());
} catch (Throwable $e) {
    error_log('cart.' . $action . ' failed: ' . $e->getMessage());
    if (!cart_is_fetch()) {
        cart_redirect_with_notice('error');
    }
    okv_error('We could not update your basket. Please try again.', 500, 'basket_failed');
}
