<?php
/**
 * includes/components/shop/delivery_policy_table.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery Policy as a table with icons, not a wall of prose. The
 * published CMS body stays in a Read sheet so legal copy is never dropped.
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_delivery_policy_table')) {
    function okv_delivery_policy_table(string $bodyHtml): void
    {
        $rows = [
            ['Mon', 'Household and business', 'calendar'],
            ['Tue', 'Business kitchens', 'handshake'],
            ['Wed', 'Household', 'basket'],
            ['Thu', 'Household', 'leaf'],
            ['Fri', 'Business kitchens', 'handshake'],
            ['Sat', 'Household', 'basket'],
        ];
        ?>
        <div class="mt-8 overflow-hidden rounded-xl border border-ink-10 bg-white">
          <table class="okv-table">
            <caption class="sr-only">Delivery days by customer type</caption>
            <thead>
              <tr>
                <th scope="col">Day</th>
                <th scope="col">Who we deliver to</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as [$day, $who, $icon]): ?>
                <tr>
                  <td>
                    <span class="inline-flex min-h-[44px] items-center gap-2 font-semibold text-ink">
                      <span class="text-forest"><?php okv_icon($icon, 'h-5 w-5'); ?></span>
                      <?= okv_e($day) ?>
                    </span>
                  </td>
                  <td><?= okv_e($who) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="mt-4 text-sm text-ink-60">Order by 16:00 the evening before. We source that morning.</p>
        <p class="mt-2">
          <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="delivery-body" aria-haspopup="dialog">
            <?php okv_icon('info', 'h-4 w-4'); ?> Read
          </button>
        </p>
        <div class="okv-sheet-backdrop" id="delivery-body" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="delivery-body-title" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <span class="text-forest"><?php okv_icon('calendar', 'h-20 w-20'); ?></span>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <h2 id="delivery-body-title" class="mt-4 font-editorial text-okv-h5 text-ink">Delivery policy</h2>
            <div class="mt-3 max-w-prose text-sm leading-6 text-ink-60" data-content-body><?= $bodyHtml ?></div>
          </section>
        </div>
        <?php
    }
}
