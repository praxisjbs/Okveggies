<?php
/**
 * includes/components/shop/delivery_policy_table.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery Policy as a table with icons, not a wall of prose. The
 * published CMS body stays in a Read sheet so legal copy is never dropped.
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_delivery_policy_table')) {
    /**
     * @param array<string,string> $copy the page's slots from ContentSlots::copy('delivery-policy', ...)
     */
    function okv_delivery_policy_table(string $bodyHtml, array $copy): void
    {
        // The days and who they are for are one "day | who" line each, edited as a
        // slot. The icon is the design: it follows the row's position.
        $icons = ['calendar', 'handshake', 'basket', 'leaf', 'handshake', 'basket'];
        $rows = ContentSlots::rows($copy['delivery_rows']);
        ?>
        <div class="mt-8 overflow-hidden rounded-xl border border-ink-10 bg-white">
          <table class="okv-table">
            <caption class="sr-only"><?= okv_e($copy['table_who_heading']) ?></caption>
            <thead>
              <tr>
                <th scope="col"><?= okv_e($copy['table_day_heading']) ?></th>
                <th scope="col"><?= okv_e($copy['table_who_heading']) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $index => [$day, $who]): ?>
                <tr>
                  <td>
                    <span class="inline-flex min-h-[44px] items-center gap-2 font-semibold text-ink">
                      <span class="text-forest"><?php okv_icon($icons[$index % count($icons)], 'h-5 w-5'); ?></span>
                      <?= okv_e($day) ?>
                    </span>
                  </td>
                  <td><?= okv_e($who) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="mt-4 text-sm text-ink-60"><?= okv_e($copy['delivery_note']) ?></p>
        <p class="mt-2">
          <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="delivery-body" aria-haspopup="dialog">
            <?php okv_icon('info', 'h-4 w-4'); ?> <?= okv_e($copy['read_label']) ?>
          </button>
        </p>
        <div class="okv-sheet-backdrop" id="delivery-body" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="delivery-body-title" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <span class="text-forest"><?php okv_icon('calendar', 'h-20 w-20'); ?></span>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <h2 id="delivery-body-title" class="mt-4 font-editorial text-okv-h5 text-ink"><?= okv_e($copy['sheet_title']) ?></h2>
            <div class="mt-3 max-w-prose text-sm leading-6 text-ink-60" data-content-body><?= $bodyHtml ?></div>
          </section>
        </div>
        <?php
    }
}
