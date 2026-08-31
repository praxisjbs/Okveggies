<?php
/** OK Veggies. One ready-made combo and everything inside it. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$slug = (string) okv_input('slug', '');
$combo = Catalogue::comboBySlug($slug);
if (!$combo) {
    http_response_code(404);
}
$components = $combo ? Catalogue::comboComponents((int) $combo['id']) : [];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $combo ? okv_e($combo['name']) . ' . ' : '' ?>OK Veggies</title>
  <meta name="robots" content="<?= $combo ? 'index,follow' : 'noindex' ?>">
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-cream">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('combos'); ?>
<main class="okv-container py-8 pb-28 md:py-12">
  <?php if (!$combo): ?>
    <nav aria-label="Breadcrumb" class="text-sm text-ink-60"><a href="/" class="text-forest underline underline-offset-4">Home</a> <span aria-hidden="true">/</span> <a href="/combos.php" class="text-forest underline underline-offset-4">Combos</a> <span aria-hidden="true">/</span> Not found</nav>
    <section class="okv-card mt-8 max-w-xl"><h1 class="font-display font-extrabold text-3xl text-ink">That combo is not on the shop.</h1><p class="mt-3 text-ink-60">It may have finished for now. Have a look at the combos ready today.</p><a href="/combos.php" class="okv-btn mt-5">See combos</a></section>
  <?php else: ?>
    <nav aria-label="Breadcrumb" class="text-sm text-ink-60"><a href="/" class="text-forest underline underline-offset-4">Home</a> <span aria-hidden="true">/</span> <a href="/combos.php" class="text-forest underline underline-offset-4">Combos</a> <span aria-hidden="true">/</span> <?= okv_e($combo['name']) ?></nav>
    <div class="mt-6 grid gap-8 lg:grid-cols-2 lg:items-start">
      <div class="aspect-square overflow-hidden rounded-md bg-forest-tint">
        <?php if (!empty($combo['image_url'])): ?><img src="<?= okv_e(okv_image_url($combo['image_url'])) ?>" alt="<?= okv_e($combo['name']) ?>" class="h-full w-full object-cover"><?php else: ?><div class="flex h-full items-center justify-center p-6 text-center text-ink-40">Photo coming soon</div><?php endif; ?>
      </div>
      <div>
        <?php if (!empty($combo['is_featured'])): ?><span class="okv-badge okv-badge-available">This week</span><?php endif; ?>
        <h1 class="font-display font-extrabold text-4xl text-ink mt-3"><?= okv_e($combo['name']) ?></h1>
        <?php if (!empty($combo['description'])): ?><p class="mt-4 text-lg text-ink-60 leading-relaxed"><?= nl2br(okv_e($combo['description'])) ?></p><?php endif; ?>
        <p class="mt-6 font-mono font-bold text-3xl text-forest"><?= okv_e(Money::format((int) $combo['price_subunit'])) ?></p>
        <form method="post" action="/api/v1/cart.php" class="mt-6" data-add-form>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="add_combo"><input type="hidden" name="combo_id" value="<?= (int) $combo['id'] ?>"><input type="hidden" name="return_to" value="<?= okv_e('/combo.php?slug=' . $combo['slug']) ?>">
          <button type="submit" class="okv-btn w-full sm:w-auto px-8" data-add-button>Add this combo</button>
        </form>
        <p class="mt-3 text-sm text-ink-60">Added as 1 basket line. The products below are packed together for this combo.</p>
      </div>
    </div>
    <section class="mt-12 max-w-3xl">
      <h2 class="font-display font-bold text-2xl text-ink">What is inside</h2>
      <ul class="mt-5 divide-y divide-mist rounded-md border border-mist bg-white">
        <?php foreach ($components as $component): ?>
          <li class="flex items-center gap-4 p-4">
            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-md bg-forest-tint">
              <?php if (!empty($component['image'])): ?><img src="<?= okv_e(okv_image_url($component['image'])) ?>" alt="<?= okv_e($component['product_name']) ?>" class="h-full w-full object-cover" loading="lazy"><?php endif; ?>
            </div>
            <div><a href="/product.php?slug=<?= okv_e($component['product_slug']) ?>" class="font-semibold text-ink underline decoration-gold underline-offset-4"><?= okv_e($component['product_name']) ?></a><p class="mt-1 text-sm text-ink-60"><?= okv_e(okv_quantity($component['quantity'])) ?><?= okv_e($component['unit']) ?></p></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</main>
<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
