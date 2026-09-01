<?php
/**
 * public/auth/activate.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Enter the 6 digit code we emailed, to activate the account.
 * Activation is what a pay-on-delivery order checks for later. Built in
 * milestone M1 Part 2. See docs/PRD.md Section 10.
 *
 * For a signed-in customer only. The code is verified through api/v1/otp.php.
 * With JavaScript the code box posts by fetch and a resend button shows a short
 * cooldown; without it, the same forms post and the server redirects.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/shop/brand.php';

Customer::requireLogin();
if (Customer::isActivated()) {
    okv_redirect('/account.php');
}

$me    = Customer::current();
$email = (string) ($me['email'] ?? '');

$messages = [
    'bad_code'      => 'That code is not right or has expired. Ask for a new one.',
    'missing_code'  => 'Enter the 6 digit code we sent you.',
    'rate_limited'  => 'Too many tries. Wait a few minutes and try again.',
    'cooldown'      => 'Please wait a moment before asking for another code.',
    'mail_failed'   => 'We could not send the code right now. Please try again in a moment.',
    'csrf_expired'  => 'Your session expired. Reload the page and try again.',
];
$errorCode = (string) okv_input('error', '');
$errorText = $errorCode !== '' ? ($messages[$errorCode] ?? 'Something went wrong. Please try again.') : '';
$justSent  = okv_input('sent', '') !== '';

// Mask the email a little for display: a***@domain.
$maskedEmail = $email;
if (strpos($email, '@') !== false) {
    [$u, $d] = explode('@', $email, 2);
    $maskedEmail = (mb_substr($u, 0, 1) . str_repeat('*', max(1, mb_strlen($u) - 1))) . '@' . $d;
}
$csrf = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= okv_e($csrf) ?>">
  <title>Activate your account . OK Veggies</title>
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-forest-tint min-h-screen text-ink">
  <div class="min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <a href="/" class="inline-block rounded-md" aria-label="OK Veggies, home">
          <?php okv_seal(120, 'mx-auto', 'The OK Veggies seal'); ?>
        </a>
        <p class="mt-4 text-sm text-ink-60">Sourced right. Priced right. Delivered right.</p>
      </div>
      <div class="bg-white rounded-lg shadow-okv-3 p-6 sm:p-8 animate-okv-rise">
        <h1 class="font-editorial text-okv-h6 text-ink">Activate your account</h1>
        <p class="text-ink-60 text-sm mt-2">We sent a 6 digit code to <span class="font-medium text-ink"><?= okv_e($maskedEmail) ?></span>. Enter it below. Activation lets you pay on delivery.</p>

        <div data-okv-notice role="status" aria-live="polite" class="rounded-md bg-foliage-tint text-forest text-sm px-4 py-3 mt-4"<?= $justSent ? '' : ' hidden' ?>>We have sent a new code to your email.</div>

        <form action="/api/v1/otp.php" method="POST" class="mt-4 space-y-4" data-okv-verify>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="verify">
          <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= $errorText === '' ? ' hidden' : '' ?>><?= okv_e($errorText) ?></div>
          <div>
            <label for="code" class="okv-label">Your 6 digit code</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" required
                   class="okv-input text-center tracking-[0.5em] font-mono text-lg" placeholder="000000" autofocus>
          </div>
          <button type="submit" class="okv-btn w-full">Activate</button>
        </form>

        <form action="/api/v1/otp.php" method="POST" class="mt-4 text-center" data-okv-resend>
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="request">
          <p class="text-sm text-ink-60">Did not get the code?
            <button type="submit" class="okv-btn-text align-baseline" data-okv-resend-btn>Send a new one</button>
          </p>
        </form>
      </div>
      <p class="text-center text-sm text-ink-60 mt-6">
        <a href="/account.php" class="okv-btn-text">Skip for now</a>
      </p>
    </div>
  </div>

  <script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
  <script src="<?= okv_e(okv_asset('/assets/js/account.min.js')) ?>" defer></script>
</body>
</html>
