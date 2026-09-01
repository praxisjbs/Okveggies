<?php
/**
 * includes/components/admin/product_list.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin catalogue list: one card per product with its manage
 * panel (details, availability, photos, remove), or the empty state. Rendered
 * by admin/products.php on a full page load and by api/v1/products.php
 * ("browse") when the live filter swaps the list in without a reload, so both
 * paths always show exactly the same markup.
 *
 * Permissions arrive as plain booleans: the caller checks Rbac::can() and this
 * component only reads the answers, so rendering never hides a privilege call.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_admin_products_url')) {
    /**
     * An admin-catalogue URL carrying the filters and the page. Page 1 and
     * empty filters stay out of the query string; $openId names the product
     * whose manage panel opens, used when the Pricing screen links back here.
     */
    function okv_admin_products_url(string $search = '', string $category = '', string $status = '', int $page = 1, int $openId = 0): string
    {
        $query = array_filter(
            [
                'search'   => $search,
                'category' => $category,
                'status'   => $status,
                'page'     => $page > 1 ? $page : null,
                'product'  => $openId > 0 ? $openId : null,
            ],
            static fn($value) => $value !== '' && $value !== null
        );
        return '/admin/products.php' . ($query ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('okv_admin_product_cards')) {
    /**
     * The cards, or the empty state when the filters match nothing. $hasFilters
     * tells the empty state which of its two messages is true.
     */
    function okv_admin_product_cards(
        array $products,
        array $categories,
        array $units,
        int $openId,
        bool $canEdit,
        bool $canDelete,
        bool $canStock,
        bool $hasFilters
    ): void {
?>
    <?php if (!$products): ?>
      <div class="okv-panel okv-panel-body">
        <p class="text-ink-60">
          <?= $hasFilters ? 'Nothing matched those filters.' : 'There are no products yet.' ?>
          <?php if ($hasFilters): ?>
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
      <div class="okv-panel" id="product-<?= $id ?>">
        <div class="okv-panel-head">
          <div class="flex gap-4 min-w-0">
            <div class="w-16 h-16 rounded-md bg-forest-tint overflow-hidden shrink-0">
              <?php if (!empty($product['image'])): ?>
                <img src="<?= okv_e(okv_image_url($product['image'])) ?>" alt="<?= okv_e($product['name']) ?>" class="w-full h-full object-cover">
              <?php endif; ?>
            </div>
            <div class="min-w-0">
              <p class="okv-panel-title"><?= okv_e($product['name']) ?></p>
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
            <p class="font-mono tabular-nums font-semibold text-forest">
              <?= $price > 0 ? okv_e(Money::format($price)) : '<span class="text-ink-40 font-sans text-sm">Not priced</span>' ?>
            </p>
            <div class="flex flex-wrap justify-end gap-1 mt-2">
              <!-- Off the shop is a decision, not a fault, so it reads neutral.
                   Tomato is kept for the state that actually costs a sale. -->
              <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-neutral' ?>"><?= $isActive ? 'On the shop' : 'Off the shop' ?></span>
              <?php
                // Three availability states, three readings. Restocking is not
                // out of stock: it has a date, so it carries the warning tone
                // rather than the alarm one. Every badge still says its word,
                // so colour is never the only signal (bible 6.8).
                $availabilityTone = [
                    'available'  => 'okv-badge-available',
                    'restocking' => 'okv-badge-warn',
                ][$availability['key']] ?? 'okv-badge-out';
              ?>
              <span class="okv-badge <?= $availabilityTone ?>"><?= okv_e($availability['short_label']) ?></span>
            </div>
          </div>
        </div>

        <details class="okv-panel-body" <?= $openId === $id ? 'open' : '' ?>>
          <summary class="okv-btn-text text-sm cursor-pointer select-none">Manage</summary>
          <div class="mt-4 grid gap-6 lg:grid-cols-2">

            <!-- Details -->
            <?php if ($canEdit): ?>
            <form action="/api/v1/products.php" method="POST" class="grid gap-3" data-product-form>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="product_id" value="<?= $id ?>">
              <div class="okv-note-bad" data-okv-error role="alert" aria-live="polite" hidden></div>

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
<?php
    }
}
