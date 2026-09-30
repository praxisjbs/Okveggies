<?php
/**
 * Basket removal controls are available on every customer-facing basket
 * surface, including the no-JavaScript route and a reversible Undo response.
 */

$root = dirname(__DIR__, 2);
$readBasketRemovalFile = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$cartPage = $readBasketRemovalFile('cart.php');
$checkoutPage = $readBasketRemovalFile('checkout.php');
$miniCart = $readBasketRemovalFile('includes/components/shop/mini_cart.php');
$basketJs = $readBasketRemovalFile('assets/js/basket.js');
$cartApi = $readBasketRemovalFile('api/v1/cart.php');
$basketClass = $readBasketRemovalFile('includes/classes/Basket.php');

okv_test_ok(str_contains($cartPage, "value=\"<?= \$combo ? 'remove_combo' : 'remove_product' ?>\""), 'the full basket posts an explicit remove action per line');
okv_test_ok(str_contains($checkoutPage, "value=\"<?= \$combo ? 'remove_combo' : 'remove_product' ?>\""), 'checkout exposes a remove action in its basket review and summary');
okv_test_ok(str_contains($checkoutPage, "if (\$step > 1)"), 'checkout keeps its summary removal control beside the later checkout steps');
okv_test_ok(str_contains($miniCart, 'data-mini-cart-checkout aria-disabled="true"'), 'the mini-cart checkout control starts disabled until basket state loads');
okv_test_ok(str_contains($basketJs, "'Remove'"), 'the mini-cart drawer renders a visible Remove control for every line');
okv_test_ok(str_contains($basketJs, "'undo_remove'") && str_contains($basketJs, 'state.undo_token'), 'the enhanced basket offers Undo after a removal');
okv_test_ok(str_contains($cartApi, "'undo_remove'") && str_contains($cartApi, "'undo_token'"), 'the cart API accepts an Undo post and returns a one-time token');
okv_test_ok(str_contains($basketClass, 'restoreRemovedLine') && str_contains($basketClass, 'unit_price_subunit'), 'Undo restores a trusted server-side price snapshot');
okv_test_ok(str_contains($cartPage, "'Continue shopping'") && str_contains($checkoutPage, 'Continue shopping'), 'the empty basket and checkout states lead customers back to shopping');
