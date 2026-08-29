<?php
/** Shared storefront footer. */
if (!function_exists('okv_shop_footer')) {
    function okv_shop_footer(): void
    {
        $name = Settings::str('business_name', 'OK Veggies');
        $tagline = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
        ?>
        <footer class="mb-14 bg-ink text-white/80 md:mb-0">
          <div class="okv-container grid gap-8 py-12 md:grid-cols-3">
            <div>
              <p class="font-display text-lg font-extrabold text-white"><?= okv_e($name) ?></p>
              <p class="mt-2 text-sm text-white/60"><?= okv_e($tagline) ?></p>
            </div>
            <div class="text-sm">
              <p class="mb-3 font-semibold text-white">Company</p>
              <ul class="space-y-2">
                <li><a href="/page.php?slug=about" class="hover:text-gold">Our Story</a></li>
                <li><a href="/page.php?slug=how-it-works" class="hover:text-gold">How It Works</a></li>
                <li><a href="/page.php?slug=faq" class="hover:text-gold">Questions</a></li>
              </ul>
            </div>
            <div class="text-sm">
              <p class="mb-3 font-semibold text-white">Legal</p>
              <ul class="space-y-2">
                <li><a href="/page.php?slug=terms" class="hover:text-gold">Terms</a></li>
                <li><a href="/page.php?slug=privacy" class="hover:text-gold">Privacy</a></li>
                <li><a href="/page.php?slug=delivery-policy" class="hover:text-gold">Delivery Policy</a></li>
              </ul>
            </div>
          </div>
          <div class="okv-container pb-8 text-xs text-white/40">© <?= date('Y') ?> <?= okv_e($name) ?>. Powered by JBS Praxis.</div>
        </footer>
        <?php
    }
}
