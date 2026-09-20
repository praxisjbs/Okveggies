<?php
/**
 * OK Veggies staff dashboard. M11 operational summary.
 *
 * The page gate opens the dashboard. Each figure has its own supporting
 * permission, and that check runs before its query. AdminDashboard contains
 * the metric rules; this file only decides what this person may see and how to
 * present it.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('dashboard.view');

$me = Database::one('SELECT first_name FROM users WHERE id = :id', [':id' => (int) Rbac::userId()]);
$firstName = trim((string) ($me['first_name'] ?? ''));

$canOrders = Rbac::can('orders.view');
$canPayments = Rbac::can('payments.view');
$canCredit = Rbac::can('credit.view');
$canAnalytics = Rbac::can('dashboard.analytics.view');
$canProducts = Rbac::can('products.view');
$canPricing = Rbac::can('pricing.view');
$canCombos = Rbac::can('combos.view');
$today = AdminDashboard::periodBounds(AdminDashboard::DEFAULT_PERIOD)['end_date'];

$cards = [];

if ($canOrders) {
    try {
        $count = AdminDashboard::ordersToday();
        $cards[] = [
            'label' => "Today's orders",
            'value' => (string) $count,
            'note' => $count === 0
                ? 'No orders have been placed today.'
                : $count . ' ' . ($count === 1 ? 'order was' : 'orders were') . ' placed today.',
            'href' => '/admin/orders.php?' . http_build_query(['filter_created' => $today]),
            'permission' => 'orders.view',
            'failed' => false,
        ];
    } catch (Throwable $e) {
        error_log('admin dashboard orders today: ' . $e->getMessage());
        $cards[] = [
            'label' => "Today's orders",
            'value' => 'Unavailable',
            'note' => 'This figure is unavailable. Try again shortly.',
            'href' => '/admin/orders.php?' . http_build_query(['filter_created' => $today]),
            'permission' => 'orders.view',
            'failed' => true,
        ];
    }
}

if ($canPayments) {
    try {
        $revenue = AdminDashboard::revenueToday();
        $net = (int) $revenue['revenue_subunit'];
        $gross = (int) $revenue['revenue_gross_subunit'];
        $refunds = (int) $revenue['revenue_refund_subunit'];
        if ($gross === 0 && $refunds === 0) {
            $revenueNote = 'No confirmed money was received or refunded today.';
        } elseif ($refunds > 0) {
            $revenueNote = Money::format($gross) . ' confirmed, less ' . Money::format($refunds) . ' in completed refunds.';
        } else {
            $revenueNote = 'Confirmed money received today, after completed refunds.';
        }
        $cards[] = [
            'label' => "Today's revenue",
            'value' => Money::format($net),
            'note' => $revenueNote,
            'href' => '/admin/payments.php#reconcile-heading',
            'permission' => 'payments.view',
            'failed' => false,
        ];
    } catch (Throwable $e) {
        error_log('admin dashboard revenue today: ' . $e->getMessage());
        $cards[] = [
            'label' => "Today's revenue",
            'value' => 'Unavailable',
            'note' => 'This figure is unavailable. Try again shortly.',
            'href' => '/admin/payments.php#reconcile-heading',
            'permission' => 'payments.view',
            'failed' => true,
        ];
    }

    try {
        $due = AdminDashboard::paymentsDue();
        $dueCount = (int) $due['payments_due_count'];
        $overdueCount = (int) $due['payments_overdue_count'];
        $dueTodayCount = (int) $due['payments_due_today_count'];
        $dueNote = $dueCount === 0
            ? 'No payments are due today or overdue.'
            : $dueCount . ' ' . ($dueCount === 1 ? 'payment needs' : 'payments need')
                . ' attention. ' . $overdueCount . ' overdue, ' . $dueTodayCount . ' due today.';
        $cards[] = [
            'label' => 'Payments due',
            'value' => Money::format((int) $due['payments_due_subunit']),
            'note' => $dueNote,
            'href' => '/admin/payments.php?due=attention#payments-due',
            'permission' => 'payments.view',
            'failed' => false,
        ];
    } catch (Throwable $e) {
        error_log('admin dashboard payments due: ' . $e->getMessage());
        $cards[] = [
            'label' => 'Payments due',
            'value' => 'Unavailable',
            'note' => 'This figure is unavailable. Try again shortly.',
            'href' => '/admin/payments.php?due=attention#payments-due',
            'permission' => 'payments.view',
            'failed' => true,
        ];
    }
}

if ($canCredit) {
    try {
        $outstanding = AdminDashboard::creditOutstanding();
        $cards[] = [
            'label' => 'Credit outstanding',
            'value' => Money::format($outstanding),
            'note' => $outstanding === 0
                ? 'No business credit is outstanding.'
                : 'Still to be paid across business credit accounts.',
            'href' => '/admin/credit.php#ageing-heading',
            'permission' => 'credit.view',
            'failed' => false,
        ];
    } catch (Throwable $e) {
        error_log('admin dashboard credit outstanding: ' . $e->getMessage());
        $cards[] = [
            'label' => 'Credit outstanding',
            'value' => 'Unavailable',
            'note' => 'This figure is unavailable. Try again shortly.',
            'href' => '/admin/credit.php#ageing-heading',
            'permission' => 'credit.view',
            'failed' => true,
        ];
    }
}

$hasQuickLinks = $canPricing || $canProducts || $canCombos;
$period = AdminDashboard::normalisePeriod($_GET['period'] ?? AdminDashboard::DEFAULT_PERIOD);
$periodBounds = AdminDashboard::periodBounds($period);
$periodLabel = date('j M Y', strtotime($periodBounds['start_date']))
    . ' to ' . date('j M Y', strtotime($periodBounds['end_date']));
$salesChart = null;
$topProductsChart = null;
$categoryChart = null;
$chartPayload = [];

if ($canAnalytics && $canPayments) {
    try {
        $sales = AdminDashboard::salesOverTime($period);
        $hasMovement = false;
        foreach ($sales['series'] as $day) {
            if ((int) $day['gross_subunit'] !== 0 || (int) $day['refund_subunit'] !== 0) {
                $hasMovement = true;
                break;
            }
        }
        $salesChart = [
            'status' => $hasMovement ? 'ready' : 'empty',
            'series' => $sales['series'],
            'undated_receipts_count' => (int) $sales['undated_receipts_count'],
        ];
        if ($hasMovement) {
            $chartPayload['sales_over_time'] = $sales['series'];
        }
    } catch (Throwable $e) {
        error_log('admin dashboard sales over time: ' . $e->getMessage());
        $salesChart = ['status' => 'error', 'series' => [], 'undated_receipts_count' => 0];
    }
}

if ($canAnalytics && $canOrders) {
    try {
        $rows = AdminDashboard::topProducts($period);
        $topProductsChart = ['status' => $rows === [] ? 'empty' : 'ready', 'rows' => $rows];
        if ($rows !== []) {
            $chartPayload['top_products'] = $rows;
        }
    } catch (Throwable $e) {
        error_log('admin dashboard top products: ' . $e->getMessage());
        $topProductsChart = ['status' => 'error', 'rows' => []];
    }

    try {
        $share = AdminDashboard::categoryShare($period);
        $rows = $share['rows'];
        $categoryChart = [
            'status' => $rows === [] ? 'empty' : 'ready',
            'rows' => $rows,
            'uncategorised_subunit' => (int) $share['uncategorised_subunit'],
            'unallocated_refund_subunit' => (int) $share['unallocated_refund_subunit'],
        ];
        if ($rows !== []) {
            $chartPayload['order_share'] = $rows;
        }
    } catch (Throwable $e) {
        error_log('admin dashboard category share: ' . $e->getMessage());
        $categoryChart = [
            'status' => 'error', 'rows' => [],
            'uncategorised_subunit' => 0, 'unallocated_refund_subunit' => 0,
        ];
    }
}

$hasChartRegion = $canAnalytics && ($canPayments || $canOrders);
$hasChartPayload = $chartPayload !== [];
$okv_admin_title = 'Dashboard';
$okv_admin_note = $firstName !== ''
    ? 'Welcome back, ' . $firstName . '. Here is what needs attention today.'
    : 'Here is what needs attention today.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <section aria-labelledby="okv-today">
      <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
          <p class="okv-eyebrow">Today</p>
          <h2 id="okv-today" class="okv-panel-title mt-1">Orders and money at a glance</h2>
        </div>
        <p class="text-sm text-ink-60"><?= okv_e(date('j M Y', strtotime($today))) ?>, Lagos time</p>
      </div>

      <?php if (!$cards): ?>
        <div class="okv-panel mt-3">
          <p class="okv-panel-body text-sm text-ink-60">
            Your role does not include today's order or money figures. Use the available links below to continue your work.
          </p>
        </div>
      <?php else: ?>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <?php foreach ($cards as $card): ?>
            <a href="<?= okv_e($card['href']) ?>"
               class="okv-stat block min-h-[44px] transition duration-botanical ease-botanical hover:border-forest"
               data-perm="<?= okv_e($card['permission']) ?>">
              <p class="okv-stat-label"><?= okv_e($card['label']) ?></p>
              <p class="okv-stat-figure<?= $card['failed'] ? ' text-tomato' : '' ?>"><?= okv_e($card['value']) ?></p>
              <p class="okv-stat-note"><?= okv_e($card['note']) ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($hasChartRegion): ?>
      <section aria-labelledby="okv-analytics">
        <div class="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p class="okv-eyebrow">Analytics</p>
            <h2 id="okv-analytics" class="okv-panel-title mt-1">How the shop is moving</h2>
            <p class="mt-1 text-sm text-ink-60"><?= okv_e($periodLabel) ?>, Lagos time</p>
          </div>
          <nav aria-label="Reporting period" class="flex flex-wrap gap-2">
            <?php foreach (AdminDashboard::PERIODS as $periodOption): ?>
              <a href="/admin/?<?= okv_e(http_build_query(['period' => $periodOption])) ?>"
                 class="okv-filter-chip<?= $period === $periodOption ? ' okv-filter-chip-active' : '' ?>"
                 <?= $period === $periodOption ? 'aria-current="page"' : '' ?>>
                <?= okv_e((string) $periodOption) ?> days
              </a>
            <?php endforeach; ?>
          </nav>
        </div>

        <div class="mt-3 grid gap-4 xl:grid-cols-2">
          <?php if ($salesChart !== null): ?>
            <article class="okv-panel xl:col-span-2" aria-labelledby="okv-sales-title">
              <div class="okv-panel-head">
                <div>
                  <h3 id="okv-sales-title" class="okv-panel-title">Sales over time</h3>
                  <p class="mt-1 text-xs text-ink-60">Net confirmed money after completed refunds.</p>
                </div>
                <span class="okv-badge okv-badge-neutral"><?= okv_e((string) $period) ?> days</span>
              </div>
              <div class="okv-panel-body">
                <?php if ($salesChart['status'] === 'error'): ?>
                  <p class="okv-note-bad">Sales could not be loaded. Try again shortly.</p>
                <?php elseif ($salesChart['status'] === 'empty'): ?>
                  <p class="text-sm text-ink-60">No confirmed sales or completed refunds in this period.</p>
                <?php else: ?>
                  <div class="okv-chart-stage" data-okv-chart="sales" aria-hidden="true">
                    <p class="text-sm text-ink-60">Preparing the sales chart. Exact figures are available below.</p>
                  </div>
                  <details class="okv-chart-details mt-4">
                    <summary>View exact sales figures</summary>
                    <div class="okv-table-wrap mt-2">
                      <table class="okv-table">
                        <caption class="sr-only">Daily gross receipts, completed refunds and net sales for <?= okv_e($periodLabel) ?></caption>
                        <thead><tr><th scope="col">Date</th><th scope="col" class="text-right">Gross</th><th scope="col" class="text-right">Refunds</th><th scope="col" class="text-right">Net sales</th></tr></thead>
                        <tbody>
                          <?php foreach ($salesChart['series'] as $day): ?>
                            <tr>
                              <th scope="row" class="px-3 py-2.5 text-left font-medium"><?= okv_e(date('j M Y', strtotime($day['date']))) ?></th>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(Money::format((int) $day['gross_subunit'])) ?></td>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(Money::format((int) $day['refund_subunit'])) ?></td>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(Money::format((int) $day['amount_subunit'])) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </details>
                <?php endif; ?>
                <?php if ($salesChart['undated_receipts_count'] > 0): ?>
                  <p class="okv-note-bad mt-4">
                    <?= okv_e((string) $salesChart['undated_receipts_count']) ?> confirmed
                    <?= $salesChart['undated_receipts_count'] === 1 ? 'receipt has' : 'receipts have' ?> no payment date and cannot appear in this chart.
                  </p>
                <?php endif; ?>
              </div>
            </article>
          <?php endif; ?>

          <?php if ($topProductsChart !== null): ?>
            <article class="okv-panel" aria-labelledby="okv-products-title">
              <div class="okv-panel-head">
                <div>
                  <h3 id="okv-products-title" class="okv-panel-title">Top products</h3>
                  <p class="mt-1 text-xs text-ink-60">Ranked by net sales value after completed refunds.</p>
                </div>
              </div>
              <div class="okv-panel-body">
                <?php if ($topProductsChart['status'] === 'error'): ?>
                  <p class="okv-note-bad">Top products could not be loaded. Try again shortly.</p>
                <?php elseif ($topProductsChart['status'] === 'empty'): ?>
                  <p class="text-sm text-ink-60">No non-cancelled order lines were sold in this period.</p>
                <?php else: ?>
                  <div class="okv-chart-stage" data-okv-chart="products" aria-hidden="true">
                    <p class="text-sm text-ink-60">Preparing the product ranking. Exact figures are available below.</p>
                  </div>
                  <details class="okv-chart-details mt-4">
                    <summary>View exact product figures</summary>
                    <div class="okv-table-wrap mt-2">
                      <table class="okv-table">
                        <caption class="sr-only">Top products ranked by net sales value for <?= okv_e($periodLabel) ?></caption>
                        <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col" class="text-right">Orders</th><th scope="col" class="text-right">Net value</th></tr></thead>
                        <tbody>
                          <?php foreach ($topProductsChart['rows'] as $index => $row): ?>
                            <tr>
                              <th scope="row" class="px-3 py-2.5 text-left">
                                <span class="okv-table-name"><?= okv_e(($index + 1) . '. ' . $row['label']) ?></span>
                                <span class="okv-table-sub block"><?= okv_e(ucwords(str_replace('_', ' ', $row['kind']))) ?></span>
                              </th>
                              <td class="font-mono tabular-nums"><?= okv_e($row['quantity'] . ' ' . $row['unit_name']) ?></td>
                              <td class="text-right font-mono tabular-nums"><?= okv_e((string) $row['order_count']) ?></td>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(Money::format((int) $row['amount_subunit'])) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </details>
                <?php endif; ?>
              </div>
            </article>
          <?php endif; ?>

          <?php if ($categoryChart !== null): ?>
            <article class="okv-panel" aria-labelledby="okv-category-title">
              <div class="okv-panel-head">
                <div>
                  <h3 id="okv-category-title" class="okv-panel-title">Order share by category</h3>
                  <p class="mt-1 text-xs text-ink-60">Share of refund-adjusted line value.</p>
                </div>
              </div>
              <div class="okv-panel-body">
                <?php if ($categoryChart['status'] === 'error'): ?>
                  <p class="okv-note-bad">Category share could not be loaded. Try again shortly.</p>
                <?php elseif ($categoryChart['status'] === 'empty'): ?>
                  <p class="text-sm text-ink-60">No categorised sales are available for this period.</p>
                <?php else: ?>
                  <div class="okv-chart-stage" data-okv-chart="categories" aria-hidden="true">
                    <p class="text-sm text-ink-60">Preparing the category chart. Exact figures are available below.</p>
                  </div>
                  <details class="okv-chart-details mt-4">
                    <summary>View exact category figures</summary>
                    <div class="okv-table-wrap mt-2">
                      <table class="okv-table">
                        <caption class="sr-only">Order share for every fixed shopping group for <?= okv_e($periodLabel) ?></caption>
                        <thead><tr><th scope="col">Category</th><th scope="col" class="text-right">Share</th><th scope="col" class="text-right">Net value</th></tr></thead>
                        <tbody>
                          <?php $shareBySlug = array_column($categoryChart['rows'], null, 'category_slug'); ?>
                          <?php foreach (AdminDashboard::SHOPPING_GROUPS as $slug => $group): ?>
                            <?php $row = $shareBySlug[$slug] ?? ['share_basis_points' => 0, 'amount_subunit' => 0]; ?>
                            <tr>
                              <th scope="row" class="px-3 py-2.5 text-left">
                                <span class="okv-chart-key okv-chart-token-<?= okv_e(str_replace('.', '-', $group['colour_token'])) ?>" aria-hidden="true"></span>
                                <span class="okv-table-name"><?= okv_e($group['label']) ?></span>
                              </th>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(intdiv((int) $row['share_basis_points'], 100) . '.' . str_pad((string) ((int) $row['share_basis_points'] % 100), 2, '0', STR_PAD_LEFT) . '%') ?></td>
                              <td class="text-right font-mono tabular-nums"><?= okv_e(Money::format((int) $row['amount_subunit'])) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </details>
                <?php endif; ?>
                <?php if ($categoryChart['uncategorised_subunit'] > 0): ?>
                  <p class="okv-note-bad mt-4"><?= okv_e(Money::format($categoryChart['uncategorised_subunit'])) ?> in manual or retired-product lines could not be assigned to a category and is excluded from the percentages.</p>
                <?php endif; ?>
                <?php if ($categoryChart['unallocated_refund_subunit'] > 0): ?>
                  <p class="okv-note-bad mt-4"><?= okv_e(Money::format($categoryChart['unallocated_refund_subunit'])) ?> in completed refunds exceeds eligible line value and is excluded from this chart.</p>
                <?php endif; ?>
              </div>
            </article>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($hasQuickLinks): ?>
      <section aria-labelledby="okv-quick">
        <h2 id="okv-quick" class="okv-eyebrow">Run the week</h2>
        <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <?php if ($canPricing): ?>
            <a href="/admin/pricing.php" class="okv-panel block min-h-[44px] okv-panel-body transition duration-botanical ease-botanical hover:border-forest">
              <p class="okv-panel-title">This week's prices</p>
              <p class="mt-1 text-sm text-ink-60">Type over a price, move a whole category, or send the spreadsheet back in. Every change is recorded.</p>
            </a>
          <?php endif; ?>
          <?php if ($canProducts): ?>
            <a href="/admin/products.php" class="okv-panel block min-h-[44px] okv-panel-body transition duration-botanical ease-botanical hover:border-forest">
              <p class="okv-panel-title">The catalogue</p>
              <p class="mt-1 text-sm text-ink-60">Add produce, set what is available this week, and manage the photos customers see.</p>
            </a>
          <?php endif; ?>
          <?php if ($canCombos): ?>
            <a href="/admin/combos.php" class="okv-panel block min-h-[44px] okv-panel-body transition duration-botanical ease-botanical hover:border-forest">
              <p class="okv-panel-title">Combos</p>
              <p class="mt-1 text-sm text-ink-60">Build a ready basket from the catalogue, price it, and put it on the shop.</p>
            </a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

  </div>
<?php if ($hasChartPayload): ?>
  <script id="okv-dashboard-data" type="application/json"><?= json_encode(
      ['period' => $period, 'charts' => $chartPayload],
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
  ) ?></script>
  <?php $okv_admin_script = '/assets/js/admin-dashboard.js'; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/components/admin/footer.php';
