<?php
/**
 * includes/components/shop/footer.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The storefront footer, and one of the two places on the site
 * where the full photographic seal has room to read (CLAUDE.md, bible 3.3).
 * Forest ground with the white horizontal lockup, the tagline, the sourcing
 * line, the content and legal columns, then the seal as the closing stamp.
 *
 * White on forest is 9.36:1. Harvest Gold on forest is 3.40:1, which fails at
 * body-text size, so a link never turns gold: it stays white and gold does the
 * work it is allowed to do, as the underline accent (bible 3.10).
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/support_widget.php';
require_once __DIR__ . '/../../config/nav.php';

if (!function_exists('okv_shop_footer')) {
    function okv_shop_footer(?array $contentNavigation = null): void
    {
        $name = Settings::str('business_name', 'OK Veggies');
        $tagline = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
        $sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
        $sourceDay = Settings::str('source_day', '');
        global $OKV_FOOTER_NAV;
        if ($contentNavigation === null) {
            try {
                $readySlugs = [];
                foreach ($OKV_FOOTER_NAV as $item) {
                    if (!empty($item['slug']) && !empty($item['public_ready'])) {
                        $readySlugs[] = (string) $item['slug'];
                    }
                }
                $contentNavigation = ContentPages::publishedNavigation($readySlugs);
            } catch (Throwable $e) {
                error_log('content.footer_navigation failed: ' . $e->getMessage());
                $contentNavigation = [];
            }
        }
        $publishedSlugs = array_fill_keys(array_column($contentNavigation, 'slug'), true);
        $company = [];
        $legal = [];
        foreach ($OKV_FOOTER_NAV as $item) {
            if (isset($item['slug']) && !isset($publishedSlugs[(string) $item['slug']])) {
                continue;
            }
            $link = [(string) $item['href'], (string) $item['label']];
            if (($item['group'] ?? '') === 'Legal') {
                $legal[] = $link;
            } else {
                $company[] = $link;
            }
        }
        $columns = [
            'Company' => $company,
            'Shop' => [
                ['/shop.php', 'All produce'],
                ['/combos.php', 'Combos'],
                ['/kitchen-runs.php', 'Kitchen Runs'],
            ],
            'Legal' => $legal,
        ];
        ?>
        <footer class="mb-14 bg-forest text-white md:mb-0">
          <div class="okv-container grid gap-10 py-12 md:grid-cols-12 md:gap-8 md:py-16">
            <div class="md:col-span-5">
              <img src="<?= okv_e(okv_asset('/assets/img/brand/lockup-white.svg')) ?>"
                   alt="<?= okv_e($name) ?>, Fresh Picks" width="229" height="60" class="h-14 w-auto">
              <p class="mt-5 max-w-sm text-okv-body text-white/75"><?= okv_e($tagline) ?></p>
              <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-4 text-white/75'); ?>
            </div>

            <?php foreach ($columns as $heading => $items): if (!$items) { continue; } ?>
              <nav class="md:col-span-2" aria-label="<?= okv_e($heading) ?>">
                <p class="okv-eyebrow-invert"><?= okv_e($heading) ?></p>
                <ul class="mt-2">
                  <?php foreach ($items as [$url, $label]): ?>
                    <li>
                      <a href="<?= okv_e($url) ?>"
                         class="inline-flex min-h-[44px] items-center text-sm text-white/75 underline-offset-4 transition duration-botanical ease-botanical hover:text-white hover:underline hover:decoration-gold hover:decoration-2">
                        <?= okv_e($label) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </nav>
            <?php endforeach; ?>
          </div>

          <div class="border-t border-white/15">
            <div class="okv-container flex flex-col items-center gap-6 py-10 text-center sm:flex-row sm:justify-between sm:text-left">
              <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-left">
                <?php okv_seal(120, 'flex-none', ''); ?>
                <div>
                  <p class="font-editorial text-okv-h6 text-white">Weighed right, every time.</p>
                  <p class="mt-1 text-sm text-white/70">Farms we have visited. Prices we can explain. A day you picked.</p>
                </div>
              </div>
              <p class="text-xs text-white/60">&copy; <?= date('Y') ?> <?= okv_e($name) ?>. Powered by JBS Praxis.</p>
            </div>
          </div>
        </footer>
        <?php
        // The mini-cart drawer is shared chrome rendered by the header on every
        // storefront page, so its behaviour loads here, once, alongside it.
        // Without JavaScript the header basket control stays a real link to
        // /cart.php, so nothing here is required for the basket to work.
        ?>
        <script src="<?= okv_e(okv_asset('/assets/js/basket.min.js')) ?>" defer></script>
        <?php okv_support_widget(); ?>
        <?php
    }
}
