<?php
/**
 * combos.php
 * OK Veggies. The ready-made combo baskets on the shop today. Filters on
 * active plus inside the availability window through Catalogue::combos(); the
 * home page featured strip and the combo detail page use the same filter, so
 * the three surfaces cannot disagree about what is on the shop.
 *
 * Layout follows bible 4.2: a basket is a cooking occasion, not a product
 * line, so the first two get the editorial spread treatment and the rest fall
 * into the card grid. That keeps the page editorial with one combo published
 * and still readable with a dozen.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';
require_once __DIR__ . '/includes/components/shop/combo_card.php';
require_once __DIR__ . '/includes/components/shop/combo_spread.php';

/** How many baskets get the full editorial spread before the grid takes over. */
const OKV_COMBO_SPREADS = 2;

$combos = Catalogue::combos();
$returnTo = '/combos.php';
$sourceRegions = Settings::str('source_regions', 'Ogun State, Jos');
$sourceDay = Settings::str('source_day', '');

$spreads = array_slice($combos, 0, OKV_COMBO_SPREADS);
$rest = array_slice($combos, OKV_COMBO_SPREADS);

// One components query per spread, run here rather than inside the component,
// so the page's query count is visible in one place.
$spreadComponents = [];
foreach ($spreads as $spread) {
    $spreadComponents[(int) $spread['id']] = Catalogue::comboComponents((int) $spread['id']);
}

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
  <meta name="description" content="Ready-made combo baskets from OK Veggies. Cooked-together items priced together, with the saving against buying the pieces separately shown on every basket.">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_title' => $pageTitle, 'og_description' => 'Ready-made combo baskets for a Lagos kitchen. One tap, one price, delivered on the day you pick.']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_activation_banner(); ?>
<?php okv_shop_header('combos'); ?>

<main>
  <section class="bg-forest text-white">
    <div class="okv-container py-10 md:py-16">
      <nav class="mb-6 text-sm text-white/70" aria-label="Breadcrumb">
        <a href="/" class="underline-offset-4 hover:text-white hover:underline hover:decoration-gold hover:decoration-2">Home</a>
        <span aria-hidden="true">/</span> <span aria-current="page" class="text-white">Combos</span>
      </nav>
      <div class="grid items-end gap-8 md:grid-cols-12">
        <div class="md:col-span-8">
          <p class="okv-eyebrow-invert">Cooked together, priced together</p>
          <h1 class="mt-3 font-editorial text-okv-h4 md:text-okv-h3">Ready baskets for this week</h1>
          <p class="mt-4 max-w-2xl text-okv-lead text-white/85">Every combo is a set of items priced together, at a saving against buying the pieces one by one. One tap adds the full basket to your order.</p>
          <?php okv_sourced_note($sourceRegions, $sourceDay, 'mt-5 text-white/75'); ?>
        </div>
        <div class="md:col-span-4 md:text-right">
          <?php okv_seal(120, 'inline-block', ''); ?>
        </div>
      </div>
    </div>
  </section>

  <?php if (isset($noticeMessages[$basketNotice])): ?>
    <div class="okv-container pt-6">
      <p class="rounded-md border <?= $basketNotice === 'added' ? 'border-foliage bg-foliage-tint text-forest' : 'border-tomato bg-tomato-tint text-tomato' ?> px-4 py-3 text-sm" role="status"><?= okv_e($noticeMessages[$basketNotice]) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($combos): ?>
    <section class="okv-container space-y-8 py-8 md:py-12" aria-label="This week's baskets">
      <?php foreach ($spreads as $index => $spread): ?>
        <?php okv_combo_spread(
            $spread,
            $spreadComponents[(int) $spread['id']] ?? [],
            $returnTo,
            $sourceRegions,
            $sourceDay,
            $index % 2 === 1
        ); ?>
      <?php endforeach; ?>
    </section>

    <?php if ($rest): ?>
      <section class="okv-container pb-12 md:pb-16">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
          <h2 class="font-editorial text-okv-h5 text-ink">More baskets this week</h2>
          <p class="text-sm text-ink-60"><?= count($combos) ?> <?= count($combos) === 1 ? 'basket' : 'baskets' ?> in total</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($rest as $combo): ?>
            <?php okv_combo_card($combo, $returnTo, $sourceRegions, $sourceDay); ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php else: ?>
    <section class="okv-container py-8 md:py-12">
      <div class="rounded-lg bg-white px-6 py-16 text-center shadow-okv-1">
        <?php okv_seal(120, 'mx-auto', ''); ?>
        <p class="okv-eyebrow mt-6">Still packing</p>
        <h2 class="mt-3 font-editorial text-okv-h5 text-ink">We are still building this week's combos</h2>
        <p class="mx-auto mt-3 max-w-md text-ink-60">The individual items are ready now. Combos land back on this page as soon as they are set.</p>
        <a href="/shop.php" class="okv-btn mt-6">Shop the produce</a>
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
