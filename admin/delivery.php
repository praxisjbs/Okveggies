<?php
/**
 * admin/delivery.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery settings: which days each customer type receives on,
 * the cutoff and lead for each, dated exceptions, and which Lagos zones are
 * live. These drive the checkout delivery picker (Section 13, 9.4).
 *
 * The delivery-day manifest and the printable packing list are M6 and are not
 * built here. Every write posts to api/v1/delivery.php, which re-checks the
 * permission on the server.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('delivery.view');

$days = Database::all('SELECT * FROM allowed_delivery_days ORDER BY customer_type, day_of_week');
$zones = Database::all('SELECT * FROM delivery_zones ORDER BY sort_order, name');
$exceptions = Database::all('SELECT * FROM delivery_date_exceptions ORDER BY exception_date DESC LIMIT 20');

$isoDays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$okv_admin_title = 'Delivery';
$okv_admin_note  = 'Delivery days, the cutoff and lead, the Lagos zones and dated exceptions. The packing manifest is M6.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-8">
  <?php if (okv_input('delivery', '') === 'updated'): ?>
    <p class="rounded-xl border border-foliage bg-foliage-tint px-4 py-3 text-sm text-ink" role="status">Delivery settings updated.</p>
  <?php endif; ?>

  <section class="okv-card">
    <h2 class="font-display text-xl font-bold text-ink">Delivery days</h2>
    <p class="mt-1 text-sm text-ink-60">Turn a day on or off, and set the cutoff time and the minimum lead days for each customer type.</p>
    <div class="mt-4 space-y-3">
      <?php foreach ($days as $day): ?>
        <form method="post" action="/api/v1/delivery.php" class="grid items-center gap-3 border-t border-mist pt-3 sm:grid-cols-6">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="set_day">
          <input type="hidden" name="customer_type" value="<?= okv_e($day['customer_type']) ?>">
          <input type="hidden" name="day_of_week" value="<?= (int) $day['day_of_week'] ?>">
          <span class="text-sm font-semibold text-ink">
            <?= okv_e(ucfirst((string) $day['customer_type'])) ?>, <?= okv_e($isoDays[(int) $day['day_of_week']] ?? (string) $day['day_of_week']) ?>
          </span>
          <label class="flex items-center gap-2 text-sm text-ink-60">On
            <input type="checkbox" name="is_active" value="1" <?= $day['is_active'] ? 'checked' : '' ?>>
          </label>
          <label class="text-sm text-ink-60">Cutoff
            <input class="okv-input mt-1" name="cutoff_time" value="<?= okv_e(substr((string) $day['cutoff_time'], 0, 5)) ?>">
          </label>
          <label class="text-sm text-ink-60">Lead days
            <input class="okv-input mt-1" name="minimum_lead_days" inputmode="numeric" value="<?= (int) $day['minimum_lead_days'] ?>">
          </label>
          <button class="okv-btn-outline px-3">Save</button>
        </form>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="okv-card">
    <h2 class="font-display text-xl font-bold text-ink">Dated exceptions</h2>
    <p class="mt-1 text-sm text-ink-60">Close a day that is normally open (a public holiday), or open one that is normally closed.</p>
    <form method="post" action="/api/v1/delivery.php" class="mt-4 grid gap-3 sm:grid-cols-5">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="save_exception">
      <input class="okv-input" type="date" name="exception_date" required aria-label="Exception date">
      <label class="flex items-center gap-2 text-sm text-ink-60">Open this day
        <input type="checkbox" name="is_available" value="1">
      </label>
      <input class="okv-input" name="reason" placeholder="Reason" aria-label="Reason">
      <input class="okv-input" type="date" name="replacement_date" aria-label="Replacement date">
      <button class="okv-btn px-3">Save date</button>
    </form>
    <?php if ($exceptions): ?>
      <ul class="mt-4 space-y-1 text-sm text-ink-60">
        <?php foreach ($exceptions as $exception): ?>
          <li>
            <?= okv_e((string) $exception['exception_date']) ?>:
            <?= $exception['is_available'] ? 'Open' : 'Blocked' ?><?= trim((string) $exception['reason']) !== '' ? '. ' . okv_e($exception['reason']) : '' ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="okv-card">
    <h2 class="font-display text-xl font-bold text-ink">Lagos zones</h2>
    <p class="mt-1 text-sm text-ink-60">Only active zones appear in the checkout area picker.</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <?php foreach ($zones as $zone): ?>
        <form method="post" action="/api/v1/delivery.php" class="flex items-center justify-between gap-3 border-t border-mist pt-3">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="set_zone_active">
          <input type="hidden" name="zone_id" value="<?= (int) $zone['id'] ?>">
          <span class="text-sm text-ink"><?= okv_e($zone['name']) ?></span>
          <label class="flex items-center gap-2 text-sm text-ink-60">Active
            <input type="checkbox" name="is_active" value="1" <?= $zone['is_active'] ? 'checked' : '' ?>>
          </label>
          <button class="okv-btn-outline px-3">Save</button>
        </form>
      <?php endforeach; ?>
    </div>
  </section>
</div>
<?php require __DIR__ . '/../includes/components/admin/footer.php';
