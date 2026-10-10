<?php
/** Staff order list and cancellation detail. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
Rbac::requirePermission('orders.view');

$statusFilter = trim((string) okv_input('filter_status', ''));
$dateFilter = trim((string) okv_input('filter_date', ''));
$createdFilter = trim((string) okv_input('filter_created', ''));
$customerFilter = mb_substr(trim((string) okv_input('filter_customer', '')), 0, 100);
$validStatuses = ['pending', 'confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'];
$where = []; $params = [];
if (in_array($statusFilter, $validStatuses, true)) { $where[] = 'o.order_status = :status'; $params[':status'] = $statusFilter; }
if (Delivery::validDate($dateFilter)) { $where[] = 'o.preferred_delivery_date = :date'; $params[':date'] = $dateFilter; }
if (Delivery::validDate($createdFilter)) {
    $createdStart = new DateTimeImmutable($createdFilter . ' 00:00:00');
    $where[] = 'o.created_at >= :created_start AND o.created_at < :created_end';
    $params[':created_start'] = $createdStart->format('Y-m-d H:i:s');
    $params[':created_end'] = $createdStart->modify('+1 day')->format('Y-m-d H:i:s');
}
// One placeholder per position. The connection runs native prepared statements
// (Database sets ATTR_EMULATE_PREPARES to false), and MySQL will not accept the
// same named placeholder twice in one statement: reusing :customer here threw
// SQLSTATE[HY093] and turned the customer filter into a 500. Every other search
// in this codebase names its placeholders separately for the same reason.
//
// "Customer" means whoever a staff member is looking for, so this matches the
// name on the delivery, the order number, and the account's own name or email.
if ($customerFilter !== '') {
    $where[] = '(a.recipient_name LIKE :customer_recipient
                 OR o.order_number LIKE :customer_number
                 OR u.email LIKE :customer_email
                 OR TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) LIKE :customer_account)';
    $like = '%' . $customerFilter . '%';
    $params[':customer_recipient'] = $like;
    $params[':customer_number']    = $like;
    $params[':customer_email']     = $like;
    $params[':customer_account']   = $like;
}
// Page the list 50 at a time. The count runs the same filters as the list, so
// the page switcher and the "Showing" line can never disagree with what the
// page actually holds.
$perPage = 50;
$totalOrders = (int) (Database::one(
    'SELECT COUNT(*) AS matched
       FROM orders o
       LEFT JOIN order_addresses a ON a.order_id = o.id
       LEFT JOIN users u ON u.id = o.user_id
      ' . ($where ? 'WHERE ' . implode(' AND ', $where) : ''),
    $params
)['matched'] ?? 0);
$pageCount = max(1, (int) ceil($totalOrders / $perPage));
$page = min(max(1, (int) okv_input('page', 1)), $pageCount);
$orders = Database::all(
    'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.order_total_subunit,
            o.preferred_delivery_date, o.created_at, a.recipient_name
       FROM orders o
       LEFT JOIN order_addresses a ON a.order_id = o.id
       LEFT JOIN users u ON u.id = o.user_id
      ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
      ORDER BY o.id DESC' . okv_limit_clause($page, $perPage),
    $params
);
$selectedId = (int) okv_input('order', $orders ? $orders[0]['id'] : 0);
$selected = $selectedId > 0 ? OrderCancellation::forStaff($selectedId) : null;
$rescheduleState = $selectedId > 0 ? OrderReschedule::forStaff($selectedId) : null;
$rescheduleHistory = $selectedId > 0 ? OrderReschedule::history($selectedId) : [];
$items = $selected ? Database::all(
    'SELECT id, item_type, item_name, quantity, unit_name, line_total_subunit FROM order_items WHERE order_id = :id ORDER BY id',
    [':id' => $selectedId]
) : [];
// One query for every component on the order rather than one per combo line.
// A basket order with six combos was six extra round trips before this.
$componentsByItem = [];
if ($items) {
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::all(
        'SELECT order_item_id, product_name, quantity, unit_name
           FROM order_item_components WHERE order_item_id IN (' . $placeholders . ') ORDER BY id',
        $ids
    ) as $component) {
        $componentsByItem[(int) $component['order_item_id']][] = $component;
    }
}
foreach ($items as &$item) {
    $item['components'] = $componentsByItem[(int) $item['id']] ?? [];
}
unset($item);
$address = $selected ? Database::one(
    'SELECT a.recipient_name, a.recipient_phone, a.address_line_1, a.address_line_2, a.city, a.state, a.landmark,
            z.name AS zone_name
       FROM order_addresses a
       LEFT JOIN orders o ON o.id = a.order_id
       LEFT JOIN delivery_zones z ON z.id = o.delivery_zone_id
      WHERE a.order_id = :id',
    [':id' => $selectedId]
) : null;
$history = $selected ? Database::all(
    'SELECT h.old_status, h.new_status, h.source, h.note, h.created_at,
            TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS actor_name
       FROM order_status_history h
       LEFT JOIN users u ON u.id = h.changed_by
      WHERE h.order_id = :id ORDER BY h.created_at, h.id',
    [':id' => $selectedId]
) : [];
// The money, read from the M5 ledger rather than recomputed here, so this
// screen and the invoice can never disagree about what is owed.
$ledger = $selected ? Database::one(
    'SELECT COALESCE(SUM(p.expected_amount_subunit), 0) AS expected,
            COALESCE(SUM(p.paid_amount_subunit), 0) AS paid
       FROM payments p WHERE p.order_id = :id',
    [':id' => $selectedId]
) : null;
$refundedSubunit = $selected ? (int) (Database::one(
    'SELECT COALESCE(SUM(amount_subunit), 0) AS total FROM refunds
      WHERE order_id = :id AND status = :processed',
    [':id' => $selectedId, ':processed' => Refunds::STATUS_PROCESSED]
)['total'] ?? 0) : 0;
$expectedSubunit = (int) ($ledger['expected'] ?? 0);
$paidSubunit     = (int) ($ledger['paid'] ?? 0);
$netSubunit      = max(0, $paidSubunit - $refundedSubunit);
$outstandingSubunit = Money::balance($expectedSubunit, $netSubunit);

// Everything that has been sent about this order, so "we emailed them" is a
// fact on the screen rather than a belief.
try {
    $messages = $selected ? Notifications::forOrder($selectedId) : [];
} catch (Throwable $e) {
    error_log('admin orders notifications: ' . $e->getMessage());
    $messages = [];
}

// The Kitchen Run this order was made from, when it was made from one. PRD 4.4
// asks for deep links in both directions and names "kitchen run to the order"
// by name; the way back existed only as a sentence in the status history.
$kitchenRun = $selected && Rbac::can('kitchen_runs.view') ? Database::one(
    'SELECT id, request_number FROM kitchen_run_requests WHERE converted_order_id = :id',
    [':id' => $selectedId]
) : null;
$issueReports = $selected && Rbac::can('issues.view')
    ? IssueReports::forOrderStaff($selectedId)
    : [];

$canCancel = Rbac::can('orders.cancel');
$canNote     = Rbac::can('orders.update');
$canResend   = Rbac::can('notifications.resend');
$canDocument = Rbac::can('payments.view');
$canRefund = Rbac::can('payments.refund');
$canTransition = Rbac::can('orders.status.update');
$canReschedule = Rbac::can('orders.reschedule');
$targets = $selected ? OrderLifecycle::staffTargets((string) $selected['order_status']) : [];

// How the money reads (the same helper the customer sees), and the payment gate
// on Placed to Sourced. The gate is enforced again on the server inside the stage
// change itself; this only decides what the screen offers.
$orderMoney = $selected ? OrderMoney::forOrder($selectedId) : null;
$gate = null;
$creditShortcut = false;
$gateFacility = null;
if ($selected && (string) $selected['order_status'] === 'pending') {
    $gate = SourcingGate::forOrder($selectedId);
    $gateOrder = Database::one(
        'SELECT user_id, payment_option, order_total_subunit, amount_paid_subunit FROM orders WHERE id = :id',
        [':id' => $selectedId]
    );
    if ($gate !== null && !$gate['allowed'] && $gateOrder !== null && $gateOrder['user_id'] !== null) {
        $gateFacility = Credit::facilityForUser((int) $gateOrder['user_id']);
        $creditShortcut = SourcingGate::creditShortcutOffered($gateOrder, $gateFacility)
            && !Payments::hasAttemptInFlight($selectedId);
    }
}
$gateBlocked = $gate !== null && !$gate['allowed'];
$canOverride = Rbac::can('orders.source.override');
$stageError = (string) okv_input('stage_error', '');
// Out of stock: who may mark a line short, what has been marked, and what the
// last action said.
$canShort   = Rbac::can('orders.shortage.record');
$canPayRefund = Rbac::can('payments.refund');
$shortages  = $selectedId > 0 ? Shortages::forOrder($selectedId) : [];
$shortageFlag  = (string) okv_input('shortage', '');
$shortageError = (string) okv_input('shortage_error', '');
$shortageNotices = [
    'recorded_choice'  => 'Marked short. The customer has been emailed a link to choose how to get their money back.',
    'recorded_reduced' => 'Marked short. The value came off what the customer still owes, so nothing is owed back.',
    'decided'          => 'Done. The customer has been told.',
    'withdrawn'        => 'Undone. The line is back on the order.',
];
$stageErrorMessages = [
    'stale'                   => 'This order changed after the page loaded. Reload it before choosing the next stage.',
    'invalid_transition'      => 'That order cannot move to the chosen stage.',
    'note_too_long'           => 'Keep the internal note to 500 characters.',
    'override_reason_invalid' => 'Give the reason for sourcing this order anyway, using 10 to 200 characters.',
    'not_found'               => 'That order could not be found.',
];
$flag = (string) okv_input('cancellation', '');
$rescheduleFlag = (string) okv_input('reschedule', '');
$statusFlag = (string) okv_input('status', '');

// Every list link and page switch keeps the filters in play, so a search or a
// stage stays put while pages turn or an order is opened.
$orderFilterQuery = array_filter([
    'filter_status'   => $statusFilter,
    'filter_date'     => $dateFilter,
    'filter_created'  => $createdFilter,
    'filter_customer' => $customerFilter,
], static fn ($v): bool => $v !== '');
$ordersPageUrl   = static fn (int $n): string => '/admin/orders.php?' . http_build_query($orderFilterQuery + ['page' => $n]);
$orderDetailUrl  = static fn (int $id): string => '/admin/orders.php?' . http_build_query($orderFilterQuery + ['page' => $page, 'order' => $id]);
$filtersActive   = $statusFilter !== '' || $dateFilter !== '' || $createdFilter !== '' || $customerFilter !== '';

$okv_admin_title = 'Orders';
$okv_admin_note  = 'Every order, what was paid, the delivery day it is on, and the trail the customer follows.';
// On a narrow screen the detail opens in a sheet rather than stacking below the
// list. Desktop keeps the two-column layout; with JavaScript off, ?order= loads
// the full page as before.
$okv_admin_script = '/assets/js/admin-orders.js';
// Static markup, authored here rather than built from request or database data,
// as the header component requires. The gate is UX only: api/v1/orders.php
// re-checks orders.create on the server.
if (Rbac::can('orders.create')) {
    $okv_admin_actions = '<a class="okv-btn px-4" href="/admin/order_new.php">Take an order</a>';
}
require __DIR__ . '/../includes/components/admin/header.php';
?>
<?php if (okv_input('created', '') !== ''): ?>
  <?php $paymentFlag = (string) okv_input('payment', ''); ?>
  <p class="okv-note-ok mb-5" role="status">
    The order has been created and the customer has their confirmation and trail link.
    <?php if ($paymentFlag === 'payment_recorded'): ?>
      The money you recorded is on it, and is waiting in the proof queue for review.
    <?php elseif ($paymentFlag === 'payment_failed'): ?>
      The payment you entered was <strong>not</strong> recorded. Record it on the
      <a class="underline" href="/admin/payments.php">Payments screen</a>. The order itself is fine.
    <?php endif; ?>
  </p>
<?php endif; ?>
<?php if ($flag !== ''): ?>
  <p class="okv-note-ok mb-5" role="status"><?= $flag === 'already_cancelled' ? 'The order was already cancelled. No second refund was raised.' : 'The order has been cancelled.' ?></p>
<?php endif; ?>
<?php if ($rescheduleFlag !== ''): ?>
  <p class="okv-note-ok mb-5" role="status"><?= $rescheduleFlag === 'already_rescheduled' ? 'That delivery day was already set. No change was made.' : 'The delivery day has been moved. Customer and team have been told.' ?></p>
<?php endif; ?>
<?php if ($stageError !== ''): ?>
  <p class="okv-note-bad mb-5" role="alert"><?=
    okv_e(
        in_array($stageError, ['payment_required', 'deposit_required', 'credit_missing'], true)
            ? ($gateBlocked ? $gate['message'] : 'This order can be sourced now. Try again.')
            : ($stageErrorMessages[$stageError]
                ?? (in_array($stageError, ['not_convertible', 'payment_in_progress', 'credit_not_approved', 'credit_limit_exceeded', 'invalid_charge'], true)
                    ? Credit::message($stageError)
                    : 'The order was not changed. Please reload it and try again.'))
    ) ?></p>
<?php endif; ?>
<?php if ($statusFlag !== ''): ?>
  <p class="okv-note-ok mb-5" role="status"><?=
    $statusFlag === 'already_transitioned' ? 'That stage was already recorded. No duplicate history was added.'
      : ($statusFlag === 'note_saved' ? 'The internal note has been saved. The customer never sees it.'
      : ($statusFlag === 'resent' ? 'That email has been sent again.'
      : 'The order stage has been updated. The customer has been told.')) ?></p>
<?php endif; ?>

<details class="group mb-5 rounded-md border border-mist bg-white" <?= $filtersActive ? 'open' : '' ?>>
  <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-2 rounded-md px-4 py-3 text-sm font-semibold text-forest">
    <span class="flex items-center gap-2">
      Filters
      <?php if ($filtersActive): ?><span class="okv-badge okv-badge-available">On</span><?php endif; ?>
    </span>
    <svg class="h-4 w-4 transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M5 8l5 5 5-5" stroke-linecap="round" stroke-linejoin="round"></path>
    </svg>
  </summary>
  <form method="get" class="grid gap-3 border-t border-mist p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div><label class="okv-label" for="filter-status">Stage</label><select class="okv-input mt-1" id="filter-status" name="filter_status"><option value="">All stages</option><?php foreach ($validStatuses as $status): ?><option value="<?= okv_e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= okv_e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
    <div><label class="okv-label" for="filter-date">Delivery date</label><input class="okv-input mt-1" id="filter-date" type="date" name="filter_date" value="<?= okv_e($dateFilter) ?>"></div>
    <div><label class="okv-label" for="filter-created">Order placed</label><input class="okv-input mt-1" id="filter-created" type="date" name="filter_created" value="<?= okv_e($createdFilter) ?>"></div>
    <div><label class="okv-label" for="filter-customer">Customer or order</label><input class="okv-input mt-1" id="filter-customer" name="filter_customer" value="<?= okv_e($customerFilter) ?>"></div>
    <button class="okv-btn min-h-[44px] self-end justify-center">Filter orders</button>
  </form>
</details>

<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.4fr)]">
  <section class="okv-panel" aria-labelledby="orders-list-heading">
    <div class="okv-panel-head">
      <h2 id="orders-list-heading" class="okv-panel-title">Latest orders</h2>
      <span class="text-xs text-ink-60"><?= okv_e(okv_page_summary($page, $totalOrders, $perPage, 'order')) ?></span>
    </div>
    <?php if (!$orders): ?>
      <p class="p-5 text-sm text-ink-60">No orders have been placed yet.</p>
    <?php else: ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($orders as $order): ?>
          <li>
            <a href="<?= okv_e($orderDetailUrl((int) $order['id'])) ?>"
               data-order-link
               class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= (int) $order['id'] === $selectedId ? 'bg-forest-tint' : '' ?>"
               <?= (int) $order['id'] === $selectedId ? 'aria-current="page"' : '' ?>>
              <span class="flex items-center justify-between gap-3">
                <strong class="font-mono text-sm"><?= okv_e($order['order_number']) ?></strong>
                <span class="okv-badge <?= (string) $order['order_status'] === 'cancelled' ? 'okv-badge-neutral' : 'okv-badge-available' ?>"><?= okv_e(ucfirst((string) $order['order_status'])) ?></span>
              </span>
              <span class="mt-1 flex items-center justify-between gap-3 text-xs text-ink-60">
                <span class="truncate"><?= okv_e($order['recipient_name'] ?: 'Customer') ?></span>
                <span class="font-mono"><?= okv_e(Money::format((int) $order['order_total_subunit'])) ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="px-4 pb-4">
        <?php okv_pagination($page, $pageCount, $ordersPageUrl, 'Order pages'); ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="okv-panel min-w-0" aria-labelledby="order-detail-heading" data-order-detail>
    <?php if (!$selected): ?>
      <p class="p-5 text-sm text-ink-60">Choose an order to see its details.</p>
    <?php else: ?>
      <div class="okv-panel-head flex-wrap items-center gap-3">
        <div>
          <p class="okv-eyebrow">Order</p>
          <h2 id="order-detail-heading" class="okv-panel-title mt-1 font-mono"><?= okv_e($selected['order_number']) ?></h2>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="okv-badge <?= (string) $selected['order_status'] === 'cancelled' ? 'okv-badge-neutral' : 'okv-badge-available' ?>"><?= okv_e(ucfirst((string) $selected['order_status'])) ?></span>
          <?php if ($selected['cancellation_id'] === null && $canCancel && $selected['may_cancel'] && !((int) $selected['amount_paid_subunit'] > 0 && !$canRefund)): ?>
            <a class="okv-btn-danger px-4 text-sm"
               href="#admin-cancel-order"
               onclick="var s=document.getElementById('staff-cancel-reason');if(s)s.focus();">
              Cancel order
            </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="space-y-6 p-4 md:p-5">
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-ink-60">Customer</dt>
            <dd class="mt-1 font-medium">
              <?php if (Rbac::can('customers.view') && !empty($selected['user_id'])): ?>
                <a class="underline decoration-mist underline-offset-2 hover:text-forest"
                   href="/admin/customers.php?customer=<?= (int) $selected['user_id'] ?>"><?= okv_e($address['recipient_name'] ?? 'Customer') ?></a>
              <?php else: ?>
                <?= okv_e($address['recipient_name'] ?? 'Customer') ?>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt class="text-ink-60">Delivery</dt>
            <dd class="mt-1">
              <?= okv_e(date('l jS F', strtotime((string) $selected['preferred_delivery_date']))) ?>
              <?php if (Rbac::can('delivery.manifest.view')): ?>
                <a class="ml-1 underline decoration-mist underline-offset-2 hover:text-forest"
                   href="/admin/delivery-manifest.php?date=<?= okv_e((string) $selected['preferred_delivery_date']) ?>">See the day</a>
              <?php endif; ?>
            </dd>
          </div>
          <div><dt class="text-ink-60">Order total</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format((int) $selected['order_total_subunit'])) ?></dd></div>
          <div><dt class="text-ink-60">Stage</dt><dd class="mt-1"><?= okv_e(ucfirst((string) $selected['order_status'])) ?></dd></div>
          <?php if ($kitchenRun): ?>
            <div>
              <dt class="text-ink-60">Came from</dt>
              <dd class="mt-1">
                <a class="font-mono underline decoration-mist underline-offset-2 hover:text-forest"
                   href="/admin/kitchen_runs.php?request=<?= (int) $kitchenRun['id'] ?>">
                  <?= okv_e((string) $kitchenRun['request_number']) ?>
                </a>
                <span class="block text-ink-60">Kitchen Run</span>
              </dd>
            </div>
          <?php endif; ?>
        </dl>

        <!-- The money, straight from the M5 ledger. Expected is what the
             payment rows ask for, net is what we have kept after refunds, and
             outstanding is what is still owed. -->
        <div>
          <h3 class="text-sm font-semibold text-ink">Money</h3>
          <?php if ($orderMoney): ?>
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
              <?php okv_money_badge($orderMoney); ?>
              <?php if ($orderMoney['credit_line'] !== ''): ?><span class="text-ink-60"><?= okv_e($orderMoney['credit_line']) ?></span><?php endif; ?>
            </p>
          <?php endif; ?>
          <dl class="mt-2 grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-5">
            <div><dt class="text-ink-60">Expected</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format($expectedSubunit)) ?></dd></div>
            <div><dt class="text-ink-60">Paid</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format($paidSubunit)) ?></dd></div>
            <div><dt class="text-ink-60">Refunded</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format($refundedSubunit)) ?></dd></div>
            <div><dt class="text-ink-60">Net</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format($netSubunit)) ?></dd></div>
            <div><dt class="text-ink-60"><?= $orderMoney && $orderMoney['on_credit'] ? 'Owed on credit' : 'Outstanding' ?></dt><dd class="mt-1 font-mono <?= $outstandingSubunit > 0 && !($orderMoney && $orderMoney['on_credit']) ? 'text-clay' : '' ?>"><?= okv_e(Money::format($outstandingSubunit)) ?></dd></div>
          </dl>
          <div class="mt-3 flex flex-wrap gap-2">
            <a class="okv-btn-outline min-h-[44px] px-3" href="/admin/order_trail.php?order=<?= (int) $selected['id'] ?>" target="_blank" rel="noopener">
              Open the customer trail<span class="sr-only">, opens in a new tab</span>
            </a>
            <?php if ($canDocument): ?>
              <a class="okv-btn-outline min-h-[44px] px-3" href="/public/documents/invoice.php?order=<?= (int) $selected['id'] ?>" target="_blank" rel="noopener">
                Invoice<span class="sr-only">, opens in a new tab</span>
              </a>
              <?php if ($paidSubunit > 0): ?>
                <a class="okv-btn-outline min-h-[44px] px-3" href="/public/documents/receipt.php?order=<?= (int) $selected['id'] ?>" target="_blank" rel="noopener">
                  Receipt<span class="sr-only">, opens in a new tab</span>
                </a>
              <?php endif; ?>
            <?php endif; ?>
            <?php foreach ($issueReports as $issueReport): ?>
              <a class="okv-btn-outline min-h-[44px] px-3" href="/admin/make_it_right.php?status=all&amp;report=<?= (int) $issueReport['id'] ?>">
                Make It Right: <?= okv_e(IssueReports::statusLabel((string) $issueReport['status'])) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($address): ?>
          <p class="text-sm text-ink-60"><strong class="text-ink">Zone:</strong> <?= okv_e($address['zone_name'] ?: 'Not assigned') ?>. <strong class="text-ink">Phone:</strong> <?= okv_e($address['recipient_phone']) ?>.</p>
          <p class="text-sm text-ink-60"><strong class="text-ink">Address:</strong> <?= okv_e(implode(', ', array_filter([$address['address_line_1'], $address['address_line_2'], $address['city'], $address['state']]))) ?><?= $address['landmark'] ? '. Near ' . okv_e($address['landmark']) : '' ?></p>
        <?php endif; ?>

        <div>
          <h3 class="text-sm font-semibold text-ink">Items</h3>
          <ul class="mt-2 divide-y divide-mist border-y border-mist">
            <?php foreach ($items as $item): ?>
              <li class="py-3 text-sm">
                <div class="flex justify-between gap-4">
                  <span><?= okv_e(okv_quantity($item['quantity'])) ?> <?= okv_e($item['unit_name']) ?> <?= okv_e($item['item_name']) ?>
                    <?php if ($item['components']): ?><span class="mt-1 block text-xs text-ink-60"><?php foreach ($item['components'] as $i => $component): ?><?= $i ? ', ' : '' ?><?= okv_e(okv_quantity($component['quantity'])) ?> <?= okv_e($component['unit_name']) ?> <?= okv_e($component['product_name']) ?> per basket<?php endforeach; ?></span><?php endif; ?>
                  </span>
                  <span class="font-mono"><?= okv_e(Money::format((int) $item['line_total_subunit'])) ?></span>
                </div>
                <?php if ((float) $item['quantity'] <= 0): ?>
                  <p class="mt-1 text-xs font-semibold text-clay-ink">Out of stock. This line is no longer on the order.</p>
                <?php elseif ($canShort && in_array((string) $selected['order_status'], Shortages::STAGES, true)): ?>
                  <details class="mt-2 rounded-md border border-mist">
                    <summary class="flex min-h-[44px] cursor-pointer list-none items-center px-3 text-sm font-semibold text-forest">Mark out of stock</summary>
                    <form action="/api/v1/shortages.php" method="POST" class="grid gap-3 border-t border-mist p-3 sm:grid-cols-2" data-once>
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="record">
                      <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                      <div>
                        <label class="okv-label" for="short-qty-<?= (int) $item['id'] ?>">How many are short? (of <?= okv_e(okv_quantity($item['quantity'])) ?> <?= okv_e($item['unit_name']) ?>)</label>
                        <input class="okv-input" id="short-qty-<?= (int) $item['id'] ?>" name="quantity" inputmode="decimal" value="<?= okv_e(okv_quantity($item['quantity'])) ?>">
                      </div>
                      <div>
                        <label class="okv-label" for="short-reason-<?= (int) $item['id'] ?>">Note, for the team</label>
                        <input class="okv-input" id="short-reason-<?= (int) $item['id'] ?>" name="reason" maxlength="200" placeholder="Optional">
                      </div>
                      <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button type="submit" class="okv-btn min-h-[44px] px-4">Mark this many short</button>
                        <button type="submit" name="whole" value="1" class="okv-btn-outline min-h-[44px] px-4">Whole line is out</button>
                      </div>
                    </form>
                  </details>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if ($shortages || $shortageFlag !== '' || $shortageError !== ''): ?>
        <div id="shortages" class="scroll-mt-20">
          <h3 class="text-sm font-semibold text-ink">Out of stock</h3>
          <?php if (isset($shortageNotices[$shortageFlag])): ?>
            <p class="okv-note mt-2 bg-foliage-tint" role="status"><?= okv_e($shortageNotices[$shortageFlag]) ?></p>
          <?php elseif ($shortageError !== ''): ?>
            <p class="okv-note mt-2 bg-clay-tint" role="alert"><?= okv_e(Shortages::message($shortageError)) ?></p>
          <?php endif; ?>
          <ul class="mt-2 divide-y divide-mist border-y border-mist">
            <?php foreach ($shortages as $sh): ?>
              <li class="py-3 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                  <span><strong class="text-ink"><?= okv_e((string) $sh['item_line']) ?></strong>
                    <?php
                      $shortageBits = ['worth ' . Money::format((int) $sh['amount_subunit'])];
                      if ((int) $sh['reduced_subunit'] > 0) { $shortageBits[] = Money::format((int) $sh['reduced_subunit']) . ' off what is still to pay'; }
                      if ((int) $sh['refund_due_subunit'] > 0) { $shortageBits[] = Money::format((int) $sh['refund_due_subunit']) . ' owed back'; }
                    ?>
                    <span class="text-ink-60"><?= okv_e(implode(', ', $shortageBits)) ?>.</span>
                  </span>
                  <span class="okv-badge <?= (string) $sh['status'] === 'awaiting_choice' ? 'okv-badge-warn' : ((string) $sh['status'] === 'withdrawn' ? 'okv-badge-neutral' : 'okv-badge-available') ?>"><?= okv_e((string) $sh['status_label']) ?></span>
                </div>
                <?php if (!empty($sh['reason'])): ?><p class="mt-1 text-xs text-ink-60"><?= okv_e((string) $sh['reason']) ?></p><?php endif; ?>
                <?php if ($canShort && (string) $sh['status'] === 'awaiting_choice'): ?>
                  <div class="mt-2 flex flex-wrap items-start gap-2">
                    <?php if ($selected['user_id'] !== null): ?>
                      <form action="/api/v1/shortages.php" method="POST" data-once>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="decide_for_customer">
                        <input type="hidden" name="shortage_id" value="<?= (int) $sh['id'] ?>">
                        <input type="hidden" name="choice" value="wallet">
                        <button type="submit" class="okv-btn min-h-[44px] px-4">Add to their wallet</button>
                      </form>
                    <?php endif; ?>
                    <details class="rounded-md border border-mist">
                      <summary class="flex min-h-[44px] cursor-pointer list-none items-center px-3 text-sm font-semibold text-forest">Refund to their bank</summary>
                      <form action="/api/v1/shortages.php" method="POST" class="grid gap-2 border-t border-mist p-3" data-once>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="decide_for_customer">
                        <input type="hidden" name="shortage_id" value="<?= (int) $sh['id'] ?>">
                        <input type="hidden" name="choice" value="bank">
                        <input class="okv-input" name="bank_name" required maxlength="100" placeholder="Bank" aria-label="Bank">
                        <input class="okv-input" name="account_number" required inputmode="numeric" maxlength="14" placeholder="Account number, 10 digits" aria-label="Account number">
                        <input class="okv-input" name="account_name" required maxlength="150" placeholder="Name on the account" aria-label="Name on the account">
                        <button type="submit" class="okv-btn min-h-[44px] px-4">Queue the refund</button>
                      </form>
                    </details>
                  </div>
                <?php endif; ?>
                <?php if ($canShort && !empty($sh['can_undo'])): ?>
                  <form action="/api/v1/shortages.php" method="POST" class="mt-2" data-once>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="withdraw">
                    <input type="hidden" name="shortage_id" value="<?= (int) $sh['id'] ?>">
                    <button type="submit" class="okv-btn-text min-h-[44px]">Undo, it was marked by mistake</button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($sh['refund_number'])): ?>
                  <p class="mt-1 text-xs text-ink-60">
                    Refund <?= okv_e((string) $sh['refund_number']) ?>: <?= okv_e(ucfirst((string) $sh['refund_status'])) ?>.
                    <?php if ($canPayRefund): ?><a class="text-forest underline" href="/admin/payments.php#refunds-heading">Open the refund queue</a><?php endif; ?>
                  </p>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div>
          <h3 class="text-sm font-semibold text-ink">Status history</h3>
          <ol class="mt-2 divide-y divide-mist border-y border-mist">
            <?php foreach ($history as $event): ?>
              <li class="py-3 text-sm">
                <span class="font-semibold"><?= okv_e(ucfirst((string) $event['new_status'])) ?></span>
                <span class="text-ink-60">at <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?> by <?= okv_e(trim((string) $event['actor_name']) ?: ucfirst((string) $event['source'])) ?></span>
                <?php if ($event['note']): ?><p class="mt-1 text-xs text-ink-60"><?= nl2br(okv_e($event['note'])) ?></p><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>

        <div>
          <h3 class="text-sm font-semibold text-ink">Reschedule history</h3>
          <?php if ($rescheduleState): ?>
            <p class="mt-1 text-xs text-ink-60"><?= okv_e($rescheduleState['policy_line']) ?></p>
          <?php endif; ?>
          <?php if (!$rescheduleHistory): ?>
            <p class="mt-2 text-sm text-ink-60">This order has not been rescheduled yet.</p>
          <?php else: ?>
            <ol class="mt-2 divide-y divide-mist border-y border-mist">
              <?php foreach ($rescheduleHistory as $rh): ?>
                <li class="py-3 text-sm">
                  <span class="font-semibold"><?= okv_e(date('j M Y', strtotime((string) $rh['old_delivery_date']))) ?> → <?= okv_e(date('j M Y', strtotime((string) $rh['new_delivery_date']))) ?></span>
                  <span class="text-ink-60">at <?= okv_e(date('j M Y, H:i', strtotime((string) $rh['created_at']))) ?> by <?= $rh['actor_type'] === 'customer' ? 'Customer' : okv_e(trim((string) ($rh['first_name'] . ' ' . $rh['last_name'])) ?: 'Staff') ?></span>
                  <?php if (!empty($rh['reason'])): ?><p class="mt-1 text-xs text-ink-60"><?= nl2br(okv_e($rh['reason'])) ?></p><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>

          <?php if ($rescheduleState && $rescheduleState['may_reschedule']): ?>
            <?php if (!$canReschedule): ?>
              <p class="okv-note bg-clay-tint mt-3">You may view this order, but you do not have permission to reschedule it.</p>
            <?php else: ?>
              <div class="rounded-md border border-foliage bg-foliage-tint p-4 mt-4">
                <h4 class="font-semibold text-ink">Move delivery to another day</h4>
                <p class="mt-1 text-sm text-ink-60">Only eligible delivery days are shown. The order total stays the same.</p>
                <form action="/api/v1/orders.php" method="POST" class="mt-4 grid gap-3">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="reschedule_staff">
                  <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
                  <input type="hidden" name="expected_delivery_date" value="<?= okv_e((string) $selected['preferred_delivery_date']) ?>">
                  <div>
                    <label class="okv-label" for="staff-reschedule-date">New delivery day</label>
                    <?php if (!empty($rescheduleState['eligible_dates'])): ?>
                      <select class="okv-input mt-1" id="staff-reschedule-date" name="new_delivery_date" required>
                        <option value="">Choose a day</option>
                        <?php foreach ($rescheduleState['eligible_dates'] as $d): ?>
                          <option value="<?= okv_e($d['date']) ?>"><?= okv_e(date('l jS F', strtotime($d['date']))) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <p class="rounded-md border border-mist bg-white px-3 py-2 text-sm text-ink-60">No other delivery days are open right now.</p>
                      <input type="hidden" name="new_delivery_date" value="">
                    <?php endif; ?>
                  </div>
                  <div>
                    <label class="okv-label" for="staff-reschedule-note">Reason, optional</label>
                    <textarea class="okv-input mt-1" id="staff-reschedule-note" name="reason_text" rows="2" maxlength="500" placeholder="Why the date is moving"></textarea>
                  </div>
                  <label class="flex min-h-[44px] items-start gap-3 text-sm">
                    <input type="checkbox" name="confirmed" value="1" class="mt-1 h-5 w-5" required>
                    <span>I understand the delivery will move and the customer and team will be told.</span>
                  </label>
                  <button class="okv-btn min-h-[44px] px-4">Move delivery</button>
                </form>
              </div>
            <?php endif; ?>
          <?php elseif ($rescheduleState && !empty($rescheduleState['restriction'])): ?>
            <p class="okv-note bg-clay-tint mt-3"><?= okv_e($rescheduleState['restriction']) ?></p>
          <?php endif; ?>
        </div>

        <!-- The internal note. It lives on the order, never in the status
             history, so it can never surface as a step on the public trail. -->
        <div>
          <h3 class="text-sm font-semibold text-ink">Internal note</h3>
          <p class="mt-1 text-xs text-ink-60">For the team only. The customer never sees this, on the trail or in any email.</p>
          <?php if ($canNote): ?>
            <form action="/api/v1/orders.php" method="POST" class="mt-2 space-y-3">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="save_note">
              <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
              <label class="sr-only" for="staff-note">Internal note</label>
              <textarea class="okv-input" id="staff-note" name="staff_note" rows="3" maxlength="2000"><?= okv_e((string) ($selected['staff_note'] ?? '')) ?></textarea>
              <button class="okv-btn-outline min-h-[44px] px-4">Save the note</button>
            </form>
          <?php elseif (trim((string) ($selected['staff_note'] ?? '')) !== ''): ?>
            <p class="mt-2 text-sm"><?= nl2br(okv_e((string) $selected['staff_note'])) ?></p>
          <?php else: ?>
            <p class="mt-2 text-sm text-ink-60">No note yet.</p>
          <?php endif; ?>
        </div>

        <!-- What was actually sent. A belief becomes a fact here: the address,
             the moment, whether it landed, and the error when it did not. -->
        <div>
          <h3 class="text-sm font-semibold text-ink">Messages sent</h3>
          <?php if (!$messages): ?>
            <p class="mt-2 text-sm text-ink-60">Nothing has been sent about this order yet.</p>
          <?php else: ?>
            <ul class="mt-2 divide-y divide-mist border-y border-mist">
              <?php foreach ($messages as $message): ?>
                <li class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                  <span class="min-w-0">
                    <span class="font-medium"><?= okv_e(Notifications::EVENTS[(string) $message['event_type']]['label'] ?? ucfirst(str_replace('_', ' ', (string) $message['event_type']))) ?></span>
                    <span class="text-ink-60">
                      by <?= (string) $message['channel'] === 'email' ? 'email to ' . okv_e((string) $message['recipient_address']) : 'in the app' ?>,
                      <?= okv_e(date('j M Y, H:i', strtotime((string) $message['created_at']))) ?>
                    </span>
                    <?php if ($message['last_error']): ?>
                      <span class="mt-1 block text-xs text-clay-ink"><?= okv_e((string) $message['last_error']) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php $state = Notifications::deliveryState($message); ?>
                  <span class="flex shrink-0 items-center gap-2">
                    <span class="okv-badge <?= $state['tone'] === 'good' ? 'okv-badge-available' : 'okv-badge-warn' ?>">
                      <?= okv_e($state['label']) ?>
                    </span>
                    <?php if ($canResend && $state['may_resend']): ?>
                      <form action="/api/v1/orders.php" method="POST">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="resend_notification">
                        <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
                        <input type="hidden" name="delivery_id" value="<?= (int) $message['delivery_id'] ?>">
                        <button class="okv-btn-text min-h-[44px]">Send it again</button>
                      </form>
                    <?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if ($canTransition && $targets && $gateBlocked): ?>
          <!-- Payment gate. Sourcing spends our money on produce, so the order has
               to be covered first. Short text, the way forward as buttons. -->
          <div class="rounded-md border border-clay bg-clay-tint p-4" id="sourcing-gate">
            <h3 class="font-semibold text-ink">Payment needed before sourcing</h3>
            <p class="mt-1 text-sm text-ink"><?= okv_e($gate['message']) ?></p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
              <?php if ($creditShortcut): ?>
                <form action="/api/v1/orders.php" method="post" data-once>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="transition">
                  <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
                  <input type="hidden" name="expected_status" value="<?= okv_e($selected['order_status']) ?>">
                  <input type="hidden" name="target_status" value="confirmed">
                  <input type="hidden" name="source_on_credit" value="1">
                  <button class="okv-btn min-h-[44px] px-4">Source on credit line</button>
                </form>
                <span class="text-sm text-ink-60"><?= okv_e(Money::format((int) ($gateFacility['available_subunit'] ?? 0))) ?> available</span>
              <?php endif; ?>
              <?php if (Rbac::can('payments.record')): ?>
                <a class="okv-btn-outline min-h-[44px] px-4" href="/admin/payments.php?order_id=<?= (int) $selected['id'] ?>">Record a payment</a>
              <?php endif; ?>
            </div>
            <?php if ($canOverride): ?>
              <details class="mt-4 rounded-md border border-mist bg-white p-3">
                <summary class="flex min-h-[44px] cursor-pointer items-center text-sm font-semibold text-tomato">Source anyway (Owner)</summary>
                <form action="/api/v1/orders.php" method="post" class="mt-3 grid gap-3" data-once>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="transition">
                  <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
                  <input type="hidden" name="expected_status" value="<?= okv_e($selected['order_status']) ?>">
                  <input type="hidden" name="target_status" value="confirmed">
                  <div>
                    <label class="okv-label" for="override-reason">Reason (kept on the order and the audit log)</label>
                    <input class="okv-input mt-1" id="override-reason" name="override_reason" minlength="10" maxlength="200" required>
                  </div>
                  <button class="okv-btn-outline min-h-[44px] justify-center border-tomato px-4 text-tomato sm:w-fit">Source with this reason</button>
                </form>
              </details>
            <?php endif; ?>
          </div>
        <?php elseif ($canTransition && $targets): ?>
          <div class="rounded-md border border-foliage bg-foliage-tint p-4">
            <h3 class="font-semibold text-ink">Move this order forward</h3>
            <p class="mt-1 text-sm text-ink-60">The current stage is rechecked when you submit. The note stays internal.</p>
            <form action="/api/v1/orders.php" method="post" class="mt-4 grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_minmax(14rem,2fr)_auto]">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="transition">
              <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
              <input type="hidden" name="expected_status" value="<?= okv_e($selected['order_status']) ?>">
              <div><label class="okv-label" for="target-status">Next stage</label><select class="okv-input mt-1" id="target-status" name="target_status" required><?php foreach ($targets as $target): ?><option value="<?= okv_e($target) ?>"><?= okv_e($target === 'confirmed' ? 'Confirm as sourced' : ucfirst($target)) ?></option><?php endforeach; ?></select></div>
              <div><label class="okv-label" for="status-note">Internal note (optional)</label><input class="okv-input mt-1" id="status-note" name="note" maxlength="500"></div>
              <button class="okv-btn min-h-[44px] self-end justify-center px-4">Record stage</button>
            </form>
          </div>
        <?php elseif (!$canTransition && $targets): ?>
          <p class="okv-note bg-clay-tint">You may view this order, but you do not have permission to change its stage.</p>
        <?php endif; ?>

        <?php if ($selected['cancellation_id'] !== null): ?>
          <div class="rounded-md border border-mist bg-forest-tint p-4">
            <h3 class="font-semibold">Cancellation recorded</h3>
            <p class="mt-1 text-sm text-ink-60"><?= okv_e(OrderCancellation::STAFF_REASONS[(string) $selected['reason_code']] ?? OrderCancellation::CUSTOMER_REASONS[(string) $selected['reason_code']] ?? 'Reason recorded') ?></p>
            <?php if ($selected['reason_text']): ?><p class="mt-1 text-sm"><?= nl2br(okv_e($selected['reason_text'])) ?></p><?php endif; ?>
            <p class="mt-2 text-sm"><strong>Refund:</strong> <?= okv_e(str_replace('_', ' ', ucfirst((string) $selected['refund_status']))) ?>.</p>
            <?php foreach ($selected['refunds'] as $refund): ?>
              <p class="mt-2 text-sm"><?= okv_e(Money::format((int) $refund['amount_subunit'])) ?>: <?= okv_e(OrderCancellation::refundStatusLine((string) $refund['status'], (string) $selected['refund_status'])) ?></p>
            <?php endforeach; ?>
          </div>
        <?php elseif (!$canCancel): ?>
          <p class="okv-note bg-clay-tint">You may view this order, but you do not have permission to cancel it.</p>
        <?php elseif (!$selected['may_cancel']): ?>
          <p class="okv-note bg-clay-tint"><?= okv_e($selected['restriction'] ?: 'This order can no longer be cancelled.') ?></p>
        <?php elseif ((int) $selected['amount_paid_subunit'] > 0 && !$canRefund): ?>
          <p class="okv-note bg-clay-tint">Money has been paid on this order. An Owner must cancel it because the cancellation also raises a refund.</p>
        <?php else: ?>
          <div id="admin-cancel-order" class="scroll-mt-6 rounded-md border border-tomato/20 bg-tomato-tint p-4">
            <h3 class="font-semibold text-ink">Cancel this order</h3>
            <p class="mt-1 text-sm text-ink-60">
              <?= okv_e(Cancellation::staffSummary($selected['money_outcome'])) ?>
              <?php if (!empty($selected['is_dispatched'])): ?>
                <strong class="text-ink">Order is already dispatched.</strong>
              <?php endif; ?>
            </p>
            <form action="/api/v1/orders.php" method="POST" class="mt-4 grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_minmax(14rem,2fr)_auto]">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="cancel_staff">
              <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
              <input type="hidden" name="confirmed" value="1">
              <?php if (!empty($selected['is_dispatched'])): ?>
                <input type="hidden" name="dispatch_terms" value="1">
              <?php endif; ?>
              <div>
                <label for="staff-cancel-reason" class="okv-label">Reason</label>
                <select id="staff-cancel-reason" name="reason_code" class="okv-input mt-1" required>
                  <option value="">Choose a reason</option>
                  <?php foreach (OrderCancellation::STAFF_REASONS as $value => $label): ?>
                    <option value="<?= okv_e($value) ?>"><?= okv_e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="staff-cancel-note" class="okv-label">Internal note (optional)</label>
                <input id="staff-cancel-note" name="reason_text" class="okv-input mt-1" maxlength="1000">
              </div>
              <button type="submit" class="okv-btn-danger min-h-[44px] self-end justify-center px-4">Cancel order</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- The order detail as a sheet, for narrow screens only. admin-orders.js moves
     the server-rendered detail in here (or fetches the tapped order) and opens
     it, so the list no longer scrolls away under a long order. Desktop never
     opens this; it keeps the two-column layout above. -->
<div class="okv-order-backdrop xl:hidden" id="order-sheet" hidden>
  <section class="okv-order-sheet" aria-labelledby="order-sheet-title" tabindex="-1" data-order-panel>
    <div class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-mist bg-white px-4 py-2">
      <h2 id="order-sheet-title" class="okv-panel-title text-base">Order details</h2>
      <button type="button" class="okv-btn-text min-h-[44px] px-2" data-order-close>Close</button>
    </div>
    <div id="order-sheet-body"></div>
  </section>
</div>
<?php

require __DIR__ . '/../includes/components/admin/footer.php';
