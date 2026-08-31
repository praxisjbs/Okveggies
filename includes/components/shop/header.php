<?php
/** Shared storefront navigation for desktop and mobile, with the mini-cart. */
require_once __DIR__ . '/mini_cart.php';

if (!function_exists('okv_shop_header')) {
    function okv_shop_header(string $active = ''): void
    {
        // One read of the basket serves the badge, the screen-reader label and
        // the mini-cart panel, so a page never asks for the same rows twice.
        $basket = Basket::state();
        $basketCount = (int) $basket['line_count'];
        $basketLabel = 'Basket, ' . $basketCount . ' ' . ($basketCount === 1 ? 'line' : 'lines') . ', ' . $basket['subtotal_display'];
        $accountLabel = Customer::isLoggedIn() && Customer::firstName() !== '' ? Customer::firstName() : 'Account';
        $links = [
            'home' => ['/', 'Home'],
            'shop' => ['/shop.php', 'Shop'],
            'combos' => ['/combos.php', 'Combos'],
            'kitchen-runs' => ['/kitchen-runs.php', 'Kitchen Runs'],
            'basket' => ['/cart.php', 'Basket'],
            'account' => ['/account.php', $accountLabel],
        ];
        ?>
        <header class="sticky top-0 z-30 border-b border-mist bg-white/95 backdrop-blur">
          <div class="okv-container flex h-16 items-center justify-between gap-4">
            <a href="/" class="font-display text-xl font-extrabold tracking-tight text-forest">OK Veggies</a>
            <nav class="hidden items-center gap-6 text-sm font-semibold text-ink md:flex" aria-label="Main navigation">
              <?php foreach ($links as $key => [$url, $label]): ?>
                <?php if ($key === 'home' || $key === 'kitchen-runs' || $key === 'basket' || $key === 'account'): continue; endif; ?>
                <a href="<?= okv_e($url) ?>" class="inline-flex min-h-[44px] items-center <?= $active === $key ? 'text-forest underline decoration-gold decoration-2 underline-offset-8' : 'hover:text-forest' ?>">
                  <?= okv_e($label) ?>
                </a>
              <?php endforeach; ?>
              <a href="/kitchen-runs.php" class="okv-btn-outline px-4">Kitchen Runs</a>
            </nav>
            <div class="flex items-center gap-2">
              <a href="/account.php" class="okv-btn-text hidden sm:inline-flex"><?= okv_e($accountLabel) ?></a>
              <!-- With JavaScript on, this opens the mini-cart. With it off, it is
                   an ordinary link to the full basket page. -->
              <a href="/cart.php" class="okv-btn px-4" aria-label="<?= okv_e($basketLabel) ?>"
                 data-mini-cart-open aria-controls="okv-mini-cart" aria-expanded="false">
                Basket <span class="okv-basket-count rounded-full bg-white px-2 py-0.5 text-xs text-forest" aria-hidden="true"><?= $basketCount ?></span>
              </a>
            </div>
          </div>
        </header>
        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-6 border-t border-mist bg-white px-1 pb-2 md:hidden" aria-label="Mobile navigation">
          <?php foreach ($links as $key => [$url, $label]): ?>
            <a href="<?= okv_e($url) ?>" class="flex min-h-[56px] flex-col items-center justify-center px-1 text-center text-xs font-semibold leading-tight <?= $active === $key ? 'text-forest' : 'text-ink-60' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?> <?= $key === 'basket' ? 'aria-label="' . okv_e($basketLabel) . '"' : '' ?>>
              <span><?= okv_e($label) ?></span>
              <?php if ($key === 'basket'): ?><span class="okv-basket-count text-xs" aria-hidden="true"><?= $basketCount ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <p class="sr-only" aria-live="polite" data-basket-live><?= okv_e($basketLabel) ?></p>
        <?php okv_mini_cart($basket); ?>
        <?php
    }
}
