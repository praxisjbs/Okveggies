<?php
/**
 * pro/orders.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Every order this business has placed, newest first, with the
 * status, the invoice and receipt from M5, and a link out to the public Order
 * Trail.
 *
 * Every read binds the signed-in customer id. Somebody else's order and an
 * order that does not exist answer the same way, so this screen cannot be used
 * to find out which order numbers are real.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pagination.php';
require_once __DIR__ . '/../includes/components/shop/pay_sheet.php';
require_once __DIR__ . '/../includes/components/shop/icons.php';

$userId = (int) Customer::id();
$filters = [
    'status' => trim((string) okv_input('status', '')),
    'payment' => trim((string) okv_input('payment', '')),
    'delivery' => trim((string) okv_input('delivery', '')),
];
$listing = ProOrders::listing($userId, $filters, (int) okv_input('page', 1));
$selectedId = (int) okv_input('order', 0);
$detail = $selectedId > 0 ? ProOrders::detail($selectedId, $userId) : null;
$notFound = $selectedId > 0 && $detail === null;

$baseQuery = array_filter([
    'status' => $listing['status'],
    'payment' => $listing['payment'],
    'delivery' => $listing['delivery'],
    'page' => $listing['page'] > 1 ? $listing['page'] : null,
], static fn($value) => $value !== '' && $value !== null);
$urlFor = static function (array $changes = []) use ($baseQuery): string {
    $query = array_merge($baseQuery, $changes);
    $query = array_filter($query, static fn($value) => $value !== '' && $value !== null);
    return '/pro/orders.php' . ($query ? '?' . http_build_query($query) : '');
};

$paymentLabels = ['unpaid' => 'Unpaid', 'part_paid' => 'Part paid', 'paid' => 'Paid'];
$okv_pro_title = 'Orders and Invoices';
$okv_pro_note = 'Check order progress, delivery, payments and documents.';
$okv_pro_active = '/pro/orders.php';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<div class="space-y-6">
  <?php if ($notFound): ?>
    <p class="rounded-md border border-mist bg-white px-4 py-3 text-sm text-ink" role="status">
      That order is not available. Choose one from this business account below.
    </p>
  <?php endif; ?>

  <form method="get" action="/pro/orders.php" class="grid gap-3 rounded-lg border border-mist bg-white p-4 sm:grid-cols-4" aria-label="Filter orders">
    <div>
      <label class="okv-label" for="order-status">Status</label>
      <select class="okv-input" id="order-status" name="status">
        <option value="">All statuses</option>
        <?php foreach (ProOrders::STATUSES as $status): ?>
          <option value="<?= okv_e($status) ?>" <?= $listing['status'] === $status ? 'selected' : '' ?>>
            <?= okv_e(OrderLifecycle::customerLabel($status)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="okv-label" for="payment-state">Payment</label>
      <select class="okv-input" id="payment-state" name="payment">
        <option value="">All payment states</option>
        <?php foreach (ProOrders::PAYMENT_STATES as $payment): ?>
          <option value="<?= okv_e($payment) ?>" <?= $listing['payment'] === $payment ? 'selected' : '' ?>>
            <?= okv_e($paymentLabels[$payment]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="okv-label" for="delivery-date">Delivery date</label>
      <input class="okv-input" id="delivery-date" type="date" name="delivery" value="<?= okv_e($listing['delivery']) ?>">
    </div>
    <button class="okv-btn self-end" type="submit">Filter orders</button>
  </form>

  <div class="grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.4fr)]">
    <section class="okv-panel min-w-0" aria-labelledby="order-list-heading">
      <div class="okv-panel-head">
        <h2 id="order-list-heading" class="okv-panel-title">Order history</h2>
        <span class="text-sm text-ink-60"><?= (int) $listing['count'] ?> total</span>
      </div>
      <?php if (!$listing['orders']): ?>
        <div class="okv-panel-body">
          <p class="font-medium text-ink">No orders match these filters.</p>
          <p class="mt-2 text-sm text-ink-60">Clear the filters, start from a saved list, or order from the shop.</p>
          <div class="mt-4 flex flex-wrap gap-2">
            <a class="okv-btn px-5" href="/pro/kitchen_lists.php">My Kitchen Lists</a>
            <a class="okv-btn-outline px-5" href="/shop.php">Open the shop</a>
          </div>
        </div>
      <?php else: ?>
        <ul class="divide-y divide-mist">
          <?php foreach ($listing['orders'] as $order): ?>
            <li>
              <a href="<?= okv_e($urlFor(['order' => (int) $order['id']])) ?>"
                 class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= (int) $order['id'] === $selectedId ? 'bg-forest-tint' : '' ?>"
                 <?= (int) $order['id'] === $selectedId ? 'aria-current="page"' : '' ?>>
                <span class="flex items-center justify-between gap-3">
                  <strong class="font-mono text-sm text-forest"><?= okv_e((string) $order['order_number']) ?></strong>
                  <span class="okv-badge <?= (string) $order['order_status'] === 'delivered' ? 'okv-badge-available' : 'okv-badge-neutral' ?>">
                    <?= okv_e((string) $order['status_label']) ?>
                  </span>
                </span>
                <span class="mt-2 flex flex-wrap justify-between gap-2 text-sm text-ink-60">
                  <span><?= okv_e(date('j M Y', strtotime((string) $order['preferred_delivery_date']))) ?></span>
                  <span class="font-mono text-ink"><?= okv_e(Money::format((int) $order['order_total_subunit'])) ?></span>
                </span>
                <span class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink-60">
                  <span><?= okv_e((string) $order['money']['badge']) ?></span>
                  <?php if ($order['money']['credit_open_subunit'] > 0): ?>
                    <span>Repay <?= okv_e(Money::format((int) $order['money']['credit_open_subunit'])) ?></span>
                  <?php endif; ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php okv_pagination((int) $listing['page'], (int) $listing['lastPage'], static fn(int $page): string => $urlFor(['page' => $page, 'order' => null]), 'Order pages'); ?>
      <?php endif; ?>
    </section>

    <?php if ($detail): $order = $detail['order']; ?>
      <section class="okv-panel min-w-0" aria-labelledby="order-detail-heading">
        <div class="okv-panel-head">
          <div>
            <p class="okv-eyebrow">Order details</p>
            <h2 id="order-detail-heading" class="okv-panel-title mt-1 font-mono">
              <?= okv_e((string) $order['order_number']) ?>
            </h2>
          </div>
          <a class="okv-btn-text" href="<?= okv_e($urlFor(['order' => null])) ?>">Close</a>
        </div>
        <div class="okv-panel-body space-y-6">
          <?php
            $statusLabel = OrderLifecycle::customerLabel((string) $order['order_status']);
            $statusTone = (string) $order['order_status'] === 'delivered'
                ? 'okv-badge-available'
                : ((string) $order['order_status'] === 'cancelled' ? 'okv-badge-out' : 'okv-badge-neutral');
            $statusIcon = (string) $order['order_status'] === 'delivered'
                ? 'check'
                : ((string) $order['order_status'] === 'dispatched' ? 'truck' : 'trail');
            $orderPageUrl = '/public/order.php?order=' . (int) $order['id'];
          ?>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-foliage-tint text-forest"><?php okv_icon($statusIcon, 'h-5 w-5'); ?></span>
              <p class="mt-3 text-sm text-ink-60">Status</p>
              <p class="mt-1"><span class="okv-badge <?= $statusTone ?>"><?= okv_e($statusLabel) ?></span></p>
            </article>
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('calendar', 'h-5 w-5'); ?></span>
              <p class="mt-3 text-sm text-ink-60">Delivery day</p>
              <p class="mt-1 font-medium"><?= okv_e(date('l jS F', strtotime((string) $order['preferred_delivery_date']))) ?></p>
            </article>
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gold-tint text-ink"><?php okv_icon('basket', 'h-5 w-5'); ?></span>
              <p class="mt-3 text-sm text-ink-60">Order total</p>
              <p class="mt-1 font-mono font-medium"><?= okv_e(Money::format((int) $order['order_total_subunit'])) ?></p>
            </article>
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('card', 'h-5 w-5'); ?></span>
              <p class="mt-3 text-sm text-ink-60">Payment</p>
              <p class="mt-1 font-medium"><?= okv_e($detail['money']['on_credit'] ? ($detail['money']['kind'] === OrderMoney::KIND_CREDIT_REPAID ? 'Credit line repaid' : 'On your credit line') : (string) $detail['money']['badge']) ?></p>
            </article>
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gold-tint text-ink"><?php okv_icon('receipt', 'h-5 w-5'); ?></span>
              <p class="mt-3 text-sm text-ink-60">Paid so far</p>
              <p class="mt-1 font-mono font-medium"><?= okv_e(Money::format((int) $order['amount_paid_subunit'])) ?></p>
            </article>
            <article class="rounded-lg border border-mist bg-white p-4">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-tomato-tint text-tomato"><?php okv_icon($detail['money']['on_credit'] ? 'receipt' : 'card', 'h-5 w-5'); ?></span>
              <?php if ($detail['money']['on_credit']): ?>
                <p class="mt-3 text-sm text-ink-60">Credit line</p>
                <p class="mt-1 font-medium"><?= okv_e($detail['money']['credit_line'] !== '' ? $detail['money']['credit_line'] : 'Nothing left to repay.') ?></p>
              <?php else: ?>
                <p class="mt-3 text-sm text-ink-60">Still to pay</p>
                <p class="mt-1 font-mono font-medium"><?= okv_e(Money::format((int) $order['balance_due_subunit'])) ?></p>
              <?php endif; ?>
            </article>
          </div>

          <nav class="flex flex-wrap gap-2" aria-label="Order actions">
            <a class="okv-btn px-4" href="<?= okv_e($orderPageUrl) ?>#reschedule-heading"><?php okv_icon('calendar', 'h-4 w-4'); ?>Manage delivery</a>
            <a class="okv-btn-outline px-4" href="<?= okv_e($orderPageUrl) ?>#order-progress"><?php okv_icon('trail', 'h-4 w-4'); ?>Track order</a>
            <a class="okv-btn-outline px-4" href="<?= okv_e($orderPageUrl) ?>"><?php okv_icon('info', 'h-4 w-4'); ?>Full order details</a>
            <a class="okv-btn-outline px-4" href="/public/documents/invoice.php?order=<?= (int) $order['id'] ?>" target="_blank" rel="noopener"><?php okv_icon('receipt', 'h-4 w-4'); ?>View invoice</a>
            <?php if ((int) $order['amount_paid_subunit'] > 0): ?>
              <a class="okv-btn-outline px-4" href="/public/documents/receipt.php?order=<?= (int) $order['id'] ?>" target="_blank" rel="noopener"><?php okv_icon('receipt', 'h-4 w-4'); ?>View receipt</a>
            <?php endif; ?>
            <form action="/api/v1/pro_orders.php" method="post" target="_blank">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="create_trail_link">
              <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
              <button class="okv-btn-outline px-4" type="submit"><?php okv_icon('trail', 'h-4 w-4'); ?>Share order progress</button>
            </form>
          </nav>
          <?php if ($detail['pay_methods'] !== []): ?>
            <section class="okv-card" aria-label="Payment action">
              <div class="mb-3 flex items-center gap-3">
                <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-gold-tint text-ink"><?php okv_icon('card', 'h-5 w-5'); ?></span>
                <h3 class="font-semibold text-ink">Make a payment</h3>
              </div>
              <?php okv_pay_action($order, $detail['pay_methods']); ?>
            </section>
          <?php endif; ?>

          <section class="okv-card" id="order-items" aria-labelledby="order-items-heading">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('basket', 'h-5 w-5'); ?></span>
              <h3 id="order-items-heading" class="font-display text-lg font-bold text-ink">Items</h3>
            </div>
            <ul class="mt-2 divide-y divide-mist">
              <?php foreach ($detail['items'] as $item): ?>
                <li class="flex justify-between gap-4 py-3 text-sm">
                  <span>
                    <?= okv_e(okv_quantity($item['quantity'])) ?>
                    <?= okv_e((string) $item['unit_name']) ?>
                    <?= okv_e((string) $item['item_name']) ?>
                  </span>
                  <span class="font-mono text-ink"><?= okv_e(Money::format((int) $item['line_total_subunit'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>

          <section class="okv-card" aria-labelledby="delivery-address-heading">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-forest-tint text-forest"><?php okv_icon('map-pin', 'h-5 w-5'); ?></span>
              <h3 id="delivery-address-heading" class="font-display text-lg font-bold text-ink">Delivery address</h3>
            </div>
            <p class="mt-3 whitespace-pre-line text-sm text-ink-60">
              <?= okv_e(OrderDocument::addressBlock($detail['address'])) ?>
            </p>
          </section>

          <section class="okv-card" aria-labelledby="progress-history-heading">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-foliage-tint text-forest"><?php okv_icon('trail', 'h-5 w-5'); ?></span>
              <h3 id="progress-history-heading" class="font-display text-lg font-bold text-ink">Progress history</h3>
            </div>
            <ol class="mt-4 grid gap-2 sm:grid-cols-2">
              <?php foreach ($detail['trail'] as $event): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-mist bg-white p-3 text-sm">
                  <span class="font-medium"><?= okv_e((string) $event['label']) ?></span>
                  <time class="text-xs text-ink-60" datetime="<?= okv_e((string) $event['created_at']) ?>">
                    <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?>
                  </time>
                </li>
              <?php endforeach; ?>
            </ol>
          </section>

          <?php if ($detail['kitchen_run'] || $detail['credit_charge']): ?>
            <div class="flex flex-wrap gap-2 border-t border-mist pt-4 text-sm">
              <?php if ($detail['kitchen_run']): ?>
                <a class="okv-btn-outline px-4" href="/kitchen-runs.php?request=<?= (int) $detail['kitchen_run']['id'] ?>">
                  <?php okv_icon('list', 'h-4 w-4'); ?>
                  Kitchen Run <?= okv_e((string) $detail['kitchen_run']['request_number']) ?>
                </a>
              <?php endif; ?>
              <?php if ($detail['credit_charge']): ?>
                <a class="okv-btn-outline px-4" href="/pro/credit.php?transaction=<?= (int) $detail['credit_charge']['id'] ?>">
                  <?php okv_icon('receipt', 'h-4 w-4'); ?>
                  Credit charge <?= okv_e(Money::format((int) $detail['credit_charge']['amount_subunit'])) ?>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    <?php else: ?>
      <section class="okv-panel" aria-labelledby="choose-order-heading">
        <div class="okv-panel-body">
          <h2 id="choose-order-heading" class="okv-panel-title">Choose an order</h2>
          <p class="mt-2 text-sm text-ink-60">Choose an order to see its status, items, delivery, payments and documents.</p>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>

