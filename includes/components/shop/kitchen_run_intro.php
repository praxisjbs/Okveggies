<?php
/**
 * includes/components/shop/kitchen_run_intro.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Kitchen Runs, for a visitor who is not signed in.
 *
 * Included by kitchen-runs.php instead of the whole page when nobody is signed
 * in. Before this existed the page called Customer::requireLogin() on line 34
 * and a visitor was redirected to /account.php?mode=signin without ever
 * reading what a Kitchen Run is. The most trusted thing we offer was invisible
 * to everybody who had not already joined.
 *
 * So the explanation is here in full, and the reason an account is needed is
 * stated plainly rather than enforced silently: we price a list by hand and we
 * have to reach the person about it. The form is not rendered at all, because a
 * form that cannot be submitted is worse than no form.
 *
 * This is a courtesy, not a gate. The gate is on the server, in
 * api/v1/kitchen_runs.php, which calls Customer::requireLoginApi() on submit.
 * -----------------------------------------------------------------------------
 */

$pageTitle = 'Kitchen Runs, send us your list. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . '/kitchen-runs.php';

/** The four ways in, described for somebody who has never sent one. */
$introStarts = [
    ['Pick from the shop', 'Build the list from what we already stock, and we price it at the shop price.'],
    ['Type your own list', 'Anything at all, including what we do not stock: pomo, meat, oil, seasoning.'],
    ['Upload a written list', 'A photo of the paper, or a PDF. We read it, type it up and price every line.'],
    ['Already priced', 'Your list with your own prices on it. We only confirm it and get moving.'],
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($pageTitle) ?></title>
  <meta name="description" content="Send OK Veggies your kitchen list, however you have it written. We source it at the market, price it and deliver it once you have approved the quote.">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-canvas text-ink">
<?php okv_shop_header('kitchen-runs'); ?>

<main class="okv-container py-8 md:py-12">

  <nav aria-label="Breadcrumb" class="text-sm text-ink-60">
    <ol class="flex flex-wrap items-center gap-2">
      <li><a class="hover:text-forest underline-offset-2 hover:underline" href="/">Home</a></li>
      <li aria-hidden="true">/</li>
      <li><a class="hover:text-forest underline-offset-2 hover:underline" href="/shop.php">Shop</a></li>
      <li aria-hidden="true">/</li>
      <li aria-current="page" class="text-ink">Kitchen Runs</li>
    </ol>
  </nav>

  <header class="mt-4 max-w-2xl">
    <h1 class="font-editorial text-3xl md:text-4xl text-ink">Send us your list</h1>
    <p class="mt-3 text-ink-60">
      This is how OK Veggies started. Write out what your kitchen needs, however you have it,
      and we buy it at the market and bring it. Plenty of it will not be on our shop:
      pomo, meat, oil, anything. Send it anyway.
    </p>
    <p class="mt-2 text-ink-60">
      We price your list and send it back. Nothing is charged until you have read the prices and approved them.
    </p>
  </header>

  <!-- How it works, before we ask anybody for anything. -->
  <section class="mt-8" aria-labelledby="how-heading">
    <h2 id="how-heading" class="font-editorial text-2xl text-ink">How a Kitchen Run works</h2>
    <ol class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <li class="rounded-xl border border-mist bg-white p-4">
        <span class="font-mono text-sm text-ink-60">1</span>
        <span class="mt-1 block font-semibold text-ink">You send the list</span>
        <span class="mt-1 block text-sm text-ink-60">Up to 100 items, in whatever shape you have them written.</span>
      </li>
      <li class="rounded-xl border border-mist bg-white p-4">
        <span class="font-mono text-sm text-ink-60">2</span>
        <span class="mt-1 block font-semibold text-ink">We price it</span>
        <span class="mt-1 block text-sm text-ink-60">Line by line, at what the market is that week, and we send it back.</span>
      </li>
      <li class="rounded-xl border border-mist bg-white p-4">
        <span class="font-mono text-sm text-ink-60">3</span>
        <span class="mt-1 block font-semibold text-ink">You approve it</span>
        <span class="mt-1 block text-sm text-ink-60">Read every line first. Nothing is charged until you say yes.</span>
      </li>
      <li class="rounded-xl border border-mist bg-white p-4">
        <span class="font-mono text-sm text-ink-60">4</span>
        <span class="mt-1 block font-semibold text-ink">We shop and deliver</span>
        <span class="mt-1 block text-sm text-ink-60">It becomes an ordinary order, on the delivery day you picked.</span>
      </li>
    </ol>
  </section>

  <section class="mt-10" aria-labelledby="ways-heading">
    <h2 id="ways-heading" class="font-editorial text-2xl text-ink">Four ways to send it</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($introStarts as [$title, $blurb]): ?>
        <div class="rounded-xl border border-mist bg-white p-4">
          <span class="block font-semibold text-ink"><?= okv_e($title) ?></span>
          <span class="mt-1 block text-sm text-ink-60"><?= okv_e($blurb) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Why an account, and the two ways to get one. -->
  <section class="mt-10 rounded-xl border border-forest bg-foliage-tint p-5 md:p-6" aria-labelledby="account-heading">
    <h2 id="account-heading" class="font-editorial text-2xl text-ink">A Kitchen Run needs an account</h2>
    <p class="mt-2 max-w-2xl text-ink-60">
      We price a kitchen list by hand, so we need somewhere to send the prices and a way to reach you
      about them. Your account holds your list, the quote we send back, your delivery address and every
      run you have sent us before. It takes about 1 minute to open.
    </p>
    <div class="mt-5 flex flex-wrap items-center gap-3">
      <a class="okv-btn min-h-[44px] inline-flex items-center" href="/account.php?mode=register">Create an account</a>
      <a class="okv-btn-outline min-h-[44px] inline-flex items-center" href="/account.php?mode=signin">Sign in</a>
    </div>
    <p class="mt-4 text-sm text-ink-60">
      Already registered but not verified? Activate your account with the 6 digit code we emailed you,
      then send your list. <a class="text-forest underline underline-offset-2" href="/public/auth/activate.php">Activate now</a>.
    </p>
  </section>

  <section class="mt-10">
    <h2 class="font-editorial text-2xl text-ink">While you are here</h2>
    <p class="mt-2 text-ink-60">
      You do not need a Kitchen Run for everything. The shop is open, and a basket goes through checkout the ordinary way.
    </p>
    <p class="mt-4 flex flex-wrap gap-3">
      <a class="okv-btn-outline min-h-[44px] inline-flex items-center" href="/shop.php">Browse the shop</a>
      <a class="okv-btn-outline min-h-[44px] inline-flex items-center" href="/combos.php">See the combos</a>
    </p>
  </section>

</main>

<?php okv_support_widget(); ?>
<?php okv_shop_footer(); ?>
</body>
</html>
