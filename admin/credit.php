<?php
/**
 * admin/credit.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The staff side of B2B credit (PRD Section 12): the application
 * queue, the review, a manual grant, the facility controls, repayments and the
 * ageing view.
 *
 * Every write posts to api/v1/credit.php, which re-checks the permission on the
 * server. The gates here decide what is worth showing, not what is allowed:
 * credit.view opens the screen, credit.apply.review approves and declines,
 * credit.grant opens a facility, credit.limit.set changes terms, moves a
 * facility between states and records a repayment.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
Rbac::requirePermission('credit.view');

$canReview = Rbac::can('credit.apply.review');
$canGrant  = Rbac::can('credit.grant');
$canManage = Rbac::can('credit.limit.set');

$search = trim((string) okv_input('search', ''));
$status = trim((string) okv_input('status', 'pending'));
$page   = (int) okv_input('page', 1);

$queue      = Credit::adminApplications($search, $status, $page);
$selectedId = (int) okv_input('application', 0);
$selected   = $selectedId ? Credit::applicationForAdmin($selectedId) : null;
$ageing     = Credit::ageing();
$totals     = Credit::ageingTotals($ageing);

$businesses = $canGrant
    ? Database::all('SELECT bc.id, bc.business_name, bc.credit_status FROM business_customers bc ORDER BY bc.business_name')
    : [];

/** Keep the current filters on every page link. */
$urlFor = static function (int $target) use ($queue): string {
    $query = array_filter(
        ['search' => $queue['search'], 'status' => $queue['status'], 'page' => $target > 1 ? $target : null],
        static fn($value) => $value !== '' && $value !== null
    );
    return '/admin/credit.php' . ($query ? '?' . http_build_query($query) : '');
};

$okv_admin_title = 'Credit';
$okv_admin_note  = 'Applications, facility terms, repayments and what each business owes.';
require __DIR__ . '/../includes/components/admin/header.php';
?>

<?php if (okv_input('saved', '') !== ''): ?>
  <p class="okv-note-ok mb-5" role="status">The credit record has been updated.</p>
<?php endif; ?>
<?php if (okv_input('error', '') !== ''): ?>
  <p class="okv-note-bad mb-5" role="alert"><?= okv_e(Credit::message((string) okv_input('error', ''))) ?></p>
<?php endif; ?>

<form method="get" class="mb-5 grid gap-3 rounded-md border border-mist bg-white p-4 sm:grid-cols-3">
  <div>
    <label class="okv-label" for="credit-search">Business or email</label>
    <input class="okv-input" id="credit-search" name="search" value="<?= okv_e($queue['search']) ?>">
  </div>
  <div>
    <label class="okv-label" for="credit-status">Application status</label>
    <select class="okv-input" id="credit-status" name="status">
      <option value="">All</option>
      <?php foreach (Credit::APPLICATION_STATES as $state): ?>
        <option value="<?= okv_e($state) ?>" <?= $queue['status'] === $state ? 'selected' : '' ?>>
          <?= okv_e(ucfirst($state)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="okv-btn self-end" type="submit">Filter applications</button>
</form>

<div class="grid gap-5 xl:grid-cols-2">

  <!-- The queue. Pending sits at the top, because it is the only state that
       needs a decision from anybody. -->
  <section class="okv-panel" aria-labelledby="applications-heading">
    <div class="okv-panel-head">
      <h2 id="applications-heading" class="okv-panel-title">Credit applications</h2>
      <span class="text-sm text-ink-60"><?= (int) $queue['count'] ?> total</span>
    </div>
    <?php if (!$queue['applications']): ?>
      <p class="p-5 text-sm text-ink-60">No applications match these filters.</p>
    <?php else: ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($queue['applications'] as $application): ?>
          <li>
            <a class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint"
               href="/admin/credit.php?<?= okv_e(http_build_query([
                   'search' => $queue['search'], 'status' => $queue['status'],
                   'page' => $queue['page'], 'application' => $application['id'],
               ])) ?>">
              <span class="flex justify-between gap-3">
                <strong><?= okv_e($application['business_name']) ?></strong>
                <span class="okv-badge <?= $application['status'] === 'pending' ? 'okv-badge-warn' : 'okv-badge-neutral' ?>">
                  <?= okv_e(ucfirst($application['status'])) ?>
                </span>
              </span>
              <span class="mt-1 block text-sm text-ink-60">
                <?= okv_e(Money::format((int) $application['requested_limit_subunit'])) ?>,
                <?= (int) $application['requested_days'] ?> days
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php okv_pagination($queue['page'], $queue['last_page'], $urlFor, 'Credit application pages'); ?>
    <?php endif; ?>
  </section>

  <!-- The review. Approving sets the terms the facility will actually carry,
       which need not be the terms that were asked for. -->
  <section class="okv-panel" aria-labelledby="review-heading">
    <div class="okv-panel-head">
      <h2 id="review-heading" class="okv-panel-title">Application review</h2>
    </div>
    <div class="okv-panel-body">
      <?php if (!$selected): ?>
        <p class="text-sm text-ink-60">Choose an application to review its request and account.</p>
      <?php else: ?>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-ink-60">Business</dt><dd><?= okv_e($selected['business_name']) ?></dd></div>
          <div><dt class="text-ink-60">Email</dt><dd><?= okv_e($selected['email']) ?></dd></div>
          <div>
            <dt class="text-ink-60">Requested</dt>
            <dd class="font-mono"><?= okv_e(Money::format((int) $selected['requested_limit_subunit'])) ?></dd>
          </div>
          <div><dt class="text-ink-60">Terms</dt><dd><?= (int) $selected['requested_days'] ?> days</dd></div>
        </dl>
        <p class="mt-4 text-sm"><?= okv_e((string) $selected['reason']) ?></p>
        <a class="okv-btn-text mt-3" href="/admin/customers.php?customer=<?= (int) $selected['user_id'] ?>">
          Open customer account
        </a>

        <?php if ($selected['status'] === 'pending' && $canReview): ?>
          <div class="mt-5 grid gap-4 md:grid-cols-2">
            <form action="/api/v1/credit.php" method="post" class="space-y-3 rounded-md border border-mist p-3">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="application_id" value="<?= (int) $selected['id'] ?>">
              <div>
                <label class="okv-label" for="approve-limit">Approved limit</label>
                <input class="okv-input" id="approve-limit" name="credit_limit" required
                       value="<?= okv_e(Money::format((int) $selected['requested_limit_subunit'], false, false)) ?>">
              </div>
              <div>
                <label class="okv-label" for="approve-days">Days</label>
                <input class="okv-input" id="approve-days" name="credit_days" type="number" min="7" max="10"
                       required value="<?= (int) $selected['requested_days'] ?>">
              </div>
              <button class="okv-btn" type="submit">Approve credit</button>
            </form>

            <form action="/api/v1/credit.php" method="post" class="space-y-3 rounded-md border border-mist p-3">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="decline">
              <input type="hidden" name="application_id" value="<?= (int) $selected['id'] ?>">
              <div>
                <label class="okv-label" for="decline-reason">Reason for the customer</label>
                <textarea class="okv-input" id="decline-reason" name="decision_reason"
                          minlength="10" maxlength="1000" required></textarea>
              </div>
              <button class="okv-btn-outline border-tomato text-tomato" type="submit">Decline application</button>
            </form>
          </div>
        <?php elseif ($selected['status'] === 'declined' && $selected['decision_reason']): ?>
          <p class="okv-note mt-5 bg-clay-tint">
            Declined: <?= okv_e((string) $selected['decision_reason']) ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php if ($canGrant): ?>
  <!-- Most facilities start here rather than with an application: the Owner
       already knows the restaurant. -->
  <section class="okv-panel mt-5" aria-labelledby="grant-heading">
    <div class="okv-panel-head">
      <h2 id="grant-heading" class="okv-panel-title">Manual grant</h2>
    </div>
    <form action="/api/v1/credit.php" method="post" class="okv-panel-body grid gap-3 sm:grid-cols-4">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="grant">
      <div>
        <label class="okv-label" for="grant-business">Business</label>
        <select class="okv-input" id="grant-business" name="business_id" required>
          <?php foreach ($businesses as $business): ?>
            <option value="<?= (int) $business['id'] ?>">
              <?= okv_e($business['business_name']) ?>, <?= okv_e($business['credit_status']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="okv-label" for="grant-limit">Limit</label>
        <input class="okv-input" id="grant-limit" name="credit_limit" required>
      </div>
      <div>
        <label class="okv-label" for="grant-days">Days</label>
        <input class="okv-input" id="grant-days" name="credit_days" type="number" min="7" max="10" required>
      </div>
      <button class="okv-btn self-end" type="submit">Grant credit</button>
    </form>
  </section>
<?php endif; ?>

<!-- The ageing view. Everything here is calculated from credit_transactions at
     the moment it is read, so no figure on this screen can drift. -->
<section class="okv-panel mt-5" aria-labelledby="ageing-heading">
  <div class="okv-panel-head">
    <h2 id="ageing-heading" class="okv-panel-title">Outstanding and overdue</h2>
    <span class="text-sm text-ink-60">How late each open charge is</span>
  </div>
  <?php if (!$ageing): ?>
    <p class="p-5 text-sm text-ink-60">No business has an active or retained credit balance.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="okv-table min-w-[64rem]">
        <thead>
          <tr>
            <th scope="col">Business</th>
            <th scope="col">State</th>
            <th scope="col">Outstanding</th>
            <?php foreach (Credit::AGEING_BUCKETS as $bucket): ?>
              <th scope="col"><?= okv_e(Credit::AGEING_LABELS[$bucket]) ?></th>
            <?php endforeach; ?>
            <th scope="col">Next due</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ageing as $row): $summary = $row['summary']; ?>
            <tr>
              <td>
                <a class="inline-flex min-h-[44px] items-center text-forest underline"
                   href="/admin/customers.php?customer=<?= (int) $row['user_id'] ?>">
                  <?= okv_e($row['business_name']) ?>
                </a>
              </td>
              <td><?= okv_e(ucfirst((string) $row['credit_status'])) ?></td>
              <td class="font-mono"><?= okv_e(Money::format((int) $summary['outstanding_subunit'])) ?></td>
              <?php foreach (Credit::AGEING_BUCKETS as $bucket): ?>
                <?php $amount = (int) ($summary['buckets'][$bucket] ?? 0); ?>
                <td class="font-mono <?= $amount > 0 && $bucket !== 'not_yet_due' ? 'text-tomato' : '' ?>">
                  <?= $amount > 0 ? okv_e(Money::format($amount)) : '<span class="text-ink-60">0</span>' ?>
                </td>
              <?php endforeach; ?>
              <td>
                <?= $summary['earliest_due_date']
                    ? okv_e(date('j M Y', strtotime((string) $summary['earliest_due_date'])))
                    : 'Nothing due' ?>
              </td>
              <td>
                <?php if ($canManage): ?>
                  <?php $payments = Credit::repayablePayments((int) $row['id']); ?>
                  <details>
                    <summary class="inline-flex min-h-[44px] cursor-pointer items-center text-forest underline">
                      Manage
                    </summary>
                    <div class="w-80 space-y-3 rounded-md border border-mist bg-white p-3">

                      <form action="/api/v1/credit.php" method="post" class="space-y-2">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="change_terms">
                        <input type="hidden" name="business_id" value="<?= (int) $row['id'] ?>">
                        <label class="okv-label" for="terms-limit-<?= (int) $row['id'] ?>">Limit</label>
                        <input class="okv-input" id="terms-limit-<?= (int) $row['id'] ?>" name="credit_limit"
                               value="<?= okv_e(Money::format((int) $row['credit_limit_subunit'], false, false)) ?>" required>
                        <label class="okv-label" for="terms-days-<?= (int) $row['id'] ?>">Days</label>
                        <input class="okv-input" id="terms-days-<?= (int) $row['id'] ?>" name="credit_days"
                               type="number" min="7" max="10" value="<?= (int) $row['credit_days'] ?>" required>
                        <button class="okv-btn-sm min-h-[44px]" type="submit">Save terms</button>
                      </form>

                      <div class="flex flex-wrap gap-2">
                        <?php
                        $transitions = $row['credit_status'] === 'suspended'
                            ? ['reinstate', 'withdraw']
                            : ['suspend', 'withdraw'];
                        ?>
                        <?php foreach ($transitions as $transition): ?>
                          <form action="/api/v1/credit.php" method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="<?= okv_e($transition) ?>">
                            <input type="hidden" name="business_id" value="<?= (int) $row['id'] ?>">
                            <button class="okv-btn-text" type="submit"><?= okv_e(ucfirst($transition)) ?></button>
                          </form>
                        <?php endforeach; ?>
                      </div>

                      <!-- Recording a repayment by hand. An on-account order
                           frees its own limit when its payment is confirmed,
                           so only money that arrived any other way is listed
                           here. The select is the whole control without
                           JavaScript; admin-credit.js turns it into a
                           type-to-filter box on top. -->
                      <?php if (!$payments): ?>
                        <p class="text-sm text-ink-60">
                          No confirmed payment from this business is waiting to be credited. Money on a
                          credit order frees the limit on its own once it is confirmed.
                        </p>
                      <?php else: ?>
                        <form action="/api/v1/credit.php" method="post" class="space-y-2"
                              data-okv-credit-repayment>
                          <?= Csrf::field() ?>
                          <input type="hidden" name="action" value="record_repayment">
                          <input type="hidden" name="business_id" value="<?= (int) $row['id'] ?>">
                          <label class="okv-label" for="repay-<?= (int) $row['id'] ?>">Confirmed payment</label>
                          <select class="okv-input" id="repay-<?= (int) $row['id'] ?>" name="payment_id" required>
                            <?php foreach ($payments as $payment): ?>
                              <option value="<?= (int) $payment['id'] ?>">
                                <?= okv_e($payment['reference']) ?>,
                                <?= okv_e($payment['order_number']) ?>,
                                <?= okv_e(Money::format((int) $payment['amount_subunit'])) ?>,
                                <?= okv_e(date('j M Y', strtotime((string) $payment['confirmed_at']))) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <button class="okv-btn-sm min-h-[44px]" type="submit">Record repayment</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" colspan="2">The whole book</th>
            <td class="font-mono"><?= okv_e(Money::format((int) $totals['outstanding_subunit'])) ?></td>
            <?php foreach (Credit::AGEING_BUCKETS as $bucket): ?>
              <td class="font-mono"><?= okv_e(Money::format((int) $totals[$bucket])) ?></td>
            <?php endforeach; ?>
            <td colspan="2"><?= okv_e(Money::format((int) $totals['overdue_subunit'])) ?> overdue</td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php
$okv_admin_script = '/assets/js/admin-credit.js';
require __DIR__ . '/../includes/components/admin/footer.php';
?>
