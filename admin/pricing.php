<?php
/**
 * admin/pricing.php
 * -----------------------------------------------------------------------------
 * OK Veggies. This week's prices. Weekly repricing is the core recurring task of
 * the business, so this screen is built to be quick: every price is editable in
 * place, a whole category moves in one action, and the spreadsheet the Manager
 * already keeps goes out and comes back in.
 *
 * Every change posts to api/v1/pricing.php, which re-checks the pricing.*
 * permissions on the server and writes the history in the same transaction.
 * Built in milestone M2. See docs/PRD.md Section 6.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('pricing.view');

$categories = Database::all(
    'SELECT c.id, c.name, c.slug, c.sort_order,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
       FROM product_categories c
      WHERE c.is_active = 1
      ORDER BY c.sort_order, c.name'
);

$products = Database::all(
    'SELECT p.id, p.name, p.sku, p.current_price_subunit, p.category_id,
            c.name AS category_name, u.symbol AS unit,
            (SELECT h.effective_from FROM product_price_history h
              WHERE h.product_id = p.id ORDER BY h.effective_from DESC, h.id DESC LIMIT 1) AS last_changed_at
       FROM products p
       JOIN product_categories c ON c.id = p.category_id
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1
      ORDER BY c.sort_order, p.name'
);

// Group by category so the table reads the way the Manager thinks about produce.
$byCategory = [];
foreach ($products as $product) {
    $byCategory[(int) $product['category_id']][] = $product;
}

$unpriced = count(array_filter($products, static fn($p) => (int) $p['current_price_subunit'] <= 0));

$canEdit   = Rbac::can('pricing.update');
$canImport = Rbac::can('pricing.import');
$canExport = Rbac::can('pricing.export');

$okv_admin_title = 'Pricing';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <!-- What this screen is, and the two bulk tools -->
    <div class="okv-card">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-xl text-ink">This week's prices</h2>
          <p class="text-sm text-ink-60 mt-1 max-w-xl">
            Change a price by typing over it. Every change is recorded with who made it and when, so nothing is ever lost.
            <?= count($products) ?> active <?= count($products) === 1 ? 'product' : 'products' ?><?= $unpriced > 0 ? ', ' . $unpriced . ' still without a price' : '' ?>.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php if ($canExport): ?>
            <a href="/api/v1/pricing.php?action=export" class="okv-btn-outline px-4">Download price list</a>
          <?php endif; ?>
          <?php if ($canEdit): ?>
            <button type="button" class="okv-btn-outline px-4" data-bulk-open>Move a whole category</button>
          <?php endif; ?>
          <?php if ($canImport): ?>
            <button type="button" class="okv-btn px-4" data-import-open>Import a price sheet</button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Move a whole category ------------------------------------------------>
    <?php if ($canEdit): ?>
    <section class="okv-card" data-bulk-panel hidden>
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-lg text-ink">Move a whole category</h2>
          <p class="text-sm text-ink-60 mt-1">See exactly what would change before anything is saved. If one product cannot take the move, none of them do.</p>
        </div>
        <button type="button" class="okv-btn-text px-2" data-bulk-close>Close</button>
      </div>

      <form action="/api/v1/pricing.php" method="POST" class="mt-4 grid gap-4 sm:grid-cols-4" data-bulk-form>
        <?= Csrf::field() ?>
        <div>
          <label for="bulk-category" class="okv-label">Category</label>
          <select id="bulk-category" name="category_id" class="okv-input" required>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= okv_e($category['name']) ?> (<?= (int) $category['product_count'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="bulk-mode" class="okv-label">Change by</label>
          <select id="bulk-mode" name="mode" class="okv-input">
            <option value="percent">A percentage</option>
            <option value="flat">A flat amount</option>
          </select>
        </div>
        <div>
          <label for="bulk-amount" class="okv-label"><span data-bulk-amount-label>Percentage</span></label>
          <input id="bulk-amount" name="amount" type="text" inputmode="decimal" class="okv-input" placeholder="10" required>
          <p class="text-xs text-ink-40 mt-1" data-bulk-hint>10 raises by a tenth. Use -10 to drop it.</p>
        </div>
        <div>
          <label for="bulk-reason" class="okv-label">Why</label>
          <input id="bulk-reason" name="reason" type="text" class="okv-input" placeholder="Supplier raised peppers" required>
          <p class="text-xs text-ink-40 mt-1">This goes into the history.</p>
        </div>
        <div class="sm:col-span-4 flex flex-wrap gap-2">
          <button type="submit" class="okv-btn-outline px-4" data-bulk-preview>Show me what changes</button>
          <button type="button" class="okv-btn px-4" data-bulk-apply hidden>Apply these prices</button>
        </div>
      </form>

      <div data-bulk-result class="mt-4" hidden></div>
    </section>
    <?php endif; ?>

    <!-- Import a price sheet -------------------------------------------------->
    <?php if ($canImport): ?>
    <section class="okv-card" data-import-panel hidden>
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 class="font-display font-extrabold text-lg text-ink">Import a price sheet</h2>
          <p class="text-sm text-ink-60 mt-1 max-w-2xl">
            Send back the sheet you downloaded, or any sheet with a SKU column and a price column. We read it and show you
            what would change. Nothing is saved until you say so. A row with an empty price is left alone, and a SKU we do
            not recognise is reported rather than added.
          </p>
        </div>
        <button type="button" class="okv-btn-text px-2" data-import-close>Close</button>
      </div>

      <form action="/api/v1/pricing.php" method="POST" enctype="multipart/form-data" class="mt-4 grid gap-4 sm:grid-cols-3" data-import-form>
        <?= Csrf::field() ?>
        <div class="sm:col-span-2">
          <label for="import-sheet" class="okv-label">Spreadsheet</label>
          <input id="import-sheet" name="sheet" type="file" accept=".xlsx,.xls,.csv" class="okv-input" required>
          <p class="text-xs text-ink-40 mt-1">An .xlsx or a .csv, under 4MB.</p>
        </div>
        <div class="flex items-end">
          <button type="submit" class="okv-btn-outline w-full px-4">Check this sheet</button>
        </div>
      </form>

      <div data-import-result class="mt-4" hidden></div>
    </section>
    <?php endif; ?>

    <!-- The pricing table ----------------------------------------------------->
    <?php foreach ($categories as $category):
        $rows = $byCategory[(int) $category['id']] ?? [];
        if (!$rows) { continue; }
    ?>
      <section class="okv-card">
        <div class="flex items-baseline justify-between gap-4">
          <h2 class="font-display font-extrabold text-lg text-ink"><?= okv_e($category['name']) ?></h2>
          <p class="text-sm text-ink-60"><?= count($rows) ?> <?= count($rows) === 1 ? 'product' : 'products' ?></p>
        </div>

        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-sm">
            <caption class="sr-only"><?= okv_e($category['name']) ?> prices, editable</caption>
            <thead>
              <tr class="text-left text-ink-60 border-b border-mist">
                <th scope="col" class="py-2 pr-4 font-medium">Product</th>
                <th scope="col" class="py-2 pr-4 font-medium hidden sm:table-cell">SKU</th>
                <th scope="col" class="py-2 pr-4 font-medium">This week</th>
                <th scope="col" class="py-2 pr-4 font-medium hidden md:table-cell">Last changed</th>
                <th scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $product):
                  $id = (int) $product['id'];
                  $price = (int) $product['current_price_subunit'];
              ?>
                <tr class="border-b border-mist last:border-0" data-price-row data-product-id="<?= $id ?>">
                  <td class="py-3 pr-4">
                    <a href="/admin/products.php?product=<?= $id ?>" class="font-medium text-ink hover:text-forest"><?= okv_e($product['name']) ?></a>
                    <p class="text-xs text-ink-40">per <?= okv_e($product['unit']) ?></p>
                  </td>
                  <td class="py-3 pr-4 hidden sm:table-cell font-mono text-xs text-ink-60"><?= okv_e($product['sku']) ?></td>
                  <td class="py-3 pr-4">
                    <?php if ($canEdit): ?>
                      <form action="/api/v1/pricing.php" method="POST" class="flex items-center gap-2" data-price-form>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="set_price">
                        <input type="hidden" name="product_id" value="<?= $id ?>">
                        <label class="sr-only" for="price-<?= $id ?>">Price for <?= okv_e($product['name']) ?>, in naira</label>
                        <div class="flex items-center gap-1">
                          <span aria-hidden="true" class="text-ink-60">₦</span>
                          <input id="price-<?= $id ?>" name="price" type="text" inputmode="decimal"
                                 class="okv-input w-28 font-mono"
                                 value="<?= $price > 0 ? okv_e(number_format($price / 100, 0, '.', '')) : '' ?>"
                                 placeholder="Not priced"
                                 data-price-input data-original="<?= $price > 0 ? okv_e(number_format($price / 100, 0, '.', '')) : '' ?>">
                        </div>
                        <button type="submit" class="okv-btn-outline px-3 text-xs" data-price-save hidden>Save</button>
                      </form>
                    <?php else: ?>
                      <span class="font-mono"><?= $price > 0 ? okv_e(Money::format($price)) : '<span class="text-ink-40">Not priced</span>' ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3 pr-4 hidden md:table-cell text-xs text-ink-40">
                    <?= $product['last_changed_at'] ? okv_e(date('j M Y', strtotime((string) $product['last_changed_at']))) : 'Never' ?>
                  </td>
                  <td class="py-3 text-right">
                    <button type="button" class="okv-btn-text text-xs px-2" data-history-open data-product-id="<?= $id ?>" data-product-name="<?= okv_e($product['name']) ?>">History</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if (!$products): ?>
      <div class="okv-card">
        <p class="text-ink-60">There are no active products to price yet. <a href="/admin/products.php" class="text-forest underline underline-offset-2">Add one first</a>.</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- Price history, opened per product -->
  <div id="price-history" class="fixed inset-0 z-40 bg-ink/40 p-4 flex items-end sm:items-center justify-center" hidden data-history-panel>
    <section class="bg-white rounded-lg shadow-okv-3 w-full max-w-xl max-h-[80vh] overflow-y-auto p-6" role="dialog" aria-modal="true" aria-labelledby="price-history-title">
      <div class="flex items-start justify-between gap-4">
        <h2 id="price-history-title" class="font-display font-extrabold text-lg text-ink">Price history</h2>
        <button type="button" class="okv-btn-text px-2" data-history-close>Close</button>
      </div>
      <div class="mt-4" data-history-body></div>
    </section>
  </div>
<?php
$okv_admin_script = '/assets/js/admin-pricing.js';
require __DIR__ . '/../includes/components/admin/footer.php';
