<?php
/**
 * Customer notification bell, shared by the storefront and Pro shells.
 *
 * Rendered only for a signed-in account holder, next to the account link in the
 * header. It shows an unread badge server-side, then loads the panel contents
 * from /api/v1/notifications.php when opened. The panel lists work that still
 * needs the customer (payments to complete, quotes to review) and the recent
 * in-app copies of the emails they were sent. Reading is per item, with a mark
 * all as read control, so the badge stays honest.
 *
 * Self-contained on purpose: the storefront has no single shared footer, so the
 * bell emits its own CSRF bootstrap and script once, guarded, and works on any
 * page that renders a header.
 */
if (!defined('OKV_BOOTSTRAPPED') || !Customer::isLoggedIn()) {
    return;
}
$okv_customer_id = (int) Customer::id();
$okv_customer_unread = 0;
try {
    $okv_customer_unread = CustomerNotifications::unreadCount($okv_customer_id);
} catch (Throwable $e) {
    error_log('customer notification badge: ' . $e->getMessage());
}
$okv_customer_badge = $okv_customer_unread > 99 ? '99+' : (string) $okv_customer_unread;
?>
<div class="relative shrink-0" data-customer-notifications>
  <button type="button" data-notification-open aria-controls="okv-customer-notification-panel"
          aria-haspopup="dialog" aria-expanded="false"
          aria-label="Updates, <?= okv_e((string) $okv_customer_unread) ?> unread"
          class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-ink-60 hover:bg-forest-tint hover:text-forest">
    <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
    <span data-notification-badge
          class="absolute right-0 top-0 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-tomato px-1 font-mono text-okv-caption font-semibold leading-none text-white <?= $okv_customer_unread < 1 ? 'hidden' : '' ?>"><?= okv_e($okv_customer_badge) ?></span>
  </button>

  <div id="okv-customer-notification-panel" class="okv-notification-backdrop" data-notification-backdrop hidden>
    <section class="okv-notification-panel" role="dialog" aria-modal="true"
             aria-labelledby="okv-customer-notification-title" aria-describedby="okv-customer-notification-description" tabindex="-1">
      <div class="flex items-start justify-between gap-4 border-b border-mist px-4 py-3">
        <div>
          <h2 id="okv-customer-notification-title" class="okv-panel-title">Updates</h2>
          <p id="okv-customer-notification-description" class="mt-1 text-xs text-ink-60">Your orders, payments and anything that needs you.</p>
        </div>
        <button type="button" data-notification-close aria-label="Close updates"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-ink-60 hover:bg-forest-tint hover:text-forest">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </div>
      <div class="max-h-[70vh] overflow-y-auto px-4 py-4" data-notification-scroll>
        <p class="text-sm text-ink-60" data-notification-state role="status">Open updates to load the latest.</p>
        <section class="hidden" data-notification-attention aria-labelledby="okv-customer-attention-title">
          <h3 id="okv-customer-attention-title" class="text-sm font-semibold text-ink">Needs you</h3>
          <div class="mt-2 space-y-2" data-notification-attention-list></div>
        </section>
        <section class="mt-5 hidden" data-notification-recent aria-labelledby="okv-customer-recent-title">
          <h3 id="okv-customer-recent-title" class="text-sm font-semibold text-ink">Recent updates</h3>
          <div class="mt-2 divide-y divide-mist" data-notification-list></div>
        </section>
      </div>
      <div class="flex items-center justify-between gap-3 border-t border-mist px-4 py-3">
        <button type="button" class="okv-btn-text text-sm" data-notification-mark-all>Mark all as read</button>
        <a href="/account.php" class="okv-btn-text text-sm">View all in your account</a>
      </div>
    </section>
  </div>
  <p class="sr-only" aria-live="polite" data-notification-live></p>
</div>
<?php if (!defined('OKV_CUSTOMER_BELL_ASSETS')): ?>
  <?php define('OKV_CUSTOMER_BELL_ASSETS', true); ?>
  <script>window.OKV = window.OKV || {}; window.OKV.csrf = window.OKV.csrf || <?= json_encode(Csrf::token(), JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= okv_e(okv_asset('/assets/js/notifications.js')) ?>" defer></script>
<?php endif; ?>
<?php unset($okv_customer_id, $okv_customer_unread, $okv_customer_badge); ?>
