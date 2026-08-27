<?php
/**
 * admin/index.php
 * OK Veggies. The admin home. The full "today at a glance" dashboard with
 * charts is milestone M11; this milestone gives it the real admin shell so sign
 * in, roles and the permission-gated navigation are in place and testable.
 * See docs/PRD.md Section 17.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('dashboard.view');

$me = Database::one('SELECT first_name FROM users WHERE id = :id', [':id' => (int) Rbac::userId()]);
$firstName = trim((string) ($me['first_name'] ?? ''));

$okv_admin_title = 'Dashboard';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="okv-card max-w-2xl">
    <h2 class="font-display font-extrabold text-2xl text-ink">Welcome<?= $firstName !== '' ? ', ' . okv_e($firstName) : '' ?></h2>
    <p class="text-ink-60 mt-2">You are signed in to the OK Veggies admin panel. The full dashboard, with today's orders, revenue, payments due and the sales charts, arrives in a later milestone. Use the menu to reach what you can manage.</p>
  </div>

  <div class="grid gap-4 mt-6 sm:grid-cols-2 lg:grid-cols-3">
    <a href="/admin/orders.php" class="okv-card block" data-perm="orders.view">
      <p class="font-display font-bold text-ink">Orders</p>
      <p class="text-sm text-ink-60 mt-1">See and move orders through their stages.</p>
    </a>
    <a href="/admin/products.php" class="okv-card block" data-perm="products.view">
      <p class="font-display font-bold text-ink">Products</p>
      <p class="text-sm text-ink-60 mt-1">Manage the produce catalogue and prices.</p>
    </a>
    <a href="/admin/users.php" class="okv-card block" data-perm="users.view">
      <p class="font-display font-bold text-ink">Users and Roles</p>
      <p class="text-sm text-ink-60 mt-1">Add staff and set who can do what.</p>
    </a>
  </div>
<?php
require __DIR__ . '/../includes/components/admin/footer.php';
