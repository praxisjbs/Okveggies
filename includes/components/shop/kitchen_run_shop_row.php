<?php
/**
 * includes/components/shop/kitchen_run_shop_row.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One shop-picked line on a customer's kitchen list.
 *
 * The "Pick from shop" row: one dropdown of the shop's products with their
 * prices, one quantity, and the line total adding itself up as the customer
 * types. No name is typed, because the item is the product, and no unit is
 * chosen, because the unit comes with the product.
 *
 * The price in the option's data-price attribute is for the live total on the
 * screen only. The server re-reads the price from the products table when the
 * list arrives and never stores what a form posted against a shop item, so a
 * customer cannot quote themselves a discount.
 *
 * Included in a loop by kitchen-runs.php and cloned by kitchen-runs.js when
 * somebody presses "Add a line", so a row added with JavaScript is the same
 * row the server rendered. Only one file to change.
 *
 * Expects, from the including scope:
 *   $row         int    the row index, which names the fields items[$row][...]
 *   $products    array  active products with a price, for the picker
 *   $productById array  the same products keyed by id, to prefill from a saved list
 *   $prefillLine array  a saved line to prefill from, or nothing
 * -----------------------------------------------------------------------------
 */

$row         = $row ?? 0;
$products    = $products ?? [];
$productById = $productById ?? [];
$prefillLine = $prefillLine ?? [];
$selectedId  = (int) ($prefillLine['product_id'] ?? 0);
$selected    = $selectedId > 0 ? ($productById[$selectedId] ?? null) : null;
$qty         = ($prefillLine['quantity'] ?? null) === null ? '' : okv_quantity($prefillLine['quantity']);
$lineTotal   = null;
if ($selected !== null && $qty !== '' && is_numeric($qty)) {
    // The same arithmetic the server will do again when the list arrives, so a
    // saved list opens showing the total it will be quoted at.
    $lineTotal = Money::lineTotal((float) $qty, (int) $selected['current_price_subunit']);
}
?>
<div data-kr-row data-kr-shop-row class="rounded-[14px] border border-ink-10 bg-white p-3">
  <div class="flex items-center justify-between gap-2">
    <label class="text-xs font-medium text-ink-60" for="kr-product-<?= (int) $row ?>" data-kr-label>Item <?= (int) $row + 1 ?></label>
    <button type="button" data-kr-remove class="inline-flex min-h-[44px] items-center rounded-full px-2 text-xs text-ink-60 hover:bg-mist" aria-label="Remove this line">Remove</button>
  </div>
  <select class="okv-input mt-2 min-h-[44px] rounded-xl" id="kr-product-<?= (int) $row ?>" name="items[<?= (int) $row ?>][product_id]" data-kr-product>
    <option value="">Pick an item</option>
    <?php foreach ($products as $product): ?>
      <option value="<?= (int) $product['id'] ?>"
              data-price="<?= (int) $product['current_price_subunit'] ?>"
              data-unit="<?= okv_e($product['unit_name']) ?>"
              <?= $selectedId === (int) $product['id'] ? 'selected' : '' ?>>
        <?= okv_e($product['name']) ?>, <?= okv_e(Money::format((int) $product['current_price_subunit'])) ?> per <?= okv_e($product['unit_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <div class="mt-2 grid grid-cols-2 gap-2">
    <div>
      <label class="sr-only" for="kr-shop-qty-<?= (int) $row ?>">How much</label>
      <input class="okv-input min-h-[44px] rounded-xl" id="kr-shop-qty-<?= (int) $row ?>" name="items[<?= (int) $row ?>][quantity]" data-kr-qty inputmode="decimal" placeholder="How much" value="<?= okv_e($qty) ?>">
    </div>
    <div class="flex min-h-[44px] items-center justify-end rounded-xl bg-mist/60 px-3">
      <span class="font-mono text-sm font-semibold text-ink" data-kr-line-total aria-label="Line total"><?= $lineTotal !== null ? okv_e(Money::format($lineTotal)) : '&#8358;0' ?></span>
    </div>
  </div>
  <p class="mt-1 min-h-[16px] text-xs text-ink-60" data-kr-price-note><?= okv_e($selected !== null ? Money::format((int) $selected['current_price_subunit']) . ' per ' . (string) $selected['unit_name'] : '') ?></p>
</div>
