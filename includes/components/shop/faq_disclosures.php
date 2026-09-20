<?php
/** Native FAQ disclosures. Every answer remains available without JavaScript. */
if (!function_exists('okv_faq_disclosures')) {
    function okv_faq_disclosures(array $items, bool $showControls = true): void
    {
        if (!$items) {
            return;
        }
        ?>
        <?php if ($showControls): ?>
          <div class="mb-4 hidden flex-wrap items-center justify-between gap-3 border-b border-mist pb-4" data-faq-controls>
            <p class="text-sm text-ink-60" aria-live="polite" data-faq-status><?= count($items) ?> questions. The first answer is open.</p>
            <div class="flex flex-wrap gap-2">
              <button class="okv-btn-text min-h-[44px]" type="button" data-faq-expand>Expand all</button>
              <button class="okv-btn-text min-h-[44px]" type="button" data-faq-collapse>Collapse all</button>
            </div>
          </div>
        <?php endif; ?>
        <div class="divide-y divide-mist" data-faq-list>
          <?php foreach ($items as $index => $item):
              $answerId = 'faq-answer-' . (string) $item['anchor'];
          ?>
            <details class="group scroll-mt-24 py-2" id="<?= okv_e((string) $item['anchor']) ?>" data-faq-item <?= $index === 0 ? 'open' : '' ?>>
              <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-4 py-3 font-semibold text-ink marker:content-none" aria-controls="<?= okv_e($answerId) ?>">
                <span class="text-lg" role="heading" aria-level="2"><?= okv_e((string) $item['question']) ?></span>
                <span class="flex flex-none items-center gap-2 text-sm text-forest" aria-hidden="true">
                  <span class="group-open:hidden">Expand</span><span class="hidden group-open:inline">Close</span>
                  <svg class="h-4 w-4 transition-transform duration-botanical ease-botanical group-open:rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                </span>
              </summary>
              <div class="max-w-xl pb-6 pr-8 text-sm leading-6 text-ink-60" id="<?= okv_e($answerId) ?>" data-faq-answer><?= $item['answer_html'] ?></div>
            </details>
          <?php endforeach; ?>
        </div>
        <?php
    }
}
