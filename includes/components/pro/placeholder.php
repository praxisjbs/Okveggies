<?php
/**
 * includes/components/pro/placeholder.php
 * -----------------------------------------------------------------------------
 * OK Veggies. What a Pro Portal screen shows before its milestone is built.
 *
 * A business customer reads this, not staff, so it never mentions a milestone
 * number or a document. It says plainly what the screen will do, and offers the
 * two things that work today: the shop, and a person on WhatsApp.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_pro_placeholder')) {
    /** @param string $summary One or two plain sentences on what this screen will do. */
    function okv_pro_placeholder(string $summary): void
    {
        $whatsapp = preg_replace('/\D+/', '', Settings::str('support_whatsapp_number', '2348000000000'));
        ?>
        <div class="okv-panel max-w-3xl">
          <div class="okv-panel-body">
            <p class="okv-eyebrow">Coming soon</p>
            <p class="mt-3 text-ink"><?= okv_e($summary) ?></p>
            <p class="mt-3 text-sm text-ink-60">
              We are building it now. Until it is ready, order from the shop the same way you always have,
              and tell us what your kitchen needs. We will sort it out with you directly.
            </p>
            <div class="mt-6 flex flex-wrap gap-2">
              <a href="/shop.php" class="okv-btn px-5">Go to the shop</a>
              <a href="https://wa.me/<?= okv_e($whatsapp) ?>" class="okv-btn-outline px-5" rel="noopener">Talk to us on WhatsApp</a>
            </div>
          </div>
        </div>
        <?php
    }
}
