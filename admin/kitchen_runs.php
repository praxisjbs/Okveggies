<?php
/**
 * admin/kitchen_runs.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The staff half of Kitchen Runs (PRD Section 8): the queue, the
 * quote workshop, and the button that turns an approved request into an order.
 *
 * The quote form is a full line editor rather than a set of price boxes over
 * fixed rows, and that is the point of it. A list that arrived as a photo or a
 * PDF reaches us as one placeholder line, and PRD 8.1 mode 3 says we transcribe
 * it: that is impossible unless staff can add lines. The same editor lets a
 * colleague drop something the market did not have and correct a customer's
 * typo without making them send the whole list again.
 *
 * Every write posts to api/v1/kitchen_runs.php, which re-checks the permission
 * on the server. The permission checks on this page are UX only.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('kitchen_runs.view');

$filter   = (string) okv_input('status', '');
$customer = mb_substr(trim((string) okv_input('customer', '')), 0, 100);
$openId   = (int) okv_input('request', 0);
$request  = $openId > 0 ? KitchenRuns::findForStaff($openId) : null;
$lines    = $request ? KitchenRuns::lines($openId) : [];
$history  = $request ? KitchenRuns::history($openId) : [];
$runs     = KitchenRuns::allForStaff($filter, 100, $customer);
$waiting  = KitchenRuns::waitingCount();
$counts   = KitchenRuns::statusCounts($customer);

/**
 * Keep both filters on every link out of this screen, so one does not clear the
 * other. The union has to put $extra on the left: PHP's array union keeps the
 * left operand for a repeated key, so the other way round every status tab
 * silently handed back the filter already in the URL and nothing ever filtered.
 */
$queryWith = static function (array $extra) use ($filter, $customer): string {
    $query = array_filter($extra + ['status' => $filter, 'customer' => $customer], static fn($v): bool => (string) $v !== '');
    return $query ? '?' . http_build_query($query) : '?';
};

$units    = Database::all('SELECT id, name FROM units_of_measurement ORDER BY id');
$zones    = Delivery::zonesActive();
$products = Database::all(
    'SELECT p.id, p.name, p.current_price_subunit, u.name AS unit_name
       FROM products p
       JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.current_price_subunit IS NOT NULL
      ORDER BY p.name'
);

// Delivery days we can actually offer for this customer, so a quote never names
// a day the shop does not run.
$eligibleDates = $request ? Delivery::nextEligibleDates((string) $request['customer_type'], 21) : [];

// The day the customer asked for when they sent the list, and whether we can
// still run it. A request can sit for days waiting on a price, so the day they
// picked may have passed; the quote form then starts at the next day we do run,
// and the panel says plainly what they originally asked for.
$requestedDate      = $request ? trim((string) ($request['preferred_delivery_date'] ?? '')) : '';
$requestedStillOpen = $requestedDate !== '' && in_array($requestedDate, array_column($eligibleDates, 'date'), true);

$errorCode = trim((string) okv_input('error', ''));
$notice    = null;
if ($errorCode !== '') {
    $notice = ['tone' => 'bad', 'text' => KitchenRuns::message($errorCode)];
} elseif (okv_input('quoted', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Quote sent. The customer has it by email and on their Kitchen Runs page.'];
} elseif (okv_input('declined', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Declined, and the customer has been told why.'];
} elseif (okv_input('approved', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'Approved for the customer, and it is on the record as your approval. Convert it when you are ready.'];
} elseif (okv_input('note_saved', '') !== '') {
    $notice = ['tone' => 'good', 'text' => 'The internal note has been saved. The customer never sees it.'];
}

$okv_admin_title  = 'Kitchen Runs';
$okv_admin_note   = 'Price a list, send the quote, then turn an approved request into an order.';
$okv_admin_crumbs = [['label' => 'Orders', 'href' => '/admin/orders.php']];
require __DIR__ . '/../includes/components/admin/header.php';
?>
<div class="space-y-8">

  <?php if ($notice): ?>
    <p class="rounded-xl border px-4 py-3 text-sm <?= $notice['tone'] === 'good' ? 'border-foliage bg-foliage-tint text-ink' : 'border-tomato bg-tomato-tint text-ink' ?>" role="status">
      <?= okv_e($notice['text']) ?>
    </p>
  <?php endif; ?>

  <!-- ---------------------------------------------------------------------
       The queue. Waiting for a price sorts first, because that is the only
       state where a customer is waiting on us.
       --------------------------------------------------------------------- -->
  <section class="okv-card">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
      <h2 class="font-display text-xl font-bold text-ink">
        The queue
        <?php if ($waiting > 0): ?>
          <span class="ml-2 rounded-full bg-forest px-2 py-0.5 text-sm text-white"><?= (int) $waiting ?> waiting</span>
        <?php endif; ?>
      </h2>
      <nav class="flex flex-wrap gap-2 text-sm" aria-label="Filter by status">
        <?php foreach (KitchenRuns::FILTERS as $status): ?>
          <?php $count = (int) ($counts[$status] ?? 0); ?>
          <a class="rounded-full border px-3 py-1 min-h-[44px] sm:min-h-0 inline-flex items-center gap-2 <?= $filter === $status ? 'border-forest bg-foliage-tint text-forest' : 'border-mist text-ink-60 hover:border-forest' ?>"
             href="<?= okv_e($queryWith(['status' => $status])) ?>"<?= $filter === $status ? ' aria-current="true"' : '' ?>>
            <?= okv_e(KitchenRuns::filterLabel($status)) ?>
            <span class="font-mono text-xs <?= $filter === $status ? 'text-forest' : 'text-ink-60' ?>"><?= $count ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- Find one customer's runs. Same search a colleague already knows from
         the orders screen: the name on the account, the email, the phone
         number on the request, or the request number itself. -->
    <form method="get" class="mt-4 flex flex-wrap items-end gap-3">
      <?php if ($filter !== ''): ?>
        <input type="hidden" name="status" value="<?= okv_e($filter) ?>">
      <?php endif; ?>
      <div class="min-w-[16rem] flex-1">
        <label class="okv-label" for="filter-customer">Customer, phone or request number</label>
        <input class="okv-input mt-1" id="filter-customer" name="customer" value="<?= okv_e($customer) ?>"
               maxlength="100" placeholder="Ada, 0803..., or OKR26004">
      </div>
      <button class="okv-btn-outline min-h-[44px] px-4" type="submit">Search</button>
      <?php if ($customer !== ''): ?>
        <a class="okv-btn-text text-sm min-h-[44px] inline-flex items-center" href="<?= okv_e($queryWith(['customer' => ''])) ?>">Clear</a>
      <?php endif; ?>
    </form>

    <?php if (!$runs): ?>
      <p class="mt-4 rounded-lg border border-mist bg-canvas px-4 py-6 text-center text-sm text-ink-60">
        <?php if ($customer !== ''): ?>
          Nothing matches "<?= okv_e($customer) ?>"<?= $filter === '' ? '' : ' with that status' ?>.
        <?php else: ?>
          Nothing here<?= $filter === '' ? ' yet' : ' with that status' ?>.
        <?php endif; ?>
      </p>
    <?php else: ?>
      <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[46rem] text-left text-sm">
          <thead class="border-b border-mist text-ink-60">
            <tr>
              <th scope="col" class="py-2 pr-3 font-medium">Request</th>
              <th scope="col" class="py-2 pr-3 font-medium">Customer</th>
              <th scope="col" class="py-2 pr-3 font-medium">How it came in</th>
              <th scope="col" class="py-2 pr-3 font-medium">Items</th>
              <th scope="col" class="py-2 pr-3 font-medium">Status</th>
              <th scope="col" class="py-2 font-medium text-right">Quote</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($runs as $run): ?>
              <tr class="border-b border-mist/60 <?= $openId === (int) $run['id'] ? 'bg-foliage-tint' : '' ?>">
                <td class="py-2 pr-3">
                  <a class="font-mono font-semibold text-forest underline-offset-2 hover:underline" href="<?= okv_e($queryWith(['request' => (int) $run['id']])) ?>">
                    <?= okv_e($run['request_number']) ?>
                  </a>
                  <span class="block text-ink-60"><?= okv_e(date('j M', strtotime((string) $run['created_at']))) ?></span>
                </td>
                <td class="py-2 pr-3">
                  <?= okv_e(trim((string) $run['customer_name']) ?: (string) $run['contact_name']) ?>
                  <span class="block text-ink-60"><?= okv_e((string) $run['customer_email']) ?></span>
                </td>
                <td class="py-2 pr-3">
                  <?= okv_e(KitchenRuns::modeLabel((string) $run['input_mode'])) ?>
                  <?php if (!empty($run['is_open_budget'])): ?>
                    <span class="block text-ink-60">Open budget</span>
                  <?php endif; ?>
                </td>
                <td class="py-2 pr-3"><?= (int) $run['line_count'] ?></td>
                <td class="py-2 pr-3"><?= okv_e($run['status_label']) ?></td>
                <td class="py-2 text-right font-mono">
                  <?= $run['quoted_total_subunit'] === null ? '<span class="text-ink-60">Not priced</span>' : okv_e(Money::format((int) $run['quoted_total_subunit'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($request): ?>
    <?php require __DIR__ . '/../includes/components/admin/kitchen_run_panel.php'; ?>
  <?php endif; ?>

</div>
<script src="<?= okv_e(okv_asset('/assets/js/admin-kitchen-runs.min.js')) ?>" defer></script>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
