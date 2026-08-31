<?php
/** OK Veggies. Ready-made baskets for cooking occasions. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';

$combos = Catalogue::combos();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Combos . OK Veggies</title>
  <meta name="description" content="Ready-made OK Veggies baskets for the pots you are planning this week.">
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-cream">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('combos'); ?>
<main>
  <section class="bg-forest text-white">
    <div class="okv-container py-12 md:py-16">
      <nav aria-label="Breadcrumb" class="text-sm text-white/70"><a href="/" class="underline decoration-gold underline-offset-4">Home</a> <span aria-hidden="true">/</span> Combos</nav>
      <h1 class="font-display font-extrabold text-4xl mt-5">Combos for the pot you have in mind.</h1>
      <p class="mt-4 max-w-2xl text-white/85">Each one is a ready-made basket. Pick a combo, add it once, and get on with the cooking.</p>
    </div>
  </section>
  <section class="okv-container py-10 md:py-14">
    <?php if ($combos): ?>
      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($combos as $combo): ?><?php okv_combo_card($combo, '/combos.php'); ?><?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="okv-card max-w-xl"><h2 class="font-display font-bold text-xl text-ink">Nothing ready-made today.</h2><p class="mt-2 text-ink-60">The market list is still open if you want to build your own basket.</p><a href="/shop.php" class="okv-btn mt-5">Shop produce</a></div>
    <?php endif; ?>
  </section>
</main>
<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
