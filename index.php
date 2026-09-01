<?php
/**
 * index.php
 * OK Veggies storefront home. The public front door. Boots the app, reads the
 * featured combo, the categories and the week's picks, and renders them on
 * brand. The hero is one of the two places the full seal has room to read at
 * 120px, so it carries the mark as a trust stamp rather than a decoration
 * (bible 3.1, CLAUDE.md). Basket and checkout land in M4; this page is the
 * anchor the rest hangs off.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/product_card.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';

// If a staff member is signed in, send them to the admin panel.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    Rbac::redirectToLanding();
}

$categories = Catalogue::categories();

$featuredCombos = Catalogue::featuredCombos(3);
$featuredCombo = $featuredCombos[0] ?? null;

$featured = Catalogue::featuredProducts(8);

$tagline = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');
$returnTo = '/';

$basketNotice = (string) okv_input('basket', '');
$noticeMessages = [
    'added' => 'Added to your basket.',
    'unavailable' => 'That item is not available yet. Its restock status is shown on the card.',
    'expired' => 'Your session expired. Please try adding the item again.',
    'missing' => 'We could not find that item. It may have left the catalogue.',
    'error' => 'We could not add that item. Please try again.',
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OK Veggies. Fresh from farms we can name.</title>
  <meta name="description" content="Fresh produce from verified farms in Ogun State and Jos, delivered on the day you pick. Sourced right. Priced right. Delivered right.">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('home'); ?>

<main>
<!-- Hero. The seal sits at 120px, where the ring's lettering still reads. -->
<section class="bg-forest text-white">
  <div class="okv-container grid items-center gap-10 py-16 md:grid-cols-2 md:py-24">
    <div class="animate-okv-rise">
      <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        <?php okv_seal(120, 'flex-none', 'The OK Veggies seal'); ?>
        <div>
          <p class="okv-eyebrow-invert">Est. 2026 . Lagos</p>
          <p class="mt-2 max-w-xs text-sm text-white/75">A trust stamp, not a logo. Every basket is weighed and checked before it leaves us.</p>
        </div>
      </div>
      <h1 class="mt-8 font-editorial text-okv-h4 md:text-okv-h2">We are bringing the other half home.</h1>
      <p class="mt-5 max-w-md text-okv-lead text-white/85">Fresh produce from farms we have checked ourselves in Ogun State and Jos. Weighed right, and brought on the day you pick.</p>
      <div class="mt-8 flex flex-wrap gap-3">
        <a href="/shop.php" class="okv-btn border border-white bg-white text-forest hover:bg-forest-tint">Start shopping</a>
        <a href="/combos.php" class="okv-btn-outline-invert">See the combos</a>
      </div>
      <p class="mt-8 border-t-2 border-gold pt-4 text-sm font-semibold uppercase tracking-wider text-white"><?= okv_e($tagline) ?></p>
    </div>

    <?php if ($featuredCombo): ?>
      <?php
        // Use the same image-fallback helper as okv_combo_card so the hero
        // never renders a blank card for a combo whose Manager has not yet
        // uploaded a hero photo. Catalogue::featuredCombos precomputes the
        // fallback in one round-trip.
        $heroImage = okv_combo_card_image($featuredCombo, [
          ['image' => (string) ($featuredCombo['fallback_image'] ?? '')],
        ]);
        $heroUrl = '/combo.php?slug=' . rawurlencode((string) $featuredCombo['slug']);
      ?>
      <a href="<?= okv_e($heroUrl) ?>" class="group hidden text-ink md:block">
        <article class="okv-card p-5">
          <div class="aspect-[4/3] overflow-hidden rounded-md bg-forest-tint">
            <?php if ($heroImage !== ''): ?>
              <img src="<?= okv_e(okv_image_url($heroImage)) ?>"
                   alt="<?= okv_e($featuredCombo['name']) ?>, ready basket of <?= (int) $featuredCombo['component_count'] ?> items, sourced from <?= okv_e($sourceRegions) ?>"
                   class="h-full w-full object-cover transition duration-botanical ease-botanical group-hover:scale-105">
            <?php else: ?>
              <div class="flex h-full items-center justify-center text-sm text-ink-40">Photo coming soon</div>
            <?php endif; ?>
          </div>
          <span class="okv-badge okv-badge-available mt-4">This week</span>
          <h2 class="mt-2 font-editorial text-okv-h6 text-ink"><?= okv_e($featuredCombo['name']) ?></h2>
          <?php if (trim((string) $featuredCombo['description']) !== ''): ?>
            <p class="mt-1 line-clamp-2 text-sm text-ink-60"><?= okv_e($featuredCombo['description']) ?></p>
          <?php endif; ?>
          <p class="mt-4 font-mono text-okv-h6 font-semibold text-forest"><?= okv_e(Money::format((int) $featuredCombo['price_subunit'])) ?></p>
          <p class="okv-btn-text mt-2">See what is inside <span aria-hidden="true">&rarr;</span></p>
        </article>
      </a>
    <?php endif; ?>
  </div>
</section>

<?php if (isset($noticeMessages[$basketNotice])): ?>
  <div class="okv-container pt-6">
    <p class="rounded-md border <?= $basketNotice === 'added' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?> px-4 py-3 text-sm" role="status"><?= okv_e($noticeMessages[$basketNotice]) ?></p>
  </div>
<?php endif; ?>

<!-- Featured combos strip -->
<?php if ($featuredCombos): ?>
<section class="okv-container pt-14">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="okv-eyebrow">Cooked together, priced together</p>
      <h2 class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4">This week's combos</h2>
    </div>
    <a href="/combos.php" class="okv-btn-text">See all combos <span aria-hidden="true">&rarr;</span></a>
  </div>
  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($featuredCombos as $combo): ?>
      <?php okv_combo_card($combo, $returnTo, $sourceRegions, $sourceDay); ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<!-- Categories -->
<section class="okv-container py-14">
  <p class="okv-eyebrow">Five aisles, one stall</p>
  <h2 class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4">Shop by category</h2>
  <div class="mt-6 grid grid-cols-2 gap-4 md:grid-cols-5">
    <?php foreach ($categories as $c): ?>
      <a href="/shop.php?category=<?= okv_e($c['slug']) ?>" class="okv-card group text-ink hover:text-forest">
        <span class="block font-semibold leading-tight"><?= okv_e($c['name']) ?></span>
        <span class="mt-1 flex items-center gap-2 text-sm text-ink-60">
          <span class="font-mono"><?= (int) $c['product_count'] ?></span>
          <?= (int) $c['product_count'] === 1 ? 'item' : 'items' ?>
          <span class="ml-auto transition-transform duration-botanical ease-botanical group-hover:translate-x-1" aria-hidden="true">&rarr;</span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<!-- This week's picks -->
<?php if ($featured): ?>
<section class="okv-container pb-16">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="okv-eyebrow">Picked this week</p>
      <h2 class="mt-2 font-editorial text-okv-h5 text-ink md:text-okv-h4">This week's picks</h2>
    </div>
    <a href="/shop.php" class="okv-btn-text">See all produce <span aria-hidden="true">&rarr;</span></a>
  </div>
  <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
    <?php foreach ($featured as $p): ?>
      <?php okv_product_card($p, $sourceRegions, $returnTo, $sourceDay); ?>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
</main>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>

<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
