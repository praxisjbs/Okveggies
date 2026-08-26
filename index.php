<?php
/**
 * index.php
 * OK Veggies storefront home. The public front door. Boots the app, reads the
 * featured combo, the categories and the featured products, and renders them on
 * brand. Full shop, product pages and checkout land in later milestones; this
 * page is the anchor the rest hangs off.
 */
require_once __DIR__ . '/includes/bootstrap.php';

// If a staff member is signed in, send them to the admin panel.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    Rbac::redirectToLanding();
}

/** Encode a stored asset path (which may contain spaces) for use in a URL. */
function okv_img(string $path): string {
    $path = '/' . ltrim($path, '/');
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

$categories = Database::all('SELECT name, slug FROM product_categories WHERE is_active = 1 ORDER BY sort_order');

$featuredCombo = Database::one(
    'SELECT id, name, slug, description, price_subunit, image_url FROM combo_packages WHERE is_active = 1 AND is_featured = 1 ORDER BY id LIMIT 1'
);

$featured = Database::all(
    'SELECT p.name, p.slug, p.short_description, p.current_price_subunit, u.symbol AS unit,
            (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image
       FROM products p JOIN units_of_measurement u ON u.id = p.unit_id
      WHERE p.is_active = 1 AND p.is_featured = 1 ORDER BY p.id LIMIT 8'
);

$waNumber = Settings::str('support_whatsapp_number', '2348000000000');
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
<body>

<!-- Top bar -->
<header class="border-b border-mist bg-white sticky top-0 z-30">
  <div class="okv-container flex items-center justify-between h-16">
    <a href="/" class="font-display font-extrabold text-forest text-xl tracking-tight">OK Veggies</a>
    <nav class="hidden md:flex items-center gap-6 text-ink font-medium">
      <a href="/shop.php" class="hover:text-forest">Shop</a>
      <a href="/combos.php" class="hover:text-forest">Combos</a>
      <a href="/kitchen-runs.php" class="okv-btn-outline min-h-[40px] px-4">Kitchen Runs</a>
    </nav>
    <div class="flex items-center gap-3">
      <a href="/account.php" class="okv-btn-text">Sign in</a>
      <a href="/cart.php" class="okv-btn min-h-[40px] px-4">Basket</a>
    </div>
  </div>
</header>

<!-- Hero -->
<section class="bg-forest text-white">
  <div class="okv-container py-16 md:py-24 grid md:grid-cols-2 gap-10 items-center">
    <div class="animate-okv-rise">
      <p class="uppercase tracking-[0.2em] text-gold text-xs font-semibold mb-4">Est. 2026 . Lagos</p>
      <h1 class="font-display font-extrabold text-4xl md:text-5xl leading-tight">We are bringing the other half home.</h1>
      <p class="mt-5 text-white/85 text-lg max-w-md">Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.</p>
      <div class="mt-8 flex flex-wrap gap-3">
        <a href="/shop.php" class="okv-btn bg-gold text-ink hover:bg-gold-hover">Start shopping</a>
        <a href="/combos.php" class="okv-btn-outline border-white text-white hover:bg-white/10">See the combos</a>
      </div>
      <p class="mt-6 text-gold text-sm font-semibold uppercase tracking-wider"><?= okv_e($tagline) ?></p>
    </div>
    <div class="hidden md:block">
      <?php if ($featuredCombo): ?>
      <a href="/combos.php" class="block okv-card bg-white text-ink">
        <?php if (!empty($featuredCombo['image_url'])): ?>
          <img src="<?= okv_e(okv_img($featuredCombo['image_url'])) ?>" alt="<?= okv_e($featuredCombo['name']) ?>" class="w-full h-56 object-cover rounded-md mb-4">
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
          <img src="<?= okv_e(okv_img($p['image'])) ?>" alt="<?= okv_e($p['name']) ?>, per <?= okv_e($p['unit']) ?>" class="w-full h-40 object-cover rounded-md mb-3" loading="lazy">
        <?php endif; ?>
        <h3 class="font-medium text-ink"><?= okv_e($p['name']) ?></h3>
        <p class="text-ink-40 text-sm">per <?= okv_e($p['unit']) ?></p>
        <p class="mt-1 font-mono text-forest"><?= okv_e(Money::format((int)$p['current_price_subunit'])) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- Footer -->
<footer class="bg-ink text-white/80">
  <div class="okv-container py-12 grid md:grid-cols-3 gap-8">
    <div>
      <p class="font-display font-extrabold text-white text-lg">OK Veggies</p>
      <p class="mt-2 text-sm text-white/60">Sourced right. Priced right. Delivered right.</p>
    </div>
    <div class="text-sm">
      <p class="text-white font-semibold mb-3">Company</p>
      <ul class="space-y-2">
        <li><a href="/page.php?slug=about" class="hover:text-gold">Our Story</a></li>
        <li><a href="/page.php?slug=how-it-works" class="hover:text-gold">How It Works</a></li>
        <li><a href="/page.php?slug=faq" class="hover:text-gold">Questions</a></li>
      </ul>
    </div>
    <div class="text-sm">
      <p class="text-white font-semibold mb-3">Legal</p>
      <ul class="space-y-2">
        <li><a href="/page.php?slug=terms" class="hover:text-gold">Terms</a></li>
        <li><a href="/page.php?slug=privacy" class="hover:text-gold">Privacy</a></li>
        <li><a href="/page.php?slug=delivery-policy" class="hover:text-gold">Delivery Policy</a></li>
      </ul>
    </div>
  </div>
  <div class="okv-container pb-8 text-xs text-white/40">© <?= date('Y') ?> OK Veggies. Powered by JBS Praxis.</div>
</footer>

<!-- Floating support widget -->
<div class="fixed bottom-5 right-5 z-40">
  <a href="https://wa.me/<?= okv_e($waNumber) ?>?text=<?= rawurlencode('Hello OK Veggies, I have a question.') ?>"
     class="okv-btn bg-foliage hover:bg-foliage-hover rounded-full w-14 h-14 p-0 shadow-okv-3" aria-label="Chat with us on WhatsApp">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15l-1.4 5 5.1-1.3A10 10 0 1 0 12 2Zm0 2a8 8 0 1 1-4.1 14.9l-.3-.2-3 .8.8-2.9-.2-.3A8 8 0 0 1 12 4Zm4.5 10.3c-.2-.1-1.4-.7-1.6-.8s-.4-.1-.5.1-.6.8-.8 1-.3.2-.5 0a6.5 6.5 0 0 1-1.9-1.2 7.2 7.2 0 0 1-1.3-1.7c-.1-.2 0-.4.1-.5l.4-.4.2-.4v-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3A2.8 2.8 0 0 0 6.6 10a5 5 0 0 0 1 2.6 11 11 0 0 0 4.2 3.7c.6.2 1 .4 1.4.5.6.2 1.1.1 1.5.1.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1l-.4-.4Z"/></svg>
  </a>
</div>

<script src="<?= okv_e(okv_asset('/assets/js/okv.js')) ?>"></script>
</body>
</html>
