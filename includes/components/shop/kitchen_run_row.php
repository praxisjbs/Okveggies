<?php
/**
 * includes/components/shop/kitchen_run_row.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One typed line on a customer's kitchen list.
 *
 * A typed line is a free-text item name with a quantity and a unit, priced by
 * us unless the customer arrived with prices already. Included in a loop by
 * kitchen-runs.php, both for the typed lists ("Type my list", "Upload list",
 * "Already priced") and for the "Anything not in the shop?" block of a
 * "Pick from shop" list, because a real kitchen list mixes the two. Cloned by
 * kitchen-runs.js when somebody presses "Add item", so a row added with
 * JavaScript is the same row the server rendered. Only one file to change.
 *
 * Expects, from the including scope:
 *   $row         int    the row index, which names the fields items[$row][...]
 *   $units       array  units of measurement
 *   $chosen      string the start mode, so the row shows only what that mode needs
 *   $prefillLine array  a saved line to prefill from, or nothing
 *
 * Money is typed in naira, never in kobo. The controller turns it into kobo
 * once, on the way in.
 * -----------------------------------------------------------------------------
 */

$row         = $row ?? 0;
$units       = $units ?? [];
$chosen      = $chosen ?? 'custom';
$prefillLine = $prefillLine ?? [];
$showPrice   = $chosen === 'priced';
?>
<div data-kr-row class="rounded-[14px] border border-ink-10 bg-white p-3">
  <div class="flex items-center justify-between gap-2">
    <label class="text-xs font-medium text-ink-60" for="kr-name-<?= (int) $row ?>" data-kr-label>Item <?= (int) $row + 1 ?></label>
    <div class="flex items-center gap-1">
      <button type="button" data-kr-up hidden class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-ink-60 hover:bg-mist" aria-label="Move item up" title="Move up">&uarr;</button>
      <button type="button" data-kr-down hidden class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-full text-ink-60 hover:bg-mist" aria-label="Move item down" title="Move down">&darr;</button>
      <button type="button" data-kr-remove class="inline-flex min-h-[44px] items-center rounded-full px-2 text-xs text-ink-60 hover:bg-mist" aria-label="Remove item">Remove</button>
    </div>
  </div>
  <input class="okv-input mt-2 min-h-[44px] rounded-xl" id="kr-name-<?= (int) $row ?>" name="items[<?= (int) $row ?>][item_name]" data-kr-name placeholder="Tomatoes, pomo, oil" maxlength="200" value="<?= okv_e((string) ($prefillLine['item_name'] ?? '')) ?>">
  <div class="mt-2 grid grid-cols-2 gap-2">
    <div>
      <label class="sr-only" for="kr-qty-<?= (int) $row ?>">How much</label>
      <input class="okv-input min-h-[44px] rounded-xl" id="kr-qty-<?= (int) $row ?>" name="items[<?= (int) $row ?>][quantity]" data-kr-qty inputmode="decimal" placeholder="How much" value="<?= okv_e(($prefillLine['quantity'] ?? null) === null ? '' : okv_quantity($prefillLine['quantity'])) ?>">
    </div>
    <div>
      <label class="sr-only" for="kr-unit-<?= (int) $row ?>">Unit</label>
      <select class="okv-input min-h-[44px] rounded-xl" id="kr-unit-<?= (int) $row ?>" name="items[<?= (int) $row ?>][unit_id]" data-kr-unit>
        <option value="">Unit</option>
        <?php foreach ($units as $unit): ?>
          <option value="<?= (int) $unit['id'] ?>" <?= (int) ($prefillLine['unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>><?= okv_e($unit['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="mt-2" data-kr-price-field <?= $showPrice ? '' : 'hidden' ?>>
    <label class="text-xs font-medium text-ink-60" for="kr-price-<?= (int) $row ?>">Your price per unit</label>
    <input class="okv-input mt-1 min-h-[44px] rounded-xl" id="kr-price-<?= (int) $row ?>" name="items[<?= (int) $row ?>][unit_price]" data-kr-price inputmode="decimal" placeholder="4,000">
  </div>
  <div class="mt-2">
    <label class="sr-only" for="kr-note-<?= (int) $row ?>">Note on this item, optional</label>
    <input class="okv-input min-h-[44px] rounded-xl" id="kr-note-<?= (int) $row ?>" name="items[<?= (int) $row ?>][note]" data-kr-note maxlength="255" placeholder="Note, optional. Firm ones please." value="<?= okv_e((string) ($prefillLine['note'] ?? '')) ?>">
  </div>
</div>
