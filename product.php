<?php
/** Product detail, availability, sourcing and related produce. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$product = Catalogue::productBySlug((string) okv_input('slug', ''));
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');

if (!$product) {
    http_response_code(404);
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product not found. OK Veggies</title><meta name="robots" content="noindex"><?php okv_head_meta(); ?><link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>"></head>
    <body class="min-h-screen bg-forest-tint">
    <?php okv_activation_banner(); okv_shop_header('shop'); ?>
    <main class="okv-container py-16 text-center md:py-24">
      <?php okv_seal(120, 'mx-auto', ''); ?>
      <p class="okv-eyebrow mt-6">Product not found</p>
      <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3">That item is not on the stall</h1>
      <p class="mx-auto mt-4 max-w-lg text-ink-60">It may have moved or is no longer in this week's catalogue. Browse the shop to see what is available and what is restocking.</p>
      <a href="/shop.php" class="okv-btn mt-8">Back to the shop</a>
    </main>
    <?php okv_shop_footer(); ?>
    <?php okv_support_widget(); ?>
    </body></html><?php
    exit;
}

$images = Catalogue::images((int) $product['id']);
$suggestions = Catalogue::suggestions((int) $product['id'], (int) $product['category_id']);
$availability = okv_availability((string) $product['availability_status'], $product['restock_date'] ?? null);
$returnTo = '/product.php?slug=' . rawurlencode($product['slug']);
$pageTitle = $product['name'] . ', per ' . $product['unit'] . '. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . $returnTo;
$ogImage = $images ? rtrim((string) APP_URL, '/') . okv_image_url($images[0]['image_url']) : '';
$basketNotice = (string) okv_input('basket', '');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="<?= okv_e($product['short_description']) ?>">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_type' => 'product', 'og_title' => $pageTitle, 'og_description' => (string) $product['short_description'], 'og_image' => $ogImage]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('shop'); ?>

<main>
  <div class="okv-container py-6 md:py-10">
    <nav class="mb-6 text-sm text-ink-60" aria-label="Breadcrumb">
      <a href="/" class="hover:text-forest">Home</a> <span aria-hidden="true">/</span>
      <a href="/shop.php" class="hover:text-forest">Shop</a> <span aria-hidden="true">/</span>
      <a href="/shop.php?category=<?= okv_e($product['category_slug']) ?>" class="hover:text-forest"><?= okv_e($product['category_name']) ?></a> <span aria-hidden="true">/</span>
      <span aria-current="page"><?= okv_e($product['name']) ?></span>
    </nav>

    <?php if ($basketNotice === 'added'): ?>
      <p class="mb-6 rounded-md border border-foliage bg-foliage-tint px-4 py-3 text-sm text-forest" role="status">Added to your basket.</p>
    <?php elseif ($basketNotice !== ''): ?>
      <p class="mb-6 rounded-md border border-tomato bg-tomato-tint px-4 py-3 text-sm text-tomato" role="status">We could not add that item. Please check its availability and try again.</p>
    <?php endif; ?>

    <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">
      <section class="lg:col-span-7" aria-label="Product photos">
        <?php if ($images): ?>
          <div class="grid gap-4 <?= count($images) > 1 ? 'sm:grid-cols-2' : '' ?>">
            <?php foreach ($images as $index => $image): ?>
              <div class="overflow-hidden rounded-lg bg-white p-4 shadow-okv-1 <?= $index === 0 && count($images) > 2 ? 'sm:col-span-2' : '' ?>">
                <img src="<?= okv_e(okv_image_url($image['image_url'])) ?>" alt="<?= okv_e($product['name']) ?>, <?= okv_e($product['unit']) ?>, sourced from <?= okv_e($sourceRegions) ?>" class="aspect-square w-full rounded-md object-cover">
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="flex aspect-square items-center justify-center rounded-lg bg-white text-ink-40 shadow-okv-1">Photo coming soon</div>
        <?php endif; ?>
      </section>

      <section class="lg:col-span-5">
        <div class="lg:sticky lg:top-24">
          <p class="okv-eyebrow"><a href="/shop.php?category=<?= okv_e($product['category_slug']) ?>" class="hover:text-forest"><?= okv_e($product['category_name']) ?></a></p>
          <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($product['name']) ?></h1>
          <div class="mt-4 flex flex-wrap items-center gap-3">
            <p class="font-mono text-okv-h6 font-semibold text-forest"><?= okv_e(Money::format((int) $product['current_price_subunit'])) ?></p>
            <span class="text-ink-60">per <?= okv_e($product['unit']) ?></span>
            <span class="okv-badge <?= $availability['key'] === 'available' ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= okv_e($availability['label']) ?></span>
          </div>

          <p class="mt-6 text-okv-lead text-ink-60"><?= nl2br(okv_e($product['description'])) ?></p>

          <div class="mt-6 rounded-lg border border-mist bg-white p-4">
            <?php okv_sourced_note($sourceRegions, $sourceDay, 'font-semibold text-ink'); ?>
            <p class="mt-2 text-sm text-ink-60">Sold per <?= okv_e($product['unit']) ?>. Minimum <?= okv_e(okv_quantity($product['minimum_quantity'])) ?><?= okv_e($product['unit']) ?>, added in steps of <?= okv_e(okv_quantity($product['quantity_increment'])) ?><?= okv_e($product['unit']) ?>.</p>
          </div>

          <form method="post" action="/api/v1/cart.php" class="mt-6" data-add-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="add_product">
            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
            <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">

            <?php if ($availability['can_add']): ?>
              <?php
                $minQty  = okv_quantity($product['minimum_quantity']);
                $stepQty = okv_quantity($product['quantity_increment']);
                $stepQty = (float) $stepQty > 0 ? $stepQty : $minQty;
              ?>
              <div class="mb-4">
                <label for="okv-qty" class="block text-sm font-semibold text-ink">
                  How much do you want?
                </label>
                <div class="mt-2 flex items-stretch gap-2">
                  <input id="okv-qty"
                         class="okv-input min-h-[44px] w-32 text-center font-mono text-lg"
                         type="number"
                         name="quantity"
                         value="<?= okv_e($minQty) ?>"
                         min="<?= okv_e($minQty) ?>"
                         step="<?= okv_e($stepQty) ?>"
                         inputmode="decimal"
                         data-qty-input
                         data-unit-price="<?= (int) $product['current_price_subunit'] ?>">
                  <span class="flex items-center text-sm text-ink-60"><?= okv_e($product['unit']) ?></span>
                </div>
                <p class="mt-2 text-sm text-ink-60" data-qty-total aria-live="polite">
                  Total <?= okv_e(Money::format((int) $product['current_price_subunit'])) ?>
                </p>
              </div>
            <?php endif; ?>

            <button type="submit" class="okv-btn w-full min-h-[44px]" <?= $availability['can_add'] ? '' : 'disabled' ?> data-add-button><?= $availability['can_add'] ? 'Add to basket' : $availability['short_label'] ?></button>
          </form>
          <?php if (!$availability['can_add']): ?><p class="mt-3 text-center text-sm text-ink-60">Keep this page handy. The status will change when sourcing is complete.</p><?php endif; ?>
        </div>
      </section>
    </div>
  </div>

  <?php if ($suggestions): ?>
    <section class="border-t border-mist bg-white">
      <div class="okv-container py-12 md:py-16">
        <div class="flex items-end justify-between gap-4">
          <div><p class="okv-eyebrow">From the same kitchen</p><h2 class="mt-3 font-editorial text-okv-h5 text-ink md:text-okv-h4">Goes well with</h2></div>
          <a href="/shop.php?category=<?= okv_e($product['category_slug']) ?>" class="okv-btn-text hidden sm:inline-flex">See <?= okv_e($product['category_name']) ?></a>
        </div>
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          <?php foreach ($suggestions as $suggestion): ?><?php okv_product_card($suggestion, $sourceRegions, $returnTo, $sourceDay); ?><?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
