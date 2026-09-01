<?php
/** Browse active produce by search term and category, one page at a time. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/pagination.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/shop_results.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$search = Catalogue::cleanSearch((string) okv_input('search', ''));
$category = Catalogue::cleanCategory((string) okv_input('category', ''));
$categories = Catalogue::categories();

$perPage = Catalogue::PER_PAGE;
$total = Catalogue::countProducts($search, $category);
$pages = max(1, (int) ceil($total / $perPage));
$page = min(max(1, (int) okv_input('page', 1)), $pages);
$products = Catalogue::products($search, $category, $page, $perPage);
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');

$activeCategory = null;
foreach ($categories as $candidate) {
    if ($candidate['slug'] === $category) {
        $activeCategory = $candidate;
        break;
    }
}

$pageTitle = $activeCategory
    ? $activeCategory['name'] . ', fresh this week. OK Veggies'
    : 'Shop fresh produce. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . ($category !== '' ? '/shop.php?category=' . rawurlencode($category) : '/shop.php');

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
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Search fresh vegetables, herbs, spices, tubers, roots, fruits and grains by category, unit, price and availability.">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_title' => $pageTitle, 'og_description' => "Search the week's produce, check the unit and price, then add what you need."]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('shop'); ?>

<main>
  <section class="border-b border-mist bg-white">
    <div class="okv-container py-8 md:py-12">
      <nav class="mb-4 text-sm text-ink-60" aria-label="Breadcrumb"><a href="/" class="hover:text-forest">Home</a> <span aria-hidden="true">/</span> <span aria-current="page">Shop</span></nav>
      <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
        <div>
          <p class="okv-eyebrow">Fresh this week</p>
          <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3">What is going into your pot?</h1>
          <p class="mt-4 max-w-2xl text-okv-lead text-ink-60">Search the week's produce, check the unit and price, then add what you need.</p>
          <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-4'); ?>
        </div>
        <form action="/shop.php" method="get" role="search" class="w-full max-w-xl">
          <label for="shop-search" class="okv-label">Search produce</label>
          <div class="flex gap-2">
            <input id="shop-search" name="search" type="search" value="<?= okv_e($search) ?>" class="okv-input" placeholder="Try tomatoes, garlic or herbs">
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= okv_e($category) ?>"><?php endif; ?>
            <button type="submit" class="okv-btn px-4">Search</button>
          </div>
        </form>
      </div>
    </div>
  </section>

  <?php if (isset($noticeMessages[$basketNotice])): ?>
    <div class="okv-container pt-6">
      <p class="rounded-md border <?= $basketNotice === 'added' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?> px-4 py-3 text-sm" role="status"><?= okv_e($noticeMessages[$basketNotice]) ?></p>
    </div>
  <?php endif; ?>

  <section class="okv-container py-8 md:py-12">
    <div class="mb-6 flex items-center justify-between gap-4 lg:hidden">
      <p class="text-sm font-semibold text-ink" data-shop-summary aria-live="polite"><?= okv_e(okv_page_summary($page, $total, $perPage, 'item')) ?></p>
      <button type="button" class="okv-btn-outline px-4" data-filter-open aria-controls="shop-filter-sheet" aria-expanded="false">Filter by category</button>
    </div>

    <div class="mb-6 flex gap-2 overflow-x-auto pb-2 lg:hidden" aria-label="Quick category filters">
      <a href="<?= okv_e(okv_shop_url($search)) ?>" data-shop-category-link="" class="okv-filter-chip <?= $category === '' ? 'okv-filter-chip-active' : '' ?>">All</a>
      <?php foreach ($categories as $item): ?>
        <a href="<?= okv_e(okv_shop_url($search, $item['slug'])) ?>" data-shop-category-link="<?= okv_e($item['slug']) ?>" class="okv-filter-chip <?= $category === $item['slug'] ? 'okv-filter-chip-active' : '' ?>"><?= okv_e($item['name']) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="grid gap-8 lg:grid-cols-12">
      <aside class="hidden lg:col-span-2 lg:block" aria-label="Product categories">
        <div class="sticky top-24 rounded-lg bg-white p-4 shadow-okv-1">
          <p class="okv-eyebrow">Categories</p>
          <nav class="mt-3 space-y-1">
            <a href="<?= okv_e(okv_shop_url($search)) ?>" data-shop-category-link="" class="okv-category-link <?= $category === '' ? 'okv-category-link-active' : '' ?>">
              <span>All produce</span><span><?= array_sum(array_map(static fn($item) => (int) $item['product_count'], $categories)) ?></span>
            </a>
            <?php foreach ($categories as $item): ?>
              <a href="<?= okv_e(okv_shop_url($search, $item['slug'])) ?>" data-shop-category-link="<?= okv_e($item['slug']) ?>" class="okv-category-link <?= $category === $item['slug'] ? 'okv-category-link-active' : '' ?>">
                <span><?= okv_e($item['name']) ?></span><span><?= (int) $item['product_count'] ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>
      </aside>

      <div class="lg:col-span-10" data-shop-results>
        <?php okv_shop_results($products, $categories, $sourceRegions, $search, $category, $page, $total, $perPage, $sourceDay); ?>
      </div>
    </div>
  </section>
</main>

<div id="shop-filter-sheet" class="okv-sheet-backdrop" hidden data-filter-sheet>
  <section class="okv-sheet" role="dialog" aria-modal="true" aria-labelledby="filter-title">
    <div class="flex items-center justify-between gap-4">
      <h2 id="filter-title" class="font-editorial text-okv-h6 text-ink">Filter by category</h2>
      <button type="button" class="okv-btn-text px-2" data-filter-close>Close</button>
    </div>
    <form action="/shop.php" method="get" class="mt-6">
      <input type="hidden" name="search" value="<?= okv_e($search) ?>" data-sheet-search <?= $search === '' ? 'disabled' : '' ?>>
      <label for="mobile-category" class="okv-label">Category</label>
      <select id="mobile-category" name="category" class="okv-input">
        <option value="">All produce</option>
        <?php foreach ($categories as $item): ?>
          <option value="<?= okv_e($item['slug']) ?>" <?= $category === $item['slug'] ? 'selected' : '' ?>><?= okv_e($item['name']) ?> (<?= (int) $item['product_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="okv-btn mt-6 w-full">Show produce</button>
    </form>
  </section>
</div>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
