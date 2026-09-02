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

$recent = Database::all(
    'SELECT t.id, t.reference, t.provider, t.status, t.amount_subunit, t.channel,
            t.paid_at, t.created_at, p.payment_type, o.id AS order_id, o.order_number
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

$okv_admin_title = 'Payments';
$okv_admin_note  = 'The queue waiting on you, recording money that arrived outside Paystack, and every transaction so far.';
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

  <!-- 3. Everything that has happened. -->
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
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
