<?php
/**
 * cart.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The basket page. Full lines with photos, per-line quantity
 * editing and removal, and an order summary. Every control is a real form
 * posting to the cart API, so the basket is fully usable with JavaScript off.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$basket = Basket::state();
$notice = (string) okv_input('basket', '');
$notices = [
    'added'    => 'Added to your basket.',
    'repriced' => 'The latest amount was added at its new price. Your earlier amount keeps the price you were given.',
    'updated'  => 'Basket updated.',
    'removed'  => 'Item removed from your basket.',
    'quantity' => 'Use the minimum and quantity steps shown for this item.',
    'missing'  => 'We could not find that item. It may have left the catalogue.',
    'unavailable' => 'That item is no longer available.',
    'expired'  => 'Your session expired. Reload the page and try again.',
    'error'    => 'We could not update your basket. Please try again.',
];

$pageTitle = 'Your basket. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/cart.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Review the produce and ready baskets in your basket, adjust quantities and head to checkout.">
  <meta name="robots" content="noindex">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_title' => 'Your basket', 'og_description' => 'Review your basket and head to checkout.']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('basket'); ?>

<main class="okv-container py-8 md:py-12">
  <nav class="mb-6 text-sm text-ink-60" aria-label="Breadcrumb">
    <a href="/" class="hover:text-forest">Home</a> / <span aria-current="page">Basket</span>
  </nav>

  <div class="flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Your order</p>
      <h1 class="mt-2 font-display text-4xl font-extrabold text-ink">Your basket</h1>
    </div>
    <a href="/shop.php" class="okv-btn-outline px-4">Keep shopping</a>
  </div>

  <?php if (isset($notices[$notice])): ?>
    <p class="mt-6 rounded-md border border-foliage bg-foliage-tint px-4 py-3 text-sm text-forest" role="status">
      <?= okv_e($notices[$notice]) ?>
    </p>
  <?php endif; ?>

  <?php if ($basket['has_repriced']): ?>
    <p class="mt-4 rounded-md border-l-4 border border-gold bg-white px-4 py-3 text-sm text-ink" role="status">
      <?= okv_e($basket['repriced_notice']) ?>
    </p>
  <?php endif; ?>

  <?php if (!$basket['lines']): ?>
    <section class="mt-8 rounded-lg bg-white px-6 py-16 text-center shadow-okv-1">
      <h2 class="font-display text-2xl font-bold text-ink">Your basket is empty</h2>
      <p class="mx-auto mt-3 max-w-md text-ink-60">Pick the produce or ready baskets you need for the kitchen.</p>
      <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="/shop.php" class="okv-btn px-4">Shop produce</a>
        <a href="/combos.php" class="okv-btn-outline px-4">See combos</a>
      </div>
    </section>
  <?php else: ?>
    <div class="mt-8 grid gap-8 lg:grid-cols-12">
      <section class="space-y-4 lg:col-span-8" aria-label="Basket items">
        <?php foreach ($basket['lines'] as $line): $combo = $line['item_type'] === 'combo'; ?>
          <article class="rounded-lg bg-white p-4 shadow-okv-1 sm:flex sm:items-start sm:gap-5">
            <div class="hidden h-20 w-20 flex-none overflow-hidden rounded-md bg-forest-tint sm:block">
              <?php if ($line['image_url'] !== ''): ?>
                <img src="<?= okv_e($line['image_url']) ?>" alt="<?= okv_e($line['name']) ?>" width="80" height="80" class="h-full w-full object-cover" loading="lazy">
              <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gold-ink"><?= $combo ? 'Ready basket' : 'Produce' ?></p>
              <h2 class="mt-1 font-display text-lg font-bold text-ink">
                <a href="<?= okv_e($line['url']) ?>" class="hover:text-forest"><?= okv_e($line['name']) ?></a>
              </h2>
              <p class="mt-1 text-sm text-ink-60">
                <?= okv_e($line['quantity_display']) ?> <?= okv_e($line['unit']) ?> at <?= okv_e($line['unit_price_display']) ?><?= $combo ? '' : ' per ' . okv_e($line['unit']) ?>
              </p>
              <?php if ($line['price_changed']): ?>
                <p class="mt-1 text-xs font-semibold uppercase tracking-[0.08em] text-gold-ink">Price changed. This line keeps the price you were given.</p>
              <?php endif; ?>
              <?php if (!$line['is_orderable']): ?>
                <p class="mt-1 text-sm text-tomato"><?= okv_e($line['availability_note']) ?></p>
              <?php endif; ?>
            </div>
            <div class="mt-4 flex flex-wrap items-end gap-3 sm:mt-0 sm:flex-col sm:items-end">
              <form method="post" action="/api/v1/cart.php" class="flex items-end gap-2" data-basket-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $combo ? 'update_combo' : 'update_product' ?>">
                <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                <input type="hidden" name="return_to" value="/cart.php">
                <label class="text-sm font-semibold text-ink">Quantity
                  <input name="quantity" inputmode="decimal" value="<?= okv_e($line['quantity_display']) ?>" class="okv-input mt-1 w-24">
                </label>
                <button class="okv-btn-outline px-3">Update</button>
              </form>
              <form method="post" action="/api/v1/cart.php" data-basket-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $combo ? 'remove_combo' : 'remove_product' ?>">
                <input type="hidden" name="line_id" value="<?= (int) $line['id'] ?>">
                <input type="hidden" name="return_to" value="/cart.php">
                <button class="okv-btn-text px-2 text-tomato">Remove</button>
              </form>
              <p class="font-mono font-semibold text-forest"><?= okv_e($line['line_total_display']) ?></p>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <aside class="lg:col-span-4">
        <div class="sticky top-24 rounded-lg bg-white p-6 shadow-okv-2">
          <h2 class="font-display text-xl font-bold text-ink">Basket total</h2>
          <div class="mt-6 flex justify-between font-mono text-lg font-semibold text-forest">
            <span>Subtotal</span>
            <span><?= okv_e($basket['subtotal_display']) ?></span>
          </div>
          <p class="mt-3 text-sm text-ink-60">Delivery is arranged and settled separately after we confirm your area.</p>
          <a href="/checkout.php" class="okv-btn mt-6 w-full justify-center">Continue to checkout</a>
        </div>
      </aside>
    </div>
  <?php endif; ?>
</main>

<?php okv_shop_footer(); okv_support_widget(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>
