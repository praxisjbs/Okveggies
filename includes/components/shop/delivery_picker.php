<?php
/**
 * includes/components/shop/delivery_picker.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The checkout delivery-day select. It offers only the days this
 * customer type may pick, already filtered for the cutoff and the lead time, so
 * a shopper never chooses a day the server will refuse.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_delivery_picker')) {
    function okv_delivery_picker(string $customerType, string $field = 'delivery_date', string $selected = ''): void
    {
        $dates = Delivery::nextEligibleDates($customerType);
        ?>
        <label class="okv-label" for="<?= okv_e($field) ?>">Delivery day</label>
        <?php if (!$dates): ?>
          <p class="rounded-md border border-mist bg-white px-4 py-3 text-sm text-ink-60">
            We have no delivery days open right now. Please check back shortly or message support.
          </p>
          <input type="hidden" name="<?= okv_e($field) ?>" value="">
        <?php else: ?>
          <select id="<?= okv_e($field) ?>" name="<?= okv_e($field) ?>" class="okv-input" required>
            <?php foreach ($dates as $date): ?>
              <option value="<?= okv_e($date['date']) ?>" <?= $selected === $date['date'] ? 'selected' : '' ?>>
                <?= okv_e(date('l jS F', strtotime($date['date']))) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="mt-2 text-sm text-ink-60">We only show days with enough time to source and pack your order.</p>
        <?php endif; ?>
        <?php
    }
}
