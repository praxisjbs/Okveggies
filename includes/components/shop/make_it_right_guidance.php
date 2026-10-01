<?php
/**
 * The Make It Right panel on How It Works and Delivery Policy. One line on the
 * page, the rest in a Learn sheet. Its words are slots the Owner edits in the
 * Content module, and its figures (the reporting window, the photo limit) are
 * tokens in those words, so they stay aligned with the live settings.
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/help_sheet.php';

// The page that includes this panel has already loaded its slots into $copy.
// Every word here is a slot the Owner edits; the reporting window and the photo
// limit are tokens in those words, so they follow the settings.
$mirOrdersLabel = (string) $copy['mir_orders_label'];
$mirSigninLabel = (string) $copy['mir_signin_label'];
?>
<section class="mt-10 border-t border-mist pt-8" id="make-it-right" tabindex="-1" aria-labelledby="make-it-right-policy-heading">
  <p class="okv-eyebrow"><?= okv_e($copy['mir_eyebrow']) ?></p>
  <h2 id="make-it-right-policy-heading" class="mt-2 scroll-mt-24 font-editorial text-okv-h6 text-ink md:text-okv-h5"><?= okv_e($copy['mir_heading']) ?></h2>
  <p class="mt-3 text-ink-60"><?= okv_e($copy['mir_line']) ?></p>
  <div class="mt-4 flex flex-wrap gap-3">
    <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="make-it-right-sheet" aria-haspopup="dialog"><?php okv_icon('info', 'h-4 w-4'); ?> <?= okv_e($copy['mir_learn_label']) ?></button>
    <?php if (Customer::isLoggedIn()): ?>
      <a class="okv-btn-outline rounded-xl" href="/account.php"><?php okv_icon('user', 'h-4 w-4'); ?> <?= okv_e($mirOrdersLabel) ?></a>
    <?php else: ?>
      <!-- Sign in to report is a solid tomato button on purpose: it is the one
           moment the interface should look like a fire exit, not a footnote.
           The same fill as the hero CTA, which the brand allows. -->
      <a class="okv-btn rounded-xl border border-tomato bg-tomato px-6 text-white hover:bg-tomato-hover active:bg-tomato-active" href="/account.php?mode=signin"><?php okv_icon('user', 'h-4 w-4'); ?> <?= okv_e($mirSigninLabel) ?></a>
    <?php endif; ?>
  </div>
</section>
<?php okv_help_sheet('make-it-right-sheet', 'shield', $copy['mir_heading'], ContentSlots::sheetHtml($copy['mir_sheet_body']), [
    Customer::isLoggedIn()
        ? ['href' => '/account.php', 'label' => $mirOrdersLabel]
        : ['href' => '/account.php?mode=signin', 'label' => $mirSigninLabel, 'style' => 'danger'],
]); ?>
