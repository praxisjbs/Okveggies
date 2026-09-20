<?php
/** OK Veggies storefront home. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';
require_once __DIR__ . '/includes/components/shop/icons.php';
require_once __DIR__ . '/includes/components/shop/empty_state.php';
require_once __DIR__ . '/includes/components/shop/help_sheet.php';

if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    Rbac::redirectToLanding();
}

$fallback = [
    'hero_eyebrow' => 'Est. 2026. Lagos',
    'hero_heading' => 'We are bringing the other half home.',
    'hero_intro' => 'Freshness You Can Trust. From Farm to Your Kitchen.',
    'primary_cta_label' => 'Start shopping',
    'primary_cta_path' => '/shop.php',
    'secondary_cta_label' => 'See the combos',
    'secondary_cta_path' => '/combos.php',
    'promise_heading' => 'Sourced right. Priced right. Delivered right.',
    'promise_body' => 'Farms we have visited. Prices we can explain. A delivery day you picked.',
    'combos_eyebrow' => 'Cooked together, priced together',
    'combos_heading' => "This week's combos",
    'categories_eyebrow' => 'Five aisles, one stall',
    'categories_heading' => 'Shop by category',
    'products_eyebrow' => 'Picked this week',
    'products_heading' => "This week's picks",
];

$home = null;
try {
    $home = ContentPages::findPublished('home');
    if ($home === null) {
        error_log('content.home published snapshot is unavailable; reviewed defaults are in use');
    }
} catch (Throwable $e) {
    error_log('content.home read failed: ' . $e->getMessage());
}
$copy = $fallback;
if ($home !== null) {
    foreach ((array) ($home['content_data'] ?? []) as $key => $value) {
        if (array_key_exists($key, $copy) && trim((string) $value) !== '') {
            $copy[$key] = (string) $value;
        }
    }
}

$categories = [];
$categoriesError = false;
try {
    $categories = Catalogue::categories();
} catch (Throwable $e) {
    error_log('home.categories failed: ' . $e->getMessage());
    $categoriesError = true;
}
$featuredCombos = [];
$combosError = false;
try {
    $featuredCombos = Catalogue::featuredCombos(3);
} catch (Throwable $e) {
    error_log('home.featured_combos failed: ' . $e->getMessage());
    $combosError = true;
}
$featured = [];
$productsError = false;
try {
    $featured = Catalogue::featuredProducts(8);
} catch (Throwable $e) {
    error_log('home.featured_products failed: ' . $e->getMessage());
    $productsError = true;
}

$tagline = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');
$returnTo = '/';
$promise = ContentRenderer::render($copy['promise_body']);

$visibleTitle = trim((string) ($home['title'] ?? 'Fresh from farms we can name'));
$seoTitle = trim((string) ($home['meta_title'] ?? '')) ?: $visibleTitle;
$description = trim((string) ($home['meta_description'] ?? ''));
if ($description === '') {
    $description = ContentRenderer::plainText($copy['hero_intro'], 155);
}
$documentTitle = $seoTitle . '. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/';
$heroPath = trim((string) ($home['image_url'] ?? ''));
$heroAlt = trim((string) ($home['image_alt'] ?? ''));
$heroImage = $heroPath !== '' && $heroAlt !== '' ? ContentImages::presentation($heroPath) : null;
$ogImage = $heroImage !== null ? rtrim((string) APP_URL, '/') . okv_image_url($heroPath) : '';

$basketNotice = (string) okv_input('basket', '');
$noticeMessages = [
    'added' => 'Added to your basket.',
    'unavailable' => 'That item is not available yet. Its restock status is shown on the card.',
    'expired' => 'Your session expired. Please try adding the item again.',
    'missing' => 'We could not find that item. It may have left the catalogue.',
    'error' => 'We could not add that item. Please try again.',
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($documentTitle) ?></title>
  <meta name="description" content="<?= okv_e($description) ?>">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php if ($heroImage !== null): ?><link rel="preload" as="image" href="<?= okv_e(okv_image_url($heroPath)) ?>"<?= $heroImage['srcset'] !== '' ? ' imagesrcset="' . okv_e($heroImage['srcset']) . '" imagesizes="(min-width: 768px) 50vw, 100vw"' : '' ?>><?php endif; ?>
  <?php okv_head_meta(['og_title' => $documentTitle, 'og_description' => $description, 'og_image' => $ogImage]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('home'); ?>

<main id="okv-main">
<section class="bg-forest text-white" aria-labelledby="home-heading">
  <div class="okv-container grid items-center gap-10 py-10 md:grid-cols-2 md:py-16">
    <!--
      PR8 motion hooks: the seal stamps in, the heading splits by word, the
      sub and CTA follow, the gold hairline draws, the photograph parallaxes.
      okv-motion.js reads these attributes; with JavaScript off every element
      here is a plain static hero and nothing is hidden.
    -->
    <div class="animate-okv-rise" data-okv-hero>
      <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        <span class="inline-flex flex-none" data-okv-hero-seal><?php okv_seal(120, 'flex-none', 'The OK Veggies seal'); ?></span>
        <div>
          <p class="okv-eyebrow-invert"><?= okv_e($copy['hero_eyebrow']) ?></p>
        </div>
      </div>
      <h1 id="home-heading" class="mt-8 font-editorial text-okv-h4 md:text-okv-h2"><?= okv_e($copy['hero_heading']) ?></h1>
      <p class="mt-5 max-w-xl text-okv-lead text-white/85" data-okv-hero-sub><?= okv_e($copy['hero_intro']) ?></p>
      <div class="mt-8 flex flex-wrap gap-3" data-okv-hero-cta>
        <a href="<?= okv_e($copy['primary_cta_path']) ?>" class="okv-btn h-14 rounded-xl border border-white bg-white px-6 text-forest shadow-lg shadow-forest/20 hover:bg-forest-tint"><?php okv_icon('arrow-right', 'h-4 w-4'); ?> <?= okv_e($copy['primary_cta_label']) ?></a>
        <a href="<?= okv_e($copy['secondary_cta_path']) ?>" class="okv-btn-outline-invert rounded-xl"><?php okv_icon('basket', 'h-4 w-4'); ?> <?= okv_e($copy['secondary_cta_label']) ?></a>
      </div>
      <!--
        The gold rule above the tagline is its own element so PR8 can draw it
        in with a scaleX, which is a transform and never shifts layout. A
        border on the paragraph cannot animate without dragging the words.
      -->
      <div class="mt-8 w-full border-t-2 border-gold" data-okv-hero-hairline aria-hidden="true"></div>
      <p class="mt-4 text-sm font-semibold uppercase tracking-wider text-white"><?= okv_e($tagline) ?></p>
    </div>

    <?php if ($heroImage !== null): ?>
      <figure class="overflow-hidden rounded-xl bg-white/10 shadow-okv-2">
        <img src="<?= okv_e(okv_image_url($heroPath)) ?>"<?= $heroImage['srcset'] !== '' ? ' srcset="' . okv_e($heroImage['srcset']) . '" sizes="(min-width: 768px) 50vw, 100vw"' : '' ?>
             alt="<?= okv_e($heroAlt) ?>" width="<?= $heroImage['width'] > 0 ? (int) $heroImage['width'] : 1280 ?>" height="<?= $heroImage['height'] > 0 ? (int) $heroImage['height'] : 960 ?>"
             class="aspect-[4/3] h-full w-full object-cover" data-okv-parallax fetchpriority="high" decoding="async">
      </figure>
    <?php else: ?>
      <aside class="flex aspect-[4/3] flex-col items-center justify-center rounded-xl border border-white/25 bg-white/10 p-6 text-center md:p-10" aria-label="Documentary photograph pending">
        <?php okv_seal(120, 'mx-auto', ''); ?>
        <p class="mt-6 text-sm font-semibold uppercase tracking-wider text-white">Documentary photograph pending</p>
        <p class="mt-3 text-sm leading-6 text-white/75">We will not replace it with stock photography.</p>
      </aside>
    <?php endif; ?>
  </div>
</section>

<?php if ($categories && !$categoriesError): ?>
  <nav class="border-b border-mist bg-white" aria-label="Shop by category">
    <div class="okv-container flex gap-2 overflow-x-auto py-3">
      <?php foreach ($categories as $pill): $count = (int) $pill['product_count']; ?>
        <a href="/shop.php?category=<?= okv_e($pill['slug']) ?>" class="okv-filter-chip inline-flex items-center gap-2">
          <?php okv_icon('leaf', 'h-4 w-4 text-forest'); ?>
          <span><?= okv_e($pill['name']) ?></span>
          <?php if ($count === 0): ?><span class="text-ink-40">Being sourced</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
<?php endif; ?>

<?php if (isset($noticeMessages[$basketNotice])): ?>
  <div class="okv-container pt-6"><p class="rounded-md border <?= $basketNotice === 'added' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?> px-4 py-3 text-sm" role="status"><?= okv_e($noticeMessages[$basketNotice]) ?></p></div>
<?php endif; ?>

<section class="bg-forest-tint" aria-labelledby="promise-heading">
  <div class="okv-container py-14">
    <p class="okv-eyebrow">The OK Veggies promise</p>
    <h2 id="promise-heading" class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4"><?= okv_e($copy['promise_heading']) ?></h2>
    <ol class="mt-8 grid gap-4 sm:grid-cols-3">
      <li class="okv-step-card okv-enter bg-foliage-tint/60">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white text-forest"><?php okv_icon('leaf', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Sourced right</h3>
        <p class="mt-2 text-sm text-ink-60">Farms we have visited.</p>
      </li>
      <li class="okv-step-card okv-enter okv-enter-2 bg-gold-tint2">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white text-forest"><?php okv_icon('sparkle', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Priced right</h3>
        <p class="mt-2 text-sm text-ink-60">Prices we can explain.</p>
      </li>
      <li class="okv-step-card okv-enter okv-enter-3 bg-clay-tint">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white text-forest"><?php okv_icon('trail', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Delivered right</h3>
        <p class="mt-2 text-sm text-ink-60">A delivery day you picked.</p>
      </li>
    </ol>
    <p class="mt-5">
      <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="promise" aria-haspopup="dialog"><?php okv_icon('info', 'h-4 w-4'); ?> Learn</button>
    </p>
    <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-4 text-sm text-ink-60'); ?>
    <nav class="mt-8 grid gap-3 sm:grid-cols-3" aria-label="Start an order">
      <a class="okv-card min-h-[72px] text-ink hover:text-forest" href="/shop.php"><strong class="flex items-center gap-2"><?php okv_icon('leaf', 'h-4 w-4 text-forest'); ?> Shop produce</strong><span class="mt-1 block text-sm text-ink-60">Pick this week's items.</span></a>
      <a class="okv-card min-h-[72px] text-ink hover:text-forest" href="/combos.php"><strong class="flex items-center gap-2"><?php okv_icon('basket', 'h-4 w-4 text-forest'); ?> Choose a Combo</strong><span class="mt-1 block text-sm text-ink-60">A ready basket for the pot.</span></a>
      <a class="okv-card min-h-[72px] text-ink hover:text-forest" href="/kitchen-runs.php"><strong class="flex items-center gap-2"><?php okv_icon('list', 'h-4 w-4 text-forest'); ?> Send a Kitchen Run</strong><span class="mt-1 block text-sm text-ink-60">Send your list. We source it.</span></a>
    </nav>
  </div>
</section>
<?php okv_help_sheet('promise', 'leaf', 'The OK Veggies promise', $promise['html']); ?>

<section class="okv-container pt-14" aria-labelledby="combos-heading">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="okv-eyebrow"><?= okv_e($copy['combos_eyebrow']) ?></p><h2 id="combos-heading" class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4"><?= okv_e($copy['combos_heading']) ?></h2></div><a href="/combos.php" class="okv-btn-text">See all combos <span aria-hidden="true">&rarr;</span></a></div>
  <?php if ($combosError): ?>
    <?php okv_empty_state('cloud', 'Combos are paused', 'Browse the full list or send us your own kitchen list.', [
        ['href' => '/combos.php', 'label' => 'Browse combos', 'icon' => 'basket'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?>
  <?php elseif (!$featuredCombos): ?>
    <?php okv_empty_state('basket-empty', 'No featured combo is on the stall today', 'Browse every available combo, or send the list your kitchen needs.', [
        ['href' => '/combos.php', 'label' => 'Browse combos', 'icon' => 'basket'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?>
  <?php else: ?>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"><?php foreach ($featuredCombos as $combo): ?><?php okv_combo_card($combo, $returnTo, $sourceRegions, $sourceDay); ?><?php endforeach; ?></div>
  <?php endif; ?>
</section>

<section class="okv-container py-14" aria-labelledby="categories-heading">
  <p class="okv-eyebrow"><?= okv_e($copy['categories_eyebrow']) ?></p>
  <h2 id="categories-heading" class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4"><?= okv_e($copy['categories_heading']) ?></h2>
  <?php if ($categoriesError): ?>
    <div class="mt-6"><?php okv_empty_state('cloud', 'Categories are paused', 'The full shop is still open while we reconnect the list.', [
        ['href' => '/shop.php', 'label' => 'Browse all produce', 'icon' => 'leaf'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?></div>
  <?php elseif (!$categories): ?>
    <div class="mt-6"><?php okv_empty_state('leaf', 'The category list is being prepared', 'Browse the shop or send a Kitchen Run while the aisles are organised.', [
        ['href' => '/shop.php', 'label' => 'Browse the shop', 'icon' => 'leaf'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?></div>
  <?php else: ?>
    <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-5"><?php foreach ($categories as $category): $count = (int) $category['product_count']; ?><a href="/shop.php?category=<?= okv_e($category['slug']) ?>" class="okv-card group text-ink hover:text-forest"><span class="block font-semibold leading-tight"><?= okv_e($category['name']) ?></span><span class="mt-1 flex items-center gap-2 text-sm text-ink-60"><?php if ($count === 0): ?><span>Being sourced</span><?php else: ?><span class="font-mono"><?= $count ?></span> <?= $count === 1 ? 'item' : 'items' ?><?php endif; ?><span class="ml-auto transition-transform duration-botanical ease-botanical group-hover:translate-x-1" aria-hidden="true">&rarr;</span></span></a><?php endforeach; ?></div>
  <?php endif; ?>
</section>

<section class="okv-container pb-16" aria-labelledby="products-heading">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="okv-eyebrow"><?= okv_e($copy['products_eyebrow']) ?></p><h2 id="products-heading" class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4"><?= okv_e($copy['products_heading']) ?></h2></div><a href="/shop.php" class="okv-btn-text">See all produce <span aria-hidden="true">&rarr;</span></a></div>
  <?php if ($productsError): ?>
    <?php okv_empty_state('cloud', "This week's picks are temporarily unavailable", 'The full shop remains available while we reconnect this selection.', [
        ['href' => '/shop.php', 'label' => 'Browse all produce', 'icon' => 'leaf'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?>
  <?php elseif (!$featured): ?>
    <?php okv_empty_state('leaf', 'No produce has been marked as a weekly pick', 'Browse the whole shop or send the list your kitchen needs.', [
        ['href' => '/shop.php', 'label' => 'Browse all produce', 'icon' => 'leaf'],
        ['href' => '/kitchen-runs.php', 'label' => 'Send a Kitchen Run', 'style' => 'outline', 'icon' => 'list'],
    ], ['heading_tag' => 'h3', 'class' => 'border border-mist bg-forest-tint shadow-none']); ?>
  <?php else: ?>
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4"><?php foreach ($featured as $product): ?><?php okv_product_card($product, $sourceRegions, $returnTo, $sourceDay); ?><?php endforeach; ?></div>
  <?php endif; ?>
</section>
</main>

<?php okv_shop_footer(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
