<?php
/** No-JavaScript contact route and full-page support form. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/components/shop/header.php';
require_once __DIR__ . '/includes/components/shop/footer.php';

$errors = [
    'name_required' => 'Enter your name.',
    'name_too_long' => 'Keep your name to 150 characters or fewer.',
    'bad_email' => 'Enter a valid email address.',
    'bad_phone' => 'Enter a valid Nigerian phone number, for example 0803 000 0000.',
    'contact_required' => 'Enter an email address or phone number so we can reply.',
    'subject_too_long' => 'Keep the subject to 200 characters or fewer.',
    'message_required' => 'Tell us how we can help.',
    'message_too_long' => 'Keep your message to 5,000 characters or fewer.',
    'rate_limited' => 'Too many messages were sent. Wait a while and try again.',
    'invalid_submission' => 'This form is no longer valid. Reload the page and try again.',
    'spam_rejected' => 'We could not accept that message. Reload the page and try again.',
    'too_fast' => 'Please check your message, then send it again.',
    'csrf_expired' => 'Your session expired. Reload the page and try again.',
    'duplicate' => 'That message was already received.',
    'failed' => 'We could not save your message. Please try again.',
];
$errorCode = trim((string) okv_input('error', ''));
$sent = okv_input('sent', '') === '1';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Contact us . OK Veggies</title>
  <?php okv_head_meta(['og_title' => 'Contact OK Veggies']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint text-ink">
<?php okv_shop_header(); ?>
<main class="okv-container py-8 md:py-12">
  <div class="mx-auto max-w-2xl">
    <a href="/" class="okv-btn-text"><span aria-hidden="true">&larr;</span> Back to the shop</a>
    <p class="okv-eyebrow mt-6">Support</p>
    <h1 class="mt-2 font-editorial text-okv-h4 text-ink">Contact us</h1>
    <p class="mt-3 text-ink-60">Send us a message here, or open WhatsApp if that is easier.</p>
    <a href="<?= okv_e(okv_support_whatsapp_url()) ?>" class="okv-btn-outline mt-5" target="_blank" rel="noopener">Chat on WhatsApp</a>

    <section class="okv-panel mt-6 p-5 md:p-8" aria-labelledby="contact-form-heading">
      <h2 id="contact-form-heading" class="font-editorial text-okv-h6 text-ink">Send a message</h2>
      <?php if ($errorCode !== ''): ?>
        <p class="okv-note mt-4 bg-clay-tint" role="alert"><?= okv_e($errors[$errorCode] ?? $errors['failed']) ?></p>
      <?php endif; ?>
      <?php if ($sent): ?>
        <section class="mt-4 rounded-md bg-foliage-tint p-5 text-center" role="status">
          <h3 class="font-editorial text-okv-h6 text-ink">We have your message</h3>
          <p class="mt-2 text-sm text-ink-60">Thank you. A member of our team will reply using the details you provided.</p>
          <a href="/shop.php" class="okv-btn-outline mt-4">Back to the shop</a>
        </section>
      <?php else: ?>
        <div class="mt-5"><?php okv_contact_form('contact_page'); ?></div>
      <?php endif; ?>
    </section>
  </div>
</main>
<?php okv_shop_footer(); ?>
</body>
</html>
