<?php
/**
 * includes/components/shop/mini_cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The mini-cart, shared by every storefront page through
 * okv_shop_header(). It slides in from the right on a laptop and up from the
 * bottom on a phone, and it carries the four things a shopper wants without
 * leaving the page they are on: how many lines are in the basket, what those
 * lines are, the subtotal, and the two ways out (the full basket, or checkout).
 *
 * It renders server-side on every page load, so it is correct before a single
 * byte of JavaScript runs. assets/js/basket.js then keeps it in step after an
 * add, an edit or a removal, from the payload the cart API returns.
 *
 * With JavaScript off the Basket control in the header is an ordinary link to
 * /cart.php, so nothing here is ever a dead end. (M4 decision Q5.)
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_mini_cart')) {
    function okv_mini_cart(?array $state = null): void
    {
        // The header already reads the basket once for its badge, so it passes
        // the state in rather than making this component ask again.
        $state = $state ?? Basket::state();
        ?>
        <div id="okv-mini-cart" class="okv-drawer-backdrop" hidden data-mini-cart>
          <aside class="okv-drawer" role="dialog" aria-modal="true" aria-labelledby="okv-mini-cart-title">
            <div class="flex items-center justify-between gap-4 border-b border-mist px-5 py-4">
              <h2 id="okv-mini-cart-title" class="font-display text-lg font-bold text-ink">Your basket</h2>
              <button type="button" class="okv-btn-text px-2" data-mini-cart-close>Close</button>
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-4" data-mini-cart-body>
              <?php okv_mini_cart_body($state); ?>
            </div>

            <div class="border-t border-mist px-5 py-4">
              <div class="flex items-baseline justify-between gap-4">
                <span class="text-sm font-medium text-ink-60">Subtotal</span>
                <span class="font-mono text-lg font-semibold text-forest" data-mini-cart-subtotal><?= okv_e($state['subtotal_display']) ?></span>
              </div>
              <p class="mt-1 text-xs text-ink-60">Delivery is arranged and settled with the rider on the day.</p>
              <div class="mt-4 grid gap-2">
                <a href="/checkout.php" class="okv-btn w-full">Checkout</a>
                <a href="/cart.php" class="okv-btn-outline w-full">View full basket</a>
              </div>
            </div>
          </aside>
        </div>
        <script defer src="<?= okv_e(okv_asset('/assets/js/basket.min.js')) ?>"></script>
        <?php
    }
}

if (!function_exists('okv_mini_cart_body')) {
    /** The lines inside the mini-cart, or the empty state. */
    function okv_mini_cart_body(array $state): void
    {
        if ($state['line_count'] === 0) {
            ?>
            <p class="font-display text-base font-bold text-ink">Nothing in here yet</p>
            <p class="mt-2 text-sm text-ink-60">Add what your kitchen needs this week and it will show up here.</p>
            <a href="/shop.php" class="okv-btn mt-4 w-full">Shop the produce</a>
            <a href="/combos.php" class="okv-btn-outline mt-2 w-full">See this week's combos</a>
            <?php
            return;
        }

        if ($state['has_repriced']) {
            ?>
            <p class="mb-3 rounded-md border border-gold bg-gold-tint px-3 py-2 text-xs text-gold-ink" role="status"><?= okv_e(Basket::REPRICED_NOTICE) ?></p>
            <?php
        }
        ?>
        <p class="mb-3 text-sm text-ink-60"><?= (int) $state['line_count'] ?> <?= $state['line_count'] === 1 ? 'line' : 'lines' ?> in your basket</p>
        <ul class="divide-y divide-mist">
          <?php foreach ($state['lines'] as $line): ?>
            <li class="flex gap-3 py-3">
              <div class="h-12 w-12 flex-none overflow-hidden rounded-md bg-forest-tint">
                <?php if ($line['image'] !== ''): ?>
                  <img src="<?= okv_e($line['image_url']) ?>" alt="<?= okv_e($line['name']) ?><?= $line['unit'] !== '' ? ', per ' . okv_e($line['unit']) : '' ?>" class="h-full w-full object-cover" loading="lazy">
                <?php endif; ?>
              </div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink"><a href="<?= okv_e($line['url']) ?>" class="hover:text-forest"><?= okv_e($line['name']) ?></a></p>
                <p class="mt-0.5 text-xs text-ink-60">
                  <?= okv_e($line['quantity_display']) ?><?= okv_e($line['unit']) ?> at <?= okv_e($line['unit_price_display']) ?><?= $line['unit'] !== '' ? ' per ' . okv_e($line['unit']) : '' ?>
                </p>
                <?php if ($line['price_changed']): ?>
                  <p class="mt-1 text-xs font-semibold text-gold-ink">Price changed since your first add</p>
                <?php endif; ?>
              </div>
              <p class="font-mono text-sm font-semibold text-ink"><?= okv_e($line['line_total_display']) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php
    }
}
