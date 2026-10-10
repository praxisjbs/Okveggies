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
  <!-- One hero figure, with the split beneath, so long naira figures never collide. -->
  <div class="okv-hero-tile"
       data-expense-kpi
       data-total="<?= (int) $summary['total'] ?>"
       data-cog="<?= (int) $summary['cost_of_goods'] ?>"
       data-op="<?= (int) $summary['operating'] ?>">
    <p class="okv-stat-label">Spent</p>
    <p class="okv-hero-value" data-kpi="total"><?= okv_e(Money::format((int) $summary['total'])) ?></p>
    <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-mist pt-3">
      <span class="flex items-center gap-2">
        <span class="okv-legend-dot" style="background-color: var(--okv-c-foliage)"></span>
        <span class="text-sm text-ink-60">Stock</span>
        <span class="font-mono text-sm font-bold text-ink" data-kpi="cog"><?= okv_e(Money::format((int) $summary['cost_of_goods'])) ?></span>
      </span>
      <span class="flex items-center gap-2">
        <span class="okv-legend-dot" style="background-color: var(--okv-c-gold)"></span>
        <span class="text-sm text-ink-60">Running</span>
        <span class="font-mono text-sm font-bold text-ink" data-kpi="op"><?= okv_e(Money::format((int) $summary['operating'])) ?></span>
      </span>
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
        <li class="okv-card flex items-center gap-2" data-expense-row
            data-id="<?= (int) $row['id'] ?>"
            data-amount="<?= (int) $row['amount_subunit'] ?>"
            data-kind="<?= okv_e((string) $row['category_kind']) ?>"
            data-amount-display="<?= okv_e(Money::format((int) $row['amount_subunit'])) ?>"
            data-category="<?= okv_e((string) $row['category_name']) ?>"
            data-colour="<?= okv_e($colour) ?>"
            data-supplier="<?= okv_e((string) ($row['supplier_name'] ?? '')) ?>"
            data-note="<?= okv_e((string) ($row['description'] ?? '')) ?>"
            data-date="<?= okv_e($when ? $when->format('j M Y') : (string) $row['spent_on']) ?>"
            data-qty="<?= okv_e((string) ($row['quantity'] ?? '')) ?>"
            data-unit-display="<?= okv_e(($row['unit_cost_subunit'] ?? null) !== null ? Money::format((int) $row['unit_cost_subunit']) : '') ?>">
          <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" data-expense-open aria-haspopup="dialog">
            <span class="okv-badge <?= okv_e(okv_expense_pill_classes($colour)) ?> flex-none"><?= okv_e((string) $row['category_name']) ?></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium text-ink"><?= okv_e($primary) ?></span>
              <span class="block font-mono text-okv-micro text-ink-40"><?= okv_e($when ? $when->format('D j M') : (string) $row['spent_on']) ?></span>
            </span>
            <span class="font-mono font-bold text-ink"><?= okv_e(Money::format((int) $row['amount_subunit'])) ?></span>
          </button>
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
<!--
  The entry form. Full screen on a phone, centred dialog on a laptop. It only
  closes on Close or a successful save, never on an outside tap, so nothing you
  are typing is lost. Amount first, all fields shown.
-->
<div class="okv-formscreen-backdrop" data-expense-sheet hidden>
  <form class="okv-formscreen" method="post" action="/api/v1/expenses.php"
        data-expense-form role="dialog" aria-modal="true" aria-labelledby="expense-sheet-title">

    <div class="okv-formscreen-head">
      <h2 id="expense-sheet-title" class="font-display text-lg font-extrabold text-ink">New expense</h2>
      <button type="button" class="okv-btn-text" data-expense-close>Close</button>
    </div>

    <div class="okv-formscreen-body">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create">

      <div class="okv-note-bad text-sm" data-expense-error hidden role="alert" aria-live="polite"></div>

      <div>
        <label class="okv-label" for="expense-amount">Amount</label>
        <div class="okv-amount-field">
          <span class="okv-amount-naira" aria-hidden="true"><?= okv_e(Money::SYMBOL) ?></span>
          <input id="expense-amount" name="amount" class="okv-amount-input" inputmode="decimal"
                 autocomplete="off" placeholder="0" required data-expense-amount>
        </div>
      </div>

      <div>
        <div class="mb-1.5 flex items-center gap-2">
          <label class="okv-label mb-0" for="expense-category">Category</label>
          <button type="button" class="okv-info-btn" aria-expanded="false" aria-controls="cat-info" data-info-toggle>
            <?= okv_expense_icon('info') ?><span class="sr-only">About categories</span>
          </button>
        </div>
        <p id="cat-info" class="okv-info-text mb-2" hidden>Stock Purchase is produce you buy to resell. Everything else is a running cost.</p>
        <select id="expense-category" name="category_slug" class="okv-input" required data-expense-category>
          <?php foreach ($categories as $c): ?>
            <option value="<?= okv_e($c['slug']) ?>" data-colour="<?= okv_e($c['colour']) ?>" data-kind="<?= okv_e($c['kind']) ?>" data-name="<?= okv_e($c['name']) ?>"><?= okv_e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="okv-label" for="expense-supplier">Supplier <span class="font-normal text-ink-40">optional</span></label>
        <div class="relative">
          <input id="expense-supplier" name="supplier_name" class="okv-input" autocomplete="off"
                 placeholder="e.g. Mile 12" data-expense-supplier
                 role="combobox" aria-expanded="false" aria-controls="expense-supplier-list" aria-autocomplete="list">
          <ul id="expense-supplier-list" class="okv-zone-popup" role="listbox" data-expense-supplier-list hidden></ul>
        </div>
      </div>

      <div>
        <label class="okv-label" for="expense-date">Date</label>
        <input id="expense-date" name="spent_on" type="date" class="okv-input" value="<?= okv_e(date('Y-m-d')) ?>" data-expense-date>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="okv-label" for="expense-qty">Quantity <span class="font-normal text-ink-40">optional</span></label>
          <input id="expense-qty" name="quantity" class="okv-input" inputmode="decimal" autocomplete="off" placeholder="optional">
        </div>
        <div>
          <label class="okv-label" for="expense-unit">Unit cost <span class="font-normal text-ink-40">optional</span></label>
          <input id="expense-unit" name="unit_cost" class="okv-input" inputmode="decimal" autocomplete="off" placeholder="optional">
        </div>
      </div>

      <div>
        <label class="okv-label" for="expense-note">Note <span class="font-normal text-ink-40">optional</span></label>
        <input id="expense-note" name="description" class="okv-input" maxlength="255" autocomplete="off" placeholder="e.g. Fresh tomatoes">
      </div>
    </div>

    <div class="okv-formscreen-foot">
      <button type="submit" class="okv-btn flex-1" data-expense-save>Save</button>
      <button type="submit" class="okv-btn-outline" data-expense-save-another>Add another</button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Expense detail. Opens when a card is tapped; read-only, with Void for managers. -->
<div class="okv-formscreen-backdrop" data-expense-detail hidden>
  <div class="okv-formscreen sm:max-w-sm" role="dialog" aria-modal="true" aria-labelledby="expense-detail-title">
    <div class="okv-formscreen-head">
      <h2 id="expense-detail-title" class="font-display text-lg font-extrabold text-ink">Expense</h2>
      <button type="button" class="okv-btn-text" data-expense-detail-close>Close</button>
    </div>
    <div class="okv-formscreen-body">
      <p class="okv-stat-label">Amount</p>
      <p class="okv-hero-value" data-detail="amount">-</p>
      <div><span class="okv-badge bg-mist text-ink-60" data-detail="category">-</span></div>
      <dl class="overflow-hidden rounded-lg border border-mist">
        <div class="flex items-center justify-between gap-3 border-b border-mist px-3 py-2">
          <dt class="text-sm text-ink-60">Supplier</dt><dd class="text-right font-medium text-ink" data-detail="supplier">-</dd>
        </div>
        <div class="flex items-center justify-between gap-3 px-3 py-2" data-detail-row="date">
          <dt class="text-sm text-ink-60">Date</dt><dd class="text-right font-mono text-ink" data-detail="date">-</dd>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-mist px-3 py-2" data-detail-row="qty" hidden>
          <dt class="text-sm text-ink-60">Quantity</dt><dd class="text-right font-mono text-ink" data-detail="qty">-</dd>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-mist px-3 py-2" data-detail-row="unit" hidden>
          <dt class="text-sm text-ink-60">Unit cost</dt><dd class="text-right font-mono text-ink" data-detail="unit">-</dd>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-mist px-3 py-2" data-detail-row="note" hidden>
          <dt class="text-sm text-ink-60">Note</dt><dd class="text-right text-ink" data-detail="note">-</dd>
        </div>
      </dl>
    </div>
    <?php if ($canManage): ?>
    <div class="okv-formscreen-foot">
      <button type="button" class="okv-btn-danger flex-1" data-expense-detail-void>Void this expense</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
