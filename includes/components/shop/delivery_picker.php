<?php
/**
 * includes/components/shop/delivery_picker.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The checkout delivery-day picker.
 *
 * The days offered are the days this customer type may pick, already filtered
 * in Lagos time for the cutoff and the lead time by Delivery::nextEligibleDates,
 * so a shopper never chooses a day the server will refuse. Those rules are the
 * business; this file only dresses them.
 *
 * It renders as a real select, so checkout works with JavaScript off. With
 * JavaScript on, assets/js/checkout.js swaps the select for a large day button
 * and opens the days as a bottom sheet with 24px corners and a blurred
 * backdrop, the way a phone app picks a date. The select stays in the form
 * either way: one field name, one server contract.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_delivery_picker')) {
    function okv_delivery_picker(string $customerType, string $field = 'delivery_date', string $selected = ''): void
    {
        $dates = Delivery::nextEligibleDates($customerType);
        $label = 'delivery-day-' . okv_e($field);
        ?>
        <div data-delivery-picker>
          <label class="okv-label" for="<?= $label ?>"><?= $field === 'delivery_date' ? 'Delivery day' : okv_e(ucwords(str_replace('_', ' ', $field))) ?></label>
          <?php if (!$dates): ?>
            <p class="rounded-md border border-mist bg-white px-4 py-3 text-sm text-ink-60">
              We have no delivery days open right now. Please check back shortly or message support.
            </p>
            <input type="hidden" name="<?= okv_e($field) ?>" value="">
          <?php else: ?>
            <?php
            // The wording on the button, and on the sheet rows, is a date in
            // the shopper's own terms: weekday first, then the numeral.
            $picked = '';
            foreach ($dates as $date) {
                if ($selected === $date['date']) {
                    $picked = date('l jS F', strtotime($date['date']));
                    break;
                }
            }
            ?>
            <button type="button" class="hidden w-full border-0 bg-transparent p-0 text-left" data-picker-button aria-haspopup="dialog" aria-expanded="false">
              <span class="flex min-h-[56px] w-full items-center gap-3 rounded-xl border px-4 py-3 text-left transition duration-botanical ease-botanical<?= $selected === '' ? ' border-ink-10' : ' border-forest ring-2 ring-gold' ?>">
                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('calendar', 'h-5 w-5'); ?></span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-ink" data-picker-label><?= $picked === '' ? 'Choose your day' : okv_e($picked) ?></span>
                  <span class="block text-xs text-ink-60">We deliver around Lagos</span>
                </span>
                <?php okv_icon('arrow-right', 'h-4 w-4 flex-none text-ink-40'); ?>
              </span>
            </button>
            <select id="<?= $label ?>" name="<?= okv_e($field) ?>" class="okv-input" required data-picker-select>
              <?php foreach ($dates as $date): ?>
                <option value="<?= okv_e($date['date']) ?>" <?= $selected === $date['date'] ? 'selected' : '' ?>>
                  <?= okv_e(date('l jS F', strtotime($date['date']))) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div class="okv-sheet-backdrop" data-picker-sheet hidden>
              <section class="okv-sheet !rounded-t-sheet p-5" role="dialog" aria-modal="true" aria-label="Choose your delivery day" tabindex="-1" data-picker-panel>
                <div class="flex items-start justify-between gap-4">
                  <div>
                    <p class="okv-eyebrow">Delivery day</p>
                    <h3 class="mt-1 font-display text-xl font-bold text-ink">When should we come?</h3>
                  </div>
                  <button type="button" class="okv-btn-text min-h-[44px]" data-picker-close>Close</button>
                </div>
                <div class="mt-4 space-y-2" data-picker-options>
                  <?php foreach ($dates as $index => $date): ?>
                    <button type="button" class="okv-enter<?= $index < 5 ? ' okv-enter-' . ($index + 1) : '' ?> flex min-h-[56px] w-full items-center gap-3 rounded-xl border px-4 py-3 text-left transition duration-botanical ease-botanical<?= $selected === $date['date'] ? ' border-forest ring-2 ring-gold' : ' border-ink-10 hover:border-forest/40 active:scale-[0.98]' ?>" data-picker-option="<?= okv_e($date['date']) ?>" <?= $selected === $date['date'] ? 'aria-pressed="true"' : '' ?>>
                      <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full <?= $selected === $date['date'] ? 'bg-forest text-white' : 'bg-forest-tint text-forest' ?>"><?php okv_icon('calendar', 'h-5 w-5'); ?></span>
                      <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-ink"><?= okv_e(date('l jS F', strtotime($date['date']))) ?></span>
                        <span class="block text-xs text-ink-60">Order by <?= okv_e(date('l jS', strtotime($date['date'] . ' -1 day'))) ?> evening</span>
                      </span>
                      <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-forest text-white" data-picker-check <?= $selected === $date['date'] ? '' : 'hidden' ?>><?php okv_icon('check', 'h-4 w-4'); ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
                <p class="mt-4 text-xs text-ink-60">Only days with enough time to source and pack your order are shown.</p>
              </section>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }
}
