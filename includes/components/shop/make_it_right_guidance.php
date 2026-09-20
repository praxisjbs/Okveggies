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
$sheetBody = '<p>After dispatch or delivery, report a wrong, missing, damaged or late item from that order.</p>'
    . '<p class="mt-3">Send it within ' . $windowDays . ' days. Add a description and up to ' . $maxPhotos . ' photos.</p>'
    . '<p class="mt-3">We record a refund, credit, replacement, or a reason if we cannot approve it.</p>'
    . '<p class="mt-3">The report stays on your signed-in order. It is never on the shareable trail.</p>';
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
