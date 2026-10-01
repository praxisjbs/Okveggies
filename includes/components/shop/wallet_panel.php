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
        $refunds = (array) ($view['refunds'] ?? []);
        $flag    = (string) ($view['cashout_flag'] ?? '');
        $flags   = [
            'requested'                    => ['Your request is with our team. We send it by hand and email you when it has gone.', 'ok'],
            'already_requested'            => ['That request was already sent.', 'ok'],
            'error_bad_bank'               => ['Check your bank details and try again.', 'problem'],
            'error_insufficient_balance'   => ['That is more than your wallet holds.', 'problem'],
            'error_bad_amount'             => ['Enter an amount of at least ₦1.', 'problem'],
            'error_bad_token'              => ['Reload the page and try again.', 'problem'],
        ];
        $naira = rtrim(rtrim(number_format($balance / 100, 2, '.', ''), '0'), '.');
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

        <?php if (isset($flags[$flag])): ?>
          <p class="rounded-xl border px-4 py-3 text-sm text-ink <?= $flags[$flag][1] === 'ok' ? 'border-foliage bg-foliage-tint' : 'border-clay bg-clay-tint' ?>" role="<?= $flags[$flag][1] === 'ok' ? 'status' : 'alert' ?>">
            <?= okv_e($flags[$flag][0]) ?>
          </p>
        <?php endif; ?>

        <?php if ($balance > 0 || $refunds !== []): ?>
          <section class="okv-card" aria-labelledby="wallet-cashout-h">
            <div class="flex items-start justify-between gap-3">
              <h2 id="wallet-cashout-h" class="font-editorial text-okv-h6 text-ink">Ask for it back</h2>
              <details class="relative">
                <summary class="flex min-h-[44px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-xl text-forest hover:bg-forest-tint" aria-label="More about asking for your money back">
                  <?php okv_icon('info', 'h-5 w-5'); ?>
                </summary>
                <p class="absolute right-0 top-full z-10 mt-1 w-64 rounded-md border border-mist bg-white p-3 text-sm text-ink-60 shadow-okv-2">
                  You can have your wallet sent to your bank account instead of spending it. A member of our team sends it by hand and we email you when it has gone. The money leaves your wallet as soon as you ask.
                </p>
              </details>
            </div>
            <?php if ($balance > 0): ?>
              <details class="mt-4 rounded-xl border border-forest">
                <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-center gap-2 rounded-xl px-5 font-semibold text-forest">
                  <?php okv_icon('banknote', 'h-5 w-5'); ?> <span>Send it to my bank</span>
                </summary>
                <form action="/api/v1/payments.php" method="POST" class="grid gap-3 border-t border-mist p-4" data-once>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="request_cashout">
                  <input type="hidden" name="amount_mode" value="part">
                  <input type="hidden" name="cashout_token" value="<?= okv_e(bin2hex(random_bytes(8))) ?>">
                  <div>
                    <label class="okv-label" for="co_amount">Amount in naira</label>
                    <input class="okv-input" id="co_amount" name="amount" inputmode="decimal" required value="<?= okv_e($naira) ?>">
                  </div>
                  <div>
                    <label class="okv-label" for="co_bank">Bank</label>
                    <input class="okv-input" id="co_bank" name="bank_name" required maxlength="100" autocomplete="off" placeholder="For example GTBank">
                  </div>
                  <div>
                    <label class="okv-label" for="co_number">Account number</label>
                    <input class="okv-input" id="co_number" name="account_number" required inputmode="numeric" pattern="[0-9 \-]{10,14}" maxlength="14" autocomplete="off" placeholder="10 digits">
                  </div>
                  <div>
                    <label class="okv-label" for="co_name">Name on the account</label>
                    <input class="okv-input" id="co_name" name="account_name" required maxlength="150" autocomplete="off">
                  </div>
                  <button type="submit" class="okv-btn inline-flex min-h-[56px] w-full items-center justify-center rounded-xl px-5">Ask for it back</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($refunds !== []): ?>
              <ul class="mt-4 divide-y divide-mist">
                <?php foreach ($refunds as $refund): ?>
                  <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3 text-sm">
                    <span class="text-ink">
                      <?= okv_e(Money::format((int) $refund['amount_subunit'])) ?> to <?= okv_e((string) $refund['bank_name']) ?> <?= okv_e(ManualRefunds::maskAccount((string) $refund['account_number'])) ?>
                    </span>
                    <span class="okv-badge <?= (string) $refund['status'] === 'paid' ? 'okv-badge-available' : 'okv-badge-warn' ?>">
                      <?= (string) $refund['status'] === 'paid' ? 'Sent' : 'Waiting to be sent' ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endif; ?>

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
