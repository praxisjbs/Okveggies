<?php
/** OK Veggies. Admin combo builder. */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('combos.view');

$search = (string) okv_input('search', '');
$status = (string) okv_input('status', '');
$openId = (int) okv_input('combo', 0);
$combos = Combos::all($search, $status);
$products = Products::all('', '', '');

$canCreate = Rbac::can('combos.create');
$canEdit = Rbac::can('combos.edit');
$canPublish = Rbac::can('combos.publish');
$canDelete = Rbac::can('combos.delete');

/** Render the complete picker and live component total for one builder form. */
function okv_combo_picker(array $products, array $selected, string $prefix): void
{
    $byProduct = [];
    foreach ($selected as $component) {
        $byProduct[(int) $component['product_id']] = $component;
    }
    ?>
    <fieldset class="sm:col-span-2" data-component-picker>
      <legend class="font-semibold text-sm text-ink">Products and quantities</legend>
      <p class="text-sm text-ink-60 mt-1">Tick each product in the basket. The total uses today’s product prices.</p>
      <div class="mt-3 max-h-96 overflow-y-auto rounded-md border border-mist divide-y divide-mist">
        <?php foreach ($products as $product):
            $productId = (int) $product['id'];
            $component = $byProduct[$productId] ?? null;
            $checked = $component !== null;
            $price = (int) $product['current_price_subunit'];
            $quantity = $checked ? okv_quantity($component['quantity']) : '1';
        ?>
          <div class="grid grid-cols-[minmax(0,1fr)_7rem] items-center gap-3 p-3" data-component-row data-product-id="<?= $productId ?>" data-price-subunit="<?= $price ?>">
            <label class="flex min-h-[44px] items-center gap-3 min-w-0">
              <input type="checkbox" name="selected_products[]" value="<?= $productId ?>"
                     class="rounded border-mist text-forest focus:ring-gold" data-component-check <?= $checked ? 'checked' : '' ?>>
              <span class="min-w-0">
                <span class="block font-medium text-sm text-ink truncate"><?= okv_e($product['name']) ?></span>
                <span class="block text-xs <?= $price > 0 ? 'text-ink-60' : 'text-tomato font-semibold' ?>">
                  <?= $price > 0 ? okv_e(Money::format($price)) . ' per ' . okv_e($product['unit']) : 'No current price' ?>
                </span>
              </span>
            </label>
            <div>
              <label for="<?= okv_e($prefix) ?>-quantity-<?= $productId ?>" class="sr-only">Quantity of <?= okv_e($product['name']) ?> in <?= okv_e($product['unit']) ?></label>
              <div class="flex items-center gap-2">
                <input id="<?= okv_e($prefix) ?>-quantity-<?= $productId ?>" name="component_quantity[<?= $productId ?>]"
                       type="text" inputmode="decimal" value="<?= okv_e($quantity) ?>" class="okv-input font-mono"
                       data-component-quantity <?= $checked ? '' : 'disabled' ?>>
                <span class="text-xs text-ink-60 shrink-0"><?= okv_e($product['unit']) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-md bg-forest-tint px-4 py-3">
        <span class="font-semibold text-sm text-ink">Live component total</span>
        <span class="font-mono font-bold text-lg text-forest" data-component-total>₦0</span>
      </div>
      <p class="mt-2 rounded-md bg-tomato-tint px-4 py-3 text-sm font-semibold text-tomato" data-loss-warning role="status" hidden>
        The sell price is below the current component total.
      </p>
      <p class="mt-2 text-sm text-tomato" data-unpriced-warning role="status" hidden>
        A selected product has no current price. Save a draft or price that product before publishing.
      </p>
    </fieldset>
    <?php
}

/** Render shared fields for a new or existing combo. */
function okv_combo_fields(array $combo, array $products, array $components, string $prefix, bool $canPublish): void
{
    $price = (int) ($combo['price_subunit'] ?? 0);
    ?>
    <div>
      <label for="<?= okv_e($prefix) ?>-name" class="okv-label">Name</label>
      <input id="<?= okv_e($prefix) ?>-name" name="name" type="text" required class="okv-input" value="<?= okv_e($combo['name'] ?? '') ?>" placeholder="The Weekend Basket">
    </div>
    <div>
      <label for="<?= okv_e($prefix) ?>-sku" class="okv-label">SKU</label>
      <input id="<?= okv_e($prefix) ?>-sku" name="sku" type="text" required class="okv-input font-mono" value="<?= okv_e($combo['sku'] ?? '') ?>" placeholder="CMB-WEEKEND-001">
    </div>
    <div class="sm:col-span-2">
      <label for="<?= okv_e($prefix) ?>-description" class="okv-label">Description</label>
      <textarea id="<?= okv_e($prefix) ?>-description" name="description" rows="3" class="okv-input"><?= okv_e($combo['description'] ?? '') ?></textarea>
    </div>
    <div>
      <label for="<?= okv_e($prefix) ?>-price" class="okv-label">Combo sell price, in naira</label>
      <input id="<?= okv_e($prefix) ?>-price" name="price" type="text" inputmode="decimal" class="okv-input font-mono"
             value="<?= $price > 0 ? okv_e((string) Money::toNaira($price)) : '' ?>" placeholder="16900" data-sell-price>
      <p class="mt-1 text-xs text-ink-60">This stays fixed until you change it.</p>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <label for="<?= okv_e($prefix) ?>-from" class="okv-label">Available from</label>
        <input id="<?= okv_e($prefix) ?>-from" name="available_from" type="date" class="okv-input" value="<?= okv_e($combo['available_from'] ?? '') ?>">
      </div>
      <div>
        <label for="<?= okv_e($prefix) ?>-until" class="okv-label">Available until</label>
        <input id="<?= okv_e($prefix) ?>-until" name="available_until" type="date" class="okv-input" value="<?= okv_e($combo['available_until'] ?? '') ?>">
      </div>
    </div>
    <?php okv_combo_picker($products, $components, $prefix); ?>
    <div class="sm:col-span-2 flex flex-wrap items-center gap-6">
      <label class="inline-flex min-h-[44px] items-center gap-2">
        <input type="checkbox" name="is_featured" value="1" class="rounded border-mist text-forest focus:ring-gold" <?= !empty($combo['is_featured']) ? 'checked' : '' ?>>
        <span class="text-sm">Feature on the home page</span>
      </label>
      <?php if ($canPublish): ?>
        <label class="inline-flex min-h-[44px] items-center gap-2">
          <input type="checkbox" name="is_active" value="1" class="rounded border-mist text-forest focus:ring-gold" data-publish-check <?= !empty($combo['is_active']) ? 'checked' : '' ?>>
          <span class="text-sm">Put it on the shop</span>
        </label>
      <?php endif; ?>
    </div>
    <?php
}

$okv_admin_title = 'Combos';
$okv_admin_script = '/assets/js/admin-combos.js';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-6">
  <section class="okv-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="font-display font-extrabold text-xl text-ink">Ready-made baskets</h2>
        <p class="text-sm text-ink-60 mt-1"><?= count($combos) ?> <?= count($combos) === 1 ? 'combo' : 'combos' ?>. Component totals follow today’s product prices; sell prices move only when you change them.</p>
      </div>
      <?php if ($canCreate): ?><button type="button" class="okv-btn px-4" data-add-open>Add a combo</button><?php endif; ?>
    </div>
    <form action="/admin/combos.php" method="get" class="mt-4 grid gap-3 sm:grid-cols-3">
      <div class="sm:col-span-2">
        <label for="search" class="okv-label">Search</label>
        <input id="search" name="search" type="search" value="<?= okv_e($search) ?>" class="okv-input" placeholder="Name or SKU">
      </div>
      <div>
        <label for="status" class="okv-label">On the shop</label>
        <div class="flex gap-2">
          <select id="status" name="status" class="okv-input">
            <option value="">Any</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>On the shop</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Drafts</option>
          </select>
          <button type="submit" class="okv-btn px-4">Filter</button>
        </div>
      </div>
    </form>
  </section>

  <?php if ($canCreate): ?>
    <section class="okv-card" data-add-panel hidden>
      <div class="flex items-start justify-between gap-4">
        <div><h2 class="font-display font-extrabold text-lg text-ink">Add a combo</h2><p class="text-sm text-ink-60 mt-1">Save it as a draft until the products, price and dates are ready.</p></div>
        <button type="button" class="okv-btn-text px-2" data-add-close>Close</button>
      </div>
      <form action="/api/v1/combos.php" method="post" class="mt-4 grid gap-4 sm:grid-cols-2" data-combo-form>
        <?= Csrf::field() ?><input type="hidden" name="action" value="save">
        <div class="sm:col-span-2 rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>
        <?php okv_combo_fields([], $products, [], 'new', $canPublish); ?>
        <div class="sm:col-span-2"><button type="submit" class="okv-btn px-6">Add combo</button></div>
      </form>
    </section>
  <?php endif; ?>

  <?php if (!$combos): ?>
    <div class="okv-card"><p class="text-ink-60"><?= ($search !== '' || $status !== '') ? 'Nothing matched those filters.' : 'There are no combos yet.' ?> <?php if ($search !== '' || $status !== ''): ?><a href="/admin/combos.php" class="text-forest underline underline-offset-2">Clear the filters</a>.<?php endif; ?></p></div>
  <?php endif; ?>

  <?php foreach ($combos as $combo):
      $id = (int) $combo['id'];
      $details = Combos::componentTotalDetailed($id);
      $total = (int) $details['total_subunit'];
      $price = (int) $combo['price_subunit'];
      $lossMaking = Combos::isLossMaking($price, $total);
      $isActive = (int) $combo['is_active'] === 1;
  ?>
    <article class="okv-card" id="combo-<?= $id ?>">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <h2 class="font-display font-bold text-lg text-ink"><?= okv_e($combo['name']) ?></h2>
          <p class="text-xs text-ink-40 font-mono"><?= okv_e($combo['sku']) ?></p>
          <p class="text-sm text-ink-60 mt-1"><?= (int) $combo['component_count'] ?> <?= (int) $combo['component_count'] === 1 ? 'product' : 'products' ?></p>
        </div>
        <div class="text-right">
          <p class="font-mono font-semibold text-forest"><?= $price > 0 ? okv_e(Money::format($price)) : 'Not priced' ?></p>
          <p class="text-xs <?= $lossMaking ? 'text-tomato font-semibold' : 'text-ink-60' ?> mt-1">Components <?= okv_e(Money::format($total)) ?><?= $lossMaking ? ', sell price is lower' : '' ?></p>
          <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-out' ?> mt-2"><?= $isActive ? 'On the shop' : 'Draft' ?></span>
        </div>
      </div>
      <details class="mt-4" <?= $openId === $id ? 'open' : '' ?>><summary class="okv-btn-text text-sm cursor-pointer select-none">Manage</summary>
        <div class="mt-4 space-y-6">
          <?php if ($canEdit): ?>
            <form action="/api/v1/combos.php" method="post" class="grid gap-4 sm:grid-cols-2" data-combo-form>
              <?= Csrf::field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="combo_id" value="<?= $id ?>">
              <input type="hidden" name="image_url" value="<?= okv_e($combo['image_url'] ?? '') ?>">
              <div class="sm:col-span-2 rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>
              <?php okv_combo_fields($combo, $products, $details['components'], 'combo-' . $id, $canPublish); ?>
              <div class="sm:col-span-2"><button type="submit" class="okv-btn px-6">Save combo</button></div>
            </form>
            <div class="border-t border-mist pt-5">
              <h3 class="font-semibold text-sm text-ink">Combo photo</h3>
              <div class="mt-3 flex flex-wrap items-center gap-4">
                <?php if (!empty($combo['image_url'])): ?><img src="<?= okv_e(okv_image_url($combo['image_url'])) ?>" alt="<?= okv_e($combo['name']) ?>" class="h-24 w-24 rounded-md object-cover"><?php endif; ?>
                <form action="/api/v1/combos.php" method="post" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" data-photo-form>
                  <?= Csrf::field() ?><input type="hidden" name="action" value="upload_photo"><input type="hidden" name="combo_id" value="<?= $id ?>">
                  <div><label for="photo-<?= $id ?>" class="okv-label">JPEG, PNG or WebP</label><input id="photo-<?= $id ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp" required class="okv-input" data-photo-input></div>
                  <img alt="New combo photo preview" class="h-20 w-20 rounded-md object-cover" data-photo-preview hidden>
                  <button type="submit" class="okv-btn-outline px-4">Upload photo</button>
                </form>
              </div>
              <?php if (empty($combo['image_url'])): ?><p class="mt-2 text-xs text-ink-60">Until you upload one, the shop uses the first product’s main photo.</p><?php endif; ?>
            </div>
          <?php else: ?>
            <ul class="grid gap-2 sm:grid-cols-2"><?php foreach ($details['components'] as $component): ?><li class="text-sm text-ink"><?= okv_e(okv_quantity($component['quantity'])) ?><?= okv_e($component['unit']) ?> <?= okv_e($component['product_name']) ?></li><?php endforeach; ?></ul>
          <?php endif; ?>
          <?php if ($canDelete): ?>
            <form action="/api/v1/combos.php" method="post" data-delete-form class="border-t border-mist pt-5">
              <?= Csrf::field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="combo_id" value="<?= $id ?>">
              <button type="submit" class="okv-btn-text text-tomato">Remove combo</button>
            </form>
          <?php endif; ?>
        </div>
      </details>
    </article>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
