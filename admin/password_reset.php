<?php
/**
 * admin/password_reset.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Reset a staff password by email code, in two steps: ask for a
 * code, then set a new password with it. This is the staff twin of the customer
 * page at public/auth/password_reset.php. See docs/PRD.md Section 10.4.
 *
 * It never says whether an email belongs to a staff account. Both steps post to
 * api/v1/auth.php (staff_forgot_password, staff_reset_password). With JavaScript
 * the steps move without a reload and carry the email across; without it, the
 * server redirects between steps and the person re-enters their email with the
 * code. A successful reset signs out every other open session for the account
 * and sends the person to the staff sign in page.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in as staff? There is nothing to reset here.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    okv_redirect('/admin/');
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
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= okv_e($csrf) ?>">
  <title>Reset your password . OK Veggies</title>
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest flex items-center justify-center p-4">
  <div class="w-full max-w-sm bg-white rounded-lg shadow-okv-3 p-8 animate-okv-rise">
    <p class="text-center uppercase tracking-[0.2em] text-gold text-xs font-semibold">OK Veggies</p>
    <h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">Reset your password</h1>

    <!-- Step 1: ask for a code -->
    <section data-okv-panel="email" <?= $step === 'email' ? '' : 'hidden' ?>>
      <p class="text-ink-60 text-sm mt-2 text-center">Enter your staff email and we will send you a 6 digit code.</p>
      <form action="/api/v1/auth.php" method="POST" class="mt-6 space-y-4" data-okv-forgot>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="staff_forgot_password">
        <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $step === 'email') ? '' : ' hidden' ?>><?= okv_e($step === 'email' ? $errorText : '') ?></div>
        <div>
          <label for="fp_email" class="okv-label">Email</label>
          <input id="fp_email" name="email" type="email" required autofocus autocomplete="email" class="okv-input" placeholder="you@okveggies.com.ng">
        </div>
        <button type="submit" class="okv-btn w-full">Send the code</button>
      </form>
    </section>

    <!-- Step 2: enter the code and a new password -->
    <section data-okv-panel="code" <?= $step === 'code' ? '' : 'hidden' ?>>
      <div data-okv-notice role="status" aria-live="polite" class="rounded-md bg-foliage-tint text-forest text-sm px-4 py-3 mt-3"<?= $sent ? '' : ' hidden' ?>>If that email belongs to a staff account, we have sent a reset code. Check your inbox.</div>
      <p class="text-ink-60 text-sm mt-3 text-center">Enter the code and choose a new password.</p>
      <form action="/api/v1/auth.php" method="POST" class="mt-6 space-y-4" data-okv-reset>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="staff_reset_password">
        <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $step === 'code') ? '' : ' hidden' ?>><?= okv_e($step === 'code' ? $errorText : '') ?></div>
        <div>
          <label for="rp_email" class="okv-label">Email</label>
          <input id="rp_email" name="email" type="email" required autocomplete="email" class="okv-input" placeholder="you@okveggies.com.ng" data-okv-reset-email>
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

    <p class="mt-6 text-center text-sm"><a href="/admin/login.php" class="okv-btn-text">Back to sign in</a></p>
  </div>
  <script src="<?= okv_e(okv_asset('/assets/js/auth.js')) ?>" defer></script>
</body>
</html>
