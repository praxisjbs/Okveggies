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
Rbac::requirePermission('products.view');

$search   = (string) okv_input('search', '');
$category = (string) okv_input('category', '');
$status   = (string) okv_input('status', '');
$openId   = (int) okv_input('product', 0);

$categories = Database::all('SELECT id, name, slug FROM product_categories WHERE is_active = 1 ORDER BY sort_order, name');
$units      = Database::all('SELECT id, name, symbol, allows_decimal FROM units_of_measurement WHERE is_active = 1 ORDER BY id');
$products   = Products::all($search, $category, $status);

$canCreate = Rbac::can('products.create');
$canEdit   = Rbac::can('products.edit');
$canDelete = Rbac::can('products.delete');
$canStock  = Rbac::can('products.availability.update');

/** Keep the current filters when a link goes back to this page. */
function products_url(array $overrides = []): string
{
    $query = array_filter(array_merge([
        'search'   => (string) okv_input('search', ''),
        'category' => (string) okv_input('category', ''),
        'status'   => (string) okv_input('status', ''),
    ], $overrides), static fn($value) => $value !== '' && $value !== null);
    return '/admin/products.php' . ($query ? '?' . http_build_query($query) : '');
}

$okv_admin_title = 'Products';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <!-- Filters --------------------------------------------------------------->
    <div class="okv-card">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-xl text-ink">The catalogue</h2>
          <p class="text-sm text-ink-60 mt-1">
            <?= count($products) ?> <?= count($products) === 1 ? 'product' : 'products' ?>.
            Prices are changed on the <a href="/admin/pricing.php" class="text-forest underline underline-offset-2">Pricing screen</a>, so every change is recorded.
          </p>
        </div>
        <?php if ($canCreate): ?>
          <button type="button" class="okv-btn px-4" data-add-open>Add a product</button>
        <?php endif; ?>
      </div>

      <form action="/admin/products.php" method="get" class="mt-4 grid gap-3 sm:grid-cols-4">
        <div class="sm:col-span-2">
          <label for="search" class="okv-label">Search</label>
          <input id="search" name="search" type="search" value="<?= okv_e($search) ?>" class="okv-input" placeholder="Name or SKU">
        </div>
        <div>
          <label for="category" class="okv-label">Category</label>
          <select id="category" name="category" class="okv-input">
            <option value="">All categories</option>
            <?php foreach ($categories as $item): ?>
              <option value="<?= okv_e($item['slug']) ?>" <?= $category === $item['slug'] ? 'selected' : '' ?>><?= okv_e($item['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="status" class="okv-label">On the shop</label>
          <div class="flex gap-2">
            <select id="status" name="status" class="okv-input">
              <option value="">Any</option>
              <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>On the shop</option>
              <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Off the shop</option>
            </select>
            <button type="submit" class="okv-btn px-4">Filter</button>
          </div>
        </div>
      </form>
    </div>

    <!-- Add a product --------------------------------------------------------->
    <?php if ($canCreate): ?>
    <section class="okv-card" data-add-panel hidden>
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
        <div class="sm:col-span-2 rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>

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
    <?php if (!$products): ?>
      <div class="okv-card">
        <p class="text-ink-60">
          <?= ($search !== '' || $category !== '' || $status !== '') ? 'Nothing matched those filters.' : 'There are no products yet.' ?>
          <?php if ($search !== '' || $category !== '' || $status !== ''): ?>
            <a href="/admin/products.php" class="text-forest underline underline-offset-2">Clear the filters</a>.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <?php foreach ($products as $product):
        $id = (int) $product['id'];
        $price = (int) $product['current_price_subunit'];
        $availability = okv_availability((string) $product['availability_status'], $product['restock_date'] ?? null);
        $isActive = (int) $product['is_active'] === 1;
    ?>
      <div class="okv-card" id="product-<?= $id ?>">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex gap-4 min-w-0">
            <div class="w-16 h-16 rounded-md bg-forest-tint overflow-hidden shrink-0">
              <?php if (!empty($product['image'])): ?>
                <img src="<?= okv_e(okv_image_url($product['image'])) ?>" alt="<?= okv_e($product['name']) ?>" class="w-full h-full object-cover">
              <?php endif; ?>
            </div>
            <div class="min-w-0">
              <p class="font-display font-bold text-ink"><?= okv_e($product['name']) ?></p>
              <p class="text-xs text-ink-40 font-mono"><?= okv_e($product['sku']) ?></p>
              <p class="text-sm text-ink-60 mt-1">
                <?= okv_e($product['category_name']) ?>, per <?= okv_e($product['unit']) ?>
                <?php if ((int) $product['image_count'] > 0): ?>
                  <span class="text-ink-40">. <?= (int) $product['image_count'] ?> <?= (int) $product['image_count'] === 1 ? 'photo' : 'photos' ?></span>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <div class="text-right shrink-0">
            <p class="font-mono font-semibold text-forest">
              <?= $price > 0 ? okv_e(Money::format($price)) : '<span class="text-ink-40 font-sans text-sm">Not priced</span>' ?>
            </p>
            <div class="flex flex-wrap justify-end gap-1 mt-2">
              <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= $isActive ? 'On the shop' : 'Off the shop' ?></span>
              <span class="okv-badge <?= $availability['key'] === 'available' ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= okv_e($availability['short_label']) ?></span>
            </div>
          </div>
        </div>

        <details class="mt-4" <?= $openId === $id ? 'open' : '' ?>>
          <summary class="okv-btn-text text-sm cursor-pointer select-none">Manage</summary>
          <div class="mt-4 grid gap-6 lg:grid-cols-2">

            <!-- Details -->
            <?php if ($canEdit): ?>
            <form action="/api/v1/products.php" method="POST" class="grid gap-3" data-product-form>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="product_id" value="<?= $id ?>">
              <div class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>

              <p class="font-semibold text-sm text-ink">Details</p>
              <div>
                <label for="name-<?= $id ?>" class="okv-label">Name</label>
                <input id="name-<?= $id ?>" name="name" type="text" required class="okv-input" value="<?= okv_e($product['name']) ?>">
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label for="sku-<?= $id ?>" class="okv-label">SKU</label>
                  <input id="sku-<?= $id ?>" name="sku" type="text" required class="okv-input font-mono" value="<?= okv_e($product['sku']) ?>">
                </div>
                <div>
                  <label for="cat-<?= $id ?>" class="okv-label">Category</label>
                  <select id="cat-<?= $id ?>" name="category_id" class="okv-input">
                    <?php foreach ($categories as $item): ?>
                      <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === (int) $product['category_id'] ? 'selected' : '' ?>><?= okv_e($item['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label for="unit-<?= $id ?>" class="okv-label">Unit</label>
                  <select id="unit-<?= $id ?>" name="unit_id" class="okv-input">
                    <?php foreach ($units as $unit): ?>
                      <option value="<?= (int) $unit['id'] ?>" <?= (int) $unit['id'] === (int) $product['unit_id'] ? 'selected' : '' ?>><?= okv_e($unit['symbol']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="min-<?= $id ?>" class="okv-label">Minimum</label>
                  <input id="min-<?= $id ?>" name="minimum_quantity" type="text" inputmode="decimal" class="okv-input font-mono" value="<?= okv_e(okv_quantity($product['minimum_quantity'])) ?>">
                </div>
                <div>
                  <label for="inc-<?= $id ?>" class="okv-label">Step</label>
                  <input id="inc-<?= $id ?>" name="quantity_increment" type="text" inputmode="decimal" class="okv-input font-mono" value="<?= okv_e(okv_quantity($product['quantity_increment'])) ?>">
                </div>
              </div>
              <div>
                <label for="short-<?= $id ?>" class="okv-label">Short description</label>
                <input id="short-<?= $id ?>" name="short_description" type="text" class="okv-input" value="<?= okv_e($product['short_description']) ?>">
              </div>
              <div class="flex flex-wrap items-center gap-6">
                <label class="inline-flex items-center gap-2 min-h-[44px]">
                  <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?> class="rounded border-mist text-forest focus:ring-gold">
                  <span class="text-sm">On the shop</span>
                </label>
                <label class="inline-flex items-center gap-2 min-h-[44px]">
                  <input type="checkbox" name="is_featured" value="1" <?= (int) $product['is_featured'] === 1 ? 'checked' : '' ?> class="rounded border-mist text-forest focus:ring-gold">
                  <span class="text-sm">Featured</span>
                </label>
              </div>
              <div>
                <button type="submit" class="okv-btn px-6">Save changes</button>
              </div>
            </form>
            <?php endif; ?>

            <div class="space-y-6">
              <!-- Availability -->
              <?php if ($canStock): ?>
              <form action="/api/v1/products.php" method="POST" class="grid gap-3" data-product-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="set_availability">
                <input type="hidden" name="product_id" value="<?= $id ?>">
                <p class="font-semibold text-sm text-ink">Availability this week</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label for="avail-<?= $id ?>" class="okv-label">Status</label>
                    <select id="avail-<?= $id ?>" name="availability_status" class="okv-input" data-availability>
                      <option value="available" <?= $product['availability_status'] === 'available' ? 'selected' : '' ?>>Available</option>
                      <option value="out_of_stock" <?= $product['availability_status'] === 'out_of_stock' ? 'selected' : '' ?>>Out of stock</option>
                      <option value="restocking" <?= $product['availability_status'] === 'restocking' ? 'selected' : '' ?>>Restocking</option>
                    </select>
                  </div>
                  <div data-restock-field <?= $product['availability_status'] === 'restocking' ? '' : 'hidden' ?>>
                    <label for="restock-<?= $id ?>" class="okv-label">Back on</label>
                    <input id="restock-<?= $id ?>" name="restock_date" type="date" class="okv-input" value="<?= okv_e($product['restock_date'] ?? '') ?>">
                  </div>
                </div>
                <div><button type="submit" class="okv-btn-outline px-4">Update availability</button></div>
              </form>
              <?php endif; ?>

              <!-- Photos -->
              <?php if ($canEdit): ?>
              <div data-images-for="<?= $id ?>">
                <p class="font-semibold text-sm text-ink">Photos</p>
                <p class="text-xs text-ink-40 mt-1">The main photo leads the shop grid. Alt text is written for you from the name, unit and source region.</p>
                <div class="grid grid-cols-3 gap-2 mt-3" data-image-list>
                  <?php foreach (Products::images($id) as $image): ?>
                    <figure class="relative rounded-md overflow-hidden bg-forest-tint" data-image-id="<?= (int) $image['id'] ?>">
                      <img src="<?= okv_e(okv_image_url($image['image_url'])) ?>" alt="<?= okv_e($product['name']) ?>" class="w-full aspect-square object-cover">
                      <figcaption class="absolute inset-x-0 bottom-0 bg-ink/70 text-white text-xs flex items-center justify-between px-1 py-1">
                        <?php if ((int) $image['is_primary'] === 1): ?>
                          <span class="px-1">Main</span>
                        <?php else: ?>
                          <button type="button" class="px-1 underline underline-offset-2" data-image-primary data-image-id="<?= (int) $image['id'] ?>">Make main</button>
                        <?php endif; ?>
                        <button type="button" class="px-1 underline underline-offset-2" data-image-delete data-image-id="<?= (int) $image['id'] ?>">Remove</button>
                      </figcaption>
                    </figure>
                  <?php endforeach; ?>
                </div>
                <form action="/api/v1/products.php" method="POST" enctype="multipart/form-data" class="mt-3 flex flex-wrap gap-2 items-end" data-image-form>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="add_image">
                  <input type="hidden" name="product_id" value="<?= $id ?>">
                  <div class="flex-1 min-w-[12rem]">
                    <label for="image-<?= $id ?>" class="okv-label">Add a photo</label>
                    <input id="image-<?= $id ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="okv-input" required>
                  </div>
                  <button type="submit" class="okv-btn-outline px-4">Upload</button>
                </form>
              </div>
              <?php endif; ?>

              <!-- Remove -->
              <?php if ($canDelete): ?>
              <div class="pt-4 border-t border-mist">
                <p class="font-semibold text-sm text-ink">Remove this product</p>
                <p class="text-xs text-ink-60 mt-1">
                  A product held by an order, a combo or its own price history cannot be removed. Switch it off instead and it leaves the shop straight away.
                </p>
                <form action="/api/v1/products.php" method="POST" class="mt-3" data-delete-form>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="product_id" value="<?= $id ?>">
                  <button type="submit" class="okv-btn-outline px-4 border-tomato text-tomato hover:bg-tomato-tint">Remove <?= okv_e($product['name']) ?></button>
                </form>
              </div>
              <?php endif; ?>
            </div>

          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </div>
<?php
$okv_admin_script = '/assets/js/admin-products.js';
require __DIR__ . '/../includes/components/admin/footer.php';
