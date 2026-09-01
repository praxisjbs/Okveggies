<?php
/**
 * admin/products.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The catalogue. Add a product, edit it, set what is available this
 * week, manage its photos, and take one off the shop. Built in milestone M2.
 * See docs/PRD.md Section 5.
 *
 * Every change posts to api/v1/products.php, which re-checks the products.*
 * permissions on the server. The list renders without JavaScript; the in-place
 * actions need the script. A price is changed on the Pricing screen, so that it
 * always goes through the history.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
require_once __DIR__ . '/../includes/components/admin/product_list.php';
Rbac::requirePermission('products.view');

$search   = (string) okv_input('search', '');
$category = (string) okv_input('category', '');
$status   = (string) okv_input('status', '');
$openId   = (int) okv_input('product', 0);

$categories = Database::all('SELECT id, name, slug FROM product_categories WHERE is_active = 1 ORDER BY sort_order, name');
$units      = Database::all('SELECT id, name, symbol, allows_decimal FROM units_of_measurement WHERE is_active = 1 ORDER BY id');

$perPage = Products::PER_PAGE;
$total   = Products::count($search, $category, $status);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min(max(1, (int) okv_input('page', 1)), $pages);
// A deep link from the Pricing screen (?product=12) must land on the product
// whatever page the pagination puts it on; an explicit page wins.
if ($openId > 0 && okv_input('page', null) === null) {
    $page = Products::pageOf($openId, $search, $category, $status, $perPage);
}
$products   = Products::all($search, $category, $status, $page, $perPage);

$canCreate = Rbac::can('products.create');
$canEdit   = Rbac::can('products.edit');
$canDelete = Rbac::can('products.delete');
$canStock  = Rbac::can('products.availability.update');
$hasFilters = $search !== '' || $category !== '' || $status !== '';

$okv_admin_title   = 'Products';
$okv_admin_note    = 'Add produce, set what is available this week, and manage the photos customers see. Prices are changed on the Pricing screen, so every change is recorded.';
$okv_admin_actions = $canCreate
    ? '<button type="button" class="okv-btn-sm" data-add-open>Add a product</button>'
    : '';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <!-- Filters --------------------------------------------------------------->
    <div class="okv-panel okv-panel-body">
      <p class="text-sm text-ink-60">
        <span data-admin-summary aria-live="polite"><?= okv_e(okv_page_summary($page, $total, $perPage, 'product')) ?></span>.
        Change a price on the <a href="/admin/pricing.php" class="text-forest underline underline-offset-2">Pricing screen</a>.
      </p>

      <form action="/admin/products.php" method="get" class="mt-4 grid gap-3 sm:grid-cols-4" data-admin-filter>
        <div class="sm:col-span-2">
          <label for="search" class="okv-label">Search</label>
          <input id="search" name="search" type="search" value="<?= okv_e($search) ?>" class="okv-input-sm" placeholder="Name or SKU">
        </div>
        <div>
          <label for="category" class="okv-label">Category</label>
          <select id="category" name="category" class="okv-input-sm">
            <option value="">All categories</option>
            <?php foreach ($categories as $item): ?>
              <option value="<?= okv_e($item['slug']) ?>" <?= $category === $item['slug'] ? 'selected' : '' ?>><?= okv_e($item['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="status" class="okv-label">On the shop</label>
          <div class="flex gap-2">
            <select id="status" name="status" class="okv-input-sm">
              <option value="">Any</option>
              <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>On the shop</option>
              <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Off the shop</option>
            </select>
            <button type="submit" class="okv-btn-sm">Filter</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Add a product --------------------------------------------------------->
    <?php if ($canCreate): ?>
    <section class="okv-panel okv-panel-body" data-add-panel hidden>
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-lg text-ink">Add a product</h2>
          <p class="text-sm text-ink-60 mt-1">Leave the price empty to save it as a draft and price it later.</p>
        </div>
        <button type="button" class="okv-btn-text px-2" data-add-close>Close</button>
      </div>

      <form action="/api/v1/products.php" method="POST" class="mt-4 grid gap-4 sm:grid-cols-2" data-product-form>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <div class="okv-note-bad sm:col-span-2" data-okv-error role="alert" aria-live="polite" hidden></div>

        <div>
          <label for="new-name" class="okv-label">Name</label>
          <input id="new-name" name="name" type="text" required class="okv-input" placeholder="Fresh Tomatoes">
        </div>
        <div>
          <label for="new-sku" class="okv-label">SKU</label>
          <input id="new-sku" name="sku" type="text" required class="okv-input font-mono" placeholder="PRD-TOMATO-025">
        </div>
        <div>
          <label for="new-category" class="okv-label">Category</label>
          <select id="new-category" name="category_id" required class="okv-input">
            <?php foreach ($categories as $item): ?>
              <option value="<?= (int) $item['id'] ?>"><?= okv_e($item['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="new-unit" class="okv-label">Unit</label>
          <select id="new-unit" name="unit_id" required class="okv-input">
            <?php foreach ($units as $unit): ?>
              <option value="<?= (int) $unit['id'] ?>"><?= okv_e($unit['name']) ?> (<?= okv_e($unit['symbol']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="new-price" class="okv-label">Opening price, in naira</label>
          <input id="new-price" name="price" type="text" inputmode="decimal" class="okv-input font-mono" placeholder="2700">
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="new-minimum" class="okv-label">Minimum</label>
            <input id="new-minimum" name="minimum_quantity" type="text" inputmode="decimal" value="1" class="okv-input font-mono">
          </div>
          <div>
            <label for="new-increment" class="okv-label">Step</label>
            <input id="new-increment" name="quantity_increment" type="text" inputmode="decimal" value="1" class="okv-input font-mono">
          </div>
        </div>
        <div class="sm:col-span-2">
          <label for="new-short" class="okv-label">Short description</label>
          <input id="new-short" name="short_description" type="text" class="okv-input" placeholder="Firm fresh tomatoes, per kilogramme.">
        </div>
        <div class="sm:col-span-2">
          <label for="new-description" class="okv-label">Full description</label>
          <textarea id="new-description" name="description" rows="3" class="okv-input"></textarea>
        </div>
        <div class="sm:col-span-2 flex flex-wrap items-center gap-6">
          <label class="inline-flex items-center gap-2 min-h-[44px]">
            <input type="checkbox" name="is_active" value="1" checked class="rounded border-mist text-forest focus:ring-gold">
            <span class="text-sm">Put it on the shop</span>
          </label>
          <label class="inline-flex items-center gap-2 min-h-[44px]">
            <input type="checkbox" name="is_featured" value="1" class="rounded border-mist text-forest focus:ring-gold">
            <span class="text-sm">Feature it on the home page</span>
          </label>
        </div>
        <div class="sm:col-span-2">
          <button type="submit" class="okv-btn px-6">Add product</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <!-- The list -------------------------------------------------------------->
    <div data-admin-results class="space-y-6">
      <?php
      okv_admin_product_cards($products, $categories, $units, $openId, $canEdit, $canDelete, $canStock, $hasFilters);
      okv_pagination($page, $pages, static fn (int $n): string => okv_admin_products_url($search, $category, $status, $n), 'Product pages');
      ?>
    </div>
  </div>
<?php
$okv_admin_script = '/assets/js/admin-products.js';
require __DIR__ . '/../includes/components/admin/footer.php';
