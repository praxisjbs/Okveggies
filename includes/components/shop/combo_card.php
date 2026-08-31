<?php
/** Ready-made combo card for the home page and Combos page. */
if (!function_exists('okv_combo_card')) {
    function okv_combo_card(array $combo, string $returnTo): void
    {
        ?>
        <article class="okv-card group flex h-full flex-col">
          <a href="/combo.php?slug=<?= okv_e($combo['slug']) ?>" class="block">
            <div class="aspect-[4/3] overflow-hidden rounded-md bg-forest-tint">
              <?php if (!empty($combo['image_url'])): ?>
                <img src="<?= okv_e(okv_image_url($combo['image_url'])) ?>" alt="<?= okv_e($combo['name']) ?>" class="h-full w-full object-cover transition duration-botanical ease-botanical group-hover:scale-105" loading="lazy">
              <?php else: ?>
                <div class="flex h-full items-center justify-center p-4 text-center text-sm text-ink-40">Photo coming soon</div>
              <?php endif; ?>
            </div>
            <div class="mt-3 flex items-start justify-between gap-3">
              <h2 class="font-display font-bold leading-tight text-ink group-hover:text-forest"><?= okv_e($combo['name']) ?></h2>
              <?php if (!empty($combo['is_featured'])): ?><span class="okv-badge okv-badge-available">This week</span><?php endif; ?>
            </div>
            <?php if (!empty($combo['description'])): ?><p class="mt-2 line-clamp-2 text-sm text-ink-60"><?= okv_e($combo['description']) ?></p><?php endif; ?>
          </a>
          <div class="mt-auto flex items-end justify-between gap-3 pt-4">
            <p class="font-mono font-semibold text-forest"><?= okv_e(Money::format((int) $combo['price_subunit'])) ?></p>
            <form method="post" action="/api/v1/cart.php" data-add-form>
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="add_combo">
              <input type="hidden" name="combo_id" value="<?= (int) $combo['id'] ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($returnTo) ?>">
              <button type="submit" class="okv-btn min-w-[72px] px-4" data-add-button>Add</button>
            </form>
          </div>
        </article>
        <?php
    }
}
