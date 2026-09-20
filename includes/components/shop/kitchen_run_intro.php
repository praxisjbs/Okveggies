<?php
/**
 * includes/components/shop/kitchen_run_intro.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Kitchen Runs, for a visitor who is not signed in.
 *
 * Included by kitchen-runs.php instead of the whole page when nobody is signed
 * in. Three visual steps, a Learn sheet for the long copy, and two account
 * buttons. The form is not rendered: a form that cannot be submitted is worse
 * than no form. The gate stays on the server in api/v1/kitchen_runs.php.
 * -----------------------------------------------------------------------------
 */

$pageTitle = 'Kitchen Runs, send us your list. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/kitchen-runs.php';

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/help_sheet.php';

$steps = [
    ['icon' => 'list', 'wash' => 'bg-foliage-tint', 'title' => 'You send the list', 'line' => 'Up to 100 items, in any shape.'],
    ['icon' => 'sparkle', 'wash' => 'bg-gold-tint2', 'title' => 'We price every line', 'line' => 'At that week\'s market, then we send it back.'],
    ['icon' => 'trail', 'wash' => 'bg-clay-tint', 'title' => 'You approve, we shop', 'line' => 'Nothing is charged until you say yes.'],
];

$sheetBody = '<p>This is how OK Veggies started. Send the list, we buy it at the market and bring it.</p>'
    . '<p class="mt-3">Four ways to send it:</p>'
    . '<ul class="mt-2 list-disc pl-5">'
    . '<li>Pick from the shop at the shop price.</li>'
    . '<li>Type your own list, including what we do not stock.</li>'
    . '<li>Upload a photo of the paper, or a PDF.</li>'
    . '<li>Already priced: we only confirm it and get moving.</li>'
    . '</ul>'
    . '<p class="mt-3">We price by hand, so we need a way to reach you. Your account holds the list, the quote and the address. It takes about 1 minute to open.</p>';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Send OK Veggies your kitchen list. We source it at the market, price it and deliver it once you have approved the quote.">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-canvas text-ink">
<?php okv_shop_header('kitchen-runs'); ?>

<main id="okv-main" class="okv-container py-8 md:py-12">

  <nav aria-label="Breadcrumb" class="text-sm text-ink-60">
    <ol class="flex flex-wrap items-center gap-2">
      <li><a class="inline-flex min-h-[44px] items-center hover:text-forest underline-offset-2 hover:underline" href="/">Home</a></li>
      <li aria-hidden="true">/</li>
      <li><a class="inline-flex min-h-[44px] items-center hover:text-forest underline-offset-2 hover:underline" href="/shop.php">Shop</a></li>
      <li aria-hidden="true">/</li>
      <li aria-current="page" class="text-ink">Kitchen Runs</li>
    </ol>
  </nav>

  <header class="mt-4 max-w-2xl">
    <h1 class="font-editorial text-okv-h4 text-ink md:text-okv-h3">Send us your list</h1>
    <p class="mt-3 text-ink-60">We source it. You approve the price. Then we deliver.</p>
  </header>

  <section class="mt-8" aria-labelledby="how-heading">
    <h2 id="how-heading" class="font-editorial text-okv-h5 text-ink">How a Kitchen Run works</h2>
    <ol class="mt-6 grid gap-4 sm:grid-cols-3">
      <?php foreach ($steps as $index => $step): ?>
        <li class="okv-step-card okv-enter <?= $index === 1 ? 'okv-enter-2' : ($index === 2 ? 'okv-enter-3' : '') ?>">
          <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full <?= okv_e($step['wash']) ?> text-forest">
            <?php okv_icon($step['icon'], 'h-12 w-12'); ?>
          </span>
          <p class="mt-4 font-mono text-xs uppercase tracking-[0.16em] text-ink-40"><?= (int) ($index + 1) ?></p>
          <h3 class="mt-1 font-editorial text-okv-h6 text-ink"><?= okv_e($step['title']) ?></h3>
          <p class="mt-2 text-sm text-ink-60"><?= okv_e($step['line']) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="mt-5">
      <button type="button" class="okv-btn-text min-h-[44px]" data-sheet-open="kr-intro-learn" aria-haspopup="dialog">
        <?php okv_icon('info', 'h-4 w-4'); ?> Learn
      </button>
    </p>
  </section>

  <section class="mt-10 rounded-xl border border-forest bg-foliage-tint p-5 md:p-6" aria-labelledby="account-heading">
    <h2 id="account-heading" class="font-editorial text-okv-h5 text-ink">A Kitchen Run needs an account</h2>
    <p class="mt-2 max-w-2xl text-ink-60">We price by hand, so we need a way to reach you.</p>
    <div class="mt-5 flex flex-col gap-3 sm:flex-row">
      <a class="okv-btn w-full justify-center rounded-xl sm:w-auto" href="/account.php?mode=register"><?php okv_icon('user', 'h-4 w-4'); ?> Create an account</a>
      <a class="okv-btn-outline w-full justify-center rounded-xl sm:w-auto" href="/account.php?mode=signin"><?php okv_icon('user', 'h-4 w-4'); ?> Sign in</a>
    </div>
  </section>

  <p class="mt-8 flex flex-wrap gap-3">
    <a class="okv-btn-outline rounded-xl" href="/shop.php"><?php okv_icon('leaf', 'h-4 w-4'); ?> Browse the shop</a>
    <a class="okv-btn-outline rounded-xl" href="/combos.php"><?php okv_icon('basket', 'h-4 w-4'); ?> See the combos</a>
  </p>

</main>

<?php okv_help_sheet('kr-intro-learn', 'list', 'How a Kitchen Run works', $sheetBody, [
    ['href' => '/account.php?mode=register', 'label' => 'Create an account'],
    ['href' => '/shop.php', 'label' => 'Browse the shop', 'style' => 'outline'],
]); ?>

<?php okv_shop_footer(); ?>
<script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
</body>
</html>
