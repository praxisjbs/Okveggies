<?php
/** Business dashboard for the authenticated Pro customer. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();

$dashboard = ProDashboard::overview((int) Customer::id());
$business = $dashboard['business'];
$credit = $dashboard['credit'];
$orders = $dashboard['orders'];
$savedLists = $dashboard['saved_lists'];
$nextDelivery = $dashboard['next_delivery'];
$businessName = trim((string) ($business['business_name'] ?? ''));

$dateLabel = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '';
    }
    return date('l jS F', strtotime($date));
};

$okv_pro_title = 'Dashboard';
$okv_pro_note = $businessName !== ''
    ? 'Orders, credit and delivery dates for ' . $businessName . '.'
    : 'Your business orders, credit and delivery dates.';
$okv_pro_active = '/pro/';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<div class="space-y-6">
  <section aria-labelledby="credit-summary-heading">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="okv-eyebrow">Credit</p>
        <h2 id="credit-summary-heading" class="okv-panel-title mt-1">Your credit position</h2>
      </div>
      <a href="/pro/credit.php" class="okv-btn-text">Open Credit</a>
    </div>

    <?php if (!$credit['approved']): ?>
      <div class="okv-panel max-w-3xl">
        <div class="okv-panel-body">
          <p class="font-medium text-ink">Credit has not been approved for this account.</p>
          <p class="mt-2 text-sm text-ink-60">Open Credit to apply or check where your request has reached.</p>
          <a href="/pro/credit.php" class="okv-btn mt-5 px-5">Apply or check eligibility</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grid gap-4 md:grid-cols-3">
        <article class="okv-panel">
          <div class="okv-panel-body">
            <p class="text-sm text-ink-60">Outstanding</p>
            <p class="mt-2 font-mono text-2xl font-semibold text-ink"><?= okv_e(Money::format((int) $credit['outstanding_subunit'])) ?></p>
            <a href="/pro/credit.php" class="okv-btn-text mt-3">See what is due</a>
          </div>
        </article>
        <article class="okv-panel">
          <div class="okv-panel-body">
            <p class="text-sm text-ink-60">Earliest unpaid due date</p>
            <p class="mt-2 text-lg font-semibold text-ink">
              <?= $credit['earliest_due_date'] === null ? 'Nothing due' : okv_e($dateLabel((string) $credit['earliest_due_date'])) ?>
            </p>
            <?php if ((int) ($business['credit_days'] ?? 0) > 0): ?>
              <p class="mt-3 text-sm text-ink-60">Terms: <?= (int) $business['credit_days'] ?> days</p>
            <?php else: ?>
              <p class="mt-3 text-sm text-ink-60">Open Credit to check the terms on this account.</p>
            <?php endif; ?>
          </div>
        </article>
        <article class="okv-panel">
          <div class="okv-panel-body">
            <p class="text-sm text-ink-60">Available</p>
            <p class="mt-2 font-mono text-2xl font-semibold text-ink"><?= okv_e(Money::format((int) $credit['available_subunit'])) ?></p>
            <p class="mt-3 text-sm text-ink-60">Limit: <?= okv_e(Money::format((int) $credit['limit_subunit'])) ?></p>
          </div>
        </article>
      </div>
      <?php if ((int) $credit['overdue_subunit'] > 0): ?>
        <p class="mt-4 rounded-md border border-tomato bg-tomato-tint px-4 py-3 text-sm text-tomato" role="status">
          Overdue: <span class="font-mono font-semibold"><?= okv_e(Money::format((int) $credit['overdue_subunit'])) ?></span>.
          <a href="/pro/credit.php" class="inline-flex min-h-[44px] items-center font-medium underline underline-offset-2">Open the credit record</a>.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="okv-panel" aria-labelledby="delivery-heading">
    <div class="okv-panel-head">
      <div>
        <p class="okv-eyebrow">Business delivery queue</p>
        <h2 id="delivery-heading" class="okv-panel-title mt-1">Next business delivery</h2>
      </div>
      <a href="/pro/kitchen_lists.php" class="okv-btn-text">Open My Kitchen Lists</a>
    </div>
    <div class="okv-panel-body sm:flex sm:items-center sm:justify-between sm:gap-5">
      <div>
        <?php if ($nextDelivery): ?>
          <p class="text-xl font-semibold text-ink"><?= okv_e($dateLabel((string) $nextDelivery['date'])) ?></p>
          <p class="mt-2 text-sm text-ink-60">This date follows the delivery days, lead time, cutoff and exceptions set by the team.</p>
        <?php else: ?>
          <p class="font-medium text-ink">No business delivery date is available in the next 90 days.</p>
          <p class="mt-2 text-sm text-ink-60">Open My Kitchen Lists to check again before sending a list.</p>
        <?php endif; ?>
      </div>
      <a href="/kitchen-runs.php" class="okv-btn mt-5 shrink-0 px-5 sm:mt-0">Start a Kitchen Run</a>
    </div>
  </section>

  <div class="grid gap-6 lg:grid-cols-3">
    <section class="okv-panel min-w-0 lg:col-span-2" aria-labelledby="recent-orders-heading">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">Orders</p>
          <h2 id="recent-orders-heading" class="okv-panel-title mt-1">Recent orders</h2>
        </div>
        <a href="/pro/orders.php" class="okv-btn-text">See all orders</a>
      </div>
      <?php if (!$orders): ?>
        <div class="okv-panel-body">
          <p class="font-medium text-ink">No orders on this business account yet.</p>
          <p class="mt-2 text-sm text-ink-60">Send a kitchen list or order from the shop when you are ready.</p>
          <div class="mt-5 flex flex-wrap gap-2">
            <a href="/pro/kitchen_lists.php" class="okv-btn px-5">Open My Kitchen Lists</a>
            <a href="/shop.php" class="okv-btn-outline px-5">Open the shop</a>
          </div>
        </div>
      <?php else: ?>
        <ul class="divide-y divide-mist">
          <?php foreach ($orders as $order): ?>
            <li>
              <a href="/pro/orders.php?order=<?= (int) $order['id'] ?>" class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint md:px-5">
                <span class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-mono font-semibold text-forest"><?= okv_e((string) $order['order_number']) ?></span>
                  <span class="okv-badge <?= (string) $order['order_status'] === 'delivered' ? 'okv-badge-available' : 'okv-badge-neutral' ?>"><?= okv_e((string) $order['status_label']) ?></span>
                </span>
                <span class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm text-ink-60">
                  <span>Delivery <?= okv_e($dateLabel((string) $order['preferred_delivery_date'])) ?></span>
                  <span class="font-mono text-ink"><?= okv_e(Money::format((int) $order['order_total_subunit'])) ?></span>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="okv-panel min-w-0" aria-labelledby="saved-lists-heading">
      <div class="okv-panel-head">
        <div>
          <p class="okv-eyebrow">Saved lists</p>
          <h2 id="saved-lists-heading" class="okv-panel-title mt-1">Recently saved</h2>
        </div>
      </div>
      <?php if (!$savedLists): ?>
        <div class="okv-panel-body">
          <p class="font-medium text-ink">No saved lists yet.</p>
          <p class="mt-2 text-sm text-ink-60">Your Kitchen Run history is available now. Saving a list for reuse will appear here when it is available.</p>
          <a href="/pro/kitchen_lists.php" class="okv-btn-outline mt-5 px-5">Open My Kitchen Lists</a>
        </div>
      <?php else: ?>
        <ul class="divide-y divide-mist">
          <?php foreach ($savedLists as $list): ?>
            <li>
              <a href="/pro/kitchen_lists.php?list=<?= (int) $list['id'] ?>" class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint md:px-5">
                <span class="block font-medium text-forest"><?= okv_e((string) $list['name']) ?></span>
                <span class="mt-1 block text-sm text-ink-60">
                  <?= (int) $list['item_count'] ?> <?= (int) $list['item_count'] === 1 ? 'item' : 'items' ?>. Updated <?= okv_e(date('j M Y', strtotime((string) $list['updated_at']))) ?>.
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="border-t border-mist px-4 py-2 md:px-5">
          <a href="/pro/kitchen_lists.php" class="okv-btn-text">See all saved lists</a>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>
