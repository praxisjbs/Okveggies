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

// The approved hero wording is fixed for this composition. Published CMS copy
// remains stored unchanged; the eyebrow, CTAs and other sections still use it.
$heroHeading = 'Bringing the Best of the Farm Straight to Your Kitchen.';
$heroIntro = 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.';

$fallback = [
    'hero_eyebrow' => 'Est. 2026. Lagos',
    'hero_heading' => $heroHeading,
    'hero_intro' => $heroIntro,
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
$heroSizes = '100vw';
$defaultHeroPath = '/assets/img/hero/fresh-produce-1280.webp';
$defaultHeroAlt = 'Crates of fresh tomatoes, red and yellow peppers, and onions.';
$defaultHeroSrcset = implode(', ', [
    okv_image_url('/assets/img/hero/fresh-produce-640.webp') . ' 640w',
    okv_image_url('/assets/img/hero/fresh-produce-960.webp') . ' 960w',
    okv_image_url($defaultHeroPath) . ' 1280w',
]);
$defaultHeroImage = [
    'src' => $defaultHeroPath,
    'srcset' => $defaultHeroSrcset,
    'width' => 1280,
    'height' => 721,
];
$defaultHeroReady = true;
foreach (['fresh-produce-640.webp', 'fresh-produce-960.webp', 'fresh-produce-1280.webp'] as $heroFile) {
    if (!is_file(__DIR__ . '/assets/img/hero/' . $heroFile)) {
        $defaultHeroReady = false;
        break;
    }
}

$heroPath = '';
$heroAlt = '';
$heroImage = null;
$publishedHeroPath = trim((string) ($home['image_url'] ?? ''));
$publishedHeroAlt = trim((string) ($home['image_alt'] ?? ''));
if ($publishedHeroPath !== '' && $publishedHeroAlt !== '') {
    $publishedHero = ContentImages::presentation($publishedHeroPath);
    if ($publishedHero['srcset'] !== '' && $publishedHero['width'] > 0 && $publishedHero['height'] > 0) {
        $heroPath = $publishedHeroPath;
        $heroAlt = $publishedHeroAlt;
        $heroImage = $publishedHero;
    }
}
if ($heroImage === null && $defaultHeroReady) {
    $heroPath = $defaultHeroImage['src'];
    $heroAlt = $defaultHeroAlt;
    $heroImage = $defaultHeroImage;
}
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
  <?php if ($heroImage !== null): ?><link rel="preload" as="image" href="<?= okv_e(okv_image_url($heroPath)) ?>" imagesrcset="<?= okv_e($heroImage['srcset']) ?>" imagesizes="<?= okv_e($heroSizes) ?>"><?php endif; ?>
  <?php okv_head_meta(['og_title' => $documentTitle, 'og_description' => $description, 'og_image' => $ogImage]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('home'); ?>

<main id="okv-main">
<section class="okv-home-hero bg-forest text-white" aria-labelledby="home-heading" data-okv-hero>
  <?php if ($heroImage !== null): ?>
    <figure class="okv-home-hero-media">
      <img src="<?= okv_e(okv_image_url($heroPath)) ?>" srcset="<?= okv_e($heroImage['srcset']) ?>" sizes="<?= okv_e($heroSizes) ?>"
           alt="<?= okv_e($heroAlt) ?>" width="<?= (int) $heroImage['width'] ?>" height="<?= (int) $heroImage['height'] ?>"
           class="okv-home-hero-image w-full object-cover" data-okv-parallax fetchpriority="high" decoding="async">
    </figure>
  <?php endif; ?>
  <div class="okv-home-hero-wash" aria-hidden="true"></div>

  <!-- The copy and seal sit on the full-hero wash, never inside a panel. -->
  <div class="okv-container relative z-10 w-full py-10 pb-24 md:py-16">
    <div class="max-w-2xl">
      <div class="flex items-center gap-6">
        <span class="inline-flex flex-none" data-okv-hero-seal><?php okv_seal(120, 'flex-none', 'The OK Veggies seal'); ?></span>
        <p class="okv-eyebrow-invert min-w-0 text-white"><?= okv_e($copy['hero_eyebrow']) ?></p>
      </div>
      <h1 id="home-heading" class="mt-6 font-editorial text-okv-h4 tracking-tight md:mt-8 md:text-okv-h2"><?= okv_e($heroHeading) ?></h1>
      <p class="mt-4 max-w-xl text-okv-lead text-white md:mt-5" data-okv-hero-sub><?= okv_e($heroIntro) ?></p>
      <div class="mt-6 flex flex-wrap gap-3 md:mt-8" data-okv-hero-cta>
        <a href="<?= okv_e($copy['primary_cta_path']) ?>" class="okv-btn min-h-14 max-w-full rounded-xl border border-tomato bg-tomato px-6 py-3 text-white shadow-lg shadow-forest/20 hover:bg-tomato-hover active:bg-tomato-active"><?php okv_icon('arrow-right', 'h-4 w-4'); ?> <?= okv_e($copy['primary_cta_label']) ?></a>
        <a href="<?= okv_e($copy['secondary_cta_path']) ?>" class="okv-btn-outline-invert min-h-14 max-w-full rounded-xl py-3"><?php okv_icon('basket', 'h-4 w-4'); ?> <?= okv_e($copy['secondary_cta_label']) ?></a>
      </div>
      <!-- A separate rule draws with scaleX without moving the tagline. -->
      <div class="mt-6 w-full border-t-2 border-gold md:mt-8" data-okv-hero-hairline aria-hidden="true"></div>
      <p class="mt-4 text-sm font-semibold uppercase tracking-wider text-white"><?= okv_e($tagline) ?></p>
      <?php if ($heroImage === null): ?>
        <p class="mt-6 text-sm font-semibold uppercase tracking-wider text-white">Documentary photograph pending</p>
        <p class="mt-3 text-sm leading-6 text-white">We will not replace it with stock photography.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($categories && !$categoriesError): ?>
  <nav class="border-b border-mist bg-white" aria-label="Shop by category">
    <div class="okv-container flex gap-2 overflow-x-auto py-3">
      <?php foreach ($categories as $pill):
          $count = (int) $pill['product_count'];
          $chipImage = (string) ($pill['sample_image'] ?? '');
      ?>
        <a href="/shop.php?category=<?= okv_e($pill['slug']) ?>"
           class="okv-cat-chip group"
           aria-label="<?= okv_e($pill['name']) ?>, <?= $count === 0 ? 'being sourced' : $count . ' items' ?>">
          <?php if ($chipImage !== ''): ?>
            <img src="<?= okv_e(okv_image_url($chipImage)) ?>" alt="" class="okv-cat-chip-photo" loading="lazy" aria-hidden="true">
          <?php endif; ?>
          <?php okv_icon('leaf', 'h-4 w-4 text-white/70'); ?>
          <span class="relative"><?= okv_e($pill['name']) ?></span>
          <?php if ($count === 0): ?>
            <span class="relative rounded-full border border-white/30 px-2 py-0.5 text-okv-micro font-semibold uppercase tracking-wide text-white/80">Being sourced</span>
          <?php else: ?>
            <span class="relative font-mono text-xs text-white/75"><?= $count ?></span>
          <?php endif; ?>
          <span class="relative transition-transform duration-botanical ease-botanical group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
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
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-forest text-white"><?php okv_icon('leaf', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Sourced right</h3>
        <p class="mt-2 text-sm text-ink-60">Farms we have visited.</p>
      </li>
      <li class="okv-step-card okv-enter okv-enter-2 bg-gold-tint2">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-forest text-white"><?php okv_icon('sparkle', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Priced right</h3>
        <p class="mt-2 text-sm text-ink-60">Prices we can explain.</p>
      </li>
      <li class="okv-step-card okv-enter okv-enter-3 bg-clay-tint">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-forest text-white"><?php okv_icon('trail', 'h-12 w-12'); ?></span>
        <h3 class="mt-4 font-editorial text-okv-h6 text-ink">Delivered right</h3>
        <p class="mt-2 text-sm text-ink-60">A delivery day you picked.</p>
      </li>
    </ol>
    <p class="mt-5">
      <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="promise" aria-haspopup="dialog"><?php okv_icon('info', 'h-4 w-4'); ?> Learn</button>
    </p>
    <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-4 flex w-fit items-center gap-2 rounded-full border border-forest/25 bg-white px-4 py-2.5 text-sm font-medium text-forest shadow-okv-1'); ?>
    <!--
      Shop produce is the primary order path, so it takes the solid forest
      fill. The other two paths stay outlined on white: the colour marks the
      main action, and the outline keeps the choice legible at a glance.
    -->
    <nav class="mt-8 grid gap-3 sm:grid-cols-3" aria-label="Start an order">
      <a class="okv-card min-h-[72px] bg-forest text-white shadow-okv-2 hover:bg-forest-hover" href="/shop.php"><strong class="flex items-center gap-2"><?php okv_icon('leaf', 'h-4 w-4 text-white'); ?> Shop produce</strong><span class="mt-1 block text-sm text-white/75">Pick this week's items.</span></a>
      <a class="okv-card min-h-[72px] border border-forest/40 text-ink hover:border-forest hover:text-forest" href="/combos.php"><strong class="flex items-center gap-2"><?php okv_icon('basket', 'h-4 w-4 text-forest'); ?> Choose a Combo</strong><span class="mt-1 block text-sm text-ink-60">A ready basket for the pot.</span></a>
      <a class="okv-card min-h-[72px] border border-forest/40 text-ink hover:border-forest hover:text-forest" href="/kitchen-runs.php"><strong class="flex items-center gap-2"><?php okv_icon('list', 'h-4 w-4 text-forest'); ?> Send a Kitchen Run</strong><span class="mt-1 block text-sm text-ink-60">Send your list. We source it.</span></a>
    </nav>
  </div>
</section>
<?php okv_help_sheet('promise', 'leaf', 'The OK Veggies promise', $promise['html']); ?>

<!--
  The combos strip sits on a soft forest band (one step deeper than the
  promise tint), so the page graduates from the green hero toward the white
  sections below instead of jumping straight from tint to white.
-->
<section class="bg-forest-tint2" aria-labelledby="combos-heading">
  <div class="okv-container py-14">
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
  </div>
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
    <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-5"><?php foreach ($categories as $category):
        $count = (int) $category['product_count'];
        $cardImage = (string) ($category['sample_image'] ?? '');
    ?>
      <a href="/shop.php?category=<?= okv_e($category['slug']) ?>" class="okv-cat-card group">
        <?php if ($cardImage !== ''): ?>
          <img src="<?= okv_e(okv_image_url($cardImage)) ?>" alt="" class="okv-cat-card-photo" loading="lazy" aria-hidden="true">
        <?php else: ?>
          <span class="pointer-events-none absolute -bottom-4 -right-4 -z-10 text-white/10" aria-hidden="true"><?php okv_icon('leaf', 'h-24 w-24'); ?></span>
        <?php endif; ?>
        <span class="relative flex items-start justify-between gap-2">
          <span class="block font-semibold leading-tight"><?= okv_e($category['name']) ?></span>
          <span class="transition-transform duration-botanical ease-botanical group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
        </span>
        <span class="relative mt-3 flex items-center gap-2 text-sm text-white/75">
          <?php if ($count === 0): ?>
            <span class="rounded-full border border-white/30 px-2 py-0.5 text-okv-micro font-semibold uppercase tracking-wide">Being sourced</span>
          <?php else: ?>
            <span class="font-mono"><?= $count ?></span> <?= $count === 1 ? 'item' : 'items' ?>
          <?php endif; ?>
        </span>
        <span class="okv-cat-card-cta pointer-events-none absolute inset-x-0 bottom-0" aria-hidden="true">
          <span>Shop <?= okv_e($category['name']) ?></span><span aria-hidden="true">&rarr;</span>
        </span>
      </a>
    <?php endforeach; ?></div>
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
