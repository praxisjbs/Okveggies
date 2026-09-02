<?php
/**
 * admin/payments.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Payments (PRD Section 11).
 *
 * The screen leads with the queue, because clearing it is the only part of this
 * page that is time sensitive: a proof nobody has looked at and a reversal
 * nobody has decided are both money sitting in limbo. Recording and lookup come
 * after it.
 *
 * Recording is one step and credits the order immediately, so the form carries
 * a confirmation the recorder has to tick, and the outstanding balance is shown
 * beside the amount so the figure being entered has something to check against.
 * The amount is pre-filled with the outstanding balance, which is what it
 * almost always is.
 *
 * Every write posts to api/v1/payments.php, which re-checks the permission on
 * the server. The gates below are UX only.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('payments.view');

$canRecord         = Rbac::can('payments.record');
$canReview         = Rbac::can('payments.proof.review');
$canRequestReversal = Rbac::can('payments.reversal.request');
$canApproveReversal = Rbac::can('payments.reversal.approve');

$pendingProofs = Database::all(
    'SELECT mp.id AS proof_id, mp.method, mp.amount_subunit, mp.bank_reference,
            mp.payer_name, mp.proof_url, mp.created_at,
            t.id AS txn_id, t.reference,
            p.payment_type, o.id AS order_id, o.order_number,
            u.first_name AS recorded_by_name
       FROM manual_payment_proofs mp
       JOIN payment_transactions t ON t.id = mp.payment_transaction_id
       JOIN payments p ON p.id = t.payment_id
       JOIN orders o ON o.id = p.order_id
       LEFT JOIN users u ON u.id = mp.recorded_by
      WHERE mp.status = :status
      ORDER BY mp.created_at
      LIMIT 50',
    [':status' => ManualPayments::PROOF_PENDING]
);

$openReversals = Database::all(
    'SELECT r.id AS reversal_id, r.amount_subunit, r.reason, r.requested_at, r.requested_by,
            t.reference, o.id AS order_id, o.order_number,
            u.first_name AS requested_by_name
       FROM payment_reversals r
       JOIN payment_transactions t ON t.id = r.payment_transaction_id
       JOIN payments p ON p.id = r.payment_id
       JOIN orders o ON o.id = p.order_id
       LEFT JOIN users u ON u.id = r.requested_by
      WHERE r.status = :status
      ORDER BY r.requested_at
      LIMIT 50',
    [':status' => ManualPayments::REVERSAL_REQUESTED]
);

// Lookup: find an order by its number, then show what it still owes.
$search      = trim((string) okv_input('order', ''));
$foundOrder  = null;
$orderPayments = [];
if ($search !== '') {
    $foundOrder = Database::one(
        'SELECT id, order_number, order_total_subunit, amount_paid_subunit,
                balance_due_subunit, payment_status, customer_type
           FROM orders WHERE order_number = :n',
        [':n' => $search]
    );
    if ($foundOrder) {
        $orderPayments = Database::all(
            'SELECT id, payment_number, payment_type, provider, expected_amount_subunit,
                    paid_amount_subunit, status, due_at
               FROM payments WHERE order_id = :id ORDER BY id',
            [':id' => (int) $foundOrder['id']]
        );
    }
}

$canRefund = Rbac::can('payments.refund');
$refundsStuck = $canRefund ? Refunds::needingAttention() : [];

// Reconciliation, from our own ledger. What we took, what Paystack kept as its
// fee, what went back out, and what should therefore reach the bank. Computed
// from the transactions we recorded rather than from Paystack's settlement
// records, so it is available the moment a payment lands rather than after a
// settlement run.
$reconciliation = Database::all(
    'SELECT DATE(t.paid_at) AS day,
            COUNT(*) AS payments,
            COALESCE(SUM(t.requested_amount_subunit), 0) AS gross_subunit,
            COALESCE(SUM(t.provider_fee_subunit), 0) AS fees_subunit
       FROM payment_transactions t
      WHERE t.status IN (\'success\', \'part_refunded\', \'refunded\')
        AND t.paid_at IS NOT NULL
        AND t.paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      GROUP BY DATE(t.paid_at)
      ORDER BY day DESC'
);

$refundsByDay = [];
foreach (Database::all(
    'SELECT DATE(refunded_at) AS day, COALESCE(SUM(amount_subunit), 0) AS refunded_subunit
       FROM refunds
      WHERE status = :processed AND refunded_at IS NOT NULL
        AND refunded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      GROUP BY DATE(refunded_at)',
    [':processed' => Refunds::STATUS_PROCESSED]
) as $row) {
    $refundsByDay[(string) $row['day']] = (int) $row['refunded_subunit'];
}

$recent = Database::all(
    'SELECT t.id, t.reference, t.provider, t.status, t.amount_subunit, t.channel,
            t.paid_at, t.created_at, p.payment_type, o.id AS order_id, o.order_number,
            COALESCE((SELECT SUM(r.amount_subunit) FROM refunds r
                       WHERE r.payment_transaction_id = t.id AND r.status <> \'failed\'), 0) AS refunded_subunit
       FROM payment_transactions t
       JOIN payments p ON p.id = t.payment_id
       JOIN orders o ON o.id = p.order_id
      ORDER BY t.id DESC
      LIMIT 20'
);

/** The badge tone for a transaction status. Colour is never the only signal. */
function okv_payment_badge(string $status): string
{
    switch ($status) {
        case 'success':  return 'okv-badge-available';
        case 'failed':
        case 'reversed': return 'okv-badge-out';
        case 'mismatch':
        case 'unknown':  return 'okv-badge-warn';
        default:         return 'okv-badge-neutral';
    }
}

$flash = (string) okv_input('payments', '');

$okv_admin_title  = 'Payments';
$okv_admin_note   = 'The queue waiting on you, recording money that arrived outside Paystack, refunds, and every transaction so far.';
$okv_admin_script = '/assets/js/admin-payments.js';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-8">

  <?php if ($flash !== ''): ?>
    <?php
      $messages = [
        'recorded'           => 'Payment recorded and the order credited.',
        'reviewed'           => 'Proof reviewed.',
        'reversal_requested' => 'Reversal requested. Someone else has to approve it.',
        'reversal_approved'  => 'Reversal approved. The money has come off the order.',
        'reversal_declined'  => 'Reversal declined. The payment stands.',
      ];
    ?>
    <p class="rounded-xl border border-foliage bg-foliage-tint px-4 py-3 text-sm text-ink" role="status">
      <?= okv_e($messages[$flash] ?? 'Done.') ?>
    </p>
  <?php endif; ?>

  <!-- 1. The queue. Everything here is money in limbo. -->
  <section class="okv-card" aria-labelledby="queue-heading">
    <h2 id="queue-heading" class="font-display text-xl font-bold text-ink">Waiting on you</h2>
    <p class="mt-1 text-sm text-ink-60">
      Proofs nobody has checked, and reversals nobody has decided.
      <?php if (!$pendingProofs && !$openReversals): ?>
        Nothing is waiting.
      <?php endif; ?>
    </p>

    <?php if ($pendingProofs): ?>
      <h3 class="mt-5 text-sm font-semibold uppercase tracking-wide text-ink">
        Proofs to check (<?= count($pendingProofs) ?>)
      </h3>
      <div class="mt-3 space-y-3">
        <?php foreach ($pendingProofs as $proof): ?>
          <div class="rounded-lg border border-mist p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="font-semibold text-ink">
                <?= okv_e(Money::format((int) $proof['amount_subunit'])) ?>
                <span class="okv-badge okv-badge-info"><?= okv_e($proof['method']) ?></span>
              </p>
              <a class="text-sm underline" href="/admin/orders.php?order=<?= (int) $proof['order_id'] ?>">
                Order <?= okv_e($proof['order_number']) ?>
              </a>
            </div>
            <p class="mt-1 text-sm text-ink-60">
              Recorded by <?= okv_e($proof['recorded_by_name'] ?? 'a staff member') ?>
              on <?= okv_e(date('j M Y, H:i', strtotime((string) $proof['created_at']))) ?>.
              <?php if (!empty($proof['payer_name'])): ?>
                Payer: <?= okv_e($proof['payer_name']) ?>.
              <?php endif; ?>
              <?php if (!empty($proof['bank_reference'])): ?>
                Reference: <?= okv_e($proof['bank_reference']) ?>.
              <?php endif; ?>
            </p>
            <?php if (!empty($proof['proof_url'])): ?>
              <p class="mt-1 text-sm">
                <a class="underline" href="/<?= okv_e(ltrim((string) $proof['proof_url'], '/')) ?>" target="_blank" rel="noopener noreferrer">
                  View the uploaded proof<span class="sr-only">, opens in a new tab</span>
                </a>
              </p>
            <?php endif; ?>

            <?php if ($canReview): ?>
              <form action="/api/v1/payments.php" method="POST" class="mt-3 flex flex-wrap items-end gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="review_proof">
                <input type="hidden" name="proof_id" value="<?= (int) $proof['proof_id'] ?>">
                <label class="text-sm text-ink-60">Note
                  <input class="okv-input mt-1" name="note" maxlength="500" placeholder="Optional">
                </label>
                <button class="okv-btn min-h-[44px]" name="decision" value="<?= okv_e(ManualPayments::PROOF_APPROVED) ?>">Confirm</button>
                <button class="okv-btn-outline min-h-[44px]" name="decision" value="<?= okv_e(ManualPayments::PROOF_REJECTED) ?>">Question this</button>
              </form>
            <?php endif; ?>

            <?php if ($canRequestReversal): ?>
              <form action="/api/v1/payments.php" method="POST" class="mt-2 flex flex-wrap items-end gap-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="request_reversal">
                <input type="hidden" name="transaction_id" value="<?= (int) $proof['txn_id'] ?>">
                <label class="text-sm text-ink-60">Reason for reversal
                  <input class="okv-input mt-1" name="reason" maxlength="500" required placeholder="Why this should come off the order">
                </label>
                <button class="okv-btn-outline min-h-[44px]">Ask for a reversal</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($refundsStuck): ?>
      <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-ink">
        Refunds needing a look (<?= count($refundsStuck) ?>)
      </h3>
      <div class="mt-3 space-y-3">
        <?php foreach ($refundsStuck as $stuck): ?>
          <div class="rounded-lg border border-clay p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="font-semibold text-ink"><?= okv_e(Money::format((int) $stuck['amount_subunit'])) ?></p>
              <a class="text-sm underline" href="/admin/orders.php?order=<?= (int) $stuck['order_id'] ?>">
                Order <?= okv_e($stuck['order_number']) ?>
              </a>
            </div>
            <p class="mt-1 text-sm text-ink">
              <?php if ((string) $stuck['status'] === Refunds::STATUS_FAILED): ?>
                This refund did not go through. The customer has not been paid. Check Paystack, then raise it again.
              <?php else: ?>
                This refund was raised but Paystack never confirmed it. Check the Paystack dashboard before raising another,
                so the customer is not paid twice.
              <?php endif; ?>
            </p>
            <p class="mt-1 text-xs text-ink-60">Reference <?= okv_e($stuck['reference']) ?>.</p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($openReversals): ?>
      <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-ink">
        Reversals to decide (<?= count($openReversals) ?>)
      </h3>
      <div class="mt-3 space-y-3">
        <?php foreach ($openReversals as $reversal): ?>
          <div class="rounded-lg border border-mist p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="font-semibold text-ink"><?= okv_e(Money::format((int) $reversal['amount_subunit'])) ?></p>
              <a class="text-sm underline" href="/admin/orders.php?order=<?= (int) $reversal['order_id'] ?>">
                Order <?= okv_e($reversal['order_number']) ?>
              </a>
            </div>
            <p class="mt-1 text-sm text-ink-60">
              Asked for by <?= okv_e($reversal['requested_by_name'] ?? 'a staff member') ?>
              on <?= okv_e(date('j M Y, H:i', strtotime((string) $reversal['requested_at']))) ?>.
            </p>
            <p class="mt-1 text-sm text-ink">Reason: <?= okv_e($reversal['reason']) ?></p>

            <?php if ($canApproveReversal): ?>
              <?php $isMine = (int) $reversal['requested_by'] === (int) Rbac::userId(); ?>
              <?php $blocked = $isMine && !in_array('owner', Rbac::roles(), true); ?>
              <?php if ($blocked): ?>
                <p class="mt-3 rounded-md border border-mist bg-forest-tint px-3 py-2 text-sm text-ink-60">
                  You asked for this reversal, so someone else has to approve it.
                </p>
              <?php else: ?>
                <form action="/api/v1/payments.php" method="POST" class="mt-3 flex flex-wrap items-end gap-2">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="decide_reversal">
                  <input type="hidden" name="reversal_id" value="<?= (int) $reversal['reversal_id'] ?>">
                  <label class="text-sm text-ink-60">Note
                    <input class="okv-input mt-1" name="note" maxlength="500" placeholder="Optional">
                  </label>
                  <button class="okv-btn min-h-[44px]" name="decision" value="<?= okv_e(ManualPayments::REVERSAL_APPROVED) ?>">Approve the reversal</button>
                  <button class="okv-btn-outline min-h-[44px]" name="decision" value="<?= okv_e(ManualPayments::REVERSAL_DECLINED) ?>">Decline</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- 2. Record money that arrived outside Paystack. -->
  <section class="okv-card" aria-labelledby="record-heading">
    <h2 id="record-heading" class="font-display text-xl font-bold text-ink">Record a payment</h2>
    <p class="mt-1 text-sm text-ink-60">Find the order, then record the transfer or cash against what it owes. The order is credited straight away.</p>

    <form method="GET" class="mt-4 flex flex-wrap items-end gap-2">
      <label class="text-sm text-ink-60">Order number
        <input class="okv-input mt-1" name="order" value="<?= okv_e($search) ?>" placeholder="OKV26000123" required>
      </label>
      <button class="okv-btn-outline min-h-[44px]">Find the order</button>
    </form>

    <?php if ($search !== '' && !$foundOrder): ?>
      <p class="mt-4 rounded-md border border-clay bg-clay-tint px-3 py-2 text-sm text-ink" role="status">
        No order carries the number <?= okv_e($search) ?>.
      </p>
    <?php endif; ?>

    <?php if ($foundOrder): ?>
      <div class="mt-5 rounded-lg border border-mist p-4">
        <p class="font-semibold text-ink">Order <?= okv_e($foundOrder['order_number']) ?></p>
        <p class="mt-1 text-sm text-ink-60">
          Total <?= okv_e(Money::format((int) $foundOrder['order_total_subunit'])) ?>.
          Paid <?= okv_e(Money::format((int) $foundOrder['amount_paid_subunit'])) ?>.
          Outstanding <?= okv_e(Money::format((int) $foundOrder['balance_due_subunit'])) ?>.
        </p>

        <?php foreach ($orderPayments as $payment): ?>
          <?php
            $expected    = (int) $payment['expected_amount_subunit'];
            $paid        = (int) $payment['paid_amount_subunit'];
            $outstanding = Money::balance($expected, $paid);
          ?>
          <div class="mt-4 border-t border-mist pt-4">
            <p class="text-sm font-semibold text-ink">
              <?= okv_e(str_replace('_', ' ', (string) $payment['payment_type'])) ?>
              <span class="okv-badge okv-badge-neutral"><?= okv_e($payment['status']) ?></span>
            </p>
            <p class="mt-1 text-sm text-ink-60">
              Expects <?= okv_e(Money::format($expected)) ?>, holds <?= okv_e(Money::format($paid)) ?>,
              outstanding <?= okv_e(Money::format($outstanding)) ?>.
              <?php if (!empty($payment['due_at'])): ?>
                Due <?= okv_e(date('j M Y', strtotime((string) $payment['due_at']))) ?>.
              <?php endif; ?>
            </p>

            <?php if ($canRecord && $outstanding > 0): ?>
              <form action="/api/v1/payments.php" method="POST" enctype="multipart/form-data" class="mt-3 grid gap-3 md:grid-cols-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="record_manual">
                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                <!-- One time token. It becomes part of the transaction reference,
                     and reference is UNIQUE, so a double submit collides in the
                     database instead of crediting the order twice. -->
                <input type="hidden" name="record_token" value="<?= okv_e(ManualPayments::newToken()) ?>">

                <label class="text-sm text-ink-60">How it arrived
                  <select class="okv-input mt-1" name="method" required>
                    <option value="transfer">Bank transfer</option>
                    <option value="cash">Cash</option>
                  </select>
                </label>

                <label class="text-sm text-ink-60">Amount received (naira)
                  <input class="okv-input mt-1" name="amount" inputmode="decimal" required
                         value="<?= okv_e(Money::format($outstanding, false, false)) ?>">
                </label>

                <label class="text-sm text-ink-60">Transaction reference
                  <input class="okv-input mt-1" name="bank_reference" maxlength="150"
                         placeholder="Required for a transfer unless you attach a screenshot">
                </label>

                <label class="text-sm text-ink-60">Who paid
                  <input class="okv-input mt-1" name="payer_name" maxlength="150" placeholder="Optional">
                </label>

                <label class="text-sm text-ink-60 md:col-span-2">Screenshot or receipt
                  <input class="okv-input mt-1" type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                </label>

                <label class="flex items-start gap-2 text-sm text-ink md:col-span-2">
                  <input type="checkbox" required class="mt-1 min-h-[20px] min-w-[20px]" name="confirmed" value="1">
                  <span>I confirm this money has been received, and that recording it credits the order now.</span>
                </label>

                <div class="md:col-span-2">
                  <button class="okv-btn min-h-[44px]">Record the payment</button>
                </div>
              </form>
            <?php elseif ($outstanding <= 0): ?>
              <p class="mt-2 text-sm text-ink-60">Nothing outstanding on this payment.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- 3. What should reach the bank. -->
  <section class="okv-card" aria-labelledby="reconcile-heading">
    <h2 id="reconcile-heading" class="font-display text-xl font-bold text-ink">Money reconciliation</h2>
    <p class="mt-1 text-sm text-ink-60">
      The last 30 days. Gross is what customers were charged for goods, fees is what Paystack kept,
      refunds is what went back out, and net is what should reach the bank.
    </p>

    <?php if (!$reconciliation): ?>
      <p class="mt-4 text-sm text-ink-60">No payment has settled yet.</p>
    <?php else: ?>
      <?php $totalGross = 0; $totalFees = 0; $totalRefunds = 0; ?>
      <div class="okv-table-wrap mt-4">
        <table class="okv-table">
          <caption class="sr-only">Payments, fees and refunds by day</caption>
          <thead>
            <tr>
              <th scope="col">Day</th>
              <th scope="col">Payments</th>
              <th scope="col">Gross</th>
              <th scope="col">Paystack fees</th>
              <th scope="col">Refunds</th>
              <th scope="col">Net</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reconciliation as $day): ?>
              <?php
                $gross   = (int) $day['gross_subunit'];
                $fees    = (int) $day['fees_subunit'];
                $back    = $refundsByDay[(string) $day['day']] ?? 0;
                $net     = $gross - $fees - $back;
                $totalGross += $gross; $totalFees += $fees; $totalRefunds += $back;
              ?>
              <tr>
                <td><?= okv_e(date('j M Y', strtotime((string) $day['day']))) ?></td>
                <td><?= (int) $day['payments'] ?></td>
                <td><?= okv_e(Money::format($gross)) ?></td>
                <td><?= okv_e(Money::format($fees)) ?></td>
                <td><?= $back > 0 ? okv_e(Money::format($back)) : '' ?></td>
                <td><?= okv_e(Money::format($net)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th scope="row">30 day total</th>
              <td></td>
              <td><?= okv_e(Money::format($totalGross)) ?></td>
              <td><?= okv_e(Money::format($totalFees)) ?></td>
              <td><?= $totalRefunds > 0 ? okv_e(Money::format($totalRefunds)) : '' ?></td>
              <td><?= okv_e(Money::format($totalGross - $totalFees - $totalRefunds)) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="mt-3 text-sm text-ink-60">
        Cross check net against what actually reached the bank. A gap means a settlement is still in
        flight, or a transaction was recorded here that Paystack never settled.
      </p>
    <?php endif; ?>
  </section>

  <!-- 4. Everything that has happened. -->
  <section class="okv-card" aria-labelledby="recent-heading">
    <h2 id="recent-heading" class="font-display text-xl font-bold text-ink">Recent payments</h2>
    <p class="mt-1 text-sm text-ink-60">The last 20 transactions, newest first.</p>

    <?php if (!$recent): ?>
      <p class="mt-4 text-sm text-ink-60">No payment has been taken yet.</p>
    <?php else: ?>
      <div class="okv-table-wrap mt-4">
        <table class="okv-table">
          <caption class="sr-only">Recent payment transactions</caption>
          <thead>
            <tr>
              <th scope="col">Order</th>
              <th scope="col">Amount</th>
              <th scope="col">How</th>
              <th scope="col">Status</th>
              <th scope="col">When</th>
              <?php if ($canRefund): ?><th scope="col">Refund</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
              <tr>
                <td>
                  <a class="underline" href="/admin/orders.php?order=<?= (int) $row['order_id'] ?>">
                    <?= okv_e($row['order_number']) ?>
                  </a>
                  <span class="okv-table-sub"><?= okv_e(str_replace('_', ' ', (string) $row['payment_type'])) ?></span>
                </td>
                <td><?= okv_e(Money::format((int) ($row['amount_subunit'] ?? 0))) ?></td>
                <td><?= okv_e($row['provider'] === 'manual' ? ($row['channel'] ?: 'manual') : 'Paystack') ?></td>
                <td>
                  <span class="okv-badge <?= okv_e(okv_payment_badge((string) $row['status'])) ?>">
                    <?= okv_e($row['status']) ?>
                  </span>
                </td>
                <td><?= okv_e(date('j M Y, H:i', strtotime((string) ($row['paid_at'] ?: $row['created_at'])))) ?></td>
                <?php if ($canRefund): ?>
                  <?php
                    $paidOnTxn   = (int) ($row['amount_subunit'] ?? 0);
                    $refundedOn  = (int) ($row['refunded_subunit'] ?? 0);
                    $refundable  = Refunds::refundableAmount($paidOnTxn, $refundedOn);
                    $isRefundable = $row['provider'] === 'paystack'
                        && $row['status'] === 'success'
                        && $refundable > 0;
                  ?>
                  <td>
                    <?php if (!$isRefundable): ?>
                      <span class="text-sm text-ink-60">
                        <?php if ($row['provider'] !== 'paystack'): ?>
                          Reverse instead
                        <?php elseif ($refundedOn > 0 && $refundable < 1): ?>
                          Fully refunded
                        <?php else: ?>
                          Not refundable
                        <?php endif; ?>
                      </span>
                    <?php else: ?>
                      <!-- A details element so this works with JavaScript off.
                           admin-payments.js upgrades it into a real modal, and
                           refreshes the figures from the server first so a page
                           left open cannot offer money that has already gone. -->
                      <details class="okv-refund" data-refund data-transaction-id="<?= (int) $row['id'] ?>">
                        <summary class="okv-btn-outline-sm inline-flex min-h-[44px] cursor-pointer items-center">Refund</summary>
                        <form action="/api/v1/payments.php" method="POST" class="mt-3 space-y-3 text-left" data-refund-form>
                          <?= Csrf::field() ?>
                          <input type="hidden" name="action" value="request_refund">
                          <input type="hidden" name="transaction_id" value="<?= (int) $row['id'] ?>">

                          <div class="rounded-md border border-mist bg-forest-tint p-3 text-sm" data-refund-summary>
                            <p class="font-semibold text-ink">Check this before you send money back</p>
                            <dl class="mt-2 space-y-1 text-ink-60">
                              <div><dt class="inline">Order:</dt> <dd class="inline text-ink"><?= okv_e($row['order_number']) ?></dd></div>
                              <div><dt class="inline">Paid on this transaction:</dt> <dd class="inline text-ink" data-refund-paid><?= okv_e(Money::format($paidOnTxn)) ?></dd></div>
                              <div><dt class="inline">Already refunded:</dt> <dd class="inline text-ink" data-refund-done><?= okv_e(Money::format($refundedOn)) ?></dd></div>
                              <div><dt class="inline">Still refundable:</dt> <dd class="inline text-ink" data-refund-left><?= okv_e(Money::format($refundable)) ?></dd></div>
                            </dl>
                            <p class="mt-2 text-ink">A refund cannot be undone. It goes back to the card or account the customer paid from.</p>
                          </div>

                          <label class="block text-sm text-ink-60">Amount to refund (naira)
                            <input class="okv-input mt-1" name="amount" inputmode="decimal" required
                                   data-refund-amount
                                   value="<?= okv_e(Money::format($refundable, false, false)) ?>">
                          </label>

                          <label class="block text-sm text-ink-60">Note for the customer
                            <input class="okv-input mt-1" name="customer_note" maxlength="255" placeholder="They may see this">
                          </label>

                          <label class="block text-sm text-ink-60">Note for our records
                            <input class="okv-input mt-1" name="merchant_note" maxlength="255" placeholder="Why we are refunding">
                          </label>

                          <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" required class="mt-1 min-h-[20px] min-w-[20px]" name="confirmed" value="1">
                            <span>I have checked the figures above and I am sending this money back now.</span>
                          </label>

                          <div class="flex flex-wrap gap-2">
                            <button class="okv-btn min-h-[44px]">Send the refund</button>
                            <button type="button" class="okv-btn-outline min-h-[44px]" data-refund-cancel hidden>Cancel</button>
                          </div>
                        </form>
                      </details>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
