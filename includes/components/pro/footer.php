<?php
/**
 * includes/components/pro/footer.php
 * OK Veggies. Closes the Pro Portal document opened by header.php.
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    exit;
}
$okv_pro_whatsapp = preg_replace('/\D+/', '', Settings::str('support_whatsapp_number', '2348000000000'));
?>
  </main>

  <footer class="mt-8 border-t border-mist bg-white">
    <div class="okv-container flex flex-wrap items-center justify-between gap-4 py-6">
      <div class="flex items-center gap-3">
        <img src="<?= okv_e(okv_asset('/assets/img/brand/seal-320.png')) ?>" alt="OK Veggies" width="48" height="48" class="h-12 w-12">
        <p class="text-xs text-ink-60">Sourced right. Priced right. Delivered right.</p>
      </div>
      <div class="flex flex-wrap items-center gap-4 text-xs">
        <a href="/shop.php" class="inline-flex min-h-[44px] items-center text-forest underline underline-offset-4">Shop</a>
        <a href="/kitchen-runs.php" class="inline-flex min-h-[44px] items-center text-forest underline underline-offset-4">Kitchen Runs</a>
        <a href="https://wa.me/<?= okv_e($okv_pro_whatsapp) ?>" class="inline-flex min-h-[44px] items-center text-forest underline underline-offset-4" rel="noopener">Talk to us on WhatsApp</a>
      </div>
    </div>
  </footer>
</body>
</html>
