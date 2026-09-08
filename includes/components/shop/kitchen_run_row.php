<?php
/**
 * includes/components/shop/kitchen_run_row.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One line on a customer's kitchen list.
 *
 * Included in a loop by kitchen-runs.php, and cloned by kitchen-runs.js when
 * somebody presses "Add another item", so the row a person adds with JavaScript
 * is the same row the server rendered without it. Only one file to change.
 *
 * Expects, from the including scope:
 *   $row       int    the row index, which names the fields items[$row][...]
 *   $products  array  active products with a price, for the shop picker
 *   $units     array  units of measurement
 *   $chosen    string the start mode, so a row shows only what that mode needs
 *
 * Money is typed in naira with the ₦ in front of the field, never in kobo. The
 * controller turns it into kobo once, on the way in.
 * -----------------------------------------------------------------------------
 */

$row      = $row ?? 0;
$products = $products ?? [];
$units    = $units ?? [];
$chosen   = $chosen ?? 'custom';
$prefillLine = $prefillLine ?? [];
$isPriced = $chosen === 'priced';
?>
<div class="rounded-lg border border-mist p-3" data-kr-row>
  <div class="grid gap-3 sm:grid-cols-12">

    <?php if ($chosen === 'catalogue' || $chosen === 'priced'): ?>
      <div class="sm:col-span-5">
        <label class="okv-label" for="kr-product-<?= (int) $row ?>">From the shop</label>
        <select class="okv-input" id="kr-product-<?= (int) $row ?>" name="items[<?= (int) $row ?>][product_id]" data-kr-product>
          <option value="">Not from the shop</option>
          <?php foreach ($products as $product): ?>
            <option value="<?= (int) $product['id'] ?>" <?= (int) ($prefillLine['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>
                    data-price="<?= (int) $product['current_price_subunit'] ?>">
              <?= okv_e($product['name']) ?>,
              <?= okv_e(Money::format((int) $product['current_price_subunit'])) ?> per <?= okv_e($product['unit_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="<?= $chosen === 'catalogue' || $chosen === 'priced' ? 'sm:col-span-7' : 'sm:col-span-5' ?>">
      <label class="okv-label" for="kr-name-<?= (int) $row ?>">Item</label>
      <input class="okv-input" id="kr-name-<?= (int) $row ?>" name="items[<?= (int) $row ?>][item_name]"
             maxlength="200" placeholder="Pomo" value="<?= okv_e((string) ($prefillLine['item_name'] ?? '')) ?>" data-kr-name>
    </div>

    <div class="sm:col-span-3">
      <label class="okv-label" for="kr-qty-<?= (int) $row ?>">How much</label>
      <input class="okv-input" id="kr-qty-<?= (int) $row ?>" name="items[<?= (int) $row ?>][quantity]"
             inputmode="decimal" placeholder="10" value="<?= okv_e((string) ($prefillLine['quantity'] ?? '')) ?>" data-kr-qty>
    </div>

    <div class="sm:col-span-4">
      <label class="okv-label" for="kr-unit-<?= (int) $row ?>">Unit</label>
      <select class="okv-input" id="kr-unit-<?= (int) $row ?>" name="items[<?= (int) $row ?>][unit_id]" data-kr-unit>
        <option value="">Choose</option>
        <?php foreach ($units as $unit): ?>
          <option value="<?= (int) $unit['id'] ?>" <?= (int) ($prefillLine['unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>><?= okv_e($unit['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="sm:col-span-5" data-kr-price-field <?= $chosen === 'catalogue' ? 'hidden' : '' ?>>
      <label class="okv-label" for="kr-price-<?= (int) $row ?>">
        <?= $isPriced ? 'Your price per unit' : 'Your budget for this item' ?>
      </label>
      <div class="flex items-center gap-2">
        <span class="font-mono text-ink-60" aria-hidden="true">&#8358;</span>
        <input class="okv-input" id="kr-price-<?= (int) $row ?>" name="items[<?= (int) $row ?>][unit_price]"
               inputmode="decimal" placeholder="4,000" data-kr-price>
      </div>
    </div>

  </div>

  <div class="mt-3">
    <label class="okv-label" for="kr-note-<?= (int) $row ?>">Item note, optional</label>
    <input class="okv-input" id="kr-note-<?= (int) $row ?>" name="items[<?= (int) $row ?>][note]"
           maxlength="255" placeholder="Firm, not very ripe" value="<?= okv_e((string) ($prefillLine['note'] ?? '')) ?>">
  </div>

  <div class="mt-2 flex justify-end">
    <button type="button" class="okv-btn-text text-sm min-h-[44px]" data-kr-remove hidden>Remove this item</button>
  </div>
</div>
