<?php
/**
 * includes/components/shop/pay_sheet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Pay control for one order.
 *
 *   no methods       nothing at all, so a settled order never carries a button
 *   one method       a single button that goes straight to it (one tap to
 *                    Paystack, one tap to the credit line)
 *   several methods  a "Pay now" button that opens a slide-up sheet with one big
 *                    button per method
 *
 * The methods come from PayMethods::forOrder(), so the sheet never offers a
 * button the server would refuse. Less text, more buttons: each method is one
 * large button, and the sentence that explains it sits behind an info icon.
 * Every button is a real form post with a CSRF token, so it works with no
 * JavaScript, and data-once stops a double tap from posting twice.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_pay_form')) {
    /**
     * One method as a form with one button.
     *
     * @param array<string,mixed> $method
     */
    function okv_pay_form(array $method, string $guestToken, bool $large): void
    {
        $primary = !empty($method['primary']);
        $class = ($primary ? 'okv-btn' : 'okv-btn-outline')
            . ' inline-flex items-center justify-center gap-2 rounded-xl'
            . ($large ? ' min-h-[56px] w-full px-5' : ' min-h-[44px] px-4');
        ?>
        <form action="<?= okv_e((string) $method['endpoint']) ?>" method="POST" data-once>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="<?= okv_e((string) $method['action']) ?>">
          <?php if (isset($method['payment_id'])): ?>
            <input type="hidden" name="payment_id" value="<?= (int) $method['payment_id'] ?>">
          <?php endif; ?>
          <?php if (isset($method['order_id'])): ?>
            <input type="hidden" name="order_id" value="<?= (int) $method['order_id'] ?>">
          <?php endif; ?>
          <?php foreach ((array) ($method['fields'] ?? []) as $fieldName => $fieldValue): ?>
            <input type="hidden" name="<?= okv_e((string) $fieldName) ?>" value="<?= okv_e((string) $fieldValue) ?>">
          <?php endforeach; ?>
          <?php if ($guestToken !== ''): ?>
            <input type="hidden" name="token" value="<?= okv_e($guestToken) ?>">
          <?php endif; ?>
          <button type="submit" class="<?= $class ?>">
            <?php okv_icon((string) ($method['icon'] ?? 'card'), 'h-5 w-5'); ?>
            <span><?= okv_e((string) $method['label']) ?></span>
          </button>
        </form>
        <?php
    }
}

if (!function_exists('okv_pay_action')) {
    /**
     * @param array<string,mixed>      $order   needs id and order_number
     * @param list<array<string,mixed>> $methods from PayMethods::forOrder()
     */
    function okv_pay_action(array $order, array $methods, string $guestToken = ''): void
    {
        if ($methods === []) {
            return;
        }
        if (count($methods) === 1) {
            okv_pay_form($methods[0], $guestToken, false);
            return;
        }

        $sheetId = 'pay-sheet-' . (int) $order['id'];
        $titleId = $sheetId . '-title';
        ?>
        <button type="button" class="okv-btn inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl px-4"
                data-sheet-open="<?= okv_e($sheetId) ?>" aria-haspopup="dialog">
          <?php okv_icon('card', 'h-5 w-5'); ?> <span>Pay now</span>
        </button>

        <div class="okv-sheet-backdrop" id="<?= okv_e($sheetId) ?>" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="<?= okv_e($titleId) ?>" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <div>
                <p class="okv-eyebrow"><?= okv_e((string) ($order['order_number'] ?? '')) ?></p>
                <h2 id="<?= okv_e($titleId) ?>" class="mt-1 font-editorial text-okv-h5 text-ink">How would you like to pay?</h2>
              </div>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <ul class="mt-5 space-y-4">
              <?php foreach ($methods as $method): ?>
                <li>
                  <div class="flex items-stretch gap-2">
                    <div class="min-w-0 flex-1"><?php okv_pay_form($method, $guestToken, true); ?></div>
                    <?php if (!empty($method['info'])): ?>
                      <details class="relative">
                        <summary class="flex min-h-[56px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-xl text-forest hover:bg-forest-tint"
                                 aria-label="More about <?= okv_e(strtolower((string) $method['label'])) ?>">
                          <?php okv_icon('info', 'h-5 w-5'); ?>
                        </summary>
                        <p class="absolute right-0 top-full z-10 mt-1 w-64 rounded-md border border-mist bg-white p-3 text-sm text-ink-60 shadow-okv-2"><?= okv_e((string) $method['info']) ?></p>
                      </details>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($method['note'])): ?>
                    <p class="mt-1 px-1 text-sm text-ink-60"><?= okv_e((string) $method['note']) ?></p>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        </div>
        <?php
    }
}
