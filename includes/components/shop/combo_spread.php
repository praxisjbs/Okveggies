<?php
/**
 * includes/components/shop/combo_spread.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The editorial spread for a combo basket (bible 4.2 and 6.2, PRD
 * 4.1). A combo is not a product line, it is a cooking occasion, so the first
 * baskets on /combos.php get a full-width spread: one large photograph, the
 * name in DM Serif Display, the contents in plain words with a link to every
 * component, the price with the saving against buying the pieces one by one,
 * and the single "Add full basket" action.
 *
 * The spread alternates sides down the page. Combos past the first two fall
 * into the card grid, so a Manager who publishes twelve baskets gets a page a
 * customer can still compare at a glance rather than a mile of scrolling.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/combo_card.php';

if (!function_exists('okv_combo_spread')) {
    /**
     * $components is passed in rather than looked up, so the page runs one
     * query per spread and never a query per render. $flip puts the photo on
     * the right, which is how the second spread reads against the first.
     */
    function okv_combo_spread(
        array $combo,
        array $components,
        string $returnTo,
        string $sourceRegions = '',
        string $sourceDay = '',
        bool $flip = false
    ): void {
        $comboId = (int) ($combo['id'] ?? 0);
        $name = (string) ($combo['name'] ?? '');
        $description = trim((string) ($combo['description'] ?? ''));
        $price = (int) ($combo['price_subunit'] ?? 0);
        $componentCount = (int) ($combo['component_count'] ?? count($components));
        $image = okv_combo_card_image($combo, $components);
        $componentTotal = array_key_exists('component_total_subunit', $combo)
            ? (int) $combo['component_total_subunit']
            : Combos::sumComponents($components);
        $saving = Combos::customerSaving($price, $componentTotal);
        $detailUrl = '/combo.php?slug=' . rawurlencode((string) ($combo['slug'] ?? ''));
        // Six lines is what fits before the list stops being a glance and
        // starts being a table. The rest are counted on the line under it.
        $preview = array_slice($components, 0, 6);
        $remaining = max(0, count($components) - count($preview));
        ?>
        <article class="overflow-hidden rounded-lg bg-white shadow-okv-1" data-combo-spread>
          <div class="grid gap-0 lg:grid-cols-2">
            <!--
              The photo links to the same place the name does. It is taken out
              of the tab order so a keyboard reaches the basket once, not
              twice, while the alt text still describes the photo for a screen
              reader, which the house rules require of every product photo.
            -->
            <a href="<?= okv_e($detailUrl) ?>"
               class="group block overflow-hidden bg-forest-tint <?= $flip ? 'lg:order-2' : '' ?>"
               tabindex="-1">
              <!--
                A 4:3 letterbox while the spread is stacked. Once the two
                columns sit side by side the photo takes its height from the
                text column instead, because holding the ratio there would
                compute a width wider than the column and spill the photo
                across the copy.
              -->
              <div class="aspect-[4/3] w-full overflow-hidden lg:aspect-auto lg:h-full">
                <?php if ($image !== ''): ?>
                  <img src="<?= okv_e(okv_image_url($image)) ?>"
                       alt="<?= okv_e($name) ?>, ready basket of <?= (int) $componentCount ?> items<?= $sourceRegions !== '' ? ', sourced from ' . okv_e($sourceRegions) : '' ?>"
                       class="h-full w-full object-cover transition duration-botanical ease-botanical group-hover:scale-105" loading="lazy">
                <?php else: ?>
                  <div class="flex h-full items-center justify-center p-6 text-center text-sm text-ink-40">Photo coming soon</div>
                <?php endif; ?>
              </div>
            </a>

            <div class="p-6 md:p-10">
              <p class="okv-eyebrow">Ready basket</p>
              <h2 class="mt-3 font-editorial text-okv-h5 text-ink md:text-okv-h4">
                <a href="<?= okv_e($detailUrl) ?>" class="transition-colors duration-botanical ease-botanical hover:text-forest"><?= okv_e($name) ?></a>
              </h2>
              <?php if ($description !== ''): ?>
                <p class="mt-4 max-w-prose text-okv-lead text-ink-60"><?= okv_e($description) ?></p>
              <?php endif; ?>

              <?php if ($preview): ?>
                <p class="mt-6 text-sm font-semibold text-ink">What is inside</p>
                <ul class="mt-2 flex flex-wrap gap-2">
                  <?php foreach ($preview as $line): ?>
                    <li>
                      <a href="/product.php?slug=<?= okv_e((string) $line['product_slug']) ?>"
                         class="inline-flex min-h-[44px] items-center rounded-full border border-mist bg-white px-4 text-sm text-ink-60 transition duration-botanical ease-botanical hover:border-forest hover:text-forest">
                        <span class="font-mono"><?= okv_e(okv_quantity($line['quantity'])) ?><?= okv_e((string) $line['unit']) ?></span>
                        <span class="ml-2"><?= okv_e((string) $line['product_name']) ?></span>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($remaining > 0): ?>
                  <p class="mt-2 text-sm text-ink-60">and <?= $remaining ?> more <?= $remaining === 1 ? 'item' : 'items' ?> in the basket.</p>
                <?php endif; ?>
              <?php endif; ?>

              <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-6'); ?>

              <div class="mt-6 flex flex-wrap items-baseline gap-3">
                <p class="font-mono text-okv-h6 font-semibold text-forest"><?= okv_e(Money::format($price)) ?></p>
                <?php if ($saving > 0): ?>
                  <p class="font-mono text-ink-40 line-through" aria-label="Component total"><?= okv_e(Money::format($componentTotal)) ?></p>
                  <p class="okv-badge bg-foliage-tint text-forest">You save <?= okv_e(Money::format($saving)) ?></p>
                <?php endif; ?>
              </div>

              <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                <form method="post" action="/api/v1/cart.php" class="sm:flex-1" data-add-form>
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="add_combo">
                  <input type="hidden" name="combo_id" value="<?= (int) $comboId ?>">
                  <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
                  <button type="submit" class="okv-btn w-full" data-add-button>Add full basket</button>
                </form>
                <a href="<?= okv_e($detailUrl) ?>" class="okv-btn-outline sm:flex-1">
                  See the <?= (int) $componentCount ?> <?= $componentCount === 1 ? 'item' : 'items' ?>
                </a>
              </div>
            </div>
          </div>
        </article>
        <?php
    }
}
