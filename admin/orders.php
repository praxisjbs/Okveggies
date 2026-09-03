<?php
/** Staff order list and cancellation detail. */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('orders.view');

$statusFilter = trim((string) okv_input('filter_status', ''));
$dateFilter = trim((string) okv_input('filter_date', ''));
$customerFilter = mb_substr(trim((string) okv_input('filter_customer', '')), 0, 100);
$validStatuses = ['pending', 'confirmed', 'packed', 'dispatched', 'delivered', 'cancelled'];
$where = []; $params = [];
if (in_array($statusFilter, $validStatuses, true)) { $where[] = 'o.order_status = :status'; $params[':status'] = $statusFilter; }
if (Delivery::validDate($dateFilter)) { $where[] = 'o.preferred_delivery_date = :date'; $params[':date'] = $dateFilter; }
if ($customerFilter !== '') { $where[] = '(a.recipient_name LIKE :customer OR o.order_number LIKE :customer)'; $params[':customer'] = '%' . $customerFilter . '%'; }
$orders = Database::all(
    'SELECT o.id, o.order_number, o.order_status, o.payment_status, o.order_total_subunit,
            o.preferred_delivery_date, o.created_at, a.recipient_name
       FROM orders o
       LEFT JOIN order_addresses a ON a.order_id = o.id
      ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
      ORDER BY o.id DESC LIMIT 50',
    $params
);
$selectedId = (int) okv_input('order', $orders ? $orders[0]['id'] : 0);
$selected = $selectedId > 0 ? OrderCancellation::forStaff($selectedId) : null;
$items = $selected ? Database::all(
    'SELECT id, item_type, item_name, quantity, unit_name, line_total_subunit FROM order_items WHERE order_id = :id ORDER BY id',
    [':id' => $selectedId]
) : [];
foreach ($items as &$item) {
    $item['components'] = (string) $item['item_type'] === 'combo'
        ? Database::all('SELECT product_name, quantity, unit_name FROM order_item_components WHERE order_item_id = :id ORDER BY id', [':id' => (int) $item['id']])
        : [];
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
$canCancel = Rbac::can('orders.cancel');
$canRefund = Rbac::can('payments.refund');
$canTransition = Rbac::can('orders.status.update');
$targets = $selected ? OrderLifecycle::staffTargets((string) $selected['order_status']) : [];
$flag = (string) okv_input('cancellation', '');
$statusFlag = (string) okv_input('status', '');

$okv_admin_title = 'Orders';
$okv_admin_note  = 'Every order, what was paid, the delivery day it is on, and the trail the customer follows.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<?php if ($flag !== ''): ?>
  <p class="okv-note-ok mb-5" role="status"><?= $flag === 'already_cancelled' ? 'The order was already cancelled. No second refund was raised.' : 'The order has been cancelled.' ?></p>
<?php endif; ?>
<?php if ($statusFlag !== ''): ?>
  <p class="okv-note-ok mb-5" role="status"><?= $statusFlag === 'already_transitioned' ? 'That stage was already recorded. No duplicate history was added.' : 'The order stage has been updated.' ?></p>
<?php endif; ?>

<form method="get" class="mb-5 grid gap-3 rounded-md border border-mist bg-white p-4 sm:grid-cols-4">
  <div><label class="okv-label" for="filter-status">Stage</label><select class="okv-input mt-1" id="filter-status" name="filter_status"><option value="">All stages</option><?php foreach ($validStatuses as $status): ?><option value="<?= okv_e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= okv_e(ucfirst($status)) ?></option><?php endforeach; ?></select></div>
  <div><label class="okv-label" for="filter-date">Delivery date</label><input class="okv-input mt-1" id="filter-date" type="date" name="filter_date" value="<?= okv_e($dateFilter) ?>"></div>
  <div><label class="okv-label" for="filter-customer">Customer or order</label><input class="okv-input mt-1" id="filter-customer" name="filter_customer" value="<?= okv_e($customerFilter) ?>"></div>
  <button class="okv-btn min-h-[44px] self-end justify-center">Filter orders</button>
</form>

<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.4fr)]">
  <section class="okv-panel" aria-labelledby="orders-list-heading">
    <div class="okv-panel-head">
      <h2 id="orders-list-heading" class="okv-panel-title">Latest orders</h2>
      <span class="text-xs text-ink-60">Last 50</span>
    </div>
    <?php if (!$orders): ?>
      <p class="p-5 text-sm text-ink-60">No orders have been placed yet.</p>
    <?php else: ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($orders as $order): ?>
          <li>
            <a href="/admin/orders.php?order=<?= (int) $order['id'] ?>"
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
    <?php endif; ?>
  </section>

  <section class="okv-panel min-w-0" aria-labelledby="order-detail-heading">
    <?php if (!$selected): ?>
      <p class="p-5 text-sm text-ink-60">Choose an order to see its details.</p>
    <?php else: ?>
      <div class="okv-panel-head flex-wrap gap-3">
        <div>
          <p class="okv-eyebrow">Order</p>
          <h2 id="order-detail-heading" class="okv-panel-title mt-1 font-mono"><?= okv_e($selected['order_number']) ?></h2>
        </div>
        <span class="okv-badge <?= (string) $selected['order_status'] === 'cancelled' ? 'okv-badge-neutral' : 'okv-badge-available' ?>"><?= okv_e(ucfirst((string) $selected['order_status'])) ?></span>
      </div>
      <div class="space-y-6 p-4 md:p-5">
        <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div><dt class="text-ink-60">Customer</dt><dd class="mt-1 font-medium"><?= okv_e($address['recipient_name'] ?? 'Customer') ?></dd></div>
          <div><dt class="text-ink-60">Delivery</dt><dd class="mt-1"><?= okv_e(date('l jS F', strtotime((string) $selected['preferred_delivery_date']))) ?></dd></div>
          <div><dt class="text-ink-60">Order total</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format((int) $selected['order_total_subunit'])) ?></dd></div>
          <div><dt class="text-ink-60">Paid</dt><dd class="mt-1 font-mono"><?= okv_e(Money::format((int) $selected['amount_paid_subunit'])) ?></dd></div>
        </dl>
        <?php if ($address): ?>
          <p class="text-sm text-ink-60"><strong class="text-ink">Zone:</strong> <?= okv_e($address['zone_name'] ?: 'Not assigned') ?>. <strong class="text-ink">Phone:</strong> <?= okv_e($address['recipient_phone']) ?>.</p>
          <p class="text-sm text-ink-60"><strong class="text-ink">Address:</strong> <?= okv_e(implode(', ', array_filter([$address['address_line_1'], $address['address_line_2'], $address['city'], $address['state']]))) ?><?= $address['landmark'] ? '. Near ' . okv_e($address['landmark']) : '' ?></p>
        <?php endif; ?>

        <div>
          <h3 class="text-sm font-semibold text-ink">Items</h3>
          <ul class="mt-2 divide-y divide-mist border-y border-mist">
            <?php foreach ($items as $item): ?>
              <li class="flex justify-between gap-4 py-3 text-sm">
                <span><?= okv_e(okv_quantity($item['quantity'])) ?> <?= okv_e($item['unit_name']) ?> <?= okv_e($item['item_name']) ?>
                  <?php if ($item['components']): ?><span class="mt-1 block text-xs text-ink-60"><?php foreach ($item['components'] as $i => $component): ?><?= $i ? ', ' : '' ?><?= okv_e(okv_quantity($component['quantity'])) ?> <?= okv_e($component['unit_name']) ?> <?= okv_e($component['product_name']) ?> per basket<?php endforeach; ?></span><?php endif; ?>
                </span>
                <span class="font-mono"><?= okv_e(Money::format((int) $item['line_total_subunit'])) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

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

        <?php if ($canTransition && $targets): ?>
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
          <div class="rounded-md border border-clay bg-clay-tint p-4">
            <h3 class="font-semibold text-ink">Cancel this order</h3>
            <p class="mt-2 text-sm text-ink-60"><?= okv_e(Cancellation::staffSummary($selected['money_outcome'])) ?></p>
            <?php if ((int) $selected['money_outcome']['refund_subunit'] > 0): ?>
              <p class="mt-1 text-sm text-ink-60">Paystack refunds are raised now but shown as pending until Paystack confirms them. Money recorded by staff is flagged for manual return.</p>
            <?php endif; ?>
            <form action="/api/v1/orders.php" method="POST" class="mt-4 grid gap-4">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="cancel_staff">
              <input type="hidden" name="order_id" value="<?= (int) $selected['id'] ?>">
              <div>
                <label for="staff-cancel-reason" class="okv-label">Reason</label>
                <select id="staff-cancel-reason" name="reason_code" class="okv-input" required>
                  <option value="">Choose a reason</option>
                  <?php foreach (OrderCancellation::STAFF_REASONS as $value => $label): ?>
                    <option value="<?= okv_e($value) ?>"><?= okv_e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="staff-cancel-note" class="okv-label">Internal note (optional)</label>
                <textarea id="staff-cancel-note" name="reason_text" class="okv-input" rows="3" maxlength="1000"></textarea>
              </div>
              <label class="flex min-h-[44px] items-start gap-3 text-sm">
                <input type="checkbox" name="confirmed" value="1" class="mt-1 h-5 w-5" required>
                <span>I have checked the order, refund and deposit consequences above.</span>
              </label>
              <button type="submit" class="okv-btn-outline min-h-[44px] border-tomato text-tomato justify-center sm:w-fit">Confirm cancellation</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php

require __DIR__ . '/../includes/components/admin/footer.php';
