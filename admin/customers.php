<?php
/**
 * admin/customers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Households and businesses in one searchable screen, and the one
 * profile that gathers what the other modules already hold: addresses, orders,
 * payments, Kitchen Runs and, for a business, its credit facility.
 *
 * This screen reads. Every action links to the module that owns it, so an
 * order is cancelled on the order screen, a payment is recorded on the payment
 * screen and credit is granted on the credit screen, each with its own audit
 * trail. See docs/PRD.md Section 17.
 *
 * Permissions are layered the way admin/orders.php layers them: customers.view
 * opens the screen, and each protected block asks for its own seeded key.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
Rbac::requirePermission('customers.view');

$canSeeAddresses = Rbac::can('customers.addresses.view');
$canSeePayments  = Rbac::can('payments.view');
$canSeeCredit    = Rbac::can('credit.view');
$canSeeRuns      = Rbac::can('kitchen_runs.view');
$canSeeOrders    = Rbac::can('orders.view');
$canGrantCredit  = Rbac::can('credit.grant');

$filters = Customers::normaliseFilters([
    'search' => okv_input('search', ''),
    'type'   => okv_input('type', ''),
    'page'   => okv_input('page', 1),
]);
$listing = Customers::listing($filters, $filters['page']);

$selectedId = (int) okv_input('customer', 0);
$customer   = $selectedId > 0 ? Customers::find($selectedId) : null;
$notFound   = $selectedId > 0 && $customer === null;

$business   = $customer['business'] ?? null;
$addresses  = $customer && $canSeeAddresses ? Customers::addresses((int) $customer['id']) : [];
$orders     = $customer && $canSeeOrders ? Customers::orders((int) $customer['id']) : [];
$payments   = $customer && $canSeePayments ? Customers::payments((int) $customer['id']) : [];
$runs       = $customer && $canSeeRuns ? Customers::kitchenRuns((int) $customer['id']) : [];
$totals     = $customer ? Customers::totals((int) $customer['id']) : null;

// Credit belongs to a business and to nobody else. A household never gets an
// empty facility card invented for it.
$creditSummary = null;
$creditEntries = [];
$creditApplication = null;
if ($customer && $business && $canSeeCredit) {
    $account = Credit::customerAccount((int) $customer['id']);
    $creditSummary = $account['summary'] ?? null;
    $creditApplication = $account['application'] ?? null;
    $creditEntries = Customers::creditEntries((int) $business['id']);
}

$baseQuery = array_filter([
    'search' => $listing['search'],
    'type'   => $listing['type'],
    'page'   => $listing['page'] > 1 ? $listing['page'] : null,
], static fn($value) => $value !== '' && $value !== null);
$urlFor = static function (array $changes = []) use ($baseQuery): string {
    $query = array_filter(array_merge($baseQuery, $changes), static fn($value) => $value !== '' && $value !== null);
    return '/admin/customers.php' . ($query ? '?' . http_build_query($query) : '');
};

$paymentStates = ['unpaid' => 'Unpaid', 'part_paid' => 'Part paid', 'paid' => 'Paid'];

$okv_admin_title = 'Customers';
$okv_admin_note  = 'Households and businesses, their addresses, their orders and their credit.';
require __DIR__ . '/../includes/components/admin/header.php';
?>

<?php if ($notFound): ?>
  <p class="okv-note" role="status">That customer is not available. Choose one from the list below.</p>
<?php endif; ?>

<form method="get" action="/admin/customers.php" class="okv-panel mt-4" aria-label="Find a customer">
  <div class="okv-panel-body grid gap-3 sm:grid-cols-[minmax(12rem,1fr)_minmax(14rem,2fr)_auto]">
    <div>
      <label class="okv-label" for="customer-search">Search</label>
      <input class="okv-input" id="customer-search" name="search" value="<?= okv_e($listing['search']) ?>"
             placeholder="Name, email, phone number or business name" maxlength="100">
    </div>
    <div>
      <label class="okv-label" for="customer-type">Account type</label>
      <select class="okv-input" id="customer-type" name="type">
        <option value="">All customers</option>
        <?php foreach (Customers::TYPES as $type): ?>
          <option value="<?= okv_e($type) ?>" <?= $listing['type'] === $type ? 'selected' : '' ?>><?= okv_e(Customers::typeLabel($type)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="okv-btn min-h-[44px] self-end" type="submit">Search customers</button>
  </div>
</form>

<div class="mt-5 grid gap-6 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.4fr)]">

  <section class="okv-panel min-w-0" aria-labelledby="customer-list-heading">
    <div class="okv-panel-head">
      <h2 id="customer-list-heading" class="okv-panel-title">Customers</h2>
      <span class="text-sm text-ink-60"><?= (int) $listing['count'] ?> total</span>
    </div>
    <?php if (!$listing['customers']): ?>
      <p class="p-5 text-sm text-ink-60">No customer matches that search. Clear it to see everyone.</p>
    <?php else: ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($listing['customers'] as $row): ?>
          <?php $isSelected = (int) $row['id'] === $selectedId; ?>
          <li>
            <a href="<?= okv_e($urlFor(['customer' => (int) $row['id']])) ?>"
               class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= $isSelected ? 'bg-forest-tint' : '' ?>"
               <?= $isSelected ? 'aria-current="page"' : '' ?>>
              <span class="flex flex-wrap items-center justify-between gap-2">
                <strong class="text-ink"><?= okv_e(Customers::displayName($row)) ?></strong>
                <span class="okv-badge okv-badge-neutral"><?= okv_e(Customers::typeLabel((string) $row['user_type'])) ?></span>
              </span>
              <span class="mt-1 block text-sm text-ink-60"><?= okv_e((string) $row['email']) ?></span>
              <span class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink-60">
                <span><?= (int) $row['order_count'] ?> order<?= (int) $row['order_count'] === 1 ? '' : 's' ?></span>
                <span><?= $row['last_order_at'] ? 'Last order ' . okv_e(date('j M Y', strtotime((string) $row['last_order_at']))) : 'No orders yet' ?></span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php okv_pagination((int) $listing['page'], (int) $listing['lastPage'], static fn(int $page): string => $urlFor(['page' => $page, 'customer' => null]), 'Customer pages'); ?>
    <?php endif; ?>
  </section>

  <div class="min-w-0 space-y-5">
    <?php if ($customer === null): ?>
      <section class="okv-panel" aria-labelledby="customer-empty-heading">
        <div class="okv-panel-body">
          <h2 id="customer-empty-heading" class="okv-panel-title">Choose a customer</h2>
          <p class="mt-2 text-sm text-ink-60">Open a customer to see their details, addresses, orders, payments, Kitchen Runs and credit.</p>
        </div>
      </section>
    <?php else: ?>

      <section class="okv-panel" aria-labelledby="customer-detail-heading">
        <div class="okv-panel-head">
          <h2 id="customer-detail-heading" class="okv-panel-title"><?= okv_e(Customers::displayName($business ? $business + ['id' => $customer['id']] : $customer)) ?></h2>
          <span class="okv-badge okv-badge-neutral"><?= okv_e(Customers::typeLabel((string) $customer['user_type'])) ?></span>
        </div>
        <div class="okv-panel-body grid gap-4 sm:grid-cols-2">
          <div>
            <p class="okv-label">Account holder</p>
            <p class="text-ink"><?= okv_e(trim(((string) $customer['first_name']) . ' ' . ((string) $customer['last_name']))) ?></p>
            <p class="text-sm text-ink-60"><?= okv_e((string) $customer['email']) ?></p>
            <p class="text-sm text-ink-60"><?= okv_e((string) $customer['phone']) ?></p>
          </div>
          <div>
            <p class="okv-label">Account</p>
            <p class="text-sm text-ink-60">Status <?= okv_e(ucfirst((string) $customer['status'])) ?></p>
            <p class="text-sm text-ink-60">Email <?= $customer['email_verified_at'] ? 'verified' : 'not verified yet' ?></p>
            <p class="text-sm text-ink-60">Joined <?= okv_e(date('j M Y', strtotime((string) $customer['created_at']))) ?></p>
            <p class="text-sm text-ink-60">Last signed in <?= $customer['last_login_at'] ? okv_e(date('j M Y', strtotime((string) $customer['last_login_at']))) : 'never' ?></p>
          </div>
          <?php if ($business): ?>
            <div>
              <p class="okv-label">Business</p>
              <p class="text-ink"><?= okv_e((string) $business['business_name']) ?></p>
              <p class="text-sm text-ink-60"><?= okv_e((string) ($business['business_type'] ?? 'Business type not given')) ?></p>
              <p class="text-sm text-ink-60">Contact <?= okv_e((string) $business['contact_person']) ?></p>
              <?php if (!empty($business['registration_number'])): ?>
                <p class="text-sm text-ink-60">Registration <?= okv_e((string) $business['registration_number']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($totals): ?>
            <div>
              <p class="okv-label">Trading</p>
              <p class="text-sm text-ink-60"><?= (int) $totals['order_count'] ?> order<?= (int) $totals['order_count'] === 1 ? '' : 's' ?>, <span class="font-mono"><?= okv_e(Money::format((int) $totals['ordered_subunit'])) ?></span> ordered</p>
              <?php if ($canSeePayments): ?>
                <p class="text-sm text-ink-60">Paid <span class="font-mono"><?= okv_e(Money::format((int) $totals['paid_subunit'])) ?></span>, still due <span class="font-mono"><?= okv_e(Money::format((int) $totals['outstanding_subunit'])) ?></span></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($canSeeAddresses): ?>
        <section class="okv-panel" aria-labelledby="customer-addresses-heading">
          <div class="okv-panel-head"><h2 id="customer-addresses-heading" class="okv-panel-title">Delivery addresses</h2></div>
          <?php if (!$addresses): ?>
            <p class="p-5 text-sm text-ink-60">This customer has saved no delivery address yet.</p>
          <?php else: ?>
            <ul class="divide-y divide-mist">
              <?php foreach ($addresses as $address): ?>
                <li class="px-5 py-3">
                  <p class="text-ink"><?= okv_e((string) $address['recipient_name']) ?>, <?= okv_e((string) $address['recipient_phone']) ?>
                    <?php if (!empty($address['is_default'])): ?><span class="okv-badge okv-badge-available">Default</span><?php endif; ?>
                  </p>
                  <p class="text-sm text-ink-60">
                    <?= okv_e(trim(((string) $address['address_line_1']) . ' ' . ((string) ($address['address_line_2'] ?? '')))) ?>,
                    <?= okv_e((string) $address['city']) ?>, <?= okv_e((string) $address['state']) ?>
                    <?= !empty($address['landmark']) ? ', near ' . okv_e((string) $address['landmark']) : '' ?>
                  </p>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($canSeeOrders): ?>
        <section class="okv-panel" aria-labelledby="customer-orders-heading">
          <div class="okv-panel-head">
            <h2 id="customer-orders-heading" class="okv-panel-title">Orders</h2>
            <a class="inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/orders.php?filter_customer=<?= rawurlencode((string) $customer['email']) ?>">All orders for this customer</a>
          </div>
          <?php if (!$orders): ?>
            <p class="p-5 text-sm text-ink-60">This customer has placed no order yet.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="okv-table min-w-[44rem]">
                <thead><tr><th>Order</th><th>Status</th><th>Delivery</th><th>Total</th><th>Payment</th><th>Balance</th></tr></thead>
                <tbody>
                  <?php foreach ($orders as $order): ?>
                    <tr>
                      <td><a class="inline-flex min-h-[44px] items-center font-mono text-forest underline" href="/admin/orders.php?order=<?= (int) $order['id'] ?>"><?= okv_e((string) $order['order_number']) ?></a></td>
                      <td><?= okv_e(ucfirst((string) $order['order_status'])) ?></td>
                      <td><?= okv_e(date('j M Y', strtotime((string) $order['preferred_delivery_date']))) ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $order['order_total_subunit'])) ?></td>
                      <td><?= okv_e($paymentStates[(string) $order['payment_status']] ?? ucfirst((string) $order['payment_status'])) ?><?= (string) $order['payment_option'] === 'on_account' ? ', on account' : '' ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $order['balance_due_subunit'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($canSeePayments): ?>
        <section class="okv-panel" aria-labelledby="customer-payments-heading">
          <div class="okv-panel-head">
            <h2 id="customer-payments-heading" class="okv-panel-title">Payments</h2>
            <a class="inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/payments.php">Open payments</a>
          </div>
          <?php if (!$payments): ?>
            <p class="p-5 text-sm text-ink-60">No payment has been recorded for this customer yet.</p>
          <?php else: ?>
            <div class="overflow-x-auto">
              <table class="okv-table min-w-[44rem]">
                <thead><tr><th>Payment</th><th>Order</th><th>Type</th><th>Status</th><th>Expected</th><th>Paid</th></tr></thead>
                <tbody>
                  <?php foreach ($payments as $payment): ?>
                    <tr>
                      <td><?php if (!empty($payment['order_number'])): ?><a class="inline-flex min-h-[44px] items-center font-mono text-forest underline" href="/admin/payments.php?order=<?= rawurlencode((string) $payment['order_number']) ?>"><?= okv_e((string) $payment['payment_number']) ?></a><?php else: ?><span class="font-mono"><?= okv_e((string) $payment['payment_number']) ?></span><?php endif; ?></td>
                      <td><?php if (!empty($payment['order_number'])): ?><a class="font-mono text-forest underline" href="/admin/orders.php?order=<?= (int) $payment['order_id'] ?>"><?= okv_e((string) $payment['order_number']) ?></a><?php else: ?>Not on an order<?php endif; ?></td>
                      <td><?= okv_e(str_replace('_', ' ', (string) $payment['payment_type'])) ?></td>
                      <td><?= okv_e(ucfirst((string) $payment['status'])) ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $payment['expected_amount_subunit'])) ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $payment['paid_amount_subunit'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($canSeeRuns): ?>
        <section class="okv-panel" aria-labelledby="customer-runs-heading">
          <div class="okv-panel-head">
            <h2 id="customer-runs-heading" class="okv-panel-title">Kitchen Runs</h2>
            <a class="inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/kitchen_runs.php">Open Kitchen Runs</a>
          </div>
          <?php if (!$runs): ?>
            <p class="p-5 text-sm text-ink-60">This customer has sent no Kitchen Run yet.</p>
          <?php else: ?>
            <ul class="divide-y divide-mist">
              <?php foreach ($runs as $run): ?>
                <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                  <a class="inline-flex min-h-[44px] items-center font-mono text-forest underline" href="/admin/kitchen_runs.php?request=<?= (int) $run['id'] ?>"><?= okv_e((string) $run['request_number']) ?></a>
                  <span class="text-sm text-ink-60"><?= okv_e(ucfirst((string) $run['status'])) ?></span>
                  <span class="font-mono text-sm"><?= $run['quoted_total_subunit'] === null ? 'Not priced yet' : okv_e(Money::format((int) $run['quoted_total_subunit'])) ?></span>
                  <?php if (!empty($run['converted_order_id'])): ?>
                    <a class="inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/orders.php?order=<?= (int) $run['converted_order_id'] ?>">Its order</a>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($business && $canSeeCredit): ?>
        <section class="okv-panel" aria-labelledby="customer-credit-heading">
          <div class="okv-panel-head">
            <h2 id="customer-credit-heading" class="okv-panel-title">Credit</h2>
            <a class="inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/credit.php?business=<?= (int) $business['id'] ?>">Open credit</a>
          </div>
          <div class="okv-panel-body">
            <?php if ($creditSummary && (string) $creditSummary['state'] === 'approved'): ?>
              <dl class="grid gap-3 sm:grid-cols-3">
                <div><dt class="okv-label">Limit</dt><dd class="font-mono"><?= okv_e(Money::format((int) $creditSummary['limit_subunit'])) ?></dd></div>
                <div><dt class="okv-label">Outstanding</dt><dd class="font-mono"><?= okv_e(Money::format((int) $creditSummary['outstanding_subunit'])) ?></dd></div>
                <div><dt class="okv-label">Available</dt><dd class="font-mono"><?= okv_e(Money::format((int) $creditSummary['available_subunit'])) ?></dd></div>
                <div><dt class="okv-label">Terms</dt><dd><?= (int) ($business['credit_days'] ?? 0) ?> days</dd></div>
                <div><dt class="okv-label">Overdue</dt><dd class="font-mono"><?= okv_e(Money::format((int) $creditSummary['overdue_subunit'])) ?></dd></div>
                <div><dt class="okv-label">Next due</dt><dd><?= $creditSummary['earliest_due_date'] ? okv_e(date('j M Y', strtotime((string) $creditSummary['earliest_due_date']))) : 'Nothing due' ?></dd></div>
              </dl>
            <?php else: ?>
              <p class="text-sm text-ink">
                This business runs on <?= okv_e(str_replace('_', ' ', (string) $business['credit_status'])) ?> credit, so nothing can be placed on account today.
              </p>
              <?php if ($canGrantCredit): ?>
                <a class="mt-3 inline-flex min-h-[44px] items-center text-sm text-forest underline" href="/admin/credit.php?business=<?= (int) $business['id'] ?>">Review or grant credit</a>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($creditApplication): ?>
              <p class="mt-4 text-sm text-ink-60">
                Latest application: <?= okv_e(Money::format((int) $creditApplication['requested_limit_subunit'])) ?>
                over <?= (int) $creditApplication['requested_days'] ?> days,
                <?= okv_e((string) $creditApplication['status']) ?>
                on <?= okv_e(date('j M Y', strtotime((string) ($creditApplication['reviewed_at'] ?? $creditApplication['created_at'])))) ?>.
                <?php if (!empty($creditApplication['decision_reason'])): ?>
                  <span class="block">Reason given: <?= okv_e((string) $creditApplication['decision_reason']) ?></span>
                <?php endif; ?>
              </p>
            <?php endif; ?>
          </div>

          <?php if ($creditEntries): ?>
            <div class="overflow-x-auto">
              <table class="okv-table min-w-[44rem]">
                <thead><tr><th>Entry</th><th>Amount</th><th>Due</th><th>Order</th><th>Recorded</th></tr></thead>
                <tbody>
                  <?php foreach ($creditEntries as $entry): ?>
                    <tr>
                      <td><?= okv_e(ucfirst((string) $entry['transaction_type'])) ?></td>
                      <td class="font-mono"><?= okv_e(Money::format((int) $entry['amount_subunit'])) ?></td>
                      <td><?= $entry['due_date'] ? okv_e(date('j M Y', strtotime((string) $entry['due_date']))) : 'Not dated' ?></td>
                      <td><?php if (!empty($entry['order_number'])): ?><a class="font-mono text-forest underline" href="/admin/orders.php?order=<?= (int) $entry['order_id'] ?>"><?= okv_e((string) $entry['order_number']) ?></a><?php else: ?>Not on an order<?php endif; ?></td>
                      <td><?= okv_e(date('j M Y', strtotime((string) $entry['created_at']))) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
