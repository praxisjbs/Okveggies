<?php
/** Published M12 information and legal pages. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/activation_banner.php';
require_once __DIR__ . '/includes/components/shop/brand.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';
require_once __DIR__ . '/includes/components/shop/faq_disclosures.php';

$slug = trim((string) okv_input('slug', ''));
$publicSlugs = ['about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'];
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

// Apache handles this redirect in production. Keeping the same rule here also
// makes legacy links canonical under the PHP development server.
if ($requestPath === '/page.php' && ContentPages::isSupportedSlug($slug)) {
    $legacyTarget = ContentPages::canonicalPath($slug);
    if ($legacyTarget !== null && $legacyTarget !== '/') {
        okv_redirect($legacyTarget, 301);
    }
}

$page = null;
$databaseFailed = false;
if (in_array($slug, $publicSlugs, true)) {
    try {
        $page = ContentPages::findPublished($slug);
    } catch (Throwable $e) {
        error_log('content.public.read failed: ' . $e->getMessage());
        $databaseFailed = true;
    }
}
$faqItems = [];
if ($page !== null && trim((string) $page['title']) === '') {
    $page = null;
}
if ($page !== null && $slug !== 'faq' && trim((string) $page['body']) === '') {
    $page = null;
}
if ($page !== null && $slug === 'faq') {
    try {
        $faqItems = FaqContent::present((string) $page['body']);
    } catch (Throwable $e) {
        error_log('content.faq.operations failed: ' . $e->getMessage());
        $databaseFailed = true;
        $page = null;
    }
}

if ($databaseFailed || $page === null) {
    $status = $databaseFailed ? 503 : 404;
    http_response_code($status);
    if ($databaseFailed) {
        header('Retry-After: 300');
    }
    header('X-Robots-Tag: noindex, nofollow');
    $errorTitle = $databaseFailed ? 'Page temporarily unavailable' : 'Page not found';
    $errorHeading = $databaseFailed ? 'We cannot open that page just now' : 'That page is not on the stall';
    $errorCopy = $databaseFailed
        ? 'The content store is not responding. Nothing has been replaced with old or placeholder copy. Please try again shortly.'
        : 'The page may be unpublished, may have moved, or the address may be wrong. You can keep browsing from here.';
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($errorTitle) ?>. OK Veggies</title>
  <meta name="description" content="<?= okv_e($errorCopy) ?>">
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(['og_title' => $errorTitle . '. OK Veggies', 'og_description' => $errorCopy]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint text-ink">
<?php okv_activation_banner(); ?>
<?php okv_shop_header(); ?>
<main class="okv-container py-10 md:py-16">
  <nav class="mb-6 flex min-h-[44px] items-center gap-2 text-sm text-ink-60" aria-label="Breadcrumb">
    <a href="/" class="inline-flex min-h-[44px] items-center font-medium hover:text-forest">Home</a>
    <span aria-hidden="true">/</span><span aria-current="page"><?= okv_e($errorTitle) ?></span>
  </nav>
  <section class="rounded-xl bg-white p-6 text-center shadow-okv-1 md:p-10">
    <?php okv_seal(120, 'mx-auto', ''); ?>
    <p class="okv-eyebrow mt-6"><?= $databaseFailed ? 'Please try again' : 'Page not found' ?></p>
    <h1 class="mx-auto mt-3 max-w-2xl font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($errorHeading) ?></h1>
    <p class="mx-auto mt-4 max-w-xl leading-7 text-ink-60"><?= okv_e($errorCopy) ?></p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
      <a href="/shop.php" class="okv-btn">Browse the shop</a>
      <a href="/combos.php" class="okv-btn-outline">See the combos</a>
      <a href="/contact.php" class="okv-btn-text min-h-[44px]">Contact us</a>
    </div>
  </section>
</main>
<?php okv_shop_footer([]); ?>
</body>
</html><?php
    exit;
}

$rendered = $slug === 'faq'
    ? ['html' => '', 'headings' => []]
    : ContentRenderer::render((string) $page['body']);
$visibleTitle = (string) $page['title'];
$seoTitle = trim((string) $page['meta_title']) !== '' ? trim((string) $page['meta_title']) : $visibleTitle;
$description = trim((string) $page['meta_description']);
if ($description === '') {
    $description = $slug === 'faq' && $faqItems
        ? ContentRenderer::plainText($faqItems[0]['question'] . ' ' . $faqItems[0]['answer'], 155)
        : ContentRenderer::plainText((string) $page['body'], 155);
}
if ($description === '' && $slug === 'faq') {
    $description = 'Answers about shopping, delivery, payment and support from OK Veggies.';
}
$documentTitle = $seoTitle . '. OK Veggies';
$canonical = rtrim((string) APP_URL, '/') . (string) $page['canonical_path'];
$ogImage = trim((string) $page['image_url']) !== ''
    ? rtrim((string) APP_URL, '/') . okv_image_url((string) $page['image_url'])
    : '';
$isStory = $slug === 'about';
$isHow = $slug === 'how-it-works';
$isFaq = $slug === 'faq';
$isLegal = in_array($slug, ['terms', 'privacy', 'delivery-policy'], true);
$eyebrow = match ($slug) {
    'about' => 'Our story',
    'how-it-works' => 'From list to doorstep',
    'faq' => 'Questions and answers',
    'terms' => 'Legal information',
    'privacy' => 'Your information',
    'delivery-policy' => 'Delivery and customer care',
};
$actions = match ($slug) {
    'about' => [['/shop.php', 'Browse the shop', 'primary'], ['/combos.php', 'See the combos', 'outline'], ['/contact.php', 'Contact us', 'text']],
    'how-it-works' => [['/shop.php', 'Start shopping', 'primary'], ['/kitchen-runs.php', 'Send a Kitchen Run', 'outline']],
    'faq' => [['/contact.php', 'Send us a message', 'primary'], [okv_support_whatsapp_url(), 'Chat on WhatsApp', 'outline']],
    default => [['/shop.php', 'Back to the shop', 'primary'], ['/contact.php', 'Ask us a question', 'outline']],
};
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($documentTitle) ?></title>
  <meta name="description" content="<?= okv_e($description) ?>">
  <link rel="canonical" href="<?= okv_e($canonical) ?>">
  <meta property="og:url" content="<?= okv_e($canonical) ?>">
  <?php okv_head_meta(['og_title' => $documentTitle, 'og_description' => $description, 'og_image' => $ogImage]); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint text-ink">
<?php okv_activation_banner(); ?>
<?php okv_shop_header(); ?>
<main>
  <div class="okv-container py-6 md:py-10">
    <nav class="mb-6 flex min-h-[44px] items-center gap-2 text-sm text-ink-60" aria-label="Breadcrumb">
      <a href="/" class="inline-flex min-h-[44px] items-center font-medium hover:text-forest">Home</a>
      <span aria-hidden="true">/</span><span aria-current="page"><?= okv_e($visibleTitle) ?></span>
    </nav>

    <?php if ($isStory): ?>
      <article>
        <header class="grid overflow-hidden rounded-xl bg-white shadow-okv-2 lg:grid-cols-12">
          <div class="p-6 md:p-10 lg:col-span-6 lg:flex lg:flex-col lg:justify-center">
            <p class="okv-eyebrow"><?= okv_e($eyebrow) ?></p>
            <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h2"><?= okv_e($visibleTitle) ?></h1>
            <p class="mt-5 text-okv-lead text-ink-60">Fresh produce begins with people, places and work we can stand behind.</p>
          </div>
          <figure class="bg-forest-tint lg:col-span-6">
            <?php if ((string) $page['image_url'] !== ''): ?>
              <img src="<?= okv_e(okv_image_url((string) $page['image_url'])) ?>" alt="<?= okv_e((string) $page['image_alt']) ?>" class="h-full min-h-80 w-full object-cover" fetchpriority="high">
            <?php else: ?>
              <div class="flex min-h-80 flex-col items-center justify-center p-8 text-center">
                <?php okv_seal(160, '', ''); ?>
                <p class="mt-5 max-w-sm text-sm font-semibold text-forest">Approved documentary photograph pending</p>
                <p class="mt-2 max-w-sm text-sm text-ink-60">This space is reserved for a rights-cleared photograph of the real OK Veggies operation.</p>
              </div>
            <?php endif; ?>
          </figure>
        </header>
        <div class="mx-auto max-w-3xl py-10 md:py-16" data-content-body><?= $rendered['html'] ?></div>
      </article>
    <?php elseif ($isHow): ?>
      <article class="rounded-xl bg-white p-6 shadow-okv-1 md:p-10">
        <header class="max-w-3xl border-b border-mist pb-8">
          <p class="okv-eyebrow"><?= okv_e($eyebrow) ?></p>
          <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($visibleTitle) ?></h1>
        </header>
        <div class="max-w-3xl pt-3" data-content-body><?= $rendered['html'] ?></div>
        <?php require __DIR__ . '/includes/components/shop/make_it_right_guidance.php'; ?>
      </article>
    <?php elseif ($isFaq): ?>
      <article class="mx-auto max-w-4xl rounded-xl bg-white p-6 shadow-okv-1 md:p-10">
        <header class="border-b border-mist pb-8">
          <p class="okv-eyebrow"><?= okv_e($eyebrow) ?></p>
          <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($visibleTitle) ?></h1>
          <p class="mt-4 max-w-2xl leading-7 text-ink-60">Open a question for the answer. If yours is not here, send us a message or chat with us on WhatsApp.</p>
        </header>
        <?php if ($faqItems): ?>
          <div class="mt-6"><?php okv_faq_disclosures($faqItems); ?></div>
        <?php else: ?>
          <section class="mt-8 rounded-lg bg-forest-tint p-6 text-center" aria-labelledby="faq-empty-heading">
            <h2 id="faq-empty-heading" class="font-editorial text-okv-h6 text-ink">We are preparing these answers</h2>
            <p class="mx-auto mt-3 max-w-xl leading-7 text-ink-60">There are no published questions just now. Tell us what you need and we will help directly.</p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
              <a class="okv-btn" href="/contact.php">Contact us</a>
              <a class="okv-btn-outline" href="<?= okv_e(okv_support_whatsapp_url()) ?>" target="_blank" rel="noopener noreferrer">Chat on WhatsApp</a>
            </div>
          </section>
        <?php endif; ?>
        <?php if ($faqItems): ?><aside class="mt-8 border-t border-mist pt-6">
          <h2 class="font-editorial text-okv-h6 text-ink">Still need a hand?</h2>
          <p class="mt-2 text-ink-60">Send the details through our contact form or start a WhatsApp chat.</p>
          <div class="mt-5 flex flex-wrap gap-3"><a class="okv-btn" href="/contact.php">Contact us</a><a class="okv-btn-outline" href="<?= okv_e(okv_support_whatsapp_url()) ?>" target="_blank" rel="noopener noreferrer">Chat on WhatsApp</a></div>
        </aside><?php endif; ?>
      </article>
    <?php elseif ($isLegal): ?>
      <article class="mx-auto max-w-4xl rounded-xl bg-white p-6 shadow-okv-1 md:p-10">
        <header class="border-b border-mist pb-8">
          <p class="okv-eyebrow"><?= okv_e($eyebrow) ?></p>
          <h1 class="mt-3 font-editorial text-okv-h4 text-ink md:text-okv-h3"><?= okv_e($visibleTitle) ?></h1>
          <p class="mt-4 max-w-2xl text-sm leading-6 text-ink-60">Read this page carefully. If anything is unclear, contact us before placing an order.</p>
        </header>
        <?php $levelTwo = array_values(array_filter($rendered['headings'], static fn(array $heading): bool => $heading['level'] === 2)); ?>
        <?php if (count($levelTwo) >= 2): ?>
          <nav class="mt-8 rounded-lg bg-forest-tint p-5" aria-label="On this page">
            <h2 class="font-semibold text-ink">On this page</h2>
            <ul class="mt-2 grid gap-1 sm:grid-cols-2">
              <?php foreach ($levelTwo as $heading): ?><li><a class="inline-flex min-h-[44px] items-center font-semibold text-forest underline underline-offset-2" href="#<?= okv_e($heading['id']) ?>"><?= okv_e($heading['text']) ?></a></li><?php endforeach; ?>
            </ul>
          </nav>
        <?php endif; ?>
        <div class="max-w-3xl pt-3" data-content-body><?= $rendered['html'] ?></div>
        <?php if ($slug === 'delivery-policy'): require __DIR__ . '/includes/components/shop/make_it_right_guidance.php'; endif; ?>
      </article>
    <?php endif; ?>

    <aside class="mx-auto mt-10 max-w-4xl rounded-xl bg-forest p-6 text-white md:p-8" aria-labelledby="content-next-heading">
      <p class="okv-eyebrow-invert">Keep going</p>
      <h2 id="content-next-heading" class="mt-2 font-editorial text-okv-h6 text-white">What would you like to do next?</h2>
      <div class="mt-5 flex flex-wrap gap-3">
        <?php foreach ($actions as [$href, $label, $style]): ?>
          <a href="<?= okv_e($href) ?>" <?= str_starts_with($href, 'https://') ? 'target="_blank" rel="noopener noreferrer"' : '' ?> class="<?= $style === 'primary' ? 'okv-btn bg-white text-forest hover:bg-forest-tint' : ($style === 'outline' ? 'inline-flex min-h-[44px] items-center justify-center rounded-md border border-white px-5 font-semibold text-white hover:bg-white/10' : 'inline-flex min-h-[44px] items-center px-3 font-semibold text-white underline underline-offset-4') ?>"><?= okv_e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </aside>
  </div>
</main>
<?php okv_shop_footer(); ?>
<?php if ($isFaq): ?><script src="<?= okv_e(okv_asset('/assets/js/faq.min.js')) ?>" defer></script><?php endif; ?>
</body>
</html>
