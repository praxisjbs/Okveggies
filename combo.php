<?php
/**
 * combo.php
 * One combo, its contents and the one-tap Add. Filters on active plus inside
 * the availability window through Catalogue::comboBySlug(); a combo that is
 * off the shop returns null and the branded 404 renders instead.
 *
 * The basket name is set in DM Serif Display (bible 5.1) and the contents are
 * listed as real produce lines with a link to every component, which is the
 * combo half of the two-way deep link in PRD 4.4.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';

$combo = Catalogue::comboBySlug((string) okv_input('slug', ''));
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');

if (!$combo) {
    http_response_code(404);
    ?><!doctype html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Combo not found. OK Veggies</title><meta name="robots" content="noindex"><?php okv_head_meta(); ?><link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>"></head>
    <body class="min-h-screen bg-forest-tint">
    <?php okv_activation_banner(); okv_shop_header('combos'); ?>
    <main class="okv-container py-16 text-center md:py-24">
      <?php okv_seal(120, 'mx-auto', ''); ?>
      <p class="okv-eyebrow mt-6">Combo not found</p>
      <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3">That basket is not on the shop</h1>
      <p class="mx-auto mt-4 max-w-lg text-ink-60">It may have moved or is no longer on this week's list. Browse the combos to see what is ready now.</p>
      <a href="/combos.php" class="okv-btn mt-8">See this week's combos</a>
    </main>
    <?php okv_shop_footer(); ?>
    <?php okv_support_widget(); ?>
    </body></html><?php
    exit;
}

$components = Catalogue::comboComponents((int) $combo['id']);
$componentTotal = Combos::sumComponents($components);
$price = (int) $combo['price_subunit'];
$saving = Combos::customerSaving($price, $componentTotal);
$image = okv_combo_card_image($combo, $components);
$returnTo = '/combo.php?slug=' . rawurlencode($combo['slug']);
$pageTitle = $combo['name'] . '. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . $returnTo;
$ogImage = $image !== '' ? rtrim((string) APP_URL, '/') . okv_image_url($image) : '';
$basketNotice = (string) okv_input('basket', '');
$description = trim((string) ($combo['description'] ?? ''));
$componentCount = (int) $combo['component_count'];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="<?= okv_e($description !== '' ? $description : $combo['name'] . ', a ready basket from OK Veggies.') ?>">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_type' => 'product', 'og_title' => $pageTitle, 'og_description' => ($description !== '' ? $description : (string) $combo['name']), 'og_image' => $ogImage]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('combos'); ?>

<main>
  <div class="okv-container py-6 md:py-10">
    <nav class="mb-6 text-sm text-ink-60" aria-label="Breadcrumb">
      <a href="/" class="hover:text-forest">Home</a> <span aria-hidden="true">/</span>
      <a href="/combos.php" class="hover:text-forest">Combos</a> <span aria-hidden="true">/</span>
      <span aria-current="page"><?= okv_e($combo['name']) ?></span>
    </nav>

    <?php if ($basketNotice === 'added'): ?>
      <p class="mb-6 rounded-md border border-foliage bg-foliage-tint px-4 py-3 text-sm text-forest" role="status">Added to your basket.</p>
    <?php elseif ($basketNotice === 'unavailable'): ?>
      <p class="mb-6 rounded-md border border-tomato bg-tomato-tint px-4 py-3 text-sm text-tomato" role="status">That combo is no longer on the shop.</p>
    <?php elseif ($basketNotice !== ''): ?>
      <p class="mb-6 rounded-md border border-tomato bg-tomato-tint px-4 py-3 text-sm text-tomato" role="status">We could not add that basket. Please try again.</p>
    <?php endif; ?>

    <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">
      <section class="lg:col-span-7" aria-label="Combo photo">
        <div class="overflow-hidden rounded-lg bg-white p-4 shadow-okv-1">
          <?php if ($image !== ''): ?>
            <img src="<?= okv_e(okv_image_url($image)) ?>" alt="<?= okv_e($combo['name']) ?>, ready basket of <?= $componentCount ?> items, sourced from <?= okv_e($sourceRegions) ?>" class="aspect-square w-full rounded-md object-cover">
          <?php else: ?>
            <div class="flex aspect-square items-center justify-center text-ink-40">Photo coming soon</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="lg:col-span-5">
        <div class="lg:sticky lg:top-24">
          <p class="okv-eyebrow">Ready basket</p>
          <h1 class="mt-2 font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($combo['name']) ?></h1>

          <?php if ($saving > 0): ?>
            <div class="mt-4 flex flex-wrap items-baseline gap-3">
              <p class="font-mono text-okv-h6 font-semibold text-forest"><?= okv_e(Money::format($price)) ?></p>
              <p class="font-mono text-okv-lead text-ink-40 line-through" aria-label="Component total"><?= okv_e(Money::format($componentTotal)) ?></p>
            </div>
            <p class="okv-badge okv-badge-available mt-2">You save <?= okv_e(Money::format($saving)) ?> against buying the pieces separately</p>
          <?php else: ?>
            <p class="mt-4 font-mono text-okv-h6 font-semibold text-forest"><?= okv_e(Money::format($price)) ?></p>
          <?php endif; ?>

          <?php if ($description !== ''): ?>
            <p class="mt-6 text-okv-lead text-ink-60"><?= nl2br(okv_e($description)) ?></p>
          <?php endif; ?>

          <div class="mt-6 rounded-lg border border-mist bg-white p-4">
            <?php okv_sourced_note($sourceRegions, $sourceDay, 'font-semibold text-ink'); ?>
            <p class="mt-2 text-sm text-ink-60"><?= $componentCount ?> <?= $componentCount === 1 ? 'item arrives together' : 'items arrive together' ?>. One tap adds the whole basket to your order.</p>
          </div>

          <form method="post" action="/api/v1/cart.php" class="mt-6" data-add-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="add_combo">
            <input type="hidden" name="combo_id" value="<?= (int) $combo['id'] ?>">
            <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
            <button type="submit" class="okv-btn w-full" data-add-button>Add full basket</button>
          </form>
        </div>
      </section>
    </div>

    <section class="mt-12" aria-labelledby="combo-contents">
      <div class="rounded-lg bg-white p-6 shadow-okv-1 md:p-8">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
          <h2 id="combo-contents" class="font-editorial text-okv-h5 text-ink">What is inside this basket</h2>
          <p class="text-sm text-ink-60"><?= count($components) ?> <?= count($components) === 1 ? 'item' : 'items' ?></p>
        </div>
        <?php if ($components): ?>
          <ul class="mt-6 divide-y divide-mist">
            <?php foreach ($components as $line):
              $quantity = okv_quantity($line['quantity']);
              $unit = (string) $line['unit'];
              $productSlug = (string) $line['product_slug'];
              $productName = (string) $line['product_name'];
            ?>
              <li class="flex items-center gap-4 py-3">
                <div class="h-14 w-14 flex-none overflow-hidden rounded-md bg-forest-tint">
                  <?php if (!empty($line['image'])): ?>
                    <img src="<?= okv_e(okv_image_url((string) $line['image'])) ?>" alt="<?= okv_e($productName) ?>, per <?= okv_e($unit) ?>, sourced from <?= okv_e($sourceRegions) ?>" class="h-full w-full object-cover" loading="lazy">
                  <?php endif; ?>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-semibold text-ink"><a href="/product.php?slug=<?= okv_e($productSlug) ?>" class="transition-colors duration-botanical ease-botanical hover:text-forest"><?= okv_e($productName) ?></a></p>
                  <p class="font-mono text-sm text-ink-60"><?= okv_e($quantity) ?><?= okv_e($unit) ?></p>
                </div>
                <a href="/product.php?slug=<?= okv_e($productSlug) ?>" class="okv-btn-text flex-none px-2 text-sm" aria-label="See <?= okv_e($productName) ?> on its own">See item</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="mt-6 text-ink-60">This basket is being set. The items will show here once the builder is done.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
