<?php
/**
 * pro/credit.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The business side of credit (PRD Section 12): apply, watch the
 * application, and once approved read the limit, the balance, what is left and
 * every entry behind it.
 *
 * Nothing here is stored twice. The limit comes from the business row, and
 * every figure beside it is calculated from credit_transactions at the moment
 * this page is read, so what the customer sees and what checkout allows cannot
 * drift apart.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pagination.php';

$userId    = (int) Customer::id();
$account   = Credit::customerAccount($userId);
$type      = trim((string) okv_input('type', ''));
$statement = Credit::statement($userId, $type, (int) okv_input('page', 1));

$application = $account['application'] ?? null;
$summary     = $account['summary'] ?? null;
$state       = (string) ($account['business']['credit_status'] ?? 'not_requested');
$errorCode   = trim((string) okv_input('error', ''));

$stateLabels = [
    'not_requested' => 'Not applied',
    'requested'     => 'Under review',
    'approved'      => 'Approved',
    'declined'      => 'Declined',
    'suspended'     => 'Suspended',
    'withdrawn'     => 'Withdrawn',
];
$transactionLabels = ['charge' => 'Charge', 'repayment' => 'Repayment', 'adjustment' => 'Adjustment'];

/** Keep the entry-type filter on every statement page link. */
$statementUrl = static function (int $page) use ($statement): string {
    $query = array_filter(
        ['type' => $statement['type'], 'page' => $page > 1 ? $page : null],
        static fn($value) => $value !== '' && $value !== null
    );
    return '/pro/credit.php' . ($query ? '?' . http_build_query($query) : '');
};

$okv_pro_title  = 'Credit';
$okv_pro_note   = 'Apply for credit, check the terms on this business account, and read every journal entry.';
$okv_pro_active = '/pro/credit.php';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<div class="space-y-6">

  <?php if (okv_input('applied', '') !== ''): ?>
    <p class="rounded-md border border-foliage bg-foliage-tint px-4 py-3 text-sm text-forest" role="status">
      Your credit application is with the team for review.
    </p>
  <?php endif; ?>

  <?php if ($errorCode !== ''): ?>
    <p class="rounded-md border border-tomato bg-tomato-tint px-4 py-3 text-sm text-tomato" role="alert">
      <?= okv_e(Credit::message($errorCode)) ?>
    </p>
  <?php endif; ?>

  <?php
  // What is late, said in a sentence. A business should not have to read a
  // table and work out which rows have gone past their date.
  $overdue = (int) ($summary['overdue_subunit'] ?? 0);
  $buckets = $summary['buckets'] ?? [];
  ?>
  <?php if ($overdue > 0): ?>
    <div class="rounded-md border border-tomato bg-tomato-tint px-4 py-3" role="alert">
      <p class="font-medium text-tomato">
        <?= okv_e(Money::format($overdue)) ?> on this account is past its due date.
      </p>
      <p class="mt-1 text-sm text-tomato">
        <?php
        $lateParts = [];
        foreach (['due_1_7' => 'up to 7 days late', 'due_8_30' => '8 to 30 days late', 'due_over_30' => 'over 30 days late'] as $bucket => $phrase) {
            $amount = (int) ($buckets[$bucket] ?? 0);
            if ($amount > 0) {
                $lateParts[] = Money::format($amount) . ' is ' . $phrase;
            }
        }
        ?>
        <?= okv_e(implode(', ', $lateParts)) ?>.
        Settle it and the same amount goes back on your limit straight away.
      </p>
    </div>
  <?php endif; ?>

  <!-- The facility itself. -->
  <section class="okv-panel" aria-labelledby="credit-status-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Credit status</p>
        <h2 id="credit-status-heading" class="okv-panel-title mt-1">
          <?= okv_e($stateLabels[$state] ?? 'Not applied') ?>
        </h2>
      </div>
    </div>
    <div class="okv-panel-body">
      <?php if ($state === 'approved' && $summary): ?>
        <div class="grid gap-4 md:grid-cols-3">
          <div>
            <p class="text-sm text-ink-60">Limit</p>
            <p class="mt-1 font-mono text-2xl font-semibold"><?= okv_e(Money::format((int) $summary['limit_subunit'])) ?></p>
          </div>
          <div>
            <p class="text-sm text-ink-60">Outstanding</p>
            <p class="mt-1 font-mono text-2xl font-semibold"><?= okv_e(Money::format((int) $summary['outstanding_subunit'])) ?></p>
          </div>
          <div>
            <p class="text-sm text-ink-60">Available</p>
            <p class="mt-1 font-mono text-2xl font-semibold"><?= okv_e(Money::format((int) $summary['available_subunit'])) ?></p>
          </div>
        </div>
        <dl class="mt-5 grid gap-4 border-t border-mist pt-4 sm:grid-cols-3">
          <div>
            <dt class="text-sm text-ink-60">Approved terms</dt>
            <dd class="mt-1"><?= (int) ($account['business']['credit_days'] ?? 0) ?> days</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-60">Overdue</dt>
            <dd class="mt-1 font-mono"><?= okv_e(Money::format($overdue)) ?></dd>
          </div>
          <div>
            <dt class="text-sm text-ink-60">Next due date</dt>
            <dd class="mt-1">
              <?= $summary['earliest_due_date']
                  ? okv_e(date('l jS F', strtotime((string) $summary['earliest_due_date'])))
                  : 'Nothing due' ?>
            </dd>
          </div>
        </dl>

      <?php elseif (in_array($state, ['suspended', 'withdrawn'], true) && $summary): ?>
        <p class="font-medium text-ink">This credit facility is <?= okv_e($state) ?>. New orders cannot draw on it.</p>
        <p class="mt-2 text-sm text-ink-60">
          Outstanding: <span class="font-mono"><?= okv_e(Money::format((int) $summary['outstanding_subunit'])) ?></span>.
          The statement below remains available.
        </p>

      <?php elseif ($application && (string) $application['status'] === 'pending'): ?>
        <p class="font-medium text-ink">Your application is waiting for review.</p>
        <p class="mt-2 text-sm text-ink-60">
          Requested <?= okv_e(Money::format((int) $application['requested_limit_subunit'])) ?>
          over <?= (int) $application['requested_days'] ?> days
          on <?= okv_e(date('j M Y', strtotime((string) $application['created_at']))) ?>.
        </p>
        <p class="mt-2 text-sm text-ink"><?= okv_e((string) $application['reason']) ?></p>

      <?php elseif ($application && (string) $application['status'] === 'declined'): ?>
        <p class="font-medium text-ink">Your latest application was declined.</p>
        <?php if ($application['decision_reason']): ?>
          <p class="mt-2 text-sm text-ink"><?= okv_e((string) $application['decision_reason']) ?></p>
        <?php endif; ?>
        <p class="mt-2 text-sm text-ink-60">You can submit a new application below.</p>

      <?php elseif ($application && (string) $application['status'] === 'withdrawn'): ?>
        <p class="font-medium text-ink">Your latest application was withdrawn.</p>
        <p class="mt-2 text-sm text-ink-60">You can submit a new application below.</p>

      <?php else: ?>
        <p class="font-medium text-ink">No credit application is active on this account.</p>
        <p class="mt-2 text-sm text-ink-60">Apply below if 7 to 10 day terms would help your kitchen.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if (Credit::mayApply($account)): ?>
    <section class="okv-panel" aria-labelledby="apply-heading">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">Application</p>
          <h2 id="apply-heading" class="okv-panel-title mt-1">Apply for business credit</h2>
        </div>
      </div>
      <form action="/api/v1/credit.php" method="post" class="okv-panel-body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="apply">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="okv-label" for="requested-limit">Requested limit, in naira</label>
            <input class="okv-input" id="requested-limit" name="requested_limit" inputmode="decimal"
                   required placeholder="500,000">
          </div>
          <div>
            <label class="okv-label" for="requested-days">Requested terms</label>
            <select class="okv-input" id="requested-days" name="requested_days" required>
              <?php for ($days = 7; $days <= 10; $days++): ?>
                <option value="<?= $days ?>"><?= $days ?> days</option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div>
          <label class="okv-label" for="credit-reason">How will this credit help your kitchen?</label>
          <textarea class="okv-input" id="credit-reason" name="reason" rows="4"
                    minlength="20" maxlength="1000" required></textarea>
          <p class="mt-1 text-sm text-ink-60">Use 20 to 1,000 characters.</p>
        </div>
        <button class="okv-btn" type="submit">Send credit application</button>
      </form>
    </section>
  <?php endif; ?>

  <!-- Every entry behind the figures above, newest first. -->
  <section class="okv-panel" aria-labelledby="statement-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Credit journal</p>
        <h2 id="statement-heading" class="okv-panel-title mt-1">Statement</h2>
      </div>
      <span class="text-sm text-ink-60"><?= (int) $statement['count'] ?> entries</span>
    </div>

    <form method="get" action="/pro/credit.php" class="flex flex-wrap items-end gap-3 border-b border-mist p-4 md:p-5">
      <div>
        <label class="okv-label" for="transaction-type">Entry type</label>
        <select class="okv-input min-w-[12rem]" id="transaction-type" name="type">
          <option value="">All entries</option>
          <?php foreach (Credit::TRANSACTION_TYPES as $entryType): ?>
            <option value="<?= okv_e($entryType) ?>" <?= $statement['type'] === $entryType ? 'selected' : '' ?>>
              <?= okv_e($transactionLabels[$entryType]) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="okv-btn" type="submit">Filter statement</button>
    </form>

    <?php if (!$statement['transactions']): ?>
      <div class="okv-panel-body">
        <p class="font-medium text-ink">No credit entries yet.</p>
        <p class="mt-2 text-sm text-ink-60">
          Charges, repayments and adjustments appear here when they are recorded.
        </p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
          <caption class="sr-only">Credit charges, repayments and adjustments, newest first</caption>
          <thead class="border-b border-mist text-ink-60">
            <tr>
              <th class="px-4 py-2 font-medium md:px-5" scope="col">Date</th>
              <th class="px-4 py-2 font-medium" scope="col">Entry</th>
              <th class="px-4 py-2 font-medium" scope="col">Related record</th>
              <th class="px-4 py-2 font-medium" scope="col">Due</th>
              <th class="px-4 py-2 text-right font-medium md:px-5" scope="col">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($statement['transactions'] as $entry): ?>
              <tr class="border-b border-mist/60">
                <td class="px-4 py-2 md:px-5">
                  <?= okv_e(date('j M Y', strtotime((string) $entry['created_at']))) ?>
                </td>
                <td class="px-4 py-2">
                  <?= okv_e($transactionLabels[(string) $entry['transaction_type']] ?? 'Entry') ?>
                </td>
                <td class="px-4 py-2">
                  <?php if ($entry['order_id']): ?>
                    <a class="inline-flex min-h-[44px] items-center text-forest underline"
                       href="/pro/orders.php?order=<?= (int) $entry['order_id'] ?>">
                      <?= okv_e((string) ($entry['order_number'] ?? 'Order')) ?>
                    </a>
                  <?php elseif ($entry['payment_order_id']): ?>
                    <a class="inline-flex min-h-[44px] items-center text-forest underline"
                       href="/public/documents/receipt.php?order=<?= (int) $entry['payment_order_id'] ?>">
                      Payment receipt
                    </a>
                  <?php else: ?>
                    Credit account
                  <?php endif; ?>
                </td>
                <td class="px-4 py-2">
                  <?= $entry['due_date']
                      ? okv_e(date('j M Y', strtotime((string) $entry['due_date'])))
                      : 'Not applicable' ?>
                </td>
                <td class="px-4 py-2 text-right font-mono md:px-5">
                  <?= okv_e(Money::format((int) $entry['amount_subunit'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php okv_pagination((int) $statement['page'], (int) $statement['last_page'], $statementUrl, 'Credit statement pages'); ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>
