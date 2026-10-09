<?php
/**
 * admin/reports.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Reporting Dashboard. Revenue against expenses, the profit it
 * leaves, and what is still owed, read at a glance: four KPI tiles, a monthly
 * trend, an expenses-by-category donut, and three short "top" tables. Mobile
 * first, cards and figures and graphs, the least text possible.
 *
 * Gated by reports.view. PHP prints every exact figure first (figures before
 * pictures); the charts are drawn by Chart.js from a JSON payload on the page,
 * self-hosted so nothing loads from a CDN at runtime. Chart colours come from
 * brand tokens published as CSS variables, so no hex ever lives in the markup.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('reports.view');

$period = FinancialReport::normalisePeriod(okv_input('period', FinancialReport::DEFAULT_PERIOD));
$data   = FinancialReport::dashboard($period);
$kpi    = $data['kpi'];

$periodLabels = ['7' => '7 days', '30' => '30 days', '90' => '90 days', 'month' => 'This month'];

/** The brand-token CSS variable for a category colour. No hex reaches the markup. */
if (!function_exists('okv_report_dot_var')) {
    function okv_report_dot_var(string $token): string
    {
        $known = ['forest', 'foliage', 'gold', 'tomato', 'clay', 'ink'];
        return 'var(--okv-c-' . (in_array($token, $known, true) ? $token : 'ink') . ')';
    }
}

/** A tiny line icon. */
if (!function_exists('okv_report_icon')) {
    function okv_report_icon(string $name): string
    {
        $paths = [
            'info'  => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
            'up'    => '<path d="M4 17 10 11l4 4 6-7"/><path d="M20 11V8M20 11h-3"/>',
            'coins' => '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M15 12.5c2.8-.2 6-1.4 6-3.5"/>',
            'bag'   => '<path d="M6 8h12l-1 12H7z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
        ];
        $inner = $paths[$name] ?? '<circle cx="12" cy="12" r="9"/>';
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}

$profit = (int) $kpi['profit_subunit'];

$okv_admin_title  = 'Reports';
$okv_admin_script = ['/assets/js/vendor/chart.umd.min.js', '/assets/js/admin-reports.js'];
require __DIR__ . '/../includes/components/admin/header.php';
?>

<!-- Period presets -->
<div class="mb-4 flex items-center gap-2 overflow-x-auto pb-1" role="group" aria-label="Period">
  <?php foreach ($periodLabels as $value => $label): ?>
    <a href="?period=<?= okv_e($value) ?>" class="okv-filter-chip <?= $period === $value ? 'okv-filter-chip-active' : '' ?>"><?= okv_e($label) ?></a>
  <?php endforeach; ?>
  <button type="button" class="okv-info-btn ml-1" aria-expanded="false" aria-controls="report-info" data-info-toggle>
    <?= okv_report_icon('info') ?><span class="sr-only">What these figures mean</span>
  </button>
</div>
<p id="report-info" class="okv-info-text mb-4" hidden>Revenue is cash confirmed through the app for the period. Expenses are what you logged. Profit is revenue minus expenses. Outstanding is money customers still owe across all live orders.</p>

<!-- KPI tiles -->
<section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Headline figures">
  <div class="okv-stat-tile border-l-4 border-forest">
    <p class="okv-stat-value"><?= okv_e(Money::format((int) $kpi['revenue_subunit'])) ?></p>
    <p class="okv-stat-label">Revenue</p>
  </div>
  <div class="okv-stat-tile border-l-4 border-clay">
    <p class="okv-stat-value"><?= okv_e(Money::format((int) $kpi['expense_subunit'])) ?></p>
    <p class="okv-stat-label">Expenses</p>
  </div>
  <div class="okv-stat-tile border-l-4 <?= $profit < 0 ? 'border-tomato' : 'border-foliage' ?>">
    <p class="okv-stat-value <?= $profit < 0 ? 'text-tomato' : 'text-forest' ?>"><?= okv_e(Money::format($profit)) ?></p>
    <p class="okv-stat-label">Profit</p>
  </div>
  <div class="okv-stat-tile border-l-4 border-gold">
    <p class="okv-stat-value"><?= okv_e(Money::format((int) $kpi['outstanding_subunit'])) ?></p>
    <p class="okv-stat-label">Outstanding</p>
  </div>
</section>

<!-- Charts -->
<section class="mb-5 grid gap-4 lg:grid-cols-2" aria-label="Charts">
  <div class="okv-card">
    <div class="mb-3 flex items-center justify-between">
      <h2 class="text-sm font-semibold text-ink">Revenue and expenses</h2>
      <span class="font-mono text-okv-micro text-ink-40">last <?= (int) FinancialReport::TREND_MONTHS ?> months</span>
    </div>
    <div class="okv-chart-box"><canvas data-report-trend aria-hidden="true"></canvas></div>
    <table class="sr-only">
      <caption>Revenue, expenses and profit by month</caption>
      <thead><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Profit</th></tr></thead>
      <tbody>
        <?php foreach ($data['series'] as $pt): ?>
          <tr>
            <td><?= okv_e($pt['month']) ?></td>
            <td><?= okv_e(Money::format((int) $pt['revenue_subunit'])) ?></td>
            <td><?= okv_e(Money::format((int) $pt['expense_subunit'])) ?></td>
            <td><?= okv_e(Money::format((int) $pt['profit_subunit'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="okv-card">
    <h2 class="mb-3 text-sm font-semibold text-ink">Expenses by category</h2>
    <?php if (!$data['category_breakdown']): ?>
      <p class="py-10 text-center text-sm text-ink-40">Nothing logged for this period.</p>
    <?php else: ?>
      <div class="flex flex-col items-center gap-4 sm:flex-row">
        <div class="okv-chart-box w-full sm:w-1/2"><canvas data-report-donut aria-hidden="true"></canvas></div>
        <ul class="w-full space-y-1.5 sm:w-1/2">
          <?php foreach ($data['category_breakdown'] as $c): ?>
            <li class="flex items-center gap-2 text-sm">
              <span class="okv-legend-dot" style="background-color: <?= okv_e(okv_report_dot_var((string) $c['colour'])) ?>"></span>
              <span class="min-w-0 flex-1 truncate text-ink-60"><?= okv_e((string) $c['name']) ?></span>
              <span class="font-mono font-medium text-ink"><?= okv_e(Money::format((int) $c['amount_subunit'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Top tables -->
<section class="grid gap-4 lg:grid-cols-3" aria-label="Leaders">
  <?php
  $tables = [
      ['title' => 'Top suppliers', 'icon' => 'coins', 'rows' => $data['top_suppliers'], 'name' => 'supplier',  'empty' => 'No expenses yet'],
      ['title' => 'Top customers', 'icon' => 'up',    'rows' => $data['top_customers'], 'name' => 'customer',  'empty' => 'No orders yet'],
      ['title' => 'Top products',  'icon' => 'bag',   'rows' => $data['top_products'],  'name' => 'label',     'empty' => 'No sales yet'],
  ];
  foreach ($tables as $t): ?>
    <div class="okv-card">
      <div class="mb-3 flex items-center gap-2 text-forest">
        <?= okv_report_icon($t['icon']) ?>
        <h2 class="text-sm font-semibold text-ink"><?= okv_e($t['title']) ?></h2>
      </div>
      <?php if (!$t['rows']): ?>
        <p class="py-6 text-center text-sm text-ink-40"><?= okv_e($t['empty']) ?></p>
      <?php else: ?>
        <ol class="space-y-2">
          <?php foreach ($t['rows'] as $i => $row): ?>
            <li class="flex items-center gap-3">
              <span class="flex h-6 w-6 flex-none items-center justify-center rounded-full bg-forest-tint font-mono text-okv-micro font-semibold text-forest"><?= (int) $i + 1 ?></span>
              <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink"><?= okv_e((string) ($row[$t['name']] ?? 'Unknown')) ?></span>
              <span class="font-mono text-sm font-bold text-ink"><?= okv_e(Money::format((int) ($row['amount_subunit'] ?? 0))) ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>

<script type="application/json" id="okv-report-data"><?= json_encode([
    'series'   => array_map(static fn(array $p): array => [
        'month'   => $p['month'],
        'revenue' => (int) $p['revenue_subunit'],
        'expense' => (int) $p['expense_subunit'],
        'profit'  => (int) $p['profit_subunit'],
    ], $data['series']),
    'categories' => array_map(static fn(array $c): array => [
        'name'   => $c['name'],
        'colour' => $c['colour'],
        'amount' => (int) $c['amount_subunit'],
    ], $data['category_breakdown']),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
