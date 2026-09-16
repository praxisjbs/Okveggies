<?php
/** Published customer information pages. Full content editing remains M12. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/support_widget.php';

$slug = trim((string) okv_input('slug', ''));
$page = $slug === '' ? null : Database::one(
    'SELECT slug, title, body FROM content_pages WHERE slug = :slug AND is_published = :published LIMIT 1',
    [':slug' => $slug, ':published' => 1]
);
if ($page === null) {
    http_response_code(404);
}
$title = $page === null ? 'Page not found' : (string) $page['title'];
$description = $page === null
    ? 'We could not find that page.'
    : mb_substr(trim(preg_replace('/\s+/', ' ', (string) $page['body'])), 0, 155);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($title) ?>. OK Veggies</title>
  <meta name="description" content="<?= okv_e($description) ?>">
  <?php if ($page === null): ?><meta name="robots" content="noindex"><?php endif; ?>
  <?php okv_head_meta(['og_title' => $title, 'og_description' => $description]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint">
<?php okv_shop_header(); ?>
<main class="okv-container py-10 md:py-16">
  <nav class="mb-6 text-sm text-ink-60" aria-label="Breadcrumb">
    <a href="/" class="inline-flex min-h-[44px] items-center hover:text-forest">Home</a> <span aria-hidden="true">/</span>
    <span aria-current="page"><?= okv_e($title) ?></span>
  </nav>
  <?php if ($page === null): ?>
    <section class="rounded-lg bg-white p-8 text-center shadow-okv-1">
      <h1 class="font-display text-3xl font-extrabold text-ink">We could not find that page</h1>
      <p class="mt-3 text-ink-60">The page may have moved. Return to the shop to keep browsing.</p>
      <a href="/shop.php" class="okv-btn mt-6 px-4">Back to the shop</a>
    </section>
  <?php else: ?>
    <article class="mx-auto max-w-3xl rounded-lg bg-white p-6 shadow-okv-1 md:p-10">
      <p class="okv-eyebrow">OK Veggies</p>
      <h1 class="mt-3 font-display text-3xl font-extrabold text-ink md:text-4xl"><?= okv_e($title) ?></h1>
      <?php foreach (preg_split('/\R{2,}/', trim((string) $page['body'])) ?: [] as $paragraph): ?>
        <p class="mt-5 whitespace-pre-line leading-7 text-ink-60"><?= okv_e($paragraph) ?></p>
      <?php endforeach; ?>

      <?php if ((string) $page['slug'] === 'delivery-policy'): ?>
        <section class="mt-10 border-t border-mist pt-8" id="make-it-right" tabindex="-1" aria-labelledby="make-it-right-policy-heading">
          <h2 id="make-it-right-policy-heading" class="font-display text-2xl font-bold text-ink">If something is not right</h2>
          <p class="mt-4 leading-7 text-ink-60">After an order is dispatched or delivered, the signed-in customer can report a wrong item, missing item, quality problem, short quantity, damage or late delivery from that order.</p>
          <p class="mt-4 leading-7 text-ink-60">Send the report within <?= (int) IssueReports::reportingWindowDays() ?> days. Add a clear description and up to <?= (int) IssueReports::MAX_PHOTOS ?> optional photos. Our team reviews it and records a refund, account credit, replacement or a plain reason if the report cannot be approved.</p>
          <p class="mt-4 leading-7 text-ink-60">The report and its outcome stay on your signed-in order. They are never shown on the shareable Order Trail.</p>
          <?php if (Customer::isLoggedIn()): ?>
            <a class="okv-btn-outline mt-6 px-4" href="/account.php">Open your orders</a>
          <?php else: ?>
            <a class="okv-btn-outline mt-6 px-4" href="/account.php?mode=signin">Sign in to open an order</a>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </article>
  <?php endif; ?>
</main>
<?php okv_shop_footer(); ?>
<?php okv_support_widget(); ?>
</body>
</html>
