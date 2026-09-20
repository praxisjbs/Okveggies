<?php
/**
 * includes/components/shop/how_it_works_steps.php
 * -----------------------------------------------------------------------------
 * OK Veggies. How It Works as three visual steps, not a paragraph wall. Each
 * card is an 80px icon, a five-word heading and one line. The CMS body stays
 * in a Learn sheet so the published copy is still there for a reader who
 * wants it, and for the content tests that look for data-content-body.
 */
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/help_sheet.php';

if (!function_exists('okv_how_it_works_steps')) {
    function okv_how_it_works_steps(string $bodyHtml): void
    {
        $steps = [
            [
                'icon' => 'basket',
                'wash' => 'bg-foliage-tint',
                'title' => 'Pick what you need',
                'line' => 'Shop produce, a combo, or send a list.',
            ],
            [
                'icon' => 'calendar',
                'wash' => 'bg-gold-tint2',
                'title' => 'Choose a delivery day',
                'line' => 'We source that morning at the market.',
            ],
            [
                'icon' => 'trail',
                'wash' => 'bg-clay-tint',
                'title' => 'We bring it over',
                'line' => 'Weighed, packed, at your door.',
            ],
        ];
        ?>
        <ol class="mt-8 grid gap-4 sm:grid-cols-3">
          <?php foreach ($steps as $index => $step): ?>
            <li class="okv-step-card okv-enter <?= $index === 1 ? 'okv-enter-2' : ($index === 2 ? 'okv-enter-3' : '') ?>">
              <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full <?= okv_e($step['wash']) ?> text-forest">
                <?php okv_icon($step['icon'], 'h-12 w-12'); ?>
              </span>
              <p class="mt-4 font-mono text-xs uppercase tracking-[0.16em] text-ink-40"><?= (int) ($index + 1) ?></p>
              <h2 class="mt-1 font-editorial text-okv-h6 text-ink"><?= okv_e($step['title']) ?></h2>
              <p class="mt-2 text-sm text-ink-60"><?= okv_e($step['line']) ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
        <p class="mt-6">
          <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="how-body" aria-haspopup="dialog">
            <?php okv_icon('info', 'h-4 w-4'); ?> Learn
          </button>
        </p>
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
