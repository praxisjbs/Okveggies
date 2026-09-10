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
<?php require __DIR__ . '/../includes/components/admin/footer.php';
