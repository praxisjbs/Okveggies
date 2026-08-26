<?php
/**
 * admin/login.php
 * OK Veggies staff sign in. The Owner and Manager enter here. The credential is
 * the phone number or the email address, plus a password. The form posts to
 * api/v1/auth.php (built in milestone M1). This page renders now so the flow and
 * the design are in place.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in as staff? Go to the dashboard.
if (Rbac::isLoggedIn() && Rbac::isStaff()) {
    okv_redirect('/admin/');
}
$error = $_GET['error'] ?? '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in . OK Veggies</title>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest flex items-center justify-center p-4">
  <div class="w-full max-w-sm bg-white rounded-lg shadow-okv-3 p-8 animate-okv-rise">
    <p class="text-center uppercase tracking-[0.2em] text-gold text-xs font-semibold">OK Veggies</p>
    <h1 class="text-center font-display font-extrabold text-2xl text-ink mt-1">Staff sign in</h1>

    <?php if ($error): ?>
      <div role="alert" class="mt-5 rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3">
        We could not sign you in. Check your details and try again.
      </div>
    <?php endif; ?>

    <form action="/api/v1/auth.php" method="POST" class="mt-6 space-y-4" autocomplete="on">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="login">
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

    <p class="mt-6 text-center text-xs text-ink-40">Forgot your password? Ask the owner to set a new one for you.</p>
  </div>
</body>
</html>
