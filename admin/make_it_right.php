<?php
/** Staff queue and report detail for Make It Right. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
Rbac::requirePermission('issues.view');

$status = (string) okv_input('status', 'active');
if (!in_array($status, array_merge(['active', 'all'], IssueReports::STATUSES), true)) {
    $status = 'active';
}
$category = (string) okv_input('category', '');
if ($category !== '' && !isset(IssueReports::CATEGORIES[$category])) {
    $category = '';
}
$from = IssueReports::validDate((string) okv_input('from', '')) ? (string) okv_input('from', '') : '';
$to = IssueReports::validDate((string) okv_input('to', '')) ? (string) okv_input('to', '') : '';
$total = IssueReports::countForStaff($status, $category, $from, $to);
$pages = max(1, (int) ceil($total / IssueReports::PER_PAGE));
$page = min($pages, max(1, (int) okv_input('page', 1)));
$reports = IssueReports::forStaff($status, $category, $from, $to, $page);
$reportId = (int) okv_input('report', $reports ? $reports[0]['id'] : 0);
$selected = $reportId > 0 ? IssueReports::findForStaff($reportId) : null;
$canResolve = Rbac::can('issues.resolve');
$staffId = (int) (Rbac::userId() ?? 0);

$urlFor = static function (array $changes = []) use ($status, $category, $from, $to, $page, $reportId): string {
    $query = array_merge([
        'status' => $status,
        'category' => $category,
        'from' => $from,
        'to' => $to,
        'page' => $page,
        'report' => $reportId,
    ], $changes);
    $query = array_filter($query, static fn($value): bool => $value !== '' && $value !== null);
    return '/admin/make_it_right.php?' . http_build_query($query);
};
$detailUrl = $urlFor(['report' => $reportId]);

$notice = (string) okv_input('notice', '');
$error = (string) okv_input('error', '');
$noticeCopy = [
    'taken' => 'You are now handling this report.',
    'already_taken' => 'You were already handling this report. No duplicate history was added.',
    'declined' => 'The report was declined and the customer has been told.',
];
$errorCopy = [
    'csrf_expired' => 'Your session expired. Reload the page and try again.',
    'stale' => 'This report changed after the page loaded. Reload it before acting.',
    'terminal' => 'This report has already been finished and cannot be changed again.',
    'not_handler' => 'Only the colleague handling this report can finish it.',
    'resolution_note_too_short' => 'Use at least 10 characters so the customer understands the outcome.',
    'resolution_note_too_long' => 'Keep the customer note to 1,000 characters or fewer.',
    'invalid_decline' => 'Take the report before declining it.',
    'invalid_take' => 'Reload the report before taking it.',
    'not_found' => 'That report could not be found.',
    'failed' => 'We could not update that report. Reload it and try again.',
];

$okv_admin_title = 'Make It Right';
$okv_admin_note = 'What arrived wrong, who is handling it, and how it was put right.';
require __DIR__ . '/../includes/components/admin/header.php';
?>

<?php if (isset($noticeCopy[$notice])): ?>
  <p class="okv-note-ok mb-5" role="status"><?= okv_e($noticeCopy[$notice]) ?></p>
<?php endif; ?>
<?php if (isset($errorCopy[$error])): ?>
  <p class="okv-note-bad mb-5" role="alert"><?= okv_e($errorCopy[$error]) ?></p>
<?php endif; ?>

<form method="get" class="mb-5 grid gap-3 rounded-md border border-mist bg-white p-4 sm:grid-cols-2 xl:grid-cols-5">
  <div>
    <label class="okv-label" for="issue-status">Status</label>
    <select class="okv-input mt-1" id="issue-status" name="status">
      <?php foreach (['active' => 'Active', 'open' => 'Open', 'in_progress' => 'Being handled', 'resolved' => 'Resolved', 'declined' => 'Declined', 'all' => 'All'] as $value => $label): ?>
        <option value="<?= okv_e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= okv_e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="okv-label" for="issue-category-filter">Category</label>
    <select class="okv-input mt-1" id="issue-category-filter" name="category">
      <option value="">All categories</option>
      <?php foreach (IssueReports::CATEGORIES as $value => $label): ?>
        <option value="<?= okv_e($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= okv_e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="okv-label" for="issue-from">Received from</label><input class="okv-input mt-1" id="issue-from" type="date" name="from" value="<?= okv_e($from) ?>"></div>
  <div><label class="okv-label" for="issue-to">Received to</label><input class="okv-input mt-1" id="issue-to" type="date" name="to" value="<?= okv_e($to) ?>"></div>
  <button class="okv-btn min-h-[44px] self-end justify-center">Filter reports</button>
</form>

<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.5fr)]">
  <section class="okv-panel min-w-0" aria-labelledby="issue-queue-heading">
    <div class="okv-panel-head">
      <div>
        <h2 id="issue-queue-heading" class="okv-panel-title">Reports</h2>
        <p class="mt-1 text-xs text-ink-60">Oldest first</p>
      </div>
      <span class="okv-badge okv-badge-neutral"><?= (int) $total ?></span>
    </div>
    <?php if (!$reports): ?>
      <div class="p-6 text-center text-sm text-ink-60">
        <p class="font-medium text-ink">No reports match these filters.</p>
        <p class="mt-1">Change a filter to check another part of the queue.</p>
      </div>
    <?php else: ?>
      <ol class="divide-y divide-mist">
        <?php foreach ($reports as $report): ?>
          <li>
            <a href="<?= okv_e($urlFor(['report' => (int) $report['id']])) ?>"
               class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= (int) $report['id'] === $reportId ? 'bg-forest-tint' : '' ?>"
               <?= (int) $report['id'] === $reportId ? 'aria-current="page"' : '' ?>>
              <span class="flex items-center justify-between gap-3">
                <strong class="font-mono text-sm"><?= okv_e($report['order_number']) ?></strong>
                <span class="okv-badge <?= $report['status'] === 'open' ? 'okv-badge-warn' : 'okv-badge-available' ?>"><?= okv_e(IssueReports::statusLabel((string) $report['status'])) ?></span>
              </span>
              <span class="mt-1 block text-sm font-medium text-ink"><?= okv_e(IssueReports::categoryLabel((string) $report['category'])) ?></span>
              <span class="mt-1 flex items-center justify-between gap-3 text-xs text-ink-60">
                <span class="truncate"><?= okv_e(trim((string) $report['customer_name']) ?: 'Customer') ?></span>
                <time datetime="<?= okv_e($report['created_at']) ?>"><?= okv_e(date('j M, H:i', strtotime((string) $report['created_at']))) ?></time>
              </span>
              <?php if ((int) $report['photo_count'] > 0): ?><span class="mt-1 block text-xs text-ink-60"><?= (int) $report['photo_count'] ?> photo<?= (int) $report['photo_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ol>
      <div class="px-4 pb-5">
        <?php okv_pagination($page, $pages, static fn(int $number): string => $urlFor(['page' => $number, 'report' => null]), 'Report pages'); ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="okv-panel min-w-0" aria-labelledby="issue-detail-heading">
    <?php if ($selected === null): ?>
      <div class="p-6 text-center text-sm text-ink-60">
        <h2 id="issue-detail-heading" class="font-editorial text-okv-h6 text-ink"><?= $reportId > 0 ? 'Report not found' : 'Choose a report' ?></h2>
        <p class="mt-2"><?= $reportId > 0 ? 'Check the queue and open it again.' : 'Open a report to see the order and customer context.' ?></p>
      </div>
    <?php else: ?>
      <div class="okv-panel-head flex-wrap gap-3">
        <div>
          <p class="okv-eyebrow">Order <?= okv_e($selected['order_number']) ?></p>
          <h2 id="issue-detail-heading" class="mt-1 font-editorial text-okv-h6 text-ink"><?= okv_e(IssueReports::categoryLabel((string) $selected['category'])) ?></h2>
        </div>
        <span class="okv-badge <?= $selected['status'] === 'open' ? 'okv-badge-warn' : ($selected['status'] === 'declined' ? 'okv-badge-neutral' : 'okv-badge-available') ?>"><?= okv_e(IssueReports::statusLabel((string) $selected['status'])) ?></span>
      </div>

      <div class="space-y-6 p-4 md:p-5">
        <div class="flex flex-wrap gap-2">
          <a class="okv-btn-outline min-h-[44px] px-3" href="/admin/orders.php?order=<?= (int) $selected['order_id'] ?>">Open order <?= okv_e($selected['order_number']) ?></a>
          <?php if (Rbac::can('customers.view') && !empty($selected['user_id'])): ?>
            <a class="okv-btn-text min-h-[44px] px-3" href="/admin/customers.php?customer=<?= (int) $selected['user_id'] ?>">Open customer</a>
          <?php endif; ?>
        </div>

        <div>
          <h3 class="text-sm font-semibold text-ink">Customer report</h3>
          <p class="mt-2 whitespace-pre-wrap break-words rounded-md border border-mist bg-white p-4 text-sm leading-relaxed"><?= okv_e($selected['description']) ?></p>
          <p class="mt-2 text-xs text-ink-60">Received <time datetime="<?= okv_e($selected['created_at']) ?>"><?= okv_e(date('j M Y, H:i', strtotime((string) $selected['created_at']))) ?></time></p>
        </div>

        <?php if (!empty($selected['photos'])): ?>
          <div>
            <h3 class="text-sm font-semibold text-ink">Customer photos</h3>
            <p class="mt-1 text-xs text-ink-60">Select a thumbnail to open the authorised full-size image.</p>
            <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
              <?php foreach ($selected['photos'] as $photoIndex => $photo): ?>
                <a href="/public/issue_photo.php?photo=<?= (int) $photo['id'] ?>"
                   class="block min-h-[44px] rounded-md focus:outline-none"
                   aria-label="Open customer photo <?= $photoIndex + 1 ?> for order <?= okv_e($selected['order_number']) ?> at full size">
                  <img src="/public/issue_photo.php?photo=<?= (int) $photo['id'] ?>"
                       alt="Customer photo <?= $photoIndex + 1 ?> for order <?= okv_e($selected['order_number']) ?>"
                       class="aspect-square w-full rounded-md border border-mist object-cover"
                       loading="lazy">
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="grid gap-4 sm:grid-cols-2">
          <section class="rounded-md border border-mist bg-white p-4" aria-labelledby="issue-customer-heading">
            <h3 id="issue-customer-heading" class="font-semibold text-ink">Customer</h3>
            <dl class="mt-3 space-y-2 text-sm">
              <div><dt class="text-ink-60">Name</dt><dd><?= okv_e(trim((string) $selected['customer_name']) ?: trim((string) $selected['recipient_name']) ?: 'Customer') ?></dd></div>
              <div><dt class="text-ink-60">Email</dt><dd class="break-words"><?= okv_e((string) ($selected['customer_email'] ?: 'Not provided')) ?></dd></div>
              <div><dt class="text-ink-60">Phone</dt><dd><?= okv_e(Phone::display((string) ($selected['customer_phone'] ?: $selected['recipient_phone'] ?: ''))) ?: 'Not provided' ?></dd></div>
            </dl>
          </section>

          <section class="rounded-md border border-mist bg-white p-4" aria-labelledby="issue-payment-heading">
            <h3 id="issue-payment-heading" class="font-semibold text-ink">Order and payment</h3>
            <dl class="mt-3 space-y-2 text-sm">
              <div><dt class="text-ink-60">Order status</dt><dd><?= okv_e(OrderLifecycle::customerLabel((string) $selected['order_status'])) ?></dd></div>
              <div><dt class="text-ink-60">Order total</dt><dd class="font-mono"><?= okv_e(Money::format((int) $selected['order_total_subunit'])) ?></dd></div>
              <div><dt class="text-ink-60">Verified paid</dt><dd class="font-mono"><?= okv_e(Money::format((int) $selected['verified_paid_subunit'])) ?></dd></div>
              <div><dt class="text-ink-60">Payment status</dt><dd><?= okv_e(ucfirst(str_replace('_', ' ', (string) $selected['payment_status']))) ?></dd></div>
            </dl>
          </section>

          <section class="rounded-md border border-mist bg-white p-4 sm:col-span-2" aria-labelledby="issue-delivery-heading">
            <h3 id="issue-delivery-heading" class="font-semibold text-ink">Delivery context</h3>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
              <div><dt class="text-ink-60">Requested day</dt><dd><?= okv_e(date('l jS F', strtotime((string) $selected['preferred_delivery_date']))) ?></dd></div>
              <div><dt class="text-ink-60">Delivery status</dt><dd><?= okv_e(ucfirst((string) ($selected['delivery_status'] ?: $selected['order_status']))) ?></dd></div>
              <div><dt class="text-ink-60">Dispatched</dt><dd><?= $selected['dispatched_at'] ? okv_e(date('j M Y, H:i', strtotime((string) $selected['dispatched_at']))) : 'Not recorded' ?></dd></div>
              <div><dt class="text-ink-60">Delivered</dt><dd><?= ($selected['delivered_at'] ?: $selected['schedule_delivered_at']) ? okv_e(date('j M Y, H:i', strtotime((string) ($selected['delivered_at'] ?: $selected['schedule_delivered_at'])))) : 'Not recorded' ?></dd></div>
              <div class="sm:col-span-2 lg:col-span-4"><dt class="text-ink-60">Address</dt><dd><?= okv_e(implode(', ', array_filter([$selected['address_line_1'], $selected['address_line_2'], $selected['city'], $selected['delivery_state']]))) ?><?= $selected['zone_name'] ? ' (' . okv_e($selected['zone_name']) . ')' : '' ?></dd></div>
            </dl>
          </section>
        </div>

        <div>
          <h3 class="text-sm font-semibold text-ink">Order items</h3>
          <?php if (!$selected['items']): ?>
            <p class="mt-2 text-sm text-ink-60">No item snapshot is available for this order.</p>
          <?php else: ?>
            <div class="mt-3 overflow-x-auto rounded-md border border-mist">
              <table class="okv-admin-table min-w-full">
                <thead><tr><th scope="col">Item</th><th scope="col">Quantity</th><th scope="col">Line total</th></tr></thead>
                <tbody>
                  <?php foreach ($selected['items'] as $item): ?>
                    <tr>
                      <td><?= okv_e($item['item_name']) ?></td>
                      <td><?= okv_e(okv_quantity($item['quantity'])) ?> <?= okv_e($item['unit_name']) ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $item['line_total_subunit'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="border-t border-mist pt-5">
          <h3 class="text-sm font-semibold text-ink">Handling</h3>
          <?php if ($selected['status'] === 'open'): ?>
            <p class="mt-2 text-sm text-ink-60">This report is waiting for a colleague.</p>
            <?php if ($canResolve): ?>
              <form action="/api/v1/make_it_right.php" method="post" class="mt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="take">
                <input type="hidden" name="issue_id" value="<?= (int) $selected['id'] ?>">
                <input type="hidden" name="expected_status" value="open">
                <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
                <button class="okv-btn min-h-[44px] px-4" type="submit">Take this report</button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <p class="mt-2 text-sm text-ink-60">
              <?= $selected['handled_by'] ? 'Handled by ' . okv_e(trim((string) $selected['handler_name']) ?: 'a former colleague') : 'No handler recorded' ?>
              <?= $selected['handled_at'] ? ' since ' . okv_e(date('j M Y, H:i', strtotime((string) $selected['handled_at']))) : '' ?>.
            </p>
          <?php endif; ?>

          <?php if ($selected['status'] === 'in_progress' && $canResolve && (int) $selected['handled_by'] === $staffId): ?>
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
              <section class="rounded-md border border-mist p-4" aria-labelledby="resolution-heading">
                <h4 id="resolution-heading" class="font-semibold text-ink">Put it right</h4>
                <p class="mt-2 text-sm text-ink-60">Refund, account credit, and replacement are connected to their existing engines in Task D. No financial outcome is recorded from this screen until that work is present.</p>
                <label class="okv-label mt-3" for="resolution-type-preview">Resolution type</label>
                <select class="okv-input mt-1" id="resolution-type-preview" disabled>
                  <?php foreach (IssueReports::RESOLUTION_TYPES as $value => $label): ?><option value="<?= okv_e($value) ?>"><?= okv_e($label) ?></option><?php endforeach; ?>
                </select>
              </section>
              <section class="rounded-md border border-mist p-4" aria-labelledby="decline-heading">
                <h4 id="decline-heading" class="font-semibold text-ink">Decline report</h4>
                <p class="mt-2 text-sm text-ink-60">The reason is shown to the customer and cannot be changed after submission.</p>
                <form action="/api/v1/make_it_right.php" method="post" class="mt-3 space-y-3" novalidate>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="decline">
                  <input type="hidden" name="issue_id" value="<?= (int) $selected['id'] ?>">
                  <input type="hidden" name="expected_status" value="in_progress">
                  <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
                  <label class="okv-label" for="decline-note">Reason shown to the customer</label>
                  <textarea class="okv-input min-h-28 py-3" id="decline-note" name="resolution_note" minlength="10" maxlength="1000" required></textarea>
                  <button class="okv-btn-outline min-h-[44px] border-tomato px-4 text-tomato" type="submit">Decline this report</button>
                </form>
              </section>
            </div>
          <?php elseif ($selected['status'] === 'in_progress' && (int) $selected['handled_by'] !== $staffId): ?>
            <p class="okv-note mt-4 bg-clay-tint">Only the colleague handling this report can finish it.</p>
          <?php endif; ?>

          <?php if (in_array((string) $selected['status'], ['resolved', 'declined'], true)): ?>
            <div class="mt-4 rounded-md border border-mist bg-forest-tint p-4">
              <p class="font-semibold text-ink"><?= okv_e(IssueReports::statusLabel((string) $selected['status'])) ?></p>
              <p class="mt-2 whitespace-pre-wrap text-sm text-ink"><?= okv_e((string) $selected['resolution_note']) ?></p>
              <?php if ($selected['resolved_at']): ?><p class="mt-2 text-xs text-ink-60"><?= okv_e(date('j M Y, H:i', strtotime((string) $selected['resolved_at']))) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="border-t border-mist pt-5">
          <h3 class="text-sm font-semibold text-ink">History</h3>
          <?php if (!$selected['history']): ?>
            <p class="mt-2 text-sm text-ink-60">No history is available. Run migration 046 if this is an older report.</p>
          <?php else: ?>
            <ol class="mt-3 space-y-3">
              <?php foreach ($selected['history'] as $event): ?>
                <li class="border-l-2 border-gold pl-3 text-sm">
                  <p class="font-medium"><?= okv_e(IssueReports::statusLabel((string) $event['new_status'])) ?></p>
                  <p class="text-xs text-ink-60"><?= okv_e(trim((string) $event['actor_name']) ?: 'Customer') ?>, <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></p>
                  <?php if (trim((string) $event['note']) !== ''): ?><p class="mt-1 whitespace-pre-wrap text-sm text-ink"><?= okv_e($event['note']) ?></p><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
