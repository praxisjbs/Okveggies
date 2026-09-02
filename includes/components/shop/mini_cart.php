<?php
/**
 * includes/components/shop/mini_cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The mini-cart drawer, rendered once by the shop header so it is
 * reachable from every storefront page. It is an empty shell: basket.js fills
 * it from /api/v1/cart.php?action=state when the shopper opens it, so a page
 * carries no extra basket query just to have the drawer available. A shopper
 * without JavaScript never opens it and reaches /cart.php through the real link
 * in the header instead.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_mini_cart')) {
    function okv_mini_cart(): void
    {
        ?>
        <div id="okv-mini-cart" class="fixed inset-0 z-50" hidden>
          <div class="absolute inset-0 bg-ink/40 transition-opacity" data-mini-cart-close></div>
          <section
            class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-okv-3"
            role="dialog" aria-modal="true" aria-labelledby="okv-mini-cart-title">
            <header class="flex items-center justify-between border-b border-mist px-5 py-4">
              <h2 id="okv-mini-cart-title" class="font-display text-xl font-bold text-ink">Your basket</h2>
              <button type="button" class="okv-btn-text px-2" data-mini-cart-close aria-label="Close basket">Close</button>
            </header>
            <div class="flex-1 overflow-auto px-5 py-4" data-mini-cart-body aria-live="polite">
              <p class="text-sm text-ink-60">Loading your basket.</p>
            </div>
            <footer class="border-t border-mist px-5 py-4">
              <div class="flex items-center justify-between font-semibold text-ink">
                <span>Subtotal</span>
                <span class="font-mono" data-mini-cart-subtotal></span>
              </div>
              <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="/cart.php" class="okv-btn-outline justify-center px-3">View basket</a>
                <a href="/checkout.php" class="okv-btn justify-center px-3">Checkout</a>
              </div>
            </footer>
          </section>
        </div>
        <?php
    }
}
