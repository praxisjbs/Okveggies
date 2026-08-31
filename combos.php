<?php
/**
 * combos.php
 * OK Veggies. The ready-made combo baskets on the shop today. Filters on
 * active plus inside the availability window through Catalogue::combos(); the
 * home page featured strip and the combo detail page use the same filter, so
 * the three surfaces cannot disagree about what is on the shop.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';

$combos = Catalogue::combos();
$returnTo = '/combos.php';

$pageTitle = 'Combos, ready baskets for this week. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/combos.php';

$basketNotice = (string) okv_input('basket', '');
$noticeMessages = [
    'added' => 'Added to your basket.',
    'unavailable' => 'That combo is no longer on the shop.',
    'expired' => 'Your session expired. Please try adding the combo again.',
    'missing' => 'We could not find that combo. It may have left the shop.',
    'error' => 'We could not add that combo. Please try again.',
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Ready-made combo baskets from OK Veggies. Cooked-together items priced together, with the saving against buying the pieces separately shown on every card.">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="OK Veggies">
  <meta property="og:title" content="<?= okv_e($pageTitle) ?>">
  <meta property="og:description" content="Ready-made combo baskets for a Lagos kitchen. One tap, one price, delivered on the day you pick.">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('combos'); ?>

<main>
  <section class="border-b border-mist bg-white">
    <div class="okv-container py-8 md:py-12">
      <nav class="mb-4 text-sm text-ink-60" aria-label="Breadcrumb"><a href="/" class="hover:text-forest">Home</a> <span aria-hidden="true">/</span> <span aria-current="page">Combos</span></nav>
      <div class="max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Cooked together, priced together</p>
        <h1 class="mt-2 font-display text-4xl font-extrabold text-ink md:text-5xl">Ready baskets for this week</h1>
        <p class="mt-3 text-ink-60">Every combo is a set of items priced together, at a saving against buying the pieces one by one. One tap adds the full basket to your order.</p>
      </div>
    </div>
  </section>

  <?php if (isset($noticeMessages[$basketNotice])): ?>
    <div class="okv-container pt-6">
      <p class="rounded-md border <?= $basketNotice === 'added' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?> px-4 py-3 text-sm" role="status"><?= okv_e($noticeMessages[$basketNotice]) ?></p>
    </div>
  <?php endif; ?>

  <section class="okv-container py-8 md:py-12">
    <?php if ($combos): ?>
      <div class="mb-6 hidden items-end justify-between gap-4 lg:flex">
        <h2 class="font-display text-2xl font-bold text-ink">This week's combos</h2>
        <p class="text-sm text-ink-60"><?= count($combos) ?> <?= count($combos) === 1 ? 'basket' : 'baskets' ?></p>
      </div>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($combos as $combo): ?>
          <?php okv_combo_card($combo, $returnTo); ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="rounded-lg bg-white px-6 py-16 text-center shadow-okv-1">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gold-ink">Coming soon</p>
        <h2 class="mt-3 font-display text-2xl font-bold text-ink">We are still building this week's combos</h2>
        <p class="mx-auto mt-3 max-w-md text-ink-60">The individual items are ready now. Combos land back on this page as soon as they are set.</p>
        <a href="/shop.php" class="okv-btn mt-6">Shop the produce</a>
      </div>
    <?php endif; ?>
  </section>
</main>

<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
<script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode(Csrf::token()) ?>;</script>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>"></script>
<script src="<?= okv_e(okv_asset('/assets/js/catalogue.min.js')) ?>"></script>
</body>
</html>
