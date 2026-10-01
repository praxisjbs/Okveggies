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
    /**
     * @param array<string,string> $copy the page's slots from ContentSlots::copy('how-it-works', ...)
     */
    function okv_how_it_works_steps(string $bodyHtml, array $copy): void
    {
        // The icons and washes are the design; every word and every destination is
        // a slot the Owner edits in Content and Messages.
        $steps = [
            ['id' => 'how-pick', 'icon' => 'basket',   'wash' => 'bg-foliage-tint'],
            ['id' => 'how-day',  'icon' => 'calendar', 'wash' => 'bg-gold-tint2'],
            ['id' => 'how-door', 'icon' => 'trail',    'wash' => 'bg-clay-tint'],
        ];
        ?>
        <ol class="mt-8 grid gap-4 sm:grid-cols-3">
          <?php foreach ($steps as $index => $step): $n = $index + 1; ?>
            <li class="okv-step-card okv-enter <?= $index === 1 ? 'okv-enter-2' : ($index === 2 ? 'okv-enter-3' : '') ?> hover:border-forest/40 hover:shadow-okv-1">
              <!--
                The whole card is one button. The title is a styled span, not
                an h2, because a button only carries phrasing content.
              -->
              <button type="button" class="block w-full text-left" data-sheet-open="<?= okv_e($step['id']) ?>" aria-haspopup="dialog">
                <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full <?= okv_e($step['wash']) ?> text-forest">
                  <?php okv_icon($step['icon'], 'h-12 w-12'); ?>
                </span>
                <span class="mt-4 block font-mono text-xs uppercase tracking-[0.16em] text-ink-40"><?= (int) $n ?></span>
                <span class="mt-1 block font-editorial text-okv-h6 text-ink"><?= okv_e($copy["step_{$n}_title"]) ?></span>
                <span class="mt-2 block text-sm text-ink-60"><?= okv_e($copy["step_{$n}_line"]) ?></span>
                <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-forest">
                  <?= okv_e($copy['tap_label']) ?> <span aria-hidden="true">&rarr;</span>
                </span>
              </button>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="mt-6">
          <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="how-body" aria-haspopup="dialog">
            <?php okv_icon('info', 'h-4 w-4'); ?> <?= okv_e($copy['learn_label']) ?>
          </button>
        </p>
        <?php
        // One detail sheet per step. Its words and its two buttons are slots, and a
        // policy figure in the words is a token ({{make_it_right_window}}), so the
        // sheet cannot drift from the setting.
        $sheetIcons = [1 => ['basket', ['user', 'leaf']], 2 => ['calendar', ['leaf', 'basket']], 3 => ['trail', ['shield', 'user']]];
        foreach ($steps as $index => $step) {
            $n = $index + 1;
            [$sheetIcon, $buttonIcons] = $sheetIcons[$n];
            okv_help_sheet(
                $step['id'],
                $sheetIcon,
                $copy["step_{$n}_title"],
                ContentSlots::sheetHtml($copy["sheet_{$n}_body"]),
                [
                    ['href' => $copy["sheet_{$n}_button_1_path"], 'label' => $copy["sheet_{$n}_button_1_label"], 'icon' => $buttonIcons[0]],
                    ['href' => $copy["sheet_{$n}_button_2_path"], 'label' => $copy["sheet_{$n}_button_2_label"], 'style' => 'outline', 'icon' => $buttonIcons[1]],
                ]
            );
        }
        ?>
        <div class="okv-sheet-backdrop" id="how-body" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="how-body-title" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <span class="text-forest"><?php okv_icon('trail', 'h-20 w-20'); ?></span>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <h2 id="how-body-title" class="mt-4 font-editorial text-okv-h5 text-ink"><?= okv_e($copy['body_sheet_title']) ?></h2>
            <div class="mt-3 max-w-prose text-sm leading-6 text-ink-60" data-content-body><?= $bodyHtml ?></div>
          </section>
        </div>
        <?php
    }
}
