<?php
/** Application-owned operational guidance, kept aligned with live settings. */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
?>
<section class="mt-10 border-t border-mist pt-8" id="make-it-right" tabindex="-1" aria-labelledby="make-it-right-policy-heading">
  <p class="okv-eyebrow">Make It Right</p>
  <h2 id="make-it-right-policy-heading" class="mt-2 scroll-mt-24 font-editorial text-okv-h6 text-ink md:text-okv-h5">If something is not right</h2>
  <p class="mt-4 leading-7 text-ink-60">After an order is dispatched or delivered, the signed-in customer can report a wrong item, missing item, quality problem, short quantity, damage or late delivery from that order.</p>
  <p class="mt-4 leading-7 text-ink-60">Send the report within <?= (int) IssueReports::reportingWindowDays() ?> days. Add a clear description and up to <?= (int) IssueReports::MAX_PHOTOS ?> optional photos. Our team reviews it and records a refund, account credit, replacement or a plain reason if the report cannot be approved.</p>
  <p class="mt-4 leading-7 text-ink-60">The report and its outcome stay on your signed-in order. They are never shown on the shareable Order Trail.</p>
  <?php if (Customer::isLoggedIn()): ?>
    <a class="okv-btn-outline mt-6" href="/account.php">Open your orders</a>
  <?php else: ?>
    <a class="okv-btn-outline mt-6" href="/account.php?mode=signin">Sign in to open an order</a>
  <?php endif; ?>
</section>
