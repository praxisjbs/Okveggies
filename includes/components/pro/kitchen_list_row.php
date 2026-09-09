<?php
/** One editable line in a saved Kitchen List. */
$row = $row ?? 0;
$line = $line ?? [];
$listProducts = $listProducts ?? [];
$listUnits = $listUnits ?? [];
?>
<div class="rounded-lg border border-mist p-3" data-kl-row>
  <div class="grid gap-3 sm:grid-cols-12">
    <div class="sm:col-span-5">
      <label class="okv-label" for="kl-product-<?= (int) $row ?>">From the shop, optional</label>
      <select class="okv-input" id="kl-product-<?= (int) $row ?>" name="items[<?= (int) $row ?>][product_id]" data-kl-product>
        <option value="">Free-text item</option>
        <?php foreach ($listProducts as $product): ?>
          <option value="<?= (int) $product['id'] ?>"
                  data-name="<?= okv_e((string) $product['name']) ?>"
                  data-unit="<?= (int) $product['unit_id'] ?>"
                  <?= (int) ($line['product_id'] ?? 0) === (int) $product['id'] ? 'selected' : '' ?>>
            <?= okv_e((string) $product['name']) ?>, per <?= okv_e((string) $product['unit_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-7">
      <label class="okv-label" for="kl-name-<?= (int) $row ?>">Item</label>
      <input class="okv-input" id="kl-name-<?= (int) $row ?>" name="items[<?= (int) $row ?>][item_name]"
             maxlength="200" value="<?= okv_e((string) ($line['item_name'] ?? '')) ?>" placeholder="Pomo" data-kl-name>
    </div>
    <div class="sm:col-span-3">
      <label class="okv-label" for="kl-qty-<?= (int) $row ?>">How much</label>
      <input class="okv-input" id="kl-qty-<?= (int) $row ?>" name="items[<?= (int) $row ?>][quantity]"
             inputmode="decimal" value="<?= okv_e((string) ($line['quantity'] ?? '')) ?>" placeholder="10">
    </div>
    <div class="sm:col-span-4">
      <label class="okv-label" for="kl-unit-<?= (int) $row ?>">Unit</label>
      <select class="okv-input" id="kl-unit-<?= (int) $row ?>" name="items[<?= (int) $row ?>][unit_id]" data-kl-unit>
        <option value="">Choose</option>
        <?php foreach ($listUnits as $unit): ?>
          <option value="<?= (int) $unit['id'] ?>" <?= (int) ($line['unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>><?= okv_e((string) $unit['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-5">
      <label class="okv-label" for="kl-note-<?= (int) $row ?>">Item note, optional</label>
      <input class="okv-input" id="kl-note-<?= (int) $row ?>" name="items[<?= (int) $row ?>][note]"
             maxlength="255" value="<?= okv_e((string) ($line['note'] ?? '')) ?>" placeholder="Firm, not very ripe">
    </div>
  </div>
  <div class="mt-2 flex justify-end">
    <button type="button" class="okv-btn-text text-sm" data-kl-remove>Remove this item</button>
  </div>
</div>
