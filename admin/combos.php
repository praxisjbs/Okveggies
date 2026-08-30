<?php
/**
 * admin/combos.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The combo builder. Add a combo with its first component, price it,
 * edit the components, set its availability window, upload a photo, publish it,
 * and take it off the shop. Built in milestone M3 PR2. See docs/PRD.md Section 7.
 *
 * Every change posts to api/v1/combos.php, which re-checks the combos.*
 * permissions on the server. The list renders without JavaScript; the in-place
 * actions need the script. The sell price moves through Combos::changePrice so
 * every movement writes a history row.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('combos.view');

$search = (string) okv_input('search', '');
$status = (string) okv_input('status', '');
$openId = (int) okv_input('combo', 0);

$combos = Combos::all($search, $status);

// Pre-load the live component total for each combo so the list can show the
// loss-making flag or the customer-saving line without a JS round-trip. There
// are typically only a handful of combos; one small query each is fine.
$details = [];
foreach ($combos as $combo) {
    $id = (int) $combo['id'];
    $details[$id] = Combos::componentTotalDetailed($id);
}

// The product picker only ever offers active products; a switched-off product
// cannot be added as a component (Products keeps them switched off for a
// reason). Units the picker offers are the active ones only.
$activeProducts = Database::all(
    'SELECT p.id, p.name, p.sku, p.unit_id, u.symbol AS unit
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id AND u.is_active = 1
      WHERE p.is_active = 1
      ORDER BY p.name'
);
$units = Database::all(
    'SELECT id, name, symbol, allows_decimal
       FROM units_of_measurement
      WHERE is_active = 1
      ORDER BY id'
);

$canCreate  = Rbac::can('combos.create');
$canEdit    = Rbac::can('combos.edit');
$canPublish = Rbac::can('combos.publish');
$canDelete  = Rbac::can('combos.delete');

$okv_admin_title = 'Combos';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <!-- Filters --------------------------------------------------------------->
    <div class="okv-card">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-xl text-ink">Combos</h2>
          <p class="text-sm text-ink-60 mt-1 max-w-xl">
            <?= count($combos) ?> <?= count($combos) === 1 ? 'combo' : 'combos' ?>.
            Change the sell price on the combo below and it is written to the price history straight away.
            The component total is a live figure and never rewrites the sell price.
          </p>
        </div>
        <?php if ($canCreate): ?>
          <button type="button" class="okv-btn px-4" data-add-open>Add a combo</button>
        <?php endif; ?>
      </div>

      <form action="/admin/combos.php" method="get" class="mt-4 grid gap-3 sm:grid-cols-4">
        <div class="sm:col-span-2">
          <label for="search" class="okv-label">Search</label>
          <input id="search" name="search" type="search" value="<?= okv_e($search) ?>" class="okv-input" placeholder="Name or SKU">
        </div>
        <div>
          <label for="status" class="okv-label">On the shop</label>
          <select id="status" name="status" class="okv-input">
            <option value="">Any</option>
            <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>On the shop</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Off the shop</option>
          </select>
        </div>
        <div class="flex items-end">
          <button type="submit" class="okv-btn w-full px-4">Filter</button>
        </div>
      </form>
    </div>

    <!-- Add a combo ---------------------------------------------------------->
    <?php if ($canCreate): ?>
    <section class="okv-card" data-add-panel hidden>
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-lg text-ink">Add a combo</h2>
          <p class="text-sm text-ink-60 mt-1">Give it a name, a SKU and one first component. Add the rest of the components on the combo's panel after.</p>
        </div>
        <button type="button" class="okv-btn-text px-2" data-add-close>Close</button>
      </div>

      <form action="/api/v1/combos.php" method="POST" class="mt-4 grid gap-4 sm:grid-cols-2" data-combo-form data-refresh-on-success>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="create">
        <div class="sm:col-span-2 rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>

        <div>
          <label for="new-name" class="okv-label">Name</label>
          <input id="new-name" name="name" type="text" required class="okv-input" placeholder="The Stew Combo">
        </div>
        <div>
          <label for="new-sku" class="okv-label">SKU</label>
          <input id="new-sku" name="sku" type="text" required class="okv-input font-mono" placeholder="OKV-COMBO-STEW">
        </div>
        <div class="sm:col-span-2">
          <label for="new-description" class="okv-label">Description</label>
          <textarea id="new-description" name="description" rows="2" class="okv-input" placeholder="A blended pepper-tomato base for a Lagos pot of stew."></textarea>
        </div>

        <div>
          <label for="new-price" class="okv-label">Sell price, in naira</label>
          <input id="new-price" name="price" type="text" inputmode="decimal" class="okv-input font-mono" placeholder="16900">
          <p class="text-xs text-ink-40 mt-1">Leave blank to save as a draft. The customer never sees a combo without a sell price.</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label for="new-from" class="okv-label">Available from</label>
            <input id="new-from" name="available_from" type="date" class="okv-input">
          </div>
          <div>
            <label for="new-until" class="okv-label">Available until</label>
            <input id="new-until" name="available_until" type="date" class="okv-input">
          </div>
        </div>
        <div class="sm:col-span-2 -mt-2">
          <p class="text-xs text-ink-40">Leave both blank for a combo that stays on the shop.</p>
        </div>

        <div class="sm:col-span-2">
          <p class="font-semibold text-sm text-ink">First component</p>
          <p class="text-xs text-ink-40 mt-1">Every combo needs at least one product. Add more on the combo's panel.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-3">
            <div>
              <label for="new-product" class="okv-label">Product</label>
              <select id="new-product" name="first_product_id" class="okv-input" required>
                <option value="">Pick a product</option>
                <?php foreach ($activeProducts as $product): ?>
                  <option value="<?= (int) $product['id'] ?>" data-unit-id="<?= (int) $product['unit_id'] ?>">
                    <?= okv_e($product['name']) ?> (<?= okv_e($product['unit']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label for="new-quantity" class="okv-label">Quantity</label>
              <input id="new-quantity" name="first_quantity" type="text" inputmode="decimal" class="okv-input font-mono" placeholder="2" required>
            </div>
            <div>
              <label for="new-unit" class="okv-label">Unit</label>
              <select id="new-unit" name="first_unit_id" class="okv-input" required>
                <?php foreach ($units as $unit): ?>
                  <option value="<?= (int) $unit['id'] ?>"><?= okv_e($unit['name']) ?> (<?= okv_e($unit['symbol']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="sm:col-span-2 flex flex-wrap items-center gap-6">
          <label class="inline-flex items-center gap-2 min-h-[44px]">
            <input type="checkbox" name="is_featured" value="1" class="rounded border-mist text-forest focus:ring-gold">
            <span class="text-sm">Feature it on the home page</span>
          </label>
          <?php if ($canPublish): ?>
            <label class="inline-flex items-center gap-2 min-h-[44px]">
              <input type="checkbox" name="is_active" value="1" class="rounded border-mist text-forest focus:ring-gold">
              <span class="text-sm">Put it on the shop straight away</span>
            </label>
          <?php endif; ?>
        </div>
        <div class="sm:col-span-2">
          <button type="submit" class="okv-btn px-6">Add combo</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <!-- The list -------------------------------------------------------------->
    <?php if (!$combos): ?>
      <div class="okv-card">
        <p class="text-ink-60">
          <?= ($search !== '' || $status !== '') ? 'Nothing matched those filters.' : 'There are no combos yet.' ?>
          <?php if ($search !== '' || $status !== ''): ?>
            <a href="/admin/combos.php" class="text-forest underline underline-offset-2">Clear the filters</a>.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <?php foreach ($combos as $combo):
        $id       = (int) $combo['id'];
        $sell     = (int) $combo['price_subunit'];
        $total    = (int) $details[$id]['total_subunit'];
        $lossy    = Combos::isLossMaking($sell, $total);
        $saving   = Combos::customerSaving($sell, $total);
        $isActive = (int) $combo['is_active'] === 1;
        $featured = (int) $combo['is_featured'] === 1;
    ?>
      <div class="okv-card" id="combo-<?= $id ?>" data-combo-panel data-combo-id="<?= $id ?>">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex gap-4 min-w-0">
            <div class="w-16 h-16 rounded-md bg-forest-tint overflow-hidden shrink-0">
              <?php if (!empty($combo['image_url'])): ?>
                <img src="<?= okv_e(okv_image_url($combo['image_url'])) ?>" alt="<?= okv_e($combo['name']) ?>" class="w-full h-full object-cover" data-combo-thumb>
              <?php else: ?>
                <div class="w-full h-full" data-combo-thumb></div>
              <?php endif; ?>
            </div>
            <div class="min-w-0">
              <p class="font-display font-bold text-ink" data-combo-name><?= okv_e($combo['name']) ?></p>
              <p class="text-xs text-ink-40 font-mono"><?= okv_e($combo['sku']) ?></p>
              <p class="text-sm text-ink-60 mt-1">
                <?= (int) $combo['component_count'] ?> <?= (int) $combo['component_count'] === 1 ? 'component' : 'components' ?>.
                Component total <span class="font-mono" data-combo-total><?= okv_e(Money::format($total)) ?></span>.
              </p>
            </div>
          </div>
          <div class="text-right shrink-0">
            <p class="font-mono font-semibold text-forest" data-combo-price>
              <?= $sell > 0 ? okv_e(Money::format($sell)) : '<span class="text-ink-40 font-sans text-sm">Not priced</span>' ?>
            </p>
            <div class="flex flex-wrap justify-end gap-1 mt-2">
              <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-out' ?>" data-combo-active-badge>
                <?= $isActive ? 'On the shop' : 'Off the shop' ?>
              </span>
              <?php if ($featured): ?>
                <span class="okv-badge okv-badge-available" data-combo-featured-badge>Featured</span>
              <?php else: ?>
                <span class="okv-badge okv-badge-out" data-combo-featured-badge hidden>Featured</span>
              <?php endif; ?>
            </div>
            <div class="mt-2" data-combo-flags>
              <?php if ($lossy): ?>
                <span class="inline-flex items-center rounded-md bg-tomato-tint text-tomato text-xs font-semibold px-2 py-1" data-combo-loss>
                  Selling below components
                </span>
                <p class="text-xs text-tomato mt-1 font-mono" data-combo-loss-line>
                  Components <?= okv_e(Money::format($total)) ?>, sell price <?= okv_e(Money::format($sell)) ?>
                </p>
              <?php elseif ($saving > 0): ?>
                <span class="text-xs text-ink-60" data-combo-saving>
                  Customer saves <span class="font-mono"><?= okv_e(Money::format($saving)) ?></span> against the components
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <details class="mt-4" <?= $openId === $id ? 'open' : '' ?>>
          <summary class="okv-btn-text text-sm cursor-pointer select-none">Manage</summary>
          <div class="mt-4 grid gap-6 lg:grid-cols-2">

            <!-- Left column: details, price, publish ------------------------------>
            <div class="space-y-6">

              <?php if ($canEdit): ?>
              <form action="/api/v1/combos.php" method="POST" class="grid gap-3" data-combo-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="combo_id" value="<?= $id ?>">
                <div class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>

                <p class="font-semibold text-sm text-ink">Details</p>
                <div>
                  <label for="name-<?= $id ?>" class="okv-label">Name</label>
                  <input id="name-<?= $id ?>" name="name" type="text" required class="okv-input" value="<?= okv_e($combo['name']) ?>">
                </div>
                <div>
                  <label for="sku-<?= $id ?>" class="okv-label">SKU</label>
                  <input id="sku-<?= $id ?>" name="sku" type="text" required class="okv-input font-mono" value="<?= okv_e($combo['sku']) ?>">
                </div>
                <div>
                  <label for="desc-<?= $id ?>" class="okv-label">Description</label>
                  <textarea id="desc-<?= $id ?>" name="description" rows="2" class="okv-input"><?= okv_e($combo['description'] ?? '') ?></textarea>
                </div>
                <div>
                  <label class="inline-flex items-center gap-2 min-h-[44px]">
                    <input type="checkbox" name="is_featured" value="1" <?= $featured ? 'checked' : '' ?> class="rounded border-mist text-forest focus:ring-gold">
                    <span class="text-sm">Feature on the home page</span>
                  </label>
                </div>
                <div>
                  <button type="submit" class="okv-btn px-6">Save details</button>
                </div>
              </form>
              <?php endif; ?>

              <!-- Availability window --------------------------------->
              <?php if ($canEdit): ?>
              <form action="/api/v1/combos.php" method="POST" class="grid gap-3" data-combo-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="set_availability_window">
                <input type="hidden" name="combo_id" value="<?= $id ?>">
                <div class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>
                <p class="font-semibold text-sm text-ink">Availability window</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label for="from-<?= $id ?>" class="okv-label">From</label>
                    <input id="from-<?= $id ?>" name="available_from" type="date" class="okv-input" value="<?= okv_e($combo['available_from'] ?? '') ?>">
                  </div>
                  <div>
                    <label for="until-<?= $id ?>" class="okv-label">Until</label>
                    <input id="until-<?= $id ?>" name="available_until" type="date" class="okv-input" value="<?= okv_e($combo['available_until'] ?? '') ?>">
                  </div>
                </div>
                <p class="text-xs text-ink-40">Leave both blank for a combo that stays on the shop.</p>
                <div>
                  <button type="submit" class="okv-btn-outline px-4">Save window</button>
                </div>
              </form>
              <?php endif; ?>

              <!-- Sell price -------------------------------------------->
              <?php if ($canEdit): ?>
              <form action="/api/v1/combos.php" method="POST" class="grid gap-3" data-combo-form>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="set_price">
                <input type="hidden" name="combo_id" value="<?= $id ?>">
                <div class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" data-okv-error role="alert" aria-live="polite" hidden></div>
                <p class="font-semibold text-sm text-ink">Sell price</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label for="price-<?= $id ?>" class="okv-label">Price in naira</label>
                    <div class="flex items-center gap-1">
                      <span aria-hidden="true" class="text-ink-60">₦</span>
                      <input id="price-<?= $id ?>" name="price" type="text" inputmode="decimal" class="okv-input w-full font-mono"
                             value="<?= $sell > 0 ? okv_e(number_format($sell / 100, 0, '.', '')) : '' ?>"
                             placeholder="Not priced">
                    </div>
                  </div>
                  <div>
                    <label for="reason-<?= $id ?>" class="okv-label">Reason (optional)</label>
                    <input id="reason-<?= $id ?>" name="reason" type="text" class="okv-input" placeholder="Supplier raised peppers">
                  </div>
                </div>
                <div>
                  <button type="submit" class="okv-btn-outline px-4">Save sell price</button>
                </div>
              </form>
              <?php endif; ?>

              <!-- Publish / Unpublish ----------------------------------->
              <?php if ($canPublish): ?>
              <div class="pt-4 border-t border-mist" data-combo-publish-block>
                <p class="font-semibold text-sm text-ink"><?= $isActive ? 'On the shop' : 'Off the shop' ?></p>
                <p class="text-xs text-ink-60 mt-1">
                  <?php if ($isActive): ?>
                    The customer can add this combo to a basket right now, inside its availability window.
                  <?php else: ?>
                    Publishing needs at least one component and a sell price of at least ₦1.
                  <?php endif; ?>
                </p>
                <form action="/api/v1/combos.php" method="POST" class="mt-2" data-combo-form>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="<?= $isActive ? 'unpublish' : 'publish' ?>" data-combo-publish-action>
                  <input type="hidden" name="combo_id" value="<?= $id ?>">
                  <button type="submit" class="<?= $isActive ? 'okv-btn-outline' : 'okv-btn' ?> px-6" data-combo-publish-button>
                    <?= $isActive ? 'Take off the shop' : 'Publish to the shop' ?>
                  </button>
                </form>
              </div>
              <?php endif; ?>

            </div>

            <!-- Right column: components, live total, photo, history, delete -->
            <div class="space-y-6">

              <!-- Components --------------------------------------------->
              <?php if ($canEdit): ?>
              <div>
                <p class="font-semibold text-sm text-ink">Components</p>
                <p class="text-xs text-ink-40 mt-1">
                  The same product can be added twice under a different unit, if the recipe needs it.
                  Combo internals ignore a product's customer-facing minimum and step.
                </p>

                <div class="mt-3 space-y-2" data-combo-components>
                  <?php foreach ($details[$id]['components'] as $component): ?>
                    <?php
                      $componentId = (int) $component['component_id'];
                      $qty         = okv_quantity($component['quantity']);
                      $unit        = (string) $component['unit'];
                      $line        = (int) $component['line_subunit'];
                      $productActive = (int) ($component['product_is_active'] ?? 0) === 1;
                    ?>
                    <form action="/api/v1/combos.php" method="POST"
                          class="grid grid-cols-[1fr_auto_auto_auto] items-end gap-2 rounded-md border border-mist px-3 py-2"
                          data-component-form data-component-id="<?= $componentId ?>">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="update_component">
                      <input type="hidden" name="combo_id" value="<?= $id ?>">
                      <input type="hidden" name="component_id" value="<?= $componentId ?>">
                      <div class="min-w-0">
                        <p class="text-sm font-medium text-ink truncate"><?= okv_e($component['product_name']) ?></p>
                        <p class="text-xs text-ink-40 font-mono">
                          <?= okv_e($component['product_sku']) ?>. Current <?= okv_e(Money::format((int) $component['current_price_subunit'])) ?>/<?= okv_e($unit) ?>.
                          Line <span class="font-mono" data-component-line><?= okv_e(Money::format($line)) ?></span>.
                          <?php if (!$productActive): ?>
                            <span class="text-tomato">This product is off the shop.</span>
                          <?php endif; ?>
                        </p>
                      </div>
                      <div>
                        <label class="sr-only" for="cq-<?= $componentId ?>">Quantity</label>
                        <input id="cq-<?= $componentId ?>" name="quantity" type="text" inputmode="decimal"
                               value="<?= okv_e($qty) ?>" class="okv-input w-20 font-mono text-right">
                      </div>
                      <div class="text-sm text-ink-60"><?= okv_e($unit) ?></div>
                      <div class="flex gap-2">
                        <button type="submit" class="okv-btn-outline px-3 text-xs">Save</button>
                        <button type="button" class="okv-btn-outline px-3 text-xs border-tomato text-tomato hover:bg-tomato-tint"
                                data-component-remove data-component-id="<?= $componentId ?>">Remove</button>
                      </div>
                    </form>
                  <?php endforeach; ?>

                  <?php if (!$details[$id]['components']): ?>
                    <p class="text-sm text-ink-60" data-combo-no-components>No components yet. Add the first one below.</p>
                  <?php endif; ?>
                </div>

                <form action="/api/v1/combos.php" method="POST"
                      class="mt-3 grid grid-cols-[1fr_auto_auto_auto] items-end gap-2"
                      data-combo-form data-component-add>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="add_component">
                  <input type="hidden" name="combo_id" value="<?= $id ?>">
                  <div class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3 col-span-4" data-okv-error role="alert" aria-live="polite" hidden></div>
                  <div class="min-w-0">
                    <label for="ap-<?= $id ?>" class="okv-label">Add a product</label>
                    <select id="ap-<?= $id ?>" name="product_id" class="okv-input" required>
                      <option value="">Pick a product</option>
                      <?php foreach ($activeProducts as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" data-unit-id="<?= (int) $product['unit_id'] ?>">
                          <?= okv_e($product['name']) ?> (<?= okv_e($product['unit']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label for="aq-<?= $id ?>" class="okv-label">Quantity</label>
                    <input id="aq-<?= $id ?>" name="quantity" type="text" inputmode="decimal" class="okv-input w-24 font-mono" placeholder="1" required>
                  </div>
                  <div>
                    <label for="au-<?= $id ?>" class="okv-label">Unit</label>
                    <select id="au-<?= $id ?>" name="unit_id" class="okv-input" required>
                      <?php foreach ($units as $unit): ?>
                        <option value="<?= (int) $unit['id'] ?>"><?= okv_e($unit['symbol']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="flex items-end">
                    <button type="submit" class="okv-btn-outline px-4">Add</button>
                  </div>
                </form>
              </div>
              <?php endif; ?>

              <!-- Photo -------------------------------------------------->
              <?php if ($canEdit): ?>
              <div data-combo-image>
                <p class="font-semibold text-sm text-ink">Photo</p>
                <p class="text-xs text-ink-40 mt-1">A single hero photo, JPEG, PNG or WebP.</p>
                <div class="mt-3 grid grid-cols-[6rem_1fr] gap-3 items-start">
                  <div class="w-24 h-24 rounded-md bg-forest-tint overflow-hidden">
                    <?php if (!empty($combo['image_url'])): ?>
                      <img src="<?= okv_e(okv_image_url($combo['image_url'])) ?>" alt="<?= okv_e($combo['name']) ?>" class="w-full h-full object-cover" data-combo-image-preview>
                    <?php else: ?>
                      <div class="w-full h-full" data-combo-image-preview></div>
                    <?php endif; ?>
                  </div>
                  <div class="space-y-2">
                    <form action="/api/v1/combos.php" method="POST" enctype="multipart/form-data" class="flex flex-wrap gap-2 items-end" data-combo-image-form>
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="upload_image">
                      <input type="hidden" name="combo_id" value="<?= $id ?>">
                      <div class="flex-1 min-w-[12rem]">
                        <label for="img-<?= $id ?>" class="okv-label">Upload a photo</label>
                        <input id="img-<?= $id ?>" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="okv-input" required>
                      </div>
                      <button type="submit" class="okv-btn-outline px-4">Upload</button>
                    </form>
                    <?php if (!empty($combo['image_url'])): ?>
                    <form action="/api/v1/combos.php" method="POST" data-combo-form>
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="remove_image">
                      <input type="hidden" name="combo_id" value="<?= $id ?>">
                      <button type="submit" class="okv-btn-text text-xs px-2 text-tomato" data-combo-image-remove>Remove photo</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <!-- History ------------------------------------------------>
              <div class="pt-4 border-t border-mist">
                <button type="button" class="okv-btn-text text-sm px-2" data-history-open data-combo-id="<?= $id ?>" data-combo-name="<?= okv_e($combo['name']) ?>">
                  Price history
                </button>
              </div>

              <!-- Delete ------------------------------------------------->
              <?php if ($canDelete): ?>
              <div class="pt-4 border-t border-mist">
                <p class="font-semibold text-sm text-ink">Remove this combo</p>
                <p class="text-xs text-ink-60 mt-1">
                  A combo held by an order, a basket or its own price history cannot be removed. Take it off the shop instead and it leaves the shop straight away.
                </p>
                <form action="/api/v1/combos.php" method="POST" class="mt-3" data-combo-delete-form>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="combo_id" value="<?= $id ?>">
                  <button type="submit" class="okv-btn-outline px-4 border-tomato text-tomato hover:bg-tomato-tint">Remove <?= okv_e($combo['name']) ?></button>
                </form>
              </div>
              <?php endif; ?>

            </div>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Price history, opened per combo -->
  <div id="combo-history" class="fixed inset-0 z-40 bg-ink/40 p-4 flex items-end sm:items-center justify-center" hidden data-history-panel>
    <section class="bg-white rounded-lg shadow-okv-3 w-full max-w-xl max-h-[80vh] overflow-y-auto p-6" role="dialog" aria-modal="true" aria-labelledby="combo-history-title">
      <div class="flex items-start justify-between gap-4">
        <h2 id="combo-history-title" class="font-display font-extrabold text-lg text-ink">Price history</h2>
        <button type="button" class="okv-btn-text px-2" data-history-close>Close</button>
      </div>
      <div class="mt-4" data-history-body></div>
    </section>
  </div>
<?php
$okv_admin_script = '/assets/js/admin-combos.js';
require __DIR__ . '/../includes/components/admin/footer.php';
