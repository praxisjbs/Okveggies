<?php
/**
 * wallet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The customer wallet: the credit OK Veggies has given you, what is
 * left of it, and every entry behind that with its credit note.
 *
 * A business customer is sent on to the Pro portal's own wallet page, so each
 * kind of customer stays inside the surface it already knows.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/icons.php';
require_once __DIR__ . '/includes/components/shop/wallet_panel.php';

Customer::requireLogin();
if (Customer::isBusiness()) {
    okv_redirect('/pro/wallet.php');
}

$view = Wallet::view((int) Customer::id());
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Your wallet . OK Veggies</title>
  <meta name="robots" content="noindex">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_shop_header(); ?>

<main id="okv-main" class="okv-container py-8 md:py-12">
  <a href="/account.php" class="okv-btn-text inline-flex min-h-[44px] items-center"><span aria-hidden="true">&larr;</span>&nbsp;Your account</a>
  <h1 class="mt-3 font-editorial text-okv-h5 text-ink md:text-okv-h4">Your wallet</h1>
  <div class="mt-8 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-3">
      <?php okv_wallet_panel($view); ?>
    </div>
  </div>
</main>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>
