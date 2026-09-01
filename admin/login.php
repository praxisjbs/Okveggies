<?php
/**
 * admin/login.php
 * OK Veggies staff sign in. The Owner and Manager enter here. The credential is
 * the phone number or the email address, plus a password. The form posts to
 * api/v1/auth.php. With JavaScript it posts by fetch and shows any error in
 * place; without JavaScript the server sends a plain redirect back here with a
 * short marker. Either way the person only ever sees a plain message.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in as staff? Go to the dashboard.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    okv_redirect('/admin/');
}

// The no-JS redirect path lands back here with ?error=<code>. Map each code to a
// plain message. Anything unexpected falls back to the general one.
$errorMessages = [
    'invalid_credentials' => 'We could not sign you in. Check your details and try again.',
    'inactive'            => 'This account is not active. Ask the owner to switch it back on.',
    'rate_limited'        => 'Too many tries. Wait a few minutes and try again.',
    'missing_fields'      => 'Enter your phone or email and your password.',
    'csrf_expired'        => 'Your session expired. Reload the page and try again.',
    'expired'             => 'Your session expired. Reload the page and try again.',
];
$errorCode = (string) ($_GET['error'] ?? '');
$errorText = $errorCode !== ''
    ? ($errorMessages[$errorCode] ?? 'We could not sign you in. Check your details and try again.')
    : '';
// Landed here straight after a successful password reset.
$resetDone = isset($_GET['reset']);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in . OK Veggies</title>
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest flex items-center justify-center p-4">
  <div class="w-full max-w-sm bg-white rounded-lg shadow-okv-3 p-8 animate-okv-rise">
    <!-- Sign in has room for the full seal to read, so it gets the seal rather
         than the small lockup (bible 3.4 and the minimum sizes in 3.3). -->
    <img src="<?= okv_e(okv_asset('/assets/img/brand/seal-320.png')) ?>" alt="OK Veggies"
         width="128" height="128" class="mx-auto h-32 w-32">
    <h1 class="text-center font-display font-extrabold text-2xl text-ink mt-4">Staff sign in</h1>
    <p class="text-center text-sm text-ink-60 mt-1">The Owner and the Manager enter here.</p>

    <form action="/api/v1/auth.php" method="POST" class="mt-6 space-y-4" autocomplete="on" data-okv-auth id="okv-login-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="login">

      <div role="status" aria-live="polite"
           class="okv-note-ok"<?= $resetDone ? '' : ' hidden' ?>>Your password is changed. Please sign in with it.</div>

      <div data-okv-error role="alert" aria-live="polite"
           class="okv-note-bad"<?= $errorText === '' ? ' hidden' : '' ?>><?= okv_e($errorText) ?></div>

      <div>
        <label for="identifier" class="okv-label">Phone number or email</label>
        <input id="identifier" name="identifier" type="text" required autofocus autocomplete="username"
               class="okv-input" placeholder="080... or you@okveggies.com.ng">
      </div>
      <div>
        <label for="password" class="okv-label">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               class="okv-input" placeholder="Your password">
      </div>
      <button type="submit" class="okv-btn w-full">Sign in</button>
    </form>

    <p class="mt-6 text-center text-sm"><a href="/admin/password_reset.php" class="okv-btn-text">Forgot your password?</a></p>
    <p class="mt-2 text-center text-xs"><a href="/" class="text-forest underline underline-offset-4">Back to the shop</a></p>
  </div>
  <script src="<?= okv_e(okv_asset('/assets/js/auth.js')) ?>" defer></script>
</body>
</html>
