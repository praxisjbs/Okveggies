<?php
/**
 * includes/components/shop/how_it_works_steps.php
 * -----------------------------------------------------------------------------
 * OK Veggies. How It Works as three visual steps, not a paragraph wall. Each
 * card is an 80px icon, a five-word heading and one line. The CMS body stays
 * in a Learn sheet so the published copy is still there for a reader who
 * wants it, and for the content tests that look for data-content-body.
 *
 * Each card is also a button: it opens the detail sheet for that step, which
 * carries the part of the journey the one-liner cannot fit: creating an
 * account in step one, checkout and payment in step two, weighing, proof and
 * Make It Right in step three.
 */
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/help_sheet.php';

if (!function_exists('okv_how_it_works_steps')) {
    function okv_how_it_works_steps(string $bodyHtml): void
    {
        $windowDays = (int) IssueReports::reportingWindowDays();
        $steps = [
            [
                'id'   => 'how-pick',
                'icon' => 'basket',
                'wash' => 'bg-foliage-tint',
                'title' => 'Pick what you need',
                'line' => 'Shop produce, a combo, or send a list.',
            ],
            [
                'id'   => 'how-day',
                'icon' => 'calendar',
                'wash' => 'bg-gold-tint2',
                'title' => 'Choose a delivery day',
                'line' => 'We source that morning at the market.',
            ],
            [
                'id'   => 'how-door',
                'icon' => 'trail',
                'wash' => 'bg-clay-tint',
                'title' => 'We bring it over',
                'line' => 'Weighed, packed, at your door.',
            ],
        ];
        ?>
        <ol class="mt-8 grid gap-4 sm:grid-cols-3">
          <?php foreach ($steps as $index => $step): ?>
            <li class="okv-step-card okv-enter <?= $index === 1 ? 'okv-enter-2' : ($index === 2 ? 'okv-enter-3' : '') ?> hover:border-forest/40 hover:shadow-okv-1">
              <!--
                The whole card is one button. The title is a styled span, not
                an h2, because a button only carries phrasing content.
              -->
              <button type="button" class="block w-full text-left" data-sheet-open="<?= okv_e($step['id']) ?>" aria-haspopup="dialog">
                <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full <?= okv_e($step['wash']) ?> text-forest">
                  <?php okv_icon($step['icon'], 'h-12 w-12'); ?>
                </span>
                <span class="mt-4 block font-mono text-xs uppercase tracking-[0.16em] text-ink-40"><?= (int) ($index + 1) ?></span>
                <span class="mt-1 block font-editorial text-okv-h6 text-ink"><?= okv_e($step['title']) ?></span>
                <span class="mt-2 block text-sm text-ink-60"><?= okv_e($step['line']) ?></span>
                <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-forest">
                  Tap for details <span aria-hidden="true">&rarr;</span>
                </span>
              </button>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="mt-6">
          <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="how-body" aria-haspopup="dialog">
            <?php okv_icon('info', 'h-4 w-4'); ?> Learn
          </button>
        </p>
        <?php
        // One detail sheet per step. The copy is application-owned, like the
        // Make It Right guidance, and the window figure comes from settings so
        // the sheet cannot drift from the policy.
        okv_help_sheet('how-pick', 'basket', 'Pick what you need',
            '<p>Three ways in, all the same basket. Shop the aisles and add what you see. Take a combo, a ready basket priced below buying it piece by piece. Or send a Kitchen Run list and let us source it for you.</p>'
            . '<p class="mt-3">New to OK Veggies? Create an account first: your name, your phone number and your email. We send a code to the phone and the account is active when you enter it. Once you are signed in the basket remembers you, so you can start it on the phone and finish it at the desk.</p>',
            [
                ['href' => '/account.php?mode=register', 'label' => 'Create an account', 'icon' => 'user'],
                ['href' => '/shop.php', 'label' => 'Start shopping', 'style' => 'outline', 'icon' => 'leaf'],
            ]);
        okv_help_sheet('how-day', 'calendar', 'Choose a delivery day',
            '<p>Every household order picks a delivery day from the days we run. We source that morning at the market, so what you ordered is what is actually on the stall that week.</p>'
            . '<p class="mt-3">At checkout you confirm the basket and choose how to pay: card, bank transfer, USSD on a feature phone, or business credit if your Pro account has an approved facility. The balance, the day and the address all sit on the order before you pay, so there is nothing to remember after.</p>'
            . '<p class="mt-3">You can cancel before sourcing begins. After that, the cancellation cost for your order applies, and it is always shown to you before you pay, never after.</p>',
            [
                ['href' => '/shop.php', 'label' => 'Start shopping', 'icon' => 'leaf'],
                ['href' => '/combos.php', 'label' => 'See the combos', 'style' => 'outline', 'icon' => 'basket'],
            ]);
        okv_help_sheet('how-door', 'trail', 'We bring it over',
            '<p>On your day we weigh everything at the source, pack it, and bring it to your door. Your order trail carries a photograph of the weighed produce, so what the van brings is what the scale showed.</p>'
            . '<p class="mt-3">Weighed light, damaged, missing or late? Report it within ' . $windowDays . ' days from your order with a description and up to ' . (int) IssueReports::MAX_PHOTOS . ' photos. We record a refund, a credit, a replacement, or a reason if we cannot approve it. The report stays on your signed-in order, never on the shareable trail.</p>',
            [
                ['href' => '#make-it-right', 'label' => 'Read Make It Right', 'icon' => 'shield'],
                ['href' => '/account.php', 'label' => 'Open your orders', 'style' => 'outline', 'icon' => 'user'],
            ]);
        ?>
        <div class="okv-sheet-backdrop" id="how-body" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="how-body-title" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <span class="text-forest"><?php okv_icon('trail', 'h-20 w-20'); ?></span>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <h2 id="how-body-title" class="mt-4 font-editorial text-okv-h5 text-ink">How a delivery works</h2>
            <div class="mt-3 max-w-prose text-sm leading-6 text-ink-60" data-content-body><?= $bodyHtml ?></div>
          </section>
        </div>
        <?php
    }
}
