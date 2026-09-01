<?php
/**
 * includes/components/pagination.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The page switcher under a listing: Previous, a window of page
 * numbers, Next. Nothing renders for a single page. Every link keeps the
 * current filters, so a search or a category stays put while pages turn.
 * Links are plain GET anchors, so pagination works without JavaScript; the
 * live search hijacks them via the data-pagination hook when it is around.
 *
 *   okv_pagination($page, $lastPage, static fn (int $n): string => $url, 'Pages');
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_pagination')) {
    /** Render the switcher. $urlFor maps a page number to its URL. */
    function okv_pagination(int $page, int $lastPage, callable $urlFor, string $label = 'Pagination'): void
    {
        $window = okv_page_window($page, $lastPage);
        if (count($window) < 2) {
            return;
        }
        $page = min(max(1, $page), $lastPage);
        ?>
        <nav class="mt-6 flex flex-wrap items-center justify-center gap-2" aria-label="<?= okv_e($label) ?>" data-pagination>
          <?php if ($page > 1): ?>
            <a href="<?= okv_e($urlFor($page - 1)) ?>" rel="prev" class="okv-filter-chip">&larr; Previous</a>
          <?php else: ?>
            <span class="okv-filter-chip text-ink-40" aria-disabled="true">&larr; Previous</span>
          <?php endif; ?>

          <?php foreach ($window as $item): ?>
            <?php if ($item === '…'): ?>
              <span class="px-2 text-ink-40" aria-hidden="true">&hellip;</span>
            <?php elseif ($item === $page): ?>
              <span class="okv-filter-chip okv-filter-chip-active" aria-current="page"><?= (int) $item ?></span>
            <?php else: ?>
              <a href="<?= okv_e($urlFor($item)) ?>" class="okv-filter-chip" aria-label="Page <?= (int) $item ?>"><?= (int) $item ?></a>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($page < $lastPage): ?>
            <a href="<?= okv_e($urlFor($page + 1)) ?>" rel="next" class="okv-filter-chip">Next &rarr;</a>
          <?php else: ?>
            <span class="okv-filter-chip text-ink-40" aria-disabled="true">Next &rarr;</span>
          <?php endif; ?>
        </nav>
        <?php
    }
}
