<?php
/**
 * includes/components/shop/payment_summary.php
 * -----------------------------------------------------------------------------
 * OK Veggies. What has been paid on an order, in two shapes fed by the same
 * PaymentSummary::forOrder() data, so they can never disagree:
 *
 *   okv_payment_received_screen()  the "Payment received" screen a customer
 *                                  lands on after Paystack confirms, or after
 *                                  staff verify a transfer. Green when money
 *                                  has arrived, calm and clear when it is
 *                                  waiting to be checked or could not be.
 *   okv_payment_panel()            the Payments panel that lives on the order
 *                                  page for good, so an order reopened next
 *                                  week still says what was received and what
 *                                  is still due.
 *
 * State is never carried by colour alone: every state has its own icon and its
 * own words beside it (PRD Section 2, accessibility).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/icons.php';

if (!function_exists('okv_payment_state_style')) {
    /**
     * The look of one state: the card classes, the icon circle classes, the
     * icon, and the badge classes and word. Kept together so a state cannot get
     * a green card with a warning icon.
     */
    function okv_payment_state_style(string $state): array
    {
        $styles = [
            'paid'      => ['card' => 'border-foliage bg-foliage-tint', 'circle' => 'bg-forest text-white',       'icon' => 'check', 'badge' => 'okv-badge-available', 'word' => 'Paid'],
            'part_paid' => ['card' => 'border-foliage bg-foliage-tint', 'circle' => 'bg-forest text-white',       'icon' => 'check', 'badge' => 'okv-badge-warn',      'word' => 'Part paid'],
            'awaiting'  => ['card' => 'border-gold bg-gold-tint',       'circle' => 'bg-white text-gold-ink',     'icon' => 'clock', 'badge' => 'okv-badge-warn',      'word' => 'Pending verification'],
            'declined'  => ['card' => 'border-clay bg-clay-tint',       'circle' => 'bg-white text-clay-ink',     'icon' => 'info',  'badge' => 'okv-badge-out',       'word' => 'Not confirmed'],
            'unpaid'    => ['card' => 'border-mist bg-white',           'circle' => 'bg-forest-tint text-forest', 'icon' => 'banknote', 'badge' => 'okv-badge-neutral', 'word' => 'Not paid yet'],
            'cancelled' => ['card' => 'border-mist bg-white',           'circle' => 'bg-mist text-ink-60',        'icon' => 'info',  'badge' => 'okv-badge-neutral',   'word' => 'Cancelled'],
        ];
        return $styles[$state] ?? $styles['unpaid'];
    }
}

if (!function_exists('okv_payment_when')) {
    /** A payment date in the words the shop uses everywhere: Tuesday 29th September, 14:32. */
    function okv_payment_when(string $datetime): string
    {
        $ts = $datetime === '' ? false : strtotime($datetime);
        return $ts === false ? '' : date('l jS F, H:i', $ts);
    }
}

if (!function_exists('okv_payment_figures')) {
    /** The three figures every payment view leads with: total, received, still due. */
    function okv_payment_figures(array $summary): void
    {
        $due = (int) $summary['due_subunit'];
        $cancelled = $summary['state'] === 'cancelled';
        $hasReceived = (int) $summary['received_subunit'] > 0;
        // Green only when money has really arrived, and the warm tone for what is
        // owed only when the customer has something to do about it. While a
        // receipt is being checked there is nothing to do, so it stays quiet.
        $dueLoud = $due > 0 && in_array($summary['state'], ['unpaid', 'part_paid', 'declined'], true);
        ?>
        <dl class="grid gap-3 sm:grid-cols-3">
          <div class="rounded-xl border border-ink-10 bg-white p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-ink-60">Order total</dt>
            <dd class="mt-1 font-mono text-lg font-semibold text-ink"><?= okv_e(Money::format((int) $summary['total_subunit'])) ?></dd>
          </div>
          <div class="rounded-xl border <?= $hasReceived ? 'border-foliage bg-foliage-tint' : 'border-ink-10 bg-white' ?> p-4">
            <dt class="text-xs font-semibold uppercase tracking-[0.15em] <?= $hasReceived ? 'text-forest' : 'text-ink-60' ?>">Received</dt>
            <dd class="mt-1 font-mono text-lg font-semibold <?= $hasReceived ? 'text-forest' : 'text-ink' ?>"><?= okv_e(Money::format((int) $summary['received_subunit'])) ?></dd>
          </div>
          <?php if (!$cancelled): ?>
            <div class="rounded-xl border <?= $dueLoud ? 'border-clay bg-clay-tint' : 'border-ink-10 bg-white' ?> p-4">
              <dt class="text-xs font-semibold uppercase tracking-[0.15em] <?= $dueLoud ? 'text-clay-ink' : 'text-ink-60' ?>">Still due</dt>
              <dd class="mt-1 font-mono text-lg font-semibold <?= $dueLoud ? 'text-clay-ink' : 'text-ink' ?>">
                <?= okv_e(Money::format($due)) ?>
                <?php if ($due < 1): ?><span class="sr-only"> nothing is left to pay</span><?php endif; ?>
              </dd>
            </div>
          <?php endif; ?>
        </dl>
        <?php
    }
}

if (!function_exists('okv_payment_lines')) {
    /** One row per payment on the order: what it was, how, how much, and where it stands. */
    function okv_payment_lines(array $summary): void
    {
        $delivery = $summary['delivery_date'] !== '' ? date('l jS F', strtotime($summary['delivery_date'])) : '';
        ?>
        <ul class="divide-y divide-ink-10 rounded-xl border border-ink-10 bg-white" aria-label="Payments on this order">
          <?php foreach ($summary['lines'] as $line):
            $style = okv_payment_state_style((string) $line['status']);
            $due   = (int) $line['due_subunit'];
          ?>
            <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 p-4">
              <div class="min-w-0">
                <p class="font-semibold text-ink"><?= okv_e($line['label']) ?></p>
                <p class="mt-0.5 text-sm text-ink-60">
                  <?php if ((int) $line['paid_subunit'] > 0): ?>
                    <?= okv_e($line['method']) ?><?= $line['paid_at'] !== '' ? ', ' . okv_e(okv_payment_when((string) $line['paid_at'])) : '' ?>
                  <?php elseif ($line['status'] === 'awaiting'): ?>
                    Receipt with our team, being checked
                  <?php elseif ($line['status'] === 'declined'): ?>
                    Receipt not confirmed
                  <?php elseif ($line['type'] === 'pay_on_delivery' || $line['type'] === 'balance'): ?>
                    Due on delivery<?= $delivery !== '' ? ', ' . okv_e($delivery) : '' ?>
                  <?php else: ?>
                    Not paid yet
                  <?php endif; ?>
                </p>
              </div>
              <div class="text-right">
                <p class="font-mono font-semibold text-ink">
                  <?= okv_e(Money::format((int) $line['paid_subunit'])) ?>
                  <span class="text-sm font-normal text-ink-60">of <?= okv_e(Money::format((int) $line['expected_subunit'])) ?></span>
                </p>
                <p class="mt-1">
                  <span class="okv-badge <?= okv_e($style['badge']) ?>"><?php okv_icon($style['icon'], 'h-3.5 w-3.5'); ?><?= okv_e($style['word']) ?></span>
                </p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php
    }
}

if (!function_exists('okv_payment_received_screen')) {
    /**
     * The full "Payment received" screen.
     *
     * The hero is green when money has arrived, whatever else is going on: a
     * deposit received while the balance receipt is being checked is still a
     * payment received, and the pending part is said just below it. When nothing
     * has arrived yet the hero says what is actually happening instead.
     *
     * @param array $summary PaymentSummary::forOrder() data.
     * @param array $links   order_href (string|null), order_label, shop_href.
     */
    function okv_payment_received_screen(array $summary, array $links): void
    {
        $received = !empty($summary['has_received']);
        $heroState = $received ? ($summary['state'] === 'paid' ? 'paid' : 'part_paid') : (string) $summary['state'];
        $style = okv_payment_state_style($heroState);
        $last  = $summary['last_received'];
        $headline = $received ? 'Payment received' : (string) $summary['headline'];
        $due = (int) $summary['due_subunit'];
        ?>
        <section class="okv-enter rounded-2xl border <?= okv_e($style['card']) ?> p-6 text-center shadow-okv-1 sm:p-8" aria-labelledby="payment-hero-heading" data-payment-hero="<?= okv_e($heroState) ?>">
          <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full <?= okv_e($style['circle']) ?> animate-okv-pop">
            <?php okv_icon($style['icon'], 'h-8 w-8'); ?>
          </span>
          <p class="mt-4 text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Order <?= okv_e($summary['order_number']) ?></p>
          <h1 id="payment-hero-heading" class="mt-2 font-display text-3xl font-extrabold text-ink sm:text-4xl" role="status"><?= okv_e($headline) ?></h1>

          <?php if ($received && $last !== null && (int) $last['amount_subunit'] > 0): ?>
            <p class="mt-4 font-mono text-4xl font-bold text-forest sm:text-5xl"><?= okv_e(Money::format((int) $last['amount_subunit'])) ?></p>
            <p class="mt-2 text-sm text-ink-60">
              <?= okv_e($last['method']) ?><?= $last['at'] !== '' ? ', ' . okv_e(okv_payment_when((string) $last['at'])) : '' ?>
            </p>
          <?php endif; ?>

          <p class="mx-auto mt-4 max-w-xl text-ink">
            <?php if ($received && $summary['state'] === 'awaiting'): ?>
              Thank you. We have your next receipt and our team is checking it against the bank.
              <?php if ($due > 0): ?>Still due on the order: <?= okv_e(Money::format($due)) ?>.<?php endif; ?>
            <?php else: ?>
              <?= okv_e($summary['detail']) ?>
            <?php endif; ?>
          </p>
        </section>

        <div class="mt-6 space-y-6">
          <?php okv_payment_figures($summary); ?>

          <section aria-labelledby="payment-lines-heading">
            <h2 id="payment-lines-heading" class="font-display text-xl font-bold text-ink">Your payments</h2>
            <div class="mt-3"><?php okv_payment_lines($summary); ?></div>
            <?php if ((int) $summary['refunded_subunit'] > 0): ?>
              <p class="mt-3 text-sm text-ink-60">Refunded so far: <span class="font-mono"><?= okv_e(Money::format((int) $summary['refunded_subunit'])) ?></span></p>
            <?php endif; ?>
          </section>

          <?php if ($summary['state'] !== 'cancelled' && $summary['delivery_date'] !== ''): ?>
            <p class="text-sm text-ink-60">Delivery day: <?= okv_e(date('l jS F', strtotime($summary['delivery_date']))) ?>. Delivery fee is arranged and settled separately after we confirm your area.</p>
          <?php endif; ?>

          <div class="flex flex-col gap-3 sm:flex-row">
            <?php if (!empty($links['order_href'])): ?>
              <a class="okv-btn justify-center min-h-[44px]" href="<?= okv_e($links['order_href']) ?>"><?= okv_e($links['order_label'] ?? 'View your order') ?></a>
            <?php endif; ?>
            <a class="okv-btn-outline justify-center min-h-[44px]" href="<?= okv_e($links['shop_href'] ?? '/shop.php') ?>">Keep shopping</a>
          </div>
        </div>
        <?php
    }
}

if (!function_exists('okv_payment_panel')) {
    /**
     * The permanent Payments panel for the order page: the state in a word, the
     * three figures, every payment, and, when a receipt is being checked or was
     * not confirmed, what that means and what to do next.
     *
     * @param array $summary PaymentSummary::forOrder() data.
     * @param array $links   receipt_href (string|null) to the green screen.
     */
    function okv_payment_panel(array $summary, array $links = []): void
    {
        $style = okv_payment_state_style((string) $summary['state']);
        ?>
        <section class="okv-card mt-6" aria-labelledby="payments-heading" data-payment-panel="<?= okv_e($summary['state']) ?>">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="payments-heading" class="font-display text-xl font-bold text-ink">Payments</h2>
            <span class="okv-badge <?= okv_e($style['badge']) ?>"><?php okv_icon($style['icon'], 'h-3.5 w-3.5'); ?><?= okv_e($style['word']) ?></span>
          </div>
          <p class="mt-2 text-sm text-ink-60"><?= okv_e($summary['detail']) ?></p>

          <div class="mt-4"><?php okv_payment_figures($summary); ?></div>
          <div class="mt-4"><?php okv_payment_lines($summary); ?></div>

          <?php foreach ($summary['receipts'] as $receipt): ?>
            <?php if ($receipt['status'] === 'submitted'): ?>
              <p class="okv-note mt-4 bg-gold-tint text-ink">
                Receipt for <span class="font-mono"><?= okv_e(Money::format((int) $receipt['amount_subunit'])) ?></span>
                sent <?= okv_e(okv_payment_when((string) $receipt['submitted_at'])) ?>.
                It is pending verification by OK Veggies. You will get an email and a notification once it is confirmed.
              </p>
            <?php elseif ($receipt['status'] === 'declined'): ?>
              <p class="okv-note mt-4 bg-clay-tint text-ink">
                We could not confirm your receipt for <span class="font-mono"><?= okv_e(Money::format((int) $receipt['amount_subunit'])) ?></span>.
                <?php if ($receipt['note'] !== ''): ?><span class="block">Reason: <?= okv_e($receipt['note']) ?></span><?php endif; ?>
              </p>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if (!empty($summary['has_received']) && !empty($links['receipt_href'])): ?>
            <a class="okv-btn-outline mt-4 w-full justify-center min-h-[44px] sm:w-auto" href="<?= okv_e($links['receipt_href']) ?>">
              <?php okv_icon('receipt', 'h-4 w-4'); ?> Open payment receipt
            </a>
          <?php endif; ?>
        </section>
        <?php
    }
}
