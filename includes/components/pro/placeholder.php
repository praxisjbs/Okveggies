<?php
/**
 * includes/components/pro/placeholder.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Shared screen shell for Pro Portal work that is not active yet.
 *
 * A business customer reads this, not staff. It names what belongs on the
 * screen, states what is available now, and links to working parts of the site.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_pro_screen_shell')) {
    /** @param array<int, array{label: string, href: string, primary?: bool}> $actions */
    function okv_pro_screen_shell(string $heading, string $summary, string $availability, array $actions): void
    {
        ?>
        <section class="okv-panel max-w-3xl" aria-labelledby="pro-screen-heading">
          <div class="okv-panel-body">
            <p class="okv-eyebrow">This screen</p>
            <h2 id="pro-screen-heading" class="okv-panel-title mt-1"><?= okv_e($heading) ?></h2>
            <p class="mt-3 text-ink"><?= okv_e($summary) ?></p>
            <p class="mt-3 text-sm text-ink-60" role="status"><?= okv_e($availability) ?></p>
            <div class="mt-6 flex flex-wrap gap-2">
              <?php foreach ($actions as $action): ?>
                <a href="<?= okv_e($action['href']) ?>" class="<?= !empty($action['primary']) ? 'okv-btn' : 'okv-btn-outline' ?> px-5">
                  <?= okv_e($action['label']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
        <?php
    }
}
