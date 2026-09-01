<?php
/**
 * admin/index.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The admin home. The full "today at a glance" dashboard, with
 * today's orders, revenue, payments due and the sales charts, is milestone M11.
 * Until then this screen tells the truth about what the business already has on
 * the shop, and puts the built screens one click away.
 *
 * Every figure below comes from a milestone that is actually built, and every
 * one is gated on the same permission as the screen it links to, so a Manager
 * never reads a count they are not allowed to open. See docs/PRD.md Section 17.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('dashboard.view');

$me        = Database::one('SELECT first_name FROM users WHERE id = :id', [':id' => (int) Rbac::userId()]);
$firstName = trim((string) ($me['first_name'] ?? ''));

$canProducts = Rbac::can('products.view');
$canPricing  = Rbac::can('pricing.view');
$canCombos   = Rbac::can('combos.view');
$canUsers    = Rbac::can('users.view');

// Counts, only for the screens this person can open.
$onShop = $offShop = $unpriced = $liveCombos = $draftCombos = $staffCount = 0;
if ($canProducts) {
    $onShop  = Products::count('', '', 'active');
    $offShop = Products::count('', '', 'inactive');
}
if ($canPricing) {
    $unpriced = (int) (Database::one(
        'SELECT COUNT(*) AS c FROM products WHERE is_active = 1 AND current_price_subunit <= 0'
    )['c'] ?? 0);
}
if ($canCombos) {
    $liveCombos  = (int) (Database::one('SELECT COUNT(*) AS c FROM combo_packages WHERE is_active = 1')['c'] ?? 0);
    $draftCombos = (int) (Database::one('SELECT COUNT(*) AS c FROM combo_packages WHERE is_active = 0')['c'] ?? 0);
}
if ($canUsers) {
    $staffCount = (int) (Database::one("SELECT COUNT(*) AS c FROM users WHERE user_type = 'staff' AND status = 'active'")['c'] ?? 0);
}

$okv_admin_title = 'Dashboard';
$okv_admin_note  = $firstName !== ''
    ? 'Welcome back, ' . $firstName . '. Here is where the shop stands right now.'
    : 'Here is where the shop stands right now.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="space-y-6">

    <!-- What is on the shop right now. Real counts, no placeholders. --------->
    <section aria-labelledby="okv-shop-state">
      <h2 id="okv-shop-state" class="okv-eyebrow">On the shop today</h2>
      <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">

        <?php if ($canProducts): ?>
          <a href="/admin/products.php?status=active" class="okv-stat block transition duration-botanical ease-botanical hover:border-forest" data-perm="products.view">
            <p class="okv-stat-label">Products on the shop</p>
            <p class="okv-stat-figure"><?= $onShop ?></p>
            <p class="okv-stat-note"><?= $offShop ?> switched off</p>
          </a>
        <?php endif; ?>

        <?php if ($canPricing): ?>
          <a href="/admin/pricing.php" class="okv-stat block transition duration-botanical ease-botanical hover:border-forest" data-perm="pricing.view">
            <p class="okv-stat-label">Still without a price</p>
            <p class="okv-stat-figure <?= $unpriced > 0 ? 'text-tomato' : '' ?>"><?= $unpriced ?></p>
            <p class="okv-stat-note"><?= $unpriced > 0 ? 'Price them before they can sell' : 'Every active product is priced' ?></p>
          </a>
        <?php endif; ?>

        <?php if ($canCombos): ?>
          <a href="/admin/combos.php" class="okv-stat block transition duration-botanical ease-botanical hover:border-forest" data-perm="combos.view">
            <p class="okv-stat-label">Combos live</p>
            <p class="okv-stat-figure"><?= $liveCombos ?></p>
            <p class="okv-stat-note"><?= $draftCombos ?> still a draft</p>
          </a>
        <?php endif; ?>

        <?php if ($canUsers): ?>
          <a href="/admin/users.php" class="okv-stat block transition duration-botanical ease-botanical hover:border-forest" data-perm="users.view">
            <p class="okv-stat-label">Staff who can sign in</p>
            <p class="okv-stat-figure"><?= $staffCount ?></p>
            <p class="okv-stat-note">Owner and Manager accounts</p>
          </a>
        <?php endif; ?>

      </div>
    </section>

    <!-- Where to go next ---------------------------------------------------->
    <section aria-labelledby="okv-quick">
      <h2 id="okv-quick" class="okv-eyebrow">Run the week</h2>
      <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <a href="/admin/pricing.php" class="okv-panel block okv-panel-body transition duration-botanical ease-botanical hover:border-forest" data-perm="pricing.view">
          <p class="okv-panel-title">This week's prices</p>
          <p class="text-sm text-ink-60 mt-1">Type over a price, move a whole category, or send the spreadsheet back in. Every change is recorded.</p>
        </a>
        <a href="/admin/products.php" class="okv-panel block okv-panel-body transition duration-botanical ease-botanical hover:border-forest" data-perm="products.view">
          <p class="okv-panel-title">The catalogue</p>
          <p class="text-sm text-ink-60 mt-1">Add produce, set what is available this week, and manage the photos customers see.</p>
        </a>
        <a href="/admin/combos.php" class="okv-panel block okv-panel-body transition duration-botanical ease-botanical hover:border-forest" data-perm="combos.view">
          <p class="okv-panel-title">Combos</p>
          <p class="text-sm text-ink-60 mt-1">Build a ready basket from the catalogue, price it, and put it on the shop.</p>
        </a>
      </div>
    </section>

    <!-- What is still to come ----------------------------------------------->
    <div class="okv-panel">
      <div class="okv-panel-body">
        <p class="okv-eyebrow">Still being built</p>
        <p class="text-sm text-ink-60 mt-2 max-w-2xl">
          Today's orders, revenue, payments due and the sales charts land with the orders and payments milestones.
          Until then the screens in the menu that are not built yet say so plainly rather than sending you nowhere.
        </p>
      </div>
    </div>

  </div>
<?php
require __DIR__ . '/../includes/components/admin/footer.php';
