<?php
/**
 * includes/components/shop/wallet_panel.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The customer's wallet, drawn once and used by every screen that
 * shows it: the storefront wallet page and the Pro portal wallet page.
 *
 * Less text, more buttons: the balance is the loudest thing on the page, the
 * explanation sits behind an info icon, and each credit carries a button to its
 * credit note. Nothing here changes any money; it only reads Wallet::view().
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_wallet_panel')) {
    /**
     * @param array{balance_subunit:int, entries:list<array<string,mixed>>} $view from Wallet::view()
     */
    function okv_wallet_panel(array $view): void
    {
        $balance = (int) $view['balance_subunit'];
        ?>
        <section class="okv-card" aria-labelledby="wallet-balance-h">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="okv-eyebrow">Wallet</p>
              <h2 id="wallet-balance-h" class="mt-1 font-editorial text-okv-h6 text-ink">What you can spend</h2>
            </div>
            <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('wallet', 'h-6 w-6'); ?></span>
          </div>
          <p class="mt-4 font-mono text-4xl font-semibold text-forest" data-wallet-balance><?= okv_e(Money::format($balance)) ?></p>
          <div class="mt-4 flex flex-wrap items-center gap-3">
            <?php if ($balance > 0): ?>
              <a href="/shop.php" class="okv-btn inline-flex min-h-[44px] items-center px-5">Spend it in the shop</a>
            <?php endif; ?>
            <details class="relative">
              <summary class="flex min-h-[44px] cursor-pointer list-none items-center gap-2 rounded-xl px-3 text-sm font-semibold text-forest hover:bg-forest-tint"
                       aria-label="More about your wallet">
                <?php okv_icon('info', 'h-5 w-5'); ?> How it works
              </summary>
              <p class="mt-2 max-w-md rounded-md border border-mist bg-white p-3 text-sm text-ink-60">
                Your wallet holds credit from OK Veggies, for example after a report is put right or an order is cancelled.
                It is offered first when you pay, and you can use part of it or all of it. You cannot add money to it yourself.
              </p>
            </details>
          </div>
        </section>

        <section class="okv-card" aria-labelledby="wallet-activity-h">
          <h2 id="wallet-activity-h" class="font-editorial text-okv-h6 text-ink">Activity</h2>
          <?php if ($view['entries'] === []): ?>
            <div class="mt-4 rounded-md bg-forest-tint p-6 text-center">
              <p class="font-medium text-ink">Nothing here yet</p>
              <p class="mx-auto mt-2 max-w-sm text-sm text-ink-60">Credit from a report that was put right, or a cancelled order, lands here with its credit note.</p>
            </div>
          <?php else: ?>
            <ul class="mt-4 divide-y divide-mist">
              <?php foreach ($view['entries'] as $entry):
                  $amount = (int) $entry['amount_subunit'];
                  $isCredit = $amount > 0;
              ?>
                <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3">
                  <div class="min-w-0">
                    <p class="font-medium text-ink"><?= okv_e((string) $entry['label']) ?></p>
                    <p class="text-sm text-ink-60">
                      <time datetime="<?= okv_e((string) $entry['created_at']) ?>"><?= okv_e(date('j M Y', strtotime((string) $entry['created_at']))) ?></time>
                      <?php if (!empty($entry['order_id']) && (string) ($entry['order_number'] ?? '') !== ''): ?>
                        . <a class="text-forest underline underline-offset-2" href="/public/order.php?order=<?= (int) $entry['order_id'] ?>">Order <?= okv_e((string) $entry['order_number']) ?></a>
                      <?php endif; ?>
                    </p>
                    <?php if (!empty($entry['credit_note_id'])): ?>
                      <a class="mt-1 inline-flex min-h-[44px] items-center gap-1 text-sm font-semibold text-forest underline underline-offset-2"
                         href="/public/documents/credit_note.php?id=<?= (int) $entry['credit_note_id'] ?>">
                        <?php okv_icon('receipt', 'h-4 w-4'); ?> Credit note <?= okv_e((string) $entry['credit_note_number']) ?>
                      </a>
                    <?php endif; ?>
                  </div>
                  <div class="text-right">
                    <p class="font-mono font-semibold <?= $isCredit ? 'text-forest' : 'text-ink' ?>"><?= $isCredit ? '+' : '-' ?><?= okv_e(Money::format(abs($amount))) ?></p>
                    <p class="text-xs text-ink-40">Balance <?= okv_e(Money::format((int) $entry['balance_after_subunit'])) ?></p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
        <?php
    }
}
