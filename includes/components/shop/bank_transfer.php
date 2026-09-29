<?php
/**
 * includes/components/shop/bank_transfer.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The OK Veggies bank account a customer transfers to, and the
 * receipt box that goes with it (PRD Section 9.3a).
 *
 * One component, two homes: the Direct bank transfer card at checkout, and the
 * order page for a customer who still owes a receipt. The account details are
 * read from Settings by TransferProofs::bankDetails(), so the Owner changes them
 * in one place and both screens change with them.
 *
 * Progressive enhancement: with no JavaScript the details are plain text the
 * customer can read and type, and the receipt box is an ordinary file input.
 * assets/js/bank-transfer.js adds the copy buttons and the early size check.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_bank_transfer_details')) {
    /**
     * The account block: bank, account name, account number and the exact amount.
     *
     * @param array{bank_name: string, account_name: string, account_number: string} $bank
     * @param int    $amountSubunit What to send, in kobo.
     * @param string $narration     An order number to put in the bank's narration, or ''.
     */
    function okv_bank_transfer_details(array $bank, int $amountSubunit, string $narration = ''): void
    {
        $rows = [
            ['Bank', $bank['bank_name'], false, null],
            ['Account name', $bank['account_name'], false, null],
            ['Account number', $bank['account_number'], true, 'the account number'],
            ['Amount to send', Money::format($amountSubunit), true, 'the amount'],
        ];
        if ($narration !== '') {
            $rows[] = ['Narration', $narration, true, 'the narration'];
        }
        ?>
        <dl class="rounded-xl border border-ink-10 bg-white p-4">
          <?php foreach ($rows as $i => [$label, $value, $mono, $copyName]): ?>
            <div class="flex items-center justify-between gap-3 <?= $i > 0 ? 'mt-3 border-t border-ink-10 pt-3' : '' ?>">
              <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-ink-60"><?= okv_e($label) ?></dt>
                <dd class="mt-0.5 break-words <?= $mono ? 'font-mono text-lg font-semibold text-ink' : 'text-base font-semibold text-ink' ?>"><?= okv_e($value) ?></dd>
              </div>
              <?php if ($copyName !== null): ?>
                <?php
                  // The copy button copies the bare value: digits for the account
                  // number, and the amount without the naira sign or commas, so it
                  // pastes into a banking app.
                  $copyValue = $label === 'Amount to send'
                      ? (string) (intdiv($amountSubunit, 100)) . ($amountSubunit % 100 !== 0 ? '.' . str_pad((string) ($amountSubunit % 100), 2, '0', STR_PAD_LEFT) : '')
                      : $value;
                ?>
                <button type="button" class="okv-btn-outline-sm min-h-[44px] flex-none hidden" data-copy="<?= okv_e($copyValue) ?>" data-copy-name="<?= okv_e($copyName) ?>">
                  Copy<span class="sr-only"> <?= okv_e($copyName) ?></span>
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </dl>
        <p class="mt-2 text-xs text-ink-60" data-copy-status role="status" aria-live="polite"></p>
        <?php
    }
}

if (!function_exists('okv_receipt_field')) {
    /**
     * The receipt box: one file, and two optional details.
     *
     * @param string $idPrefix    Makes the ids unique when the component is used twice.
     * @param bool   $required    True at checkout, where an order needs its receipt.
     */
    function okv_receipt_field(string $idPrefix, bool $required): void
    {
        $maxMb = max(1, (int) floor(Uploads::maxBytes() / (1024 * 1024)));
        ?>
        <div class="mt-4" data-receipt-field data-max-bytes="<?= (int) Uploads::maxBytes() ?>">
          <label class="okv-label" for="<?= okv_e($idPrefix) ?>-receipt">
            Your bank receipt<?= $required ? '' : ' <span class="font-normal text-ink-60">(photo or PDF)</span>' ?>
          </label>
          <input class="okv-input w-full" type="file" id="<?= okv_e($idPrefix) ?>-receipt" name="receipt"
                 accept="image/jpeg,image/png,image/webp,application/pdf"
                 aria-describedby="<?= okv_e($idPrefix) ?>-receipt-help <?= okv_e($idPrefix) ?>-receipt-error"
                 data-receipt-input
                 <?= $required ? 'required data-receipt-required' : '' ?>>
          <p id="<?= okv_e($idPrefix) ?>-receipt-help" class="mt-1.5 text-xs text-ink-60">
            A screenshot from your banking app or a PDF receipt. JPG, PNG, WebP or PDF, up to <?= (int) $maxMb ?>MB.
          </p>
          <p id="<?= okv_e($idPrefix) ?>-receipt-error" class="mt-1.5 hidden text-sm text-tomato" role="alert" data-receipt-error></p>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
              <label class="okv-label" for="<?= okv_e($idPrefix) ?>-payer">Name on the sending account <span class="font-normal text-ink-60">(optional)</span></label>
              <input class="okv-input w-full" type="text" id="<?= okv_e($idPrefix) ?>-payer" name="payer_name" maxlength="150" autocomplete="off">
            </div>
            <div>
              <label class="okv-label" for="<?= okv_e($idPrefix) ?>-ref">Bank reference <span class="font-normal text-ink-60">(optional)</span></label>
              <input class="okv-input w-full font-mono" type="text" id="<?= okv_e($idPrefix) ?>-ref" name="bank_reference" maxlength="150" autocomplete="off">
            </div>
          </div>
        </div>
        <?php
    }
}
