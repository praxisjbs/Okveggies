<?php
/** Permission-protected delivery-day manifest and packing list. */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('delivery.manifest.view');

$date = trim((string) okv_input('date', date('Y-m-d')));
$dateError = '';
try {
    $manifest = DeliveryManifest::forDate($date);
} catch (InvalidArgumentException $e) {
    $dateError = 'Choose a valid delivery date.';
    $date = date('Y-m-d');
    $manifest = DeliveryManifest::forDate($date);
}

$okv_admin_title = 'Day manifest';
$okv_admin_note = 'Orders to pack and deliver, grouped by the zone recorded at checkout.';
$okv_admin_crumbs = [['label' => 'Delivery', 'href' => '/admin/delivery.php']];
require __DIR__ . '/../includes/components/admin/header.php';
?>
<style>
@media print {
  @page { margin: 12mm; }
  body { background: white !important; }
  body > div > aside, body > div > div > header, .okv-page-head, [data-print-control], footer { display: none !important; }
  #okv-admin-main { padding: 0 !important; max-width: none !important; }
  .okv-manifest-zone { break-inside: avoid; box-shadow: none !important; }
  .okv-manifest-order { break-inside: avoid; }
}
</style>

<div data-print-control class="mb-6 flex flex-wrap items-end gap-3 rounded-md border border-mist bg-white p-4">
  <form method="get" class="flex flex-wrap items-end gap-3">
    <div>
      <label class="okv-label" for="manifest-date">Delivery date</label>
      <input class="okv-input mt-1" id="manifest-date" type="date" name="date" value="<?= okv_e($date) ?>" required>
    </div>
    <button class="okv-btn min-h-[44px] px-4">Show manifest</button>
  </form>
  <button type="button" class="okv-btn-outline min-h-[44px] px-4" onclick="window.print()">Print packing list</button>
</div>
<?php if ($dateError): ?><p class="okv-note-bad mb-5" role="alert"><?= okv_e($dateError) ?></p><?php endif; ?>

<header class="mb-6 border-b-2 border-forest pb-4">
  <p class="okv-eyebrow">OK Veggies delivery day</p>
  <h1 class="mt-1 font-display text-3xl font-bold text-ink"><?= okv_e(date('l jS F Y', strtotime($date))) ?></h1>
  <p class="mt-2 text-sm text-ink-60"><?= (int) $manifest['order_count'] ?> order<?= (int) $manifest['order_count'] === 1 ? '' : 's' ?> to pack and deliver.</p>
</header>

<?php if (!$manifest['zones']): ?>
  <section class="okv-card"><p class="text-sm text-ink-60">There are no non-cancelled orders scheduled for this date.</p></section>
<?php endif; ?>

<div class="space-y-7">
  <?php foreach ($manifest['zones'] as $zone): ?>
    <section class="okv-card okv-manifest-zone" aria-labelledby="zone-<?= okv_e($zone['id'] ?? 'none') ?>">
      <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-mist pb-3">
        <h2 id="zone-<?= okv_e($zone['id'] ?? 'none') ?>" class="font-display text-2xl font-bold text-ink"><?= okv_e($zone['name']) ?></h2>
        <span class="text-sm text-ink-60"><?= count($zone['orders']) ?> order<?= count($zone['orders']) === 1 ? '' : 's' ?></span>
      </div>

      <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <?php foreach ($zone['orders'] as $order): ?>
          <article class="okv-manifest-order rounded-md border border-mist p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-mono font-bold text-ink"><?= okv_e($order['order_number']) ?></h3>
                <p class="mt-1 font-semibold"><?= okv_e($order['recipient_name'] ?: 'Customer') ?></p>
              </div>
              <span class="okv-badge okv-badge-available"><?= okv_e(ucfirst((string) $order['order_status'])) ?></span>
            </div>
            <p class="mt-2 text-sm"><strong>Phone:</strong> <?= okv_e($order['recipient_phone']) ?></p>
            <p class="mt-1 text-sm"><strong>Deliver to:</strong> <?= okv_e(implode(', ', array_filter([$order['address_line_1'], $order['address_line_2'], $order['city'], $order['state']]))) ?><?= $order['landmark'] ? '. Near ' . okv_e($order['landmark']) : '' ?></p>
            <?php if ($order['delivery_window']): ?><p class="mt-1 text-sm"><strong>Window:</strong> <?= okv_e($order['delivery_window']) ?></p><?php endif; ?>
            <?php if ($order['admin_note']): ?><p class="mt-1 text-sm"><strong>Delivery note:</strong> <?= okv_e($order['admin_note']) ?></p><?php endif; ?>
            <h4 class="mt-4 text-xs font-bold uppercase tracking-wide text-ink-60">Pack</h4>
            <ul class="mt-2 divide-y divide-mist">
              <?php foreach ($order['packing_lines'] as $line): ?>
                <li class="flex justify-between gap-3 py-2 text-sm">
                  <span><?= okv_e($line['name']) ?><?= $line['from_combo'] ? ' (' . okv_e($line['from_combo']) . ')' : '' ?></span>
                  <strong class="whitespace-nowrap font-mono"><?= okv_e($line['quantity']) ?> <?= okv_e($line['unit']) ?></strong>
                </li>
              <?php endforeach; ?>
            </ul>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="mt-5 border-t border-mist pt-4">
        <h3 class="text-sm font-bold text-ink">Zone packing totals</h3>
        <ul class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($zone['packing_totals'] as $total): ?>
            <li class="flex justify-between gap-3"><span><?= okv_e($total['name']) ?></span><strong class="font-mono"><?= okv_e($total['quantity']) ?> <?= okv_e($total['unit']) ?></strong></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/components/admin/footer.php';
