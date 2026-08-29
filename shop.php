<?php
/** Browse active produce by search term and category. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$search = Catalogue::cleanSearch((string) okv_input('search', ''));
$category = Catalogue::cleanCategory((string) okv_input('category', ''));
$categories = Catalogue::categories();
$products = Catalogue::products($search, $category);
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$returnTo = shop_url($search, $category);

$activeCategory = null;
foreach ($categories as $candidate) {
    if ($candidate['slug'] === $category) {
        $activeCategory = $candidate;
        break;
    }
}

function shop_url(string $search, string $category = ''): string
{
    $query = array_filter(['search' => $search, 'category' => $category], static fn($value) => $value !== '');
    return '/shop.php' . ($query ? '?' . http_build_query($query) : '');
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
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="OK Veggies">
  <meta property="og:title" content="<?= okv_e($pageTitle) ?>">
  <meta property="og:description" content="Search the week's produce, check the unit and price, then add what you need.">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
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
          <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Fresh this week</p>
          <h1 class="mt-2 font-display text-4xl font-extrabold text-ink md:text-5xl">What is going into your pot?</h1>
          <p class="mt-3 max-w-2xl text-ink-60">Search the week's produce, check the unit and price, then add what you need.</p>
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
      <p class="text-sm font-semibold text-ink"><?= count($products) ?> <?= count($products) === 1 ? 'item' : 'items' ?></p>
      <button type="button" class="okv-btn-outline px-4" data-filter-open aria-controls="shop-filter-sheet" aria-expanded="false">Filter by category</button>
    </div>

    <div class="mb-6 flex gap-2 overflow-x-auto pb-2 lg:hidden" aria-label="Quick category filters">
      <a href="<?= okv_e(shop_url($search)) ?>" class="okv-filter-chip <?= $category === '' ? 'okv-filter-chip-active' : '' ?>">All</a>
      <?php foreach ($categories as $item): ?>
        <a href="<?= okv_e(shop_url($search, $item['slug'])) ?>" class="okv-filter-chip <?= $category === $item['slug'] ? 'okv-filter-chip-active' : '' ?>"><?= okv_e($item['name']) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="grid gap-8 lg:grid-cols-12">
      <aside class="hidden lg:col-span-2 lg:block" aria-label="Product categories">
        <div class="sticky top-24 rounded-lg bg-white p-4 shadow-okv-1">
          <p class="font-display font-bold text-ink">Categories</p>
          <nav class="mt-3 space-y-1">
            <a href="<?= okv_e(shop_url($search)) ?>" class="okv-category-link <?= $category === '' ? 'okv-category-link-active' : '' ?>">
              <span>All produce</span><span><?= array_sum(array_map(static fn($item) => (int) $item['product_count'], $categories)) ?></span>
            </a>
            <?php foreach ($categories as $item): ?>
              <a href="<?= okv_e(shop_url($search, $item['slug'])) ?>" class="okv-category-link <?= $category === $item['slug'] ? 'okv-category-link-active' : '' ?>">
                <span><?= okv_e($item['name']) ?></span><span><?= (int) $item['product_count'] ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        </div>
      </aside>

      <div class="lg:col-span-10">
        <div class="mb-6 hidden items-end justify-between gap-4 lg:flex">
          <div>
            <h2 class="font-display text-2xl font-bold text-ink"><?= okv_e($activeCategory['name'] ?? 'All produce') ?></h2>
            <?php if ($search !== ''): ?><p class="mt-1 text-sm text-ink-60">Results for “<?= okv_e($search) ?>”</p><?php endif; ?>
          </div>
          <p class="text-sm text-ink-60"><?= count($products) ?> <?= count($products) === 1 ? 'item' : 'items' ?></p>
        </div>

        <?php if ($products): ?>
          <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($products as $product): ?>
              <?php okv_product_card($product, $sourceRegions, $returnTo); ?>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="rounded-lg bg-white px-6 py-16 text-center shadow-okv-1">
            <?php if ($search !== ''): ?>
              <h2 class="font-display text-2xl font-bold text-ink">Nothing matched that search</h2>
              <p class="mx-auto mt-3 max-w-md text-ink-60">Try another produce name<?= $category !== '' ? ', or clear the category to search everything available this week' : '' ?>.</p>
            <?php else: ?>
              <h2 class="font-display text-2xl font-bold text-ink">Nothing in <?= okv_e($activeCategory['name'] ?? 'this category') ?> this week</h2>
              <p class="mx-auto mt-3 max-w-md text-ink-60">We are still sourcing for this one. The rest of the week's produce is ready now.</p>
            <?php endif; ?>
            <a href="/shop.php" class="okv-btn mt-6">See all produce</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<div id="shop-filter-sheet" class="okv-sheet-backdrop" hidden data-filter-sheet>
  <section class="okv-sheet" role="dialog" aria-modal="true" aria-labelledby="filter-title">
    <div class="flex items-center justify-between gap-4">
      <h2 id="filter-title" class="font-display text-xl font-bold text-ink">Filter by category</h2>
      <button type="button" class="okv-btn-text px-2" data-filter-close>Close</button>
    </div>
    <form action="/shop.php" method="get" class="mt-6">
      <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= okv_e($search) ?>"><?php endif; ?>
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
