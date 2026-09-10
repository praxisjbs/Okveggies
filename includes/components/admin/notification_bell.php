<?php
/** Shared notification bell. Message content is fetched only for this user. */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
$okv_notification_count = 0;
try {
    $okv_notification_count = AdminNotifications::unreadCount((int) Rbac::userId());
} catch (Throwable $e) {
    error_log('admin notification badge: ' . $e->getMessage());
}
$okv_notification_badge = $okv_notification_count > 99 ? '99+' : (string) $okv_notification_count;
?>
<div class="relative shrink-0" data-admin-notifications>
  <button type="button" data-notification-open aria-controls="okv-notification-panel"
          aria-haspopup="dialog" aria-expanded="false"
          aria-label="Notifications, <?= okv_e((string) $okv_notification_count) ?> unread"
          class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-60 hover:bg-forest-tint hover:text-forest">
    <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
    <span data-notification-badge
          class="absolute right-0 top-0 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-tomato px-1 font-mono text-[10px] font-semibold leading-none text-white <?= $okv_notification_count < 1 ? 'hidden' : '' ?>"><?= okv_e($okv_notification_badge) ?></span>
  </button>

  <div id="okv-notification-panel" class="okv-notification-backdrop" data-notification-backdrop hidden>
    <section class="okv-notification-panel" role="dialog" aria-modal="true"
             aria-labelledby="okv-notification-title" aria-describedby="okv-notification-description" tabindex="-1">
      <div class="flex items-start justify-between gap-4 border-b border-mist px-4 py-3">
        <div>
          <h2 id="okv-notification-title" class="okv-panel-title">Notifications</h2>
          <p id="okv-notification-description" class="mt-1 text-xs text-ink-60">Updates and work that need your attention.</p>
        </div>
        <button type="button" data-notification-close aria-label="Close notifications"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-60 hover:bg-forest-tint hover:text-forest">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </div>
      <div class="max-h-[70vh] overflow-y-auto px-4 py-4" data-notification-scroll>
        <p class="text-sm text-ink-60" data-notification-state role="status">Open notifications to load the latest updates.</p>
        <section class="hidden" data-notification-attention aria-labelledby="okv-attention-title">
          <h3 id="okv-attention-title" class="text-sm font-semibold text-ink">Needs attention</h3>
          <div class="mt-2 space-y-2" data-notification-attention-list></div>
        </section>
        <section class="mt-5 hidden" data-notification-recent aria-labelledby="okv-recent-title">
          <h3 id="okv-recent-title" class="text-sm font-semibold text-ink">Recent updates</h3>
          <div class="mt-2 divide-y divide-mist" data-notification-list></div>
        </section>
      </div>
      <div class="flex items-center justify-between gap-3 border-t border-mist px-4 py-3">
        <button type="button" class="okv-btn-text text-sm" data-notification-mark-all>Mark all as read</button>
        <a href="/admin/notifications.php" class="okv-btn-text text-sm">View all notifications</a>
      </div>
    </section>
  </div>
  <p class="sr-only" aria-live="polite" data-notification-live></p>
</div>
<?php unset($okv_notification_count, $okv_notification_badge); ?>
