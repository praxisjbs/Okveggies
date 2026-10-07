<?php
/** Shared storefront navigation for desktop and mobile. */
require_once __DIR__ . '/mini_cart.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/brand.php';

if (!function_exists('okv_shop_header')) {
    function okv_shop_header(string $active = ''): void
    {
        $basketCount = Basket::count();
        $mobileActive = $active === 'combos' ? 'shop' : $active;
        $accountLabel = Customer::isLoggedIn() && Customer::firstName() !== '' ? Customer::firstName() : 'Account';
        $links = [
            'home' => ['/', 'Home'],
            'shop' => ['/shop.php', 'Shop'],
            'combos' => ['/combos.php', 'Combos'],
            'kitchen-runs' => ['/kitchen-runs.php', 'Kitchen Runs'],
            'basket' => ['/cart.php', 'Basket'],
            'account' => ['/account.php', $accountLabel],
        ];
        // The motion dead man switch prints before any content parses, so the
        // blocks okv-motion.js will animate never flash. See head_meta.php.
        okv_motion_pending();
        ?>
        <a href="#okv-main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-md focus:bg-white focus:px-4 focus:py-3 focus:text-forest">Skip to content</a>
        <header class="sticky top-0 z-30 border-b border-mist bg-white/95 backdrop-blur">
          <div class="okv-container flex h-16 items-center justify-between gap-4">
            <a href="/" class="inline-flex min-h-[44px] items-center rounded-md" aria-label="OK Veggies, home">
              <!-- The Owner's one-logo decision of 7 October 2026: the seal is
                   the only logo, in the tight header as everywhere else. -->
              <?php okv_seal(48, 'h-11 w-11 sm:h-12 sm:w-12', 'OK Veggies, Fresh Picks'); ?>
            </a>
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
              <?php require __DIR__ . '/notification_bell.php'; ?>
              <a href="/account.php" class="okv-btn-text hidden sm:inline-flex"><?= okv_e($accountLabel) ?></a>
              <a href="/cart.php" class="okv-btn px-4" aria-label="Basket, <?= $basketCount ?> items" data-basket-open>
                Basket <span class="okv-basket-count rounded-full bg-white px-2 py-0.5 text-xs text-forest" aria-live="polite"><?= $basketCount ?></span>
              </a>
            </div>
          </div>
        </header>
        <nav class="okv-mobile-nav" aria-label="Mobile navigation">
          <a href="/"
             class="okv-mobile-tab<?= $mobileActive === 'home' ? ' okv-mobile-tab--active' : '' ?>"
             <?= $mobileActive === 'home' ? 'aria-current="page"' : '' ?>>
            <span class="okv-mobile-tab__icon"><?php okv_icon('home', 'okv-mobile-icon'); ?></span>
            <span class="okv-mobile-tab__label">Home</span>
          </a>
          <details class="okv-mobile-shop">
            <summary class="okv-mobile-tab<?= $mobileActive === 'shop' ? ' okv-mobile-tab--active' : '' ?>"
                     <?= $mobileActive === 'shop' ? 'aria-current="page"' : '' ?>>
              <span class="okv-mobile-tab__icon"><?php okv_icon('leaf', 'okv-mobile-icon'); ?></span>
              <span class="okv-mobile-tab__label">
                Shop <span class="okv-mobile-shop__chevron"><?php okv_icon('chevron-down', 'okv-mobile-chevron-icon'); ?></span>
              </span>
            </summary>
            <div class="okv-mobile-shop__menu">
              <p class="okv-mobile-shop__eyebrow">Shop by</p>
              <div class="okv-mobile-shop__options" role="group" aria-label="Shop categories">
                <a href="/shop.php"
                   class="okv-mobile-shop__option<?= $active === 'shop' ? ' okv-mobile-shop__option--active' : '' ?>"
                   <?= $active === 'shop' ? 'aria-current="page"' : '' ?>>
                  <span class="okv-mobile-shop__option-icon"><?php okv_icon('leaf', 'okv-mobile-menu-icon'); ?></span>
                  <span class="okv-mobile-shop__option-copy">
                    <span class="okv-mobile-shop__option-title">Individual items</span>
                    <span class="okv-mobile-shop__option-note">Fresh produce, by item</span>
                  </span>
                </a>
                <a href="/combos.php"
                   class="okv-mobile-shop__option<?= $active === 'combos' ? ' okv-mobile-shop__option--active' : '' ?>"
                   <?= $active === 'combos' ? 'aria-current="page"' : '' ?>>
                  <span class="okv-mobile-shop__option-icon"><?php okv_icon('plate', 'okv-mobile-menu-icon'); ?></span>
                  <span class="okv-mobile-shop__option-copy">
                    <span class="okv-mobile-shop__option-title">Combos</span>
                    <span class="okv-mobile-shop__option-note">Ready-made kitchen baskets</span>
                  </span>
                </a>
              </div>
            </div>
          </details>
          <a href="/kitchen-runs.php"
             class="okv-mobile-tab<?= $mobileActive === 'kitchen-runs' ? ' okv-mobile-tab--active' : '' ?>"
             <?= $mobileActive === 'kitchen-runs' ? 'aria-current="page"' : '' ?>>
            <span class="okv-mobile-tab__icon"><?php okv_icon('list', 'okv-mobile-icon'); ?></span>
            <span class="okv-mobile-tab__label">Kitchen Runs</span>
          </a>
          <a href="/account.php"
             class="okv-mobile-tab<?= $mobileActive === 'account' ? ' okv-mobile-tab--active' : '' ?>"
             <?= $mobileActive === 'account' ? 'aria-current="page"' : '' ?>>
            <span class="okv-mobile-tab__icon"><?php okv_icon('user', 'okv-mobile-icon'); ?></span>
            <span class="okv-mobile-tab__label">Account</span>
          </a>
        </nav>
        <?php
        // The notification panel is rendered here, outside the header, because
        // the header's backdrop-blur would otherwise become the containing
        // block for the panel's fixed phone sheet and trap it in the 64px bar.
        // The mini-cart drawer follows it, so it still paints on top.
        ?>
        <?php okv_customer_bell_panel(); ?>
        <?php okv_mini_cart(); ?>
        <?php
    }
}
