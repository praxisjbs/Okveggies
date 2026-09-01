<?php
/**
 * includes/components/shop/shop_results.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The shop grid's results column: the heading with its count, the
 * grid of cards (or the empty state) and the pagination underneath. shop.php
 * renders it on a full page load and api/v1/catalog.php ("browse") renders the
 * very same markup when the live search swaps it in without a reload, so the
 * two can never drift. Add-to-basket return paths carry the live filters, so a
 * customer lands back on the page of results they were browsing.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_shop_url')) {
    /**
     * A shop URL for a search, a category and a page. Page 1 and empty filters
     * stay out of the query string, so the URLs read clean and share well.
     */
    function okv_shop_url(string $search, string $category = '', int $page = 1): string
    {
        $query = array_filter(
            ['search' => $search, 'category' => $category, 'page' => $page > 1 ? $page : null],
            static fn($value) => $value !== '' && $value !== null
        );
        return '/shop.php' . ($query ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('okv_shop_results')) {
    /**
     * Render the results column. $page must already be clamped against the
     * page count by the caller, which has to run the count first anyway.
     */
    function okv_shop_results(
        array $products,
        array $categories,
        string $sourceRegions,
        string $search,
        string $category,
        int $page,
        int $total,
        int $perPage
    ): void {
        $activeCategory = null;
        foreach ($categories as $candidate) {
            if (($candidate['slug'] ?? '') === $category) {
                $activeCategory = $candidate;
                break;
            }
        }
        $perPage = max(1, $perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $returnTo = okv_shop_url($search, $category, $page);
        ?>
        <div class="mb-6 hidden items-end justify-between gap-4 lg:flex">
          <div>
            <h2 class="font-display text-2xl font-bold text-ink"><?= okv_e($activeCategory['name'] ?? 'All produce') ?></h2>
            <?php if ($search !== ''): ?><p class="mt-1 text-sm text-ink-60">Results for &ldquo;<?= okv_e($search) ?>&rdquo;</p><?php endif; ?>
          </div>
          <p class="text-sm text-ink-60" data-shop-summary aria-live="polite"><?= okv_e(okv_page_summary($page, $total, $perPage, 'item')) ?></p>
        </div>

        <?php if ($products): ?>
          <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <?php foreach ($products as $product): ?>
              <?php okv_product_card($product, $sourceRegions, $returnTo); ?>
            <?php endforeach; ?>
          </div>
          <?php okv_pagination($page, $lastPage, static fn (int $n): string => okv_shop_url($search, $category, $n), 'Produce pages'); ?>
        <?php else: ?>
          <div class="rounded-lg bg-white px-6 py-16 text-center shadow-okv-1">
            <?php if ($search !== ''): ?>
              <h2 class="font-display text-2xl font-bold text-ink">Nothing matched that search</h2>
              <p class="mx-auto mt-3 max-w-md text-ink-60">Try another produce name<?= $category !== '' ? ', or clear the category to search everything available this week' : '' ?>.</p>
            <?php else: ?>
              <h2 class="font-display text-2xl font-bold text-ink">Nothing in <?= okv_e($activeCategory['name'] ?? 'this category') ?> this week</h2>
              <p class="mx-auto mt-3 max-w-md text-ink-60">We are still sourcing for this one. The rest of the week's produce is ready now.</p>
            <?php endif; ?>
            <a href="/shop.php" class="okv-btn mt-6">See all produce</a>
          </div>
        <?php endif; ?>
        <?php
    }
}
