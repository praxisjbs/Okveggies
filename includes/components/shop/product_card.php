<?php
/** Product card used by search results and related-product rows. */
if (!function_exists('okv_product_card')) {
    function okv_product_card(array $product, string $sourceRegions, string $returnTo): void
    {
        $availability = okv_availability((string) ($product['availability_status'] ?? 'available'), $product['restock_date'] ?? null);
        $unit = (string) ($product['unit'] ?? '');
        ?>
        <article class="okv-card group flex h-full flex-col" data-product-card>
          <a href="/product.php?slug=<?= okv_e($product['slug']) ?>" class="block">
            <div class="aspect-square overflow-hidden rounded-md bg-forest-tint">
              <?php if (!empty($product['image'])): ?>
                <img src="<?= okv_e(okv_image_url($product['image'])) ?>"
                     alt="<?= okv_e($product['name']) ?>, <?= okv_e($unit) ?>, sourced from <?= okv_e($sourceRegions) ?>"
                     class="h-full w-full object-cover transition duration-botanical ease-botanical group-hover:scale-105" loading="lazy">
              <?php else: ?>
                <div class="flex h-full items-center justify-center p-4 text-center text-sm text-ink-40">Photo coming soon</div>
              <?php endif; ?>
            </div>
            <div class="mt-3 flex items-start justify-between gap-2">
              <div class="min-w-0">
                <h3 class="font-display font-bold leading-tight text-ink group-hover:text-forest"><?= okv_e($product['name']) ?></h3>
                <p class="mt-1 text-sm text-ink-60">per <?= okv_e($unit) ?></p>
              </div>
              <span class="okv-badge <?= $availability['key'] === 'available' ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= okv_e($availability['short_label']) ?></span>
            </div>
            <?php if ($availability['note'] !== ''): ?>
              <p class="mt-2 text-sm text-ink-60"><?= okv_e($availability['note']) ?></p>
            <?php endif; ?>
          </a>
          <?php if (!empty($product['short_description'])): ?>
            <p class="mt-2 line-clamp-2 text-sm text-ink-60"><?= okv_e($product['short_description']) ?></p>
          <?php endif; ?>
          <div class="mt-auto flex items-end justify-between gap-2 pt-4">
            <p class="font-mono font-semibold text-forest"><?= okv_e(Money::format((int) $product['current_price_subunit'])) ?></p>
            <form method="post" action="/api/v1/cart.php" data-add-form>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="add_product">
              <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
              <button type="submit" class="okv-btn min-w-[72px] px-4" <?= $availability['can_add'] ? '' : 'disabled' ?> data-add-button><?= $availability['can_add'] ? 'Add' : 'Waiting' ?></button>
            </form>
          </div>
        </article>
        <?php
    }
}
