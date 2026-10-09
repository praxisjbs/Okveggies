<?php
/**
 * admin/expenses.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Expense module screen. Money out, logged on a phone in a few
 * taps and read at a glance: a KPI strip, a month and category filter as pills,
 * a list of expense cards, and a slide-up sheet to record one. Little text, more
 * icons; explanations hide behind info buttons.
 *
 * The page renders the figures and the list server-side (figures before
 * pictures, like the dashboard), gated by expenses.view. The entry sheet and
 * the void control only render for expenses.manage, and every action is
 * re-checked on the server in api/v1/expenses.php.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('expenses.view');

$canManage = Rbac::can('expenses.manage');

// Month filter, default to this month; only a real YYYY-MM is accepted.
$month = (string) okv_input('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
$month      = $monthStart->format('Y-m');
$monthLabel = $monthStart->format('F Y');
$prevMonth  = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth  = $monthStart->modify('+1 month')->format('Y-m');

// Category filter, only a real category slug is accepted.
$activeCategory = (string) okv_input('category', '');
if ($activeCategory !== '' && !Expenses::isCategory($activeCategory)) {
    $activeCategory = '';
}

$categories = Expenses::categories();

// The list honours both filters. The KPI strip reports the whole month, so it
// stays steady while a category pill narrows the list beneath it.
$listRows  = Expenses::listRecent(['month' => $month, 'category_slug' => $activeCategory, 'limit' => 300]);
$monthRows = $activeCategory === ''
    ? $listRows
    : Expenses::listRecent(['month' => $month, 'limit' => 300]);
$summary = Expenses::summarise(array_map(
    static fn(array $r): array => ['amount_subunit' => (int) $r['amount_subunit'], 'category_slug' => (string) $r['category_slug']],
    $monthRows
));

/** The tint and text classes for a category colour token. Literal strings, so Tailwind sees them. */
if (!function_exists('okv_expense_pill_classes')) {
    function okv_expense_pill_classes(string $token): string
    {
        return [
            'forest'  => 'bg-forest-tint text-forest',
            'foliage' => 'bg-foliage-tint text-forest',
            'gold'    => 'bg-gold-tint2 text-gold-ink',
            'tomato'  => 'bg-tomato-tint text-tomato',
            'clay'    => 'bg-clay-tint text-clay-ink',
            'ink'     => 'bg-mist text-ink-60',
        ][$token] ?? 'bg-mist text-ink-60';
    }
}

/** A tiny line icon. 20px, 2px stroke, matches the admin set. */
if (!function_exists('okv_expense_icon')) {
    function okv_expense_icon(string $name): string
    {
        $paths = [
            'plus'    => '<path d="M12 5v14M5 12h14"/>',
            'left'    => '<path d="m15 6-6 6 6 6"/>',
            'right'   => '<path d="m9 6 6 6-6 6"/>',
            'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
            'trash'   => '<path d="M5 7h14M10 7V5h4v2M6 7l1 12h10l1-12"/>',
            'tick'    => '<path d="m5 12 4 4 10-10"/>',
            'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
            'wallet'  => '<path d="M4 7h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M4 7V6a2 2 0 0 1 2-2h10"/><circle cx="16" cy="13" r="1.3"/>',
        ];
        $inner = $paths[$name] ?? '<circle cx="12" cy="12" r="9"/>';
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}

$okv_admin_title  = 'Expenses';
$okv_admin_crumbs = [];
if ($canManage) {
    $okv_admin_actions = '<button type="button" class="okv-btn-sm" data-expense-add-open>'
        . okv_expense_icon('plus') . '<span>Record expense</span></button>';
}
$okv_admin_script = '/assets/js/admin-expenses.js';
require __DIR__ . '/../includes/components/admin/header.php';
?>

<!-- KPI strip: the month's spend, split into produce you resell and running costs. -->
<section class="mb-5" aria-label="This month's spend">
  <div class="mb-2 flex items-center gap-2">
    <h2 class="text-sm font-semibold text-ink"><?= okv_e($monthLabel) ?></h2>
    <button type="button" class="okv-info-btn" aria-expanded="false" aria-controls="kpi-info" data-info-toggle>
      <?= okv_expense_icon('info') ?><span class="sr-only">What these figures mean</span>
    </button>
  </div>
  <p id="kpi-info" class="okv-info-text mb-3" hidden>Stock is the produce and goods you buy to resell. Running is every other cost: fuel, transport, data, charges and the rest. Spent is the two together.</p>
  <div class="grid grid-cols-3 gap-3"
       data-expense-kpi
       data-total="<?= (int) $summary['total'] ?>"
       data-cog="<?= (int) $summary['cost_of_goods'] ?>"
       data-op="<?= (int) $summary['operating'] ?>">
    <div class="okv-stat-tile">
      <p class="okv-stat-value" data-kpi="total"><?= okv_e(Money::format((int) $summary['total'])) ?></p>
      <p class="okv-stat-label">Spent</p>
    </div>
    <div class="okv-stat-tile border-l-4 border-foliage">
      <p class="okv-stat-value" data-kpi="cog"><?= okv_e(Money::format((int) $summary['cost_of_goods'])) ?></p>
      <p class="okv-stat-label">Stock</p>
    </div>
    <div class="okv-stat-tile border-l-4 border-gold">
      <p class="okv-stat-value" data-kpi="op"><?= okv_e(Money::format((int) $summary['operating'])) ?></p>
      <p class="okv-stat-label">Running</p>
    </div>
  </div>
</section>

<!-- Filters: month left and right, then a scrollable strip of category pills. -->
<section class="sticky top-16 z-[5] -mx-4 mb-4 bg-forest-tint px-4 py-2 md:mx-0 md:rounded-lg md:px-3" aria-label="Filter expenses">
  <div class="mb-2 flex items-center justify-between gap-2">
    <a href="?month=<?= okv_e($prevMonth) ?><?= $activeCategory ? '&category=' . okv_e($activeCategory) : '' ?>"
       class="okv-filter-chip" aria-label="Previous month"><?= okv_expense_icon('left') ?></a>
    <span class="font-mono text-sm font-semibold text-ink"><?= okv_e($monthLabel) ?></span>
    <a href="?month=<?= okv_e($nextMonth) ?><?= $activeCategory ? '&category=' . okv_e($activeCategory) : '' ?>"
       class="okv-filter-chip" aria-label="Next month"><?= okv_expense_icon('right') ?></a>
  </div>
  <div class="flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Category">
    <a href="?month=<?= okv_e($month) ?>" class="okv-filter-chip <?= $activeCategory === '' ? 'okv-filter-chip-active' : '' ?>">All</a>
    <?php foreach ($categories as $c): ?>
      <a href="?month=<?= okv_e($month) ?>&category=<?= okv_e($c['slug']) ?>"
         class="okv-filter-chip <?= $activeCategory === $c['slug'] ? 'okv-filter-chip-active' : '' ?>">
        <?= okv_e($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- The list. Server-rendered; JS prepends new rows and strikes voided ones. -->
<section aria-label="Expenses" data-expense-list>
  <?php if (!$listRows): ?>
    <div class="okv-empty" data-expense-empty>
      <div class="okv-empty-art"><?= okv_expense_icon('receipt') ?></div>
      <p class="mt-3 font-display text-lg font-extrabold text-ink">Nothing logged yet</p>
      <p class="mt-1 text-sm text-ink-60">No expenses for <?= okv_e($monthLabel) ?><?= $activeCategory ? ' in that category' : '' ?>.</p>
      <?php if ($canManage): ?>
        <button type="button" class="okv-btn-sm mt-4" data-expense-add-open><?= okv_expense_icon('plus') ?><span>Record expense</span></button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <ul class="space-y-2" data-expense-rows>
      <?php foreach ($listRows as $row):
          $cat   = Expenses::category((string) $row['category_slug']);
          $colour = (string) ($row['category_colour'] ?? ($cat['colour'] ?? 'ink'));
          $when  = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['spent_on']);
          $primary = trim((string) ($row['supplier_name'] ?? '')) !== ''
              ? (string) $row['supplier_name']
              : (trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : (string) $row['category_name']);
      ?>
        <li class="okv-card flex items-center gap-3" data-expense-row data-id="<?= (int) $row['id'] ?>"
            data-amount="<?= (int) $row['amount_subunit'] ?>" data-kind="<?= okv_e((string) $row['category_kind']) ?>">
          <span class="okv-badge <?= okv_e(okv_expense_pill_classes($colour)) ?> flex-none"><?= okv_e((string) $row['category_name']) ?></span>
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-ink"><?= okv_e($primary) ?></p>
            <p class="font-mono text-okv-micro text-ink-40"><?= okv_e($when ? $when->format('D j M') : (string) $row['spent_on']) ?></p>
          </div>
          <span class="font-mono font-bold text-ink"><?= okv_e(Money::format((int) $row['amount_subunit'])) ?></span>
          <?php if ($canManage): ?>
            <button type="button" class="okv-info-btn border-tomato/40 text-tomato hover:border-tomato hover:text-tomato"
                    data-expense-void data-id="<?= (int) $row['id'] ?>" aria-label="Void this expense">
              <?= okv_expense_icon('trash') ?>
            </button>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if ($canManage): ?>
<!-- The entry sheet. Slides up on a phone, centres on a laptop. Amount first. -->
<div class="okv-sheet-backdrop" data-expense-sheet hidden>
  <form class="okv-sheet" method="post" action="/api/v1/expenses.php"
        data-expense-form role="dialog" aria-modal="true" aria-labelledby="expense-sheet-title">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="create">

    <div class="mb-4 flex items-center justify-between">
      <h2 id="expense-sheet-title" class="font-display text-lg font-extrabold text-ink">New expense</h2>
      <button type="button" class="okv-btn-text" data-expense-close>Close</button>
    </div>

    <div class="okv-note-bad mb-3 text-sm" data-expense-error hidden role="alert" aria-live="polite"></div>

    <label class="okv-label" for="expense-amount">Amount</label>
    <div class="okv-amount-field mb-4">
      <span class="okv-amount-naira" aria-hidden="true"><?= okv_e(Money::SYMBOL) ?></span>
      <input id="expense-amount" name="amount" class="okv-amount-input" inputmode="decimal"
             autocomplete="off" placeholder="0" required data-expense-amount>
    </div>

    <div class="mb-1.5 flex items-center gap-2">
      <span class="okv-label mb-0">Category</span>
      <button type="button" class="okv-info-btn" aria-expanded="false" aria-controls="cat-info" data-info-toggle>
        <?= okv_expense_icon('info') ?><span class="sr-only">About categories</span>
      </button>
    </div>
    <p id="cat-info" class="okv-info-text mb-2" hidden>Stock Purchase is produce you buy to resell. Everything else is a running cost.</p>
    <div class="mb-4 flex flex-wrap gap-2" role="radiogroup" aria-label="Category">
      <?php foreach ($categories as $i => $c): ?>
        <label class="okv-cat-pill <?= okv_e(okv_expense_pill_classes($c['colour'])) ?>">
          <input type="radio" class="sr-only" name="category_slug" value="<?= okv_e($c['slug']) ?>"
                 data-colour="<?= okv_e($c['colour']) ?>" data-name="<?= okv_e($c['name']) ?>" data-kind="<?= okv_e($c['kind']) ?>"
                 <?= $i === 0 ? 'required' : '' ?>>
          <span class="okv-cat-tick"><?= okv_expense_icon('tick') ?></span>
          <span><?= okv_e($c['name']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <label class="okv-label" for="expense-supplier">Supplier <span class="font-normal text-ink-40">optional</span></label>
    <div class="relative mb-4">
      <input id="expense-supplier" name="supplier_name" class="okv-input" autocomplete="off"
             placeholder="e.g. Mile 12" data-expense-supplier
             role="combobox" aria-expanded="false" aria-controls="expense-supplier-list" aria-autocomplete="list">
      <ul id="expense-supplier-list" class="okv-zone-popup" role="listbox" data-expense-supplier-list hidden></ul>
    </div>

    <details class="mb-4 rounded-md border border-mist px-3 py-2">
      <summary class="cursor-pointer text-sm font-medium text-ink-60">More</summary>
      <div class="mt-3 space-y-3">
        <div>
          <label class="okv-label" for="expense-date">Date</label>
          <input id="expense-date" name="spent_on" type="date" class="okv-input" value="<?= okv_e(date('Y-m-d')) ?>" data-expense-date>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="okv-label" for="expense-qty">Quantity</label>
            <input id="expense-qty" name="quantity" class="okv-input" inputmode="decimal" autocomplete="off" placeholder="optional">
          </div>
          <div>
            <label class="okv-label" for="expense-unit">Unit cost</label>
            <input id="expense-unit" name="unit_cost" class="okv-input" inputmode="decimal" autocomplete="off" placeholder="optional">
          </div>
        </div>
        <div>
          <label class="okv-label" for="expense-note">Note</label>
          <input id="expense-note" name="description" class="okv-input" maxlength="255" autocomplete="off" placeholder="e.g. Fresh tomatoes">
        </div>
      </div>
    </details>

    <div class="flex gap-2">
      <button type="submit" class="okv-btn flex-1" data-expense-save>Save</button>
      <button type="submit" class="okv-btn-outline" data-expense-save-another>Save and add another</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
