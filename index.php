<?php
/**
 * index.php
 * OK Veggies storefront home. The public front door. Boots the app, reads the
 * featured combo, the categories and the featured products, and renders them on
 * brand. Full shop, product pages and checkout land in later milestones; this
 * page is the anchor the rest hangs off.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/basket_notice.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';

// If a staff member is signed in, send them to the admin panel.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    Rbac::redirectToLanding();
}

$categories = Database::all('SELECT name, slug FROM product_categories WHERE is_active = 1 ORDER BY sort_order');

$featuredCombos = Catalogue::featuredCombos(3);
$featuredCombo = $featuredCombos[0] ?? null;

$featured = Database::all(
    'SELECT p.name, p.slug, p.short_description, p.current_price_subunit, u.symbol AS unit,
            (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image
       FROM products p JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.is_featured = 1 ORDER BY p.id LIMIT 8'
);

$tagline  = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OK Veggies. Fresh from farms we can name.</title>
  <meta name="description" content="Fresh produce from verified farms in Ogun State and Jos, delivered on the day you pick. Sourced right. Priced right. Delivered right.">
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('home'); ?>

<?php if (okv_basket_notice_message((string) okv_input('basket', '')) !== null): ?>
  <div class="okv-container pt-6"><?php okv_basket_notice(null, ''); ?></div>
<?php endif; ?>

<!-- Hero -->
<section class="bg-forest text-white">
  <div class="okv-container py-16 md:py-24 grid md:grid-cols-2 gap-10 items-center">
    <div class="animate-okv-rise">
      <p class="uppercase tracking-[0.2em] text-gold text-xs font-semibold mb-4">Est. 2026 . Lagos</p>
      <h1 class="font-display font-extrabold text-4xl md:text-5xl leading-tight">We are bringing the other half home.</h1>
      <p class="mt-5 text-white/85 text-lg max-w-md">Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.</p>
      <div class="mt-8 flex flex-wrap gap-3">
        <a href="/shop.php" class="okv-btn border border-white bg-white text-forest hover:bg-forest-tint">Start shopping</a>
        <a href="/combos.php" class="okv-btn-outline border-white text-white hover:bg-white/10">See the combos</a>
      </div>
      <p class="mt-6 text-gold text-sm font-semibold uppercase tracking-wider"><?= okv_e($tagline) ?></p>
    </div>
    <div class="hidden md:block">
      <?php if ($featuredCombo): ?>
      <?php
        // Use the same image-fallback helper as okv_combo_card so the hero
        // never renders a blank card for a combo whose Manager has not yet
        // uploaded a hero photo. Catalogue::featuredCombos precomputes the
        // fallback in one round-trip.
        $heroImage = okv_combo_card_image($featuredCombo, [
          ['image' => (string) ($featuredCombo['fallback_image'] ?? '')],
        ]);
      ?>
      <a href="/combos.php" class="block okv-card bg-white text-ink">
        <?php if ($heroImage !== ''): ?>
          <img src="<?= okv_e(okv_image_url($heroImage)) ?>" alt="<?= okv_e($featuredCombo['name']) ?>" class="w-full h-56 object-cover rounded-md mb-4">
        <?php endif; ?>
        <span class="okv-badge okv-badge-available">This week</span>
        <h3 class="font-display font-bold text-2xl mt-2"><?= okv_e($featuredCombo['name']) ?></h3>
        <p class="text-ink-60 mt-1 text-sm"><?= okv_e($featuredCombo['description']) ?></p>
        <p class="mt-3 font-mono text-xl text-forest"><?= okv_e(Money::format((int)$featuredCombo['price_subunit'])) ?></p>
      </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Featured combos strip -->
<?php if ($featuredCombos): ?>
<section class="okv-container pt-14">
  <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Cooked together, priced together</p>
      <h2 class="font-display font-bold text-2xl text-ink mt-2">This week's combos</h2>
    </div>
    <a href="/combos.php" class="okv-btn-text">See all combos</a>
  </div>
  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($featuredCombos as $combo): ?>
      <?php okv_combo_card($combo, '/'); ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Categories -->
<section class="okv-container py-14">
  <h2 class="font-display font-bold text-2xl text-ink mb-6">Shop by category</h2>
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
    <?php foreach ($categories as $c): ?>
      <a href="/shop.php?category=<?= okv_e($c['slug']) ?>" class="okv-card text-center font-medium text-ink hover:text-forest">
        <?= okv_e($c['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Featured products -->
<section class="okv-container pb-16">
  <h2 class="font-display font-bold text-2xl text-ink mb-6">This week's picks</h2>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php foreach ($featured as $p): ?>
      <a href="/product.php?slug=<?= okv_e($p['slug']) ?>" class="okv-card block">
        <?php if (!empty($p['image'])): ?>
          <img src="<?= okv_e(okv_image_url($p['image'])) ?>" alt="<?= okv_e($p['name']) ?>, per <?= okv_e($p['unit']) ?>" class="w-full h-40 object-cover rounded-md mb-3" loading="lazy">
        <?php endif; ?>
        <h3 class="font-medium text-ink"><?= okv_e($p['name']) ?></h3>
        <p class="text-ink-40 text-sm">per <?= okv_e($p['unit']) ?></p>
        <p class="mt-1 font-mono text-forest"><?= okv_e(Money::format((int)$p['current_price_subunit'])) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>

<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
</body>
</html>
