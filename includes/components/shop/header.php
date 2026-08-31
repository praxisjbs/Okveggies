<?php
/** Shared storefront navigation for desktop and mobile. */
if (!function_exists('okv_shop_header')) {
    function okv_shop_header(string $active = ''): void
    {
        $basketCount = Basket::count();
        $miniBasket = Basket::state();
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
              <a href="/cart.php" class="okv-btn px-4 md:hidden" aria-label="Basket, <?= $basketCount ?> items">
                Basket <span class="okv-basket-count rounded-full bg-white px-2 py-0.5 text-xs text-forest" aria-live="polite"><?= $basketCount ?></span>
              </a>
              <details class="relative hidden md:block">
                <summary class="okv-btn list-none px-4" aria-label="Basket, <?= $basketCount ?> items">Basket <span class="okv-basket-count rounded-full bg-white px-2 py-0.5 text-xs text-forest" aria-live="polite"><?= $basketCount ?></span></summary>
                <section class="absolute right-0 top-full z-50 mt-2 w-96 rounded-lg bg-white p-4 shadow-okv-3" aria-label="Basket summary">
                  <h2 class="font-display font-bold text-ink">Basket</h2>
                  <?php if (!$miniBasket['lines']): ?><p class="mt-3 text-sm text-ink-60">Your basket is empty.</p>
                  <?php else: ?><ul class="mt-3 max-h-72 space-y-3 overflow-auto"><?php foreach ($miniBasket['lines'] as $line): ?><li class="flex justify-between gap-4 text-sm"><span><?= okv_e($line['name']) ?>, <?= okv_e(okv_quantity($line['quantity'])) ?> <?= okv_e($line['unit']) ?></span><span class="font-mono"><?= okv_e(Money::format($line['line_total_subunit'])) ?></span></li><?php endforeach; ?></ul><div class="mt-4 flex justify-between border-t border-mist pt-4 font-semibold text-ink"><span>Subtotal</span><span class="font-mono"><?= okv_e(Money::format($miniBasket['subtotal_subunit'])) ?></span></div><?php endif; ?>
                  <div class="mt-4 grid grid-cols-2 gap-2"><a href="/cart.php" class="okv-btn-outline justify-center px-3">View basket</a><a href="/checkout.php" class="okv-btn justify-center px-3">Checkout</a></div>
                </section>
              </details>
            </div>
          </div>
        </header>
        <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-6 border-t border-mist bg-white px-1 pb-2 md:hidden" aria-label="Mobile navigation">
          <?php foreach ($links as $key => [$url, $label]): ?>
            <a href="<?= okv_e($url) ?>" class="flex min-h-[56px] flex-col items-center justify-center px-1 text-center text-xs font-semibold leading-tight <?= $active === $key ? 'text-forest' : 'text-ink-60' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?> <?= $key === 'basket' ? 'aria-label="Basket, ' . $basketCount . ' items"' : '' ?>>
              <span><?= okv_e($label) ?></span>
              <?php if ($key === 'basket'): ?><span class="okv-basket-count text-xs" aria-live="polite"><?= $basketCount ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </nav>
        <?php
    }
}
