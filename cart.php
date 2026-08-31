<?php
/**
 * cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket. Guest friendly, and it keeps working with
 * JavaScript switched off: every control here is a real form that posts to
 * api/v1/cart.php and comes back with a notice.
 *
 * A product that repriced between two adds shows as two lines, each at the
 * price the customer was given, with the newer one badged. That is the only
 * honest way to show it: one row carries one price. (M4 decision Q2.)
 *
 * See docs/PRD.md Section 9.1.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/basket_notice.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$basket = Basket::state();
$lines = $basket['lines'];
$returnTo = '/cart.php';
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');

// An empty basket is never a dead end: it offers this week's produce and
// combos right there, with working add controls. (M4 decision Q4.)
$suggestedProducts = [];
$suggestedCombos = [];
if ($basket['line_count'] === 0) {
    $suggestedProducts = array_slice(Catalogue::products(), 0, 4);
    $suggestedCombos = Catalogue::featuredCombos(3);
}

$noticeCode = (string) okv_input('basket', '');

$pageTitle = 'Your basket. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/cart.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Check what is in your basket, change quantities, and go to checkout.">
  <meta name="robots" content="noindex">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('basket'); ?>

<main>
  <div class="okv-container py-6 md:py-10">
    <nav class="mb-6 text-sm text-ink-60" aria-label="Breadcrumb">
      <a href="/" class="hover:text-forest">Home</a> <span aria-hidden="true">/</span>
      <a href="/shop.php" class="hover:text-forest">Shop</a> <span aria-hidden="true">/</span>
      <span aria-current="page">Basket</span>
    </nav>

    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">What you are ordering</p>
        <h1 class="mt-2 font-display text-4xl font-extrabold text-ink md:text-5xl">Your basket</h1>
      </div>
      <p class="text-sm text-ink-60" data-basket-line-count><?= (int) $basket['line_count'] ?> <?= $basket['line_count'] === 1 ? 'line' : 'lines' ?></p>
    </div>

    <?php okv_basket_notice($noticeCode); ?>

    <?php if ($basket['has_repriced'] && $noticeCode !== 'repriced'): ?>
      <p class="mt-6 rounded-md border border-gold bg-gold-tint px-4 py-3 text-sm text-gold-ink" role="status" data-repriced-notice><?= okv_e(Basket::REPRICED_NOTICE) ?></p>
    <?php endif; ?>

    <?php if ($basket['line_count'] === 0): ?>

      <section class="mt-8 rounded-lg bg-white px-6 py-12 text-center shadow-okv-1 md:py-16">
        <h2 class="font-display text-2xl font-bold text-ink">Your basket is empty</h2>
        <p class="mx-auto mt-3 max-w-md text-ink-60">Nothing in here yet. Start with this week's produce, or take a ready basket that is already priced together.</p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
          <a href="/shop.php" class="okv-btn">Shop the produce</a>
          <a href="/combos.php" class="okv-btn-outline">See this week's combos</a>
        </div>
      </section>

      <?php if ($suggestedProducts): ?>
        <section class="mt-10" aria-labelledby="basket-suggestions">
          <div class="flex items-end justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Fresh this week</p>
              <h2 id="basket-suggestions" class="mt-2 font-display text-2xl font-bold text-ink">Start with these</h2>
            </div>
            <a href="/shop.php" class="okv-btn-text hidden sm:inline-flex">See all produce</a>
          </div>
          <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($suggestedProducts as $product): ?>
              <?php okv_product_card($product, $sourceRegions, $returnTo); ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($suggestedCombos): ?>
        <section class="mt-10" aria-labelledby="basket-combos">
          <div class="flex items-end justify-between gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Cooked together, priced together</p>
              <h2 id="basket-combos" class="mt-2 font-display text-2xl font-bold text-ink">Ready baskets</h2>
            </div>
            <a href="/combos.php" class="okv-btn-text hidden sm:inline-flex">See all combos</a>
          </div>
          <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($suggestedCombos as $combo): ?>
              <?php okv_combo_card($combo, $returnTo); ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

    <?php else: ?>

      <div class="mt-8 grid gap-6 lg:grid-cols-12 lg:gap-8">
        <section class="lg:col-span-8" aria-label="Basket lines">
          <ul class="grid gap-4" data-basket-lines>
            <?php foreach ($lines as $line):
              $isCombo = $line['item_type'] === 'combo';
              $updateAction = $isCombo ? 'update_combo' : 'update_product';
              $removeAction = $isCombo ? 'remove_combo' : 'remove_product';
              $step = $isCombo ? 1.0 : (float) $line['quantity_increment'];
              $minimum = $isCombo ? 1.0 : (float) $line['minimum_quantity'];
              $inputId = 'line-quantity-' . (int) $line['id'];
            ?>
              <li class="rounded-lg bg-white p-4 shadow-okv-1" data-basket-line="<?= (int) $line['id'] ?>">
                <div class="flex gap-4">
                  <a href="<?= okv_e($line['url']) ?>" class="h-20 w-20 flex-none overflow-hidden rounded-md bg-forest-tint sm:h-24 sm:w-24">
                    <?php if ($line['image'] !== ''): ?>
                      <img src="<?= okv_e($line['image_url']) ?>" alt="<?= okv_e($line['name']) ?><?= $line['unit'] !== '' ? ', per ' . okv_e($line['unit']) : ', ready basket' ?>, sourced from <?= okv_e($sourceRegions) ?>" class="h-full w-full object-cover" loading="lazy">
                    <?php else: ?>
                      <span class="flex h-full items-center justify-center px-1 text-center text-xs text-ink-40">Photo coming soon</span>
                    <?php endif; ?>
                  </a>

                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                      <div class="min-w-0">
                        <h2 class="font-display text-lg font-bold leading-tight text-ink">
                          <a href="<?= okv_e($line['url']) ?>" class="hover:text-forest"><?= okv_e($line['name']) ?></a>
                        </h2>
                        <p class="mt-1 text-sm text-ink-60">
                          <?php if ($isCombo): ?>
                            Ready basket of <?= (int) $line['component_count'] ?> items at <?= okv_e($line['unit_price_display']) ?> each
                          <?php else: ?>
                            <?= okv_e($line['unit_price_display']) ?> per <?= okv_e($line['unit']) ?>
                          <?php endif; ?>
                        </p>
                      </div>
                      <p class="font-mono text-lg font-semibold text-forest" data-line-total><?= okv_e($line['line_total_display']) ?></p>
                    </div>

                    <?php if ($line['price_changed'] && $line['previous_price_subunit'] !== null): ?>
                      <p class="mt-2 inline-flex flex-wrap items-center gap-2 rounded-md bg-gold-tint px-2.5 py-1 text-xs font-semibold text-gold-ink">
                        <span>Price changed</span>
                        <span class="font-mono font-normal">Was <?= okv_e(Money::format((int) $line['previous_price_subunit'])) ?>, now <?= okv_e($line['unit_price_display']) ?><?= $line['unit'] !== '' ? ' per ' . okv_e($line['unit']) : '' ?></span>
                      </p>
                      <p class="mt-1 text-xs text-ink-60">The rest of this item stays on its own line at the price you were given.</p>
                    <?php endif; ?>

                    <?php if (!$line['is_orderable']): ?>
                      <p class="mt-2 rounded-md bg-tomato-tint px-2.5 py-1 text-xs font-semibold text-tomato"><?= okv_e($line['availability_note']) ?></p>
                    <?php endif; ?>

                    <div class="mt-4 flex flex-wrap items-end gap-3">
                      <form method="post" action="/api/v1/cart.php" class="flex flex-wrap items-end gap-2" data-line-form data-line-type="<?= okv_e($line['item_type']) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="<?= okv_e($updateAction) ?>">
                        <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                        <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
                        <div>
                          <label for="<?= okv_e($inputId) ?>" class="okv-label mb-1">
                            <?= $isCombo ? 'How many baskets' : 'How much (' . okv_e($line['unit']) . ')' ?>
                          </label>
                          <div class="flex items-stretch gap-1">
                            <button type="button" class="okv-btn-outline w-11 px-0" data-quantity-step="-1" aria-label="Reduce the quantity of <?= okv_e($line['name']) ?>">-</button>
                            <input id="<?= okv_e($inputId) ?>" name="quantity" type="number" inputmode="decimal"
                                   class="okv-input w-20 px-2 text-center font-mono sm:w-24 sm:px-4"
                                   value="<?= okv_e($line['quantity_display']) ?>"
                                   min="<?= okv_e(okv_quantity($minimum)) ?>" step="<?= okv_e(okv_quantity($step)) ?>"
                                   data-quantity-input>
                            <button type="button" class="okv-btn-outline w-11 px-0" data-quantity-step="1" aria-label="Increase the quantity of <?= okv_e($line['name']) ?>">+</button>
                          </div>
                        </div>
                        <button type="submit" class="okv-btn px-4" data-line-update>Update</button>
                      </form>

                      <form method="post" action="/api/v1/cart.php" data-line-remove-form>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="<?= okv_e($removeAction) ?>">
                        <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                        <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
                        <button type="submit" class="okv-btn-text px-2 text-tomato hover:text-tomato-hover" data-line-remove>Remove</button>
                      </form>
                    </div>

                    <?php if (!$isCombo): ?>
                      <p class="mt-2 text-xs text-ink-60">Minimum <?= okv_e(okv_quantity($minimum)) ?><?= okv_e($line['unit']) ?>, in steps of <?= okv_e(okv_quantity($step)) ?><?= okv_e($line['unit']) ?>.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>

          <div class="mt-6 flex flex-wrap gap-3">
            <a href="/shop.php" class="okv-btn-outline">Keep shopping</a>
            <a href="/combos.php" class="okv-btn-text">See this week's combos</a>
          </div>
        </section>

        <aside class="lg:col-span-4" aria-label="Order summary">
          <div class="rounded-lg bg-white p-5 shadow-okv-1 lg:sticky lg:top-24">
            <h2 class="font-display text-xl font-bold text-ink">Order summary</h2>
            <dl class="mt-4 space-y-2 text-sm">
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-ink-60">Lines</dt>
                <dd class="font-mono text-ink" data-summary-lines><?= (int) $basket['line_count'] ?></dd>
              </div>
              <div class="flex items-baseline justify-between gap-4">
                <dt class="text-ink-60">Subtotal</dt>
                <dd class="font-mono text-lg font-semibold text-forest" data-summary-subtotal><?= okv_e($basket['subtotal_display']) ?></dd>
              </div>
            </dl>
            <p class="mt-3 text-xs text-ink-60">Delivery is arranged and settled with the rider on the day, so it is not added here. We confirm the fee for your area before dispatch.</p>
            <a href="/checkout.php" class="okv-btn mt-5 w-full">Go to checkout</a>
            <?php if (!Customer::isLoggedIn()): ?>
              <p class="mt-3 text-center text-xs text-ink-60">
                Shopping as a guest. <a href="/account.php?mode=signin" class="text-forest underline decoration-gold decoration-2 underline-offset-4">Sign in</a> and this basket comes with you.
              </p>
            <?php endif; ?>
          </div>
        </aside>
      </div>

    <?php endif; ?>
  </div>
</main>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
