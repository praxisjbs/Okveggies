<?php
/**
 * public/auth/password_reset.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Reset a password by email code, in two steps: ask for a code,
 * then set a new password with it. Built in milestone M1 Part 2. See docs/PRD.md
 * Section 10.
 *
 * The page never says whether an email is registered. Both steps post to
 * api/v1/auth.php (forgot_password, reset_password). With JavaScript the steps
 * move without a reload and carry the email across; without it, the server
 * redirects between steps and the person re-enters their email with the code.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/shop/brand.php';

// A signed-in customer does not need this page.
if (Customer::isLoggedIn()) {
    okv_redirect('/account.php');
}

$step = (string) okv_input('step', 'email');
if (!in_array($step, ['email', 'code'], true)) {
    $step = 'email';
}
$sent = okv_input('sent', '') !== '';

$messages = [
    'bad_email'      => 'Enter a valid email address.',
    'missing_fields' => 'Enter the code we sent and your new password.',
    'mismatch'       => 'The two new passwords do not match.',
    'weak_password'  => 'Choose a stronger password. Use at least 10 characters.',
    'bad_code'       => 'That code is not right or has expired. Ask for a new one.',
    'rate_limited'   => 'Too many tries. Wait a few minutes and try again.',
    'csrf_expired'   => 'Your session expired. Reload the page and try again.',
];
$errorCode = (string) okv_input('error', '');
$errorText = $errorCode !== '' ? ($messages[$errorCode] ?? 'Something went wrong. Please try again.') : '';
$csrf = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= okv_e($csrf) ?>">
  <title>Reset your password . OK Veggies</title>
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
        <h1 class="font-editorial text-okv-h6 text-ink">Reset your password</h1>

        <!-- Step 1: ask for a code -->
        <section data-okv-panel="email" <?= $step === 'email' ? '' : 'hidden' ?>>
          <p class="text-ink-60 text-sm mt-2">Enter your email and we will send you a 6 digit code.</p>
          <form action="/api/v1/auth.php" method="POST" class="mt-4 space-y-4" data-okv-forgot>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="forgot_password">
            <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $step === 'email') ? '' : ' hidden' ?>><?= okv_e($step === 'email' ? $errorText : '') ?></div>
            <div>
              <label for="fp_email" class="okv-label">Email</label>
              <input id="fp_email" name="email" type="email" required autocomplete="email" class="okv-input" placeholder="you@example.com">
            </div>
            <button type="submit" class="okv-btn w-full">Send the code</button>
          </form>
        </section>

        <!-- Step 2: enter the code and a new password -->
        <section data-okv-panel="code" <?= $step === 'code' ? '' : 'hidden' ?>>
          <div data-okv-notice role="status" aria-live="polite" class="rounded-md bg-foliage-tint text-forest text-sm px-4 py-3 mt-3"<?= $sent ? '' : ' hidden' ?>>If that email is registered, we have sent a reset code. Check your inbox.</div>
          <p class="text-ink-60 text-sm mt-3">Enter the code and choose a new password.</p>
          <form action="/api/v1/auth.php" method="POST" class="mt-4 space-y-4" data-okv-reset>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reset_password">
            <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $step === 'code') ? '' : ' hidden' ?>><?= okv_e($step === 'code' ? $errorText : '') ?></div>
            <div>
              <label for="rp_email" class="okv-label">Email</label>
              <input id="rp_email" name="email" type="email" required autocomplete="email" class="okv-input" placeholder="you@example.com" data-okv-reset-email>
            </div>
            <div>
              <label for="rp_code" class="okv-label">Your 6 digit code</label>
              <input id="rp_code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" required class="okv-input font-mono tracking-[0.4em]" placeholder="000000">
            </div>
            <div>
              <label for="rp_new" class="okv-label">New password</label>
              <input id="rp_new" name="new_password" type="password" required autocomplete="new-password" class="okv-input" placeholder="At least 10 characters">
            </div>
            <div>
              <label for="rp_confirm" class="okv-label">Confirm new password</label>
              <input id="rp_confirm" name="confirm_password" type="password" required autocomplete="new-password" class="okv-input" placeholder="Type it again">
            </div>
            <button type="submit" class="okv-btn w-full">Save new password</button>
          </form>
        </section>
      </div>
      <p class="text-center text-sm text-ink-60 mt-6"><a href="/account.php?mode=signin" class="okv-btn-text">Back to sign in</a></p>
    </div>
  </div>

  <script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
  <script src="<?= okv_e(okv_asset('/assets/js/account.min.js')) ?>" defer></script>
</body>
</html>
