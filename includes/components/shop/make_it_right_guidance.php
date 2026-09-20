<?php
/**
 * Application-owned operational guidance, kept aligned with live settings.
 * One line on the page. The rest lives in a Learn sheet.
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/help_sheet.php';

$windowDays = (int) IssueReports::reportingWindowDays();
$maxPhotos = (int) IssueReports::MAX_PHOTOS;
$sheetBody = '<p>After an order is dispatched or delivered, the signed-in customer can report a wrong item, missing item, quality problem, short quantity, damage or late delivery from that order.</p>'
    . '<p class="mt-3">Send the report within ' . $windowDays . ' days. Add a clear description and up to ' . $maxPhotos . ' optional photos. Our team reviews it and records a refund, account credit, replacement or a plain reason if the report cannot be approved.</p>'
    . '<p class="mt-3">The report and its outcome stay on your signed-in order. They are never shown on the shareable Order Trail.</p>';
?>
<section class="mt-10 border-t border-mist pt-8" id="make-it-right" tabindex="-1" aria-labelledby="make-it-right-policy-heading">
  <p class="okv-eyebrow">Make It Right</p>
  <h2 id="make-it-right-policy-heading" class="mt-2 scroll-mt-24 font-editorial text-okv-h6 text-ink md:text-okv-h5">If something is not right</h2>
  <p class="mt-3 text-ink-60">Report it within <?= $windowDays ?> days from your order.</p>
  <div class="mt-4 flex flex-wrap gap-3">
    <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="make-it-right-sheet" aria-haspopup="dialog"><?php okv_icon('info', 'h-4 w-4'); ?> Learn</button>
    <?php if (Customer::isLoggedIn()): ?>
      <a class="okv-btn-outline rounded-xl" href="/account.php"><?php okv_icon('user', 'h-4 w-4'); ?> Open your orders</a>
    <?php else: ?>
      <a class="okv-btn-outline rounded-xl" href="/account.php?mode=signin"><?php okv_icon('user', 'h-4 w-4'); ?> Sign in to report</a>
    <?php endif; ?>
  </div>
</section>
<?php okv_help_sheet('make-it-right-sheet', 'shield', 'If something is not right', $sheetBody, [
    Customer::isLoggedIn()
        ? ['href' => '/account.php', 'label' => 'Open your orders']
        : ['href' => '/account.php?mode=signin', 'label' => 'Sign in to report'],
]); ?>
