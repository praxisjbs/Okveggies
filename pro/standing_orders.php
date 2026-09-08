<?php
/**
 * pro/standing_orders.php
 * OK Veggies. Honest Phase 1 boundary for automatic standing orders.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();

$whatsapp = preg_replace('/\D+/', '', Settings::str('support_whatsapp_number', '2348000000000'));

$okv_pro_title  = 'Standing Orders';
$okv_pro_note   = 'Automatic repeat ordering is planned for a later phase.';
$okv_pro_active = '/pro/standing_orders.php';
require __DIR__ . '/../includes/components/pro/header.php';
?>

<section class="okv-panel max-w-3xl" aria-labelledby="standing-orders-heading">
  <div class="okv-panel-body">
    <p class="okv-eyebrow">Not active</p>
    <h2 id="standing-orders-heading" class="okv-panel-title mt-1">Standing orders are planned for a later phase</h2>
    <p class="mt-3 text-ink">
      A standing order will let a business repeat a saved Kitchen List on agreed delivery days.
      Nothing is scheduled from this screen today.
    </p>
    <p class="mt-3 text-sm text-ink-60">
      For now, save your regular items as a Kitchen List and start a Kitchen Run when your kitchen needs them.
    </p>
    <div class="mt-6 flex flex-wrap gap-2">
      <a href="/pro/kitchen_lists.php" class="okv-btn px-5">Open My Kitchen Lists</a>
      <a href="/kitchen-runs.php" class="okv-btn-outline px-5">Start a Kitchen Run</a>
      <a href="/pro/orders.php" class="okv-btn-text px-3">View orders</a>
      <a href="https://wa.me/<?= okv_e($whatsapp) ?>" class="okv-btn-text px-3" rel="noopener">Talk to us on WhatsApp</a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../includes/components/pro/footer.php'; ?>
