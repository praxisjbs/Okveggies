<?php
/** Full staff notification history, filtered by the current permission set. */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requireAuth();

$notifications = [];
$attention = [];
$loadFailed = false;
try {
    $notifications = AdminNotifications::recent((int) Rbac::userId(), true);
    $attention = AdminNotifications::attention();
} catch (Throwable $e) {
    error_log('admin notifications page: ' . $e->getMessage());
    $loadFailed = true;
}

$okv_admin_title = 'Notifications';
$okv_admin_note = 'Recent updates and the work waiting for your role.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<section class="okv-panel" aria-labelledby="attention-heading">
  <div class="okv-panel-head"><h2 id="attention-heading" class="okv-panel-title">Needs attention</h2></div>
  <div class="okv-panel-body">
    <?php if ($loadFailed): ?>
      <p class="text-sm text-tomato" role="alert">Notifications could not be loaded. Reload the page to try again.</p>
    <?php elseif (!$attention): ?>
      <p class="text-sm text-ink-60">Nothing needs your attention right now.</p>
    <?php else: ?>
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($attention as $item): ?>
          <a href="<?= okv_e($item['href']) ?>" class="flex min-h-[44px] items-center justify-between gap-3 rounded-md border border-mist px-4 py-3 hover:border-forest">
            <span class="font-medium text-ink"><?= okv_e($item['label']) ?></span>
            <span class="font-mono tabular-nums text-forest"><?= okv_e((string) $item['count']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="okv-panel mt-5" aria-labelledby="history-heading">
  <div class="okv-panel-head">
    <div><h2 id="history-heading" class="okv-panel-title">Recent updates</h2><p class="okv-panel-note">Latest 100 notification messages available to your role.</p></div>
  </div>
  <div class="divide-y divide-mist">
    <?php if (!$loadFailed && !$notifications): ?>
      <p class="px-5 py-8 text-sm text-ink-60">No notification messages yet.</p>
    <?php endif; ?>
    <?php foreach ($notifications as $item): ?>
      <a href="<?= okv_e($item['href']) ?>" data-notification-link data-delivery-id="<?= okv_e((string) $item['delivery_id']) ?>"
         class="block min-h-[44px] px-5 py-4 hover:bg-forest-tint <?= empty($item['is_read']) ? 'bg-forest-tint/50' : '' ?>">
        <span class="flex flex-wrap items-start justify-between gap-2">
          <strong class="text-sm text-ink"><?= okv_e($item['title'] ?: 'OK Veggies update') ?></strong>
          <time class="font-mono text-xs tabular-nums text-ink-40" datetime="<?= okv_e((string) $item['created_at']) ?>"><?= okv_e(date('j M Y, H:i', strtotime((string) $item['created_at']))) ?></time>
        </span>
        <span class="mt-1 block whitespace-pre-line text-sm text-ink-60"><?= okv_e($item['body']) ?></span>
        <?php if (empty($item['is_read'])): ?><span class="mt-1 block text-xs font-medium text-forest">Unread</span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
