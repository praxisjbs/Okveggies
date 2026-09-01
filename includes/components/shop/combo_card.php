<?php
/**
 * includes/components/shop/combo_card.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The combo card used by the combos grid and the home page strip.
 * The card mirrors product_card.php: a link on the photo, name and description
 * area, and a separate Add form at the bottom. The card image falls back to the
 * first component's primary photo when the combo has no image_url of its own
 * (M3 decision Q6). The saving line shows the strike-through component total
 * and a "You save" label only when the sell price is below the components,
 * which is what Combos::customerSaving returns for us.
 *
 * Combo names are set in DM Serif Display through font-editorial: bible 5.1
 * gives the serif combo-pack names, pull quotes and section titles, and the
 * combo is where a basket earns an editorial voice rather than a product line.
 * The full editorial spread for the first combos on /combos.php lives in
 * combo_spread.php; this is the card the rest of them fall into.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/brand.php';

if (!function_exists('okv_combo_card')) {
    function okv_combo_card(array $combo, string $returnTo, string $sourceRegions = '', string $sourceDay = ''): void
    {
        $comboId = (int) ($combo['id'] ?? 0);
        $slug = (string) ($combo['slug'] ?? '');
        $name = (string) ($combo['name'] ?? '');
        $description = trim((string) ($combo['description'] ?? ''));
        $price = (int) ($combo['price_subunit'] ?? 0);
        $componentCount = (int) ($combo['component_count'] ?? 0);

        // Prefer the values Catalogue::combos and comboBySlug precompute in
        // one SQL round-trip. Fall back to a per-card lookup only when the
        // caller passed a row without them, so the card stays usable from a
        // caller that has not been updated yet.
        $hasTotal = array_key_exists('component_total_subunit', $combo);
        $hasFallback = array_key_exists('fallback_image', $combo);
        if ($hasTotal && $hasFallback) {
            $componentTotal = (int) $combo['component_total_subunit'];
            $image = okv_combo_card_image($combo, [
                ['image' => (string) ($combo['fallback_image'] ?? '')],
            ]);
        } else {
            $components = Catalogue::comboComponents($comboId);
            $image = okv_combo_card_image($combo, $components);
            $componentTotal = Combos::sumComponents($components);
        }
        $saving = Combos::customerSaving($price, $componentTotal);
        $detailUrl = '/combo.php?slug=' . rawurlencode($slug);
        ?>
        <article class="okv-card group flex h-full flex-col" data-combo-card>
          <a href="<?= okv_e($detailUrl) ?>" class="block">
            <div class="aspect-[4/3] overflow-hidden rounded-md bg-forest-tint">
              <?php if ($image !== ''): ?>
                <img src="<?= okv_e(okv_image_url($image)) ?>"
                     alt="<?= okv_e($name) ?>, ready basket of <?= (int) $componentCount ?> items<?= $sourceRegions !== '' ? ', sourced from ' . okv_e($sourceRegions) : '' ?>"
                     class="h-full w-full object-cover transition duration-botanical ease-botanical group-hover:scale-105" loading="lazy">
              <?php else: ?>
                <div class="flex h-full items-center justify-center p-4 text-center text-sm text-ink-40">Photo coming soon</div>
              <?php endif; ?>
            </div>
            <div class="mt-4 flex items-start justify-between gap-2">
              <div class="min-w-0">
                <h3 class="font-editorial text-okv-h6 leading-tight text-ink transition-colors duration-botanical ease-botanical group-hover:text-forest"><?= okv_e($name) ?></h3>
                <p class="mt-1 text-sm text-ink-60"><?= (int) $componentCount ?> <?= $componentCount === 1 ? 'item inside' : 'items inside' ?></p>
              </div>
              <span class="okv-badge okv-badge-available">Ready basket</span>
            </div>
            <?php if ($description !== ''): ?>
              <p class="mt-2 line-clamp-2 text-sm text-ink-60"><?= okv_e($description) ?></p>
            <?php endif; ?>
          </a>
          <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-2 text-xs text-ink-60'); ?>
          <div class="mt-auto pt-4">
            <?php if ($saving > 0): ?>
              <div class="flex flex-wrap items-baseline gap-2">
                <p class="font-mono text-okv-lead font-semibold text-forest"><?= okv_e(Money::format($price)) ?></p>
                <p class="font-mono text-sm text-ink-40 line-through" aria-label="Component total"><?= okv_e(Money::format($componentTotal)) ?></p>
              </div>
              <p class="okv-badge okv-badge-available mt-1">You save <?= okv_e(Money::format($saving)) ?></p>
            <?php else: ?>
              <p class="font-mono text-okv-lead font-semibold text-forest"><?= okv_e(Money::format($price)) ?></p>
            <?php endif; ?>
            <form method="post" action="/api/v1/cart.php" class="mt-3" data-add-form>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="add_combo">
              <input type="hidden" name="combo_id" value="<?= (int) $comboId ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
              <button type="submit" class="okv-btn w-full" data-add-button>Add full basket</button>
            </form>
          </div>
        </article>
        <?php
    }
}

if (!function_exists('okv_combo_card_image')) {
    /**
     * The photo the card and the detail page show. Uses the combo's own
     * image_url when set, else falls back to the primary photo of the first
     * component in Catalogue::comboComponents order (which orders by
     * combo_package_items.id ascending, so "first" is the row the Manager
     * added first in the builder). Returns an empty string when neither is
     * available; the template then shows "Photo coming soon".
     */
    function okv_combo_card_image(array $combo, array $components): string
    {
        $own = trim((string) ($combo['image_url'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        foreach ($components as $component) {
            $fallback = trim((string) ($component['image'] ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }
        return '';
    }
}
