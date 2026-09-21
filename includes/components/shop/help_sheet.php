<?php
/**
 * includes/components/shop/help_sheet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Long help lives here, not in a paragraph on the page. An 80px
 * illustration, a short heading, the body, and optional actions. Opened by
 * data-sheet-open="id" and closed by Close, the backdrop, Escape or a swipe.
 */
require_once __DIR__ . '/icons.php';

if (!function_exists('okv_help_sheet')) {
    /**
     * @param list<array{href:string,label:string,style?:string}> $actions
     */
    function okv_help_sheet(string $id, string $icon, string $heading, string $bodyHtml, array $actions = []): void
    {
        $titleId = 'okv-sheet-' . $id . '-title';
        ?>
        <div class="okv-sheet-backdrop" id="<?= okv_e($id) ?>" hidden>
          <section class="okv-sheet p-5" role="dialog" aria-modal="true" aria-labelledby="<?= okv_e($titleId) ?>" tabindex="-1" data-sheet-panel>
            <div class="flex items-start justify-between gap-4">
              <span class="text-forest"><?php okv_icon($icon, 'h-20 w-20'); ?></span>
              <button type="button" class="okv-btn-text min-h-[44px] px-2" data-sheet-close>Close</button>
            </div>
            <h2 id="<?= okv_e($titleId) ?>" class="mt-4 font-editorial text-okv-h5 text-ink"><?= okv_e($heading) ?></h2>
            <div class="mt-3 max-w-prose text-sm leading-6 text-ink-60"><?= $bodyHtml ?></div>
            <?php if ($actions): ?>
              <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                <?php foreach ($actions as $action):
                    // 'danger' is the tomato fill, for the one action the
                    // reader has to see: the sign-in that unlocks reporting.
                    $btn = match ($action['style'] ?? 'primary') {
                        'outline' => 'okv-btn-outline',
                        'danger'  => 'okv-btn border border-tomato bg-tomato text-white hover:bg-tomato-hover active:bg-tomato-active',
                        default   => 'okv-btn',
                    };
                ?>
                  <a href="<?= okv_e((string) $action['href']) ?>" class="<?= $btn ?> w-full justify-center rounded-xl sm:w-auto">
                    <?php if (!empty($action['icon'])): ?><?php okv_icon((string) $action['icon'], 'h-4 w-4'); ?><?php endif; ?>
                    <?= okv_e((string) $action['label']) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>
        <?php
    }
}
