<?php
/**
 * includes/components/shop/delivery_picker.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The delivery pickers: the day (okv_delivery_picker) and the
 * area (okv_zone_picker). Every screen where a customer or a colleague chooses
 * a delivery day or area uses these, so the behaviour cannot drift apart.
 *
 * THE DAY PICKER
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
 *
 * THE AREA PICKER
 *
 * The active zones, their names, their "what it covers" notes and their order
 * all come from delivery_zones through Delivery::zonesActive(); nothing about
 * Lagos is written here. It renders as a real select named delivery_zone_id,
 * so the form works with JavaScript off. With JavaScript on,
 * assets/js/zone-picker.js reads the zones back out of that select and lays a
 * type-to-search combobox over it (the ARIA 1.2 editable combobox with a
 * listbox popup), matching the typed words against the name and the note.
 * The select stays in the form and is still the only thing posted: the box a
 * person types in has no name, so display text is never sent as identity.
 * The server checks the id against is_active on every write regardless.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/icons.php';

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

if (!function_exists('okv_zone_picker')) {
    /**
     * The delivery-area picker.
     *
     * @param array<int, array<string, mixed>> $zones   rows from Delivery::zonesActive(), already in order
     * @param array<string, mixed>             $options
     *   field        the posted name, default delivery_zone_id
     *   id           the select's id, default the field name
     *   label        the visible label, default "Delivery area"
     *   placeholder  the empty choice, default "Choose your area"
     *   selected     the zone id to open on, or 0. Pass it through
     *                Delivery::preferredZoneId() so it is always active.
     *   required     default true
     *   stale_name   the name of a zone that was chosen before and has since
     *                been switched off; a notice asks for another one
     *   icon         an okv_icon name drawn inside the field, or ''
     *   large        true for the 56px checkout size
     *   empty_text   what to say when no zone is active; '' when the page
 *                says it itself
     */
    function okv_zone_picker(array $zones, array $options = []): void
    {
        $field       = (string) ($options['field'] ?? 'delivery_zone_id');
        $id          = (string) ($options['id'] ?? $field);
        $label       = (string) ($options['label'] ?? 'Delivery area');
        $placeholder = (string) ($options['placeholder'] ?? 'Choose your area');
        $selected    = Delivery::zoneIdFrom($options['selected'] ?? 0);
        $required    = (bool) ($options['required'] ?? true);
        $staleName   = trim((string) ($options['stale_name'] ?? ''));
        $icon        = (string) ($options['icon'] ?? '');
        $large       = (bool) ($options['large'] ?? false);
        $emptyText   = (string) ($options['empty_text'] ?? 'No delivery area is open right now. Please message support and we will sort it out.');

        $height   = $large ? 'min-h-[56px]' : 'min-h-[48px]';
        $padLeft  = $icon !== '' ? ' pl-12' : '';
        $listId   = $id . '-listbox';
        $labelId  = $id . '-label';
        $staleId  = $id . '-stale';
        $hintId   = $id . '-hint';
        $errorId  = $id . '-error';
        $describe = $staleName !== '' ? $staleId : '';
        ?>
        <div class="okv-zone-picker" data-zone-picker data-zone-count="<?= count($zones) ?>">
          <label class="okv-label" id="<?= okv_e($labelId) ?>" for="<?= okv_e($id) ?>"><?= okv_e($label) ?></label>
          <?php if ($staleName !== ''): ?>
            <p class="mb-2 rounded-md border border-clay bg-clay-tint px-3 py-2 text-sm text-clay-ink" id="<?= okv_e($staleId) ?>" data-zone-stale>
              <?= okv_e($staleName) ?> is no longer on our delivery list. Choose another area.
            </p>
          <?php endif; ?>
          <div class="relative">
            <?php if ($icon !== ''): ?>
              <span class="pointer-events-none absolute left-4 top-1/2 z-10 -translate-y-1/2 text-forest"><?php okv_icon($icon, 'h-5 w-5'); ?></span>
            <?php endif; ?>
            <select class="okv-input <?= $height . $padLeft ?>" id="<?= okv_e($id) ?>" name="<?= okv_e($field) ?>"
                    <?= $required ? 'required' : '' ?> <?= $describe !== '' ? 'aria-describedby="' . okv_e($describe) . '"' : '' ?> data-zone-select>
              <option value=""><?= okv_e($placeholder) ?></option>
              <?php foreach ($zones as $zone): ?>
                <?php
                $zoneId = (int) ($zone['id'] ?? 0);
                $note   = trim((string) ($zone['area_note'] ?? ''));
                ?>
                <option value="<?= $zoneId ?>"<?= $note !== '' ? ' data-note="' . okv_e($note) . '"' : '' ?><?= $zoneId === $selected ? ' selected' : '' ?>><?= okv_e((string) ($zone['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>

            <?php /* The search box. Hidden until assets/js/zone-picker.js takes over, so with JavaScript off the select above is the whole picker. */ ?>
            <div data-zone-combo hidden>
              <input type="text" class="okv-input <?= $height . $padLeft ?> pr-12" id="<?= okv_e($id) ?>-search"
                     role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?= okv_e($listId) ?>"
                     aria-describedby="<?= okv_e(trim($hintId . ' ' . $describe)) ?>"
                     autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="done"
                     placeholder="<?= okv_e($placeholder) ?>" data-zone-input>
              <button type="button" class="absolute right-0 top-0 flex h-full min-h-[44px] min-w-[44px] items-center justify-center rounded-r-md text-ink-60 hover:text-forest"
                      tabindex="-1" aria-label="Show every area" aria-controls="<?= okv_e($listId) ?>" aria-expanded="false" data-zone-toggle>
                <?php okv_icon('chevron-down', 'h-5 w-5'); ?>
              </button>
              <div class="okv-zone-popup" data-zone-popup hidden>
                <ul class="py-1" id="<?= okv_e($listId) ?>" role="listbox" aria-labelledby="<?= okv_e($labelId) ?>" data-zone-list></ul>
                <p class="px-4 py-3 text-sm text-ink-60" data-zone-empty hidden></p>
              </div>
              <template data-zone-check><?php okv_icon('check', 'h-4 w-4'); ?></template>
            </div>
          </div>
          <p class="mt-1 text-xs text-ink-60" id="<?= okv_e($hintId) ?>" data-zone-hint hidden>Type part of the area name or a street it covers, then pick from the list.</p>
          <p class="mt-1 text-sm font-medium text-tomato" id="<?= okv_e($errorId) ?>" data-zone-error hidden></p>
          <p class="sr-only" role="status" aria-live="polite" aria-atomic="true" data-zone-status></p>
          <?php if (!$zones && $emptyText !== ''): ?>
            <p class="mt-1 text-sm text-tomato" data-zone-none><?= okv_e($emptyText) ?></p>
          <?php endif; ?>
        </div>
        <?php
    }
}
