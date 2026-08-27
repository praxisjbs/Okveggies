<?php
/**
 * account.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The customer account. Signed out, this is sign in, create account
 * and the link to reset a password. Signed in, it is a simple home: your
 * details, your addresses, and a place your orders will appear. Built in
 * milestone M1 Part 2. See docs/PRD.md Section 10.
 *
 * Forms work without JavaScript (a plain post gets a real redirect). With
 * JavaScript, assets/js/account.js posts by fetch, shows errors in place, and
 * handles the account area without full page reloads.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/bootstrap.php';

$signedIn = Customer::isLoggedIn();

// The no-JS post paths land back here with ?error=<code>. Map each to a plain
// message. Anything unexpected falls back to the general one.
$errorMessages = [
    'invalid_credentials' => 'We could not sign you in. Check your details and try again.',
    'inactive'            => 'This account is not active. Please contact us so we can help.',
    'rate_limited'        => 'Too many tries. Wait a few minutes and try again.',
    'missing_fields'      => 'Enter your phone or email and your password.',
    'missing_name'        => 'Enter your first and last name.',
    'bad_email'           => 'Enter a valid email address.',
    'bad_phone'           => 'Enter a valid phone number, for example 0803 000 0000.',
    'weak_password'       => 'Choose a stronger password. Use at least 10 characters.',
    'missing_business'    => 'Enter your business name.',
    'account_exists'      => 'You already have an account with these details. Please sign in.',
    'register_failed'     => 'We could not create your account. Please try again.',
    'csrf_expired'        => 'Your session expired. Reload the page and try again.',
];
$errorCode = (string) okv_input('error', '');
$errorText = $errorCode !== '' ? ($errorMessages[$errorCode] ?? 'Something went wrong. Please try again.') : '';

$csrf = Csrf::token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= okv_e($csrf) ?>">
  <title><?= $signedIn ? 'Your account' : 'Sign in or create an account' ?> . OK Veggies</title>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-forest-tint min-h-screen text-ink">
<?php if ($signedIn):
    require_once __DIR__ . '/includes/components/shop/activation_banner.php';
    okv_activation_banner();

    $me  = Customer::current();
    $biz = Customer::isBusiness()
        ? Database::one('SELECT business_name, business_type, credit_status FROM business_customers WHERE user_id = :u', [':u' => Customer::id()])
        : null;
    $addresses = Database::all(
        'SELECT id, label, recipient_name, recipient_phone, address_line_1, address_line_2, city, state, landmark, is_default
           FROM customer_addresses WHERE user_id = :u ORDER BY is_default DESC, id DESC',
        [':u' => Customer::id()]
    );
    $firstName = (string) ($me['first_name'] ?? '');
?>
  <!-- ============================ SIGNED-IN HOME ============================ -->
  <header class="bg-white border-b border-mist">
    <div class="okv-container flex items-center justify-between py-4">
      <a href="/" class="okv-btn-text" aria-label="Back to the shop">
        <span aria-hidden="true">&larr;</span> Shop
      </a>
      <p class="font-display font-extrabold text-forest">OK Veggies</p>
      <form action="/api/v1/auth.php" method="POST" class="m-0">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="context" value="storefront">
        <button type="submit" class="okv-btn-text" data-okv-logout>Sign out</button>
      </form>
    </div>
  </header>

  <main class="okv-container py-8 md:py-12">
    <p class="uppercase tracking-[0.2em] text-gold text-xs font-semibold">Your account</p>
    <h1 class="font-display font-extrabold text-2xl md:text-3xl mt-1">
      Hello, <?= okv_e($firstName !== '' ? $firstName : 'there') ?>
    </h1>

    <div class="grid gap-6 lg:grid-cols-3 mt-8">
      <!-- Profile -->
      <section class="okv-card lg:col-span-1" aria-labelledby="profile-h">
        <div class="flex items-center justify-between">
          <h2 id="profile-h" class="font-display font-bold text-lg">Your details</h2>
          <button type="button" class="okv-btn-text" data-okv-open="profile-sheet">Edit</button>
        </div>
        <dl class="mt-4 space-y-3 text-sm">
          <div><dt class="text-ink-40">Name</dt><dd class="mt-0.5" data-field="name"><?= okv_e(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))) ?></dd></div>
          <div><dt class="text-ink-40">Email</dt><dd class="mt-0.5 break-words"><?= okv_e($me['email'] ?? '') ?>
            <?php if (Customer::isActivated()): ?>
              <span class="okv-badge okv-badge-available ml-1">Activated</span>
            <?php else: ?>
              <a href="/public/auth/activate.php" class="okv-badge okv-badge-out ml-1">Activate</a>
            <?php endif; ?>
          </dd></div>
          <div><dt class="text-ink-40">Phone</dt><dd class="mt-0.5" data-field="phone"><?= okv_e(Phone::display($me['phone'] ?? '')) ?></dd></div>
          <div><dt class="text-ink-40">Account</dt><dd class="mt-0.5"><?= Customer::isBusiness() ? 'Business' : 'Household' ?></dd></div>
          <?php if ($biz): ?>
            <div><dt class="text-ink-40">Business</dt><dd class="mt-0.5"><?= okv_e($biz['business_name']) ?><?= $biz['business_type'] ? ' (' . okv_e($biz['business_type']) . ')' : '' ?></dd></div>
          <?php endif; ?>
        </dl>
      </section>

      <!-- Orders (placeholder until M4) -->
      <section class="okv-card lg:col-span-2" aria-labelledby="orders-h">
        <h2 id="orders-h" class="font-display font-bold text-lg">Your orders</h2>
        <div class="mt-4 rounded-md bg-forest-tint p-6 text-center">
          <p class="text-ink-60">You have not placed an order yet.</p>
          <a href="/shop.php" class="okv-btn mt-4">Start shopping</a>
        </div>
      </section>

      <!-- Addresses -->
      <section class="okv-card lg:col-span-3" aria-labelledby="addr-h">
        <div class="flex items-center justify-between">
          <h2 id="addr-h" class="font-display font-bold text-lg">Delivery addresses</h2>
          <button type="button" class="okv-btn-outline min-h-[40px] px-4" data-okv-add-address>Add address</button>
        </div>
        <div id="okv-address-list" class="mt-4 grid gap-4 sm:grid-cols-2" data-empty-text="You have no saved addresses yet.">
          <?php if (!$addresses): ?>
            <p class="text-ink-60" data-empty>You have no saved addresses yet.</p>
          <?php else: foreach ($addresses as $a): ?>
            <article class="rounded-md border border-mist p-4" data-address-id="<?= (int) $a['id'] ?>">
              <div class="flex items-start justify-between gap-2">
                <p class="font-medium">
                  <?= okv_e($a['recipient_name']) ?>
                  <?php if ((int) $a['is_default'] === 1): ?><span class="okv-badge okv-badge-available ml-1">Default</span><?php endif; ?>
                </p>
              </div>
              <p class="text-sm text-ink-60 mt-1">
                <?= okv_e($a['address_line_1']) ?><?= $a['address_line_2'] ? ', ' . okv_e($a['address_line_2']) : '' ?>,
                <?= okv_e($a['city']) ?>, <?= okv_e($a['state']) ?>
              </p>
              <p class="text-sm text-ink-40 mt-0.5"><?= okv_e(Phone::display($a['recipient_phone'])) ?></p>
              <div class="flex flex-wrap gap-2 mt-3">
                <button type="button" class="okv-btn-text" data-okv-edit-address='<?= okv_e(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>Edit</button>
                <?php if ((int) $a['is_default'] !== 1): ?>
                  <button type="button" class="okv-btn-text" data-okv-default-address="<?= (int) $a['id'] ?>">Make default</button>
                <?php endif; ?>
                <button type="button" class="okv-btn-text text-tomato hover:text-tomato-hover" data-okv-delete-address="<?= (int) $a['id'] ?>">Remove</button>
              </div>
            </article>
          <?php endforeach; endif; ?>
        </div>
      </section>
    </div>
  </main>

  <!-- Profile edit sheet -->
  <div class="okv-sheet-backdrop" id="profile-sheet" hidden>
    <div class="okv-sheet" role="dialog" aria-modal="true" aria-labelledby="profile-sheet-h">
      <div class="flex items-center justify-between">
        <h2 id="profile-sheet-h" class="font-display font-bold text-lg">Edit your details</h2>
        <button type="button" class="okv-btn-text" data-okv-close aria-label="Close">Close</button>
      </div>
      <form action="/api/v1/account.php" method="POST" class="mt-4 space-y-4" data-okv-ajax data-okv-reload>
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" hidden></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="okv-label" for="pf_first">First name</label><input class="okv-input" id="pf_first" name="first_name" value="<?= okv_e($me['first_name'] ?? '') ?>" required></div>
          <div><label class="okv-label" for="pf_last">Last name</label><input class="okv-input" id="pf_last" name="last_name" value="<?= okv_e($me['last_name'] ?? '') ?>" required></div>
        </div>
        <div><label class="okv-label" for="pf_phone">Phone number</label><input class="okv-input" id="pf_phone" name="phone" inputmode="tel" value="<?= okv_e(Phone::display($me['phone'] ?? '')) ?>" required></div>
        <button type="submit" class="okv-btn w-full">Save</button>
      </form>
    </div>
  </div>

  <!-- Address add / edit sheet -->
  <div class="okv-sheet-backdrop" id="address-sheet" hidden>
    <div class="okv-sheet" role="dialog" aria-modal="true" aria-labelledby="address-sheet-h">
      <div class="flex items-center justify-between">
        <h2 id="address-sheet-h" class="font-display font-bold text-lg">Add a delivery address</h2>
        <button type="button" class="okv-btn-text" data-okv-close aria-label="Close">Close</button>
      </div>
      <form action="/api/v1/account.php" method="POST" class="mt-4 space-y-4" data-okv-ajax data-okv-reload id="okv-address-form">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="add_address">
        <input type="hidden" name="address_id" value="">
        <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3" hidden></div>
        <div><label class="okv-label" for="ad_name">Who receives it</label><input class="okv-input" id="ad_name" name="recipient_name" required></div>
        <div><label class="okv-label" for="ad_phone">Delivery phone</label><input class="okv-input" id="ad_phone" name="recipient_phone" inputmode="tel" placeholder="0803 000 0000" required></div>
        <div><label class="okv-label" for="ad_l1">Street address</label><input class="okv-input" id="ad_l1" name="address_line_1" required></div>
        <div><label class="okv-label" for="ad_l2">Apartment or floor (optional)</label><input class="okv-input" id="ad_l2" name="address_line_2"></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="okv-label" for="ad_city">City or area</label><input class="okv-input" id="ad_city" name="city" required></div>
          <div><label class="okv-label" for="ad_state">State</label><input class="okv-input" id="ad_state" name="state" value="Lagos" required></div>
        </div>
        <div><label class="okv-label" for="ad_land">Landmark (optional)</label><input class="okv-input" id="ad_land" name="landmark"></div>
        <div class="flex items-center gap-2">
          <input type="checkbox" id="ad_default" name="is_default" value="1" class="min-h-[20px] w-5">
          <label for="ad_default" class="text-sm">Make this my default delivery address</label>
        </div>
        <button type="submit" class="okv-btn w-full">Save address</button>
      </form>
    </div>
  </div>

<?php else:
    $mode    = (string) okv_input('mode', 'signin');
    if (!in_array($mode, ['signin', 'register'], true)) { $mode = 'signin'; }
    $prefill = trim((string) okv_input('id', ''));
    $justReset = okv_input('reset', '') !== '';
?>
  <!-- ============================== AUTH HUB =============================== -->
  <div class="min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-6">
        <p class="uppercase tracking-[0.2em] text-gold text-xs font-semibold">OK Veggies</p>
        <p class="text-ink-60 text-sm mt-1">Sourced right. Priced right. Delivered right.</p>
      </div>

      <div class="bg-white rounded-lg shadow-okv-3 p-6 sm:p-8 animate-okv-rise">
        <!-- Tabs -->
        <div class="grid grid-cols-2 gap-1 p-1 rounded-md bg-forest-tint mb-6" role="tablist">
          <a href="?mode=signin" role="tab" data-okv-tab="signin"
             class="text-center min-h-[40px] inline-flex items-center justify-center rounded-md text-sm font-medium <?= $mode === 'signin' ? 'bg-white text-forest shadow-okv-1' : 'text-ink-60' ?>"
             aria-selected="<?= $mode === 'signin' ? 'true' : 'false' ?>">Sign in</a>
          <a href="?mode=register" role="tab" data-okv-tab="register"
             class="text-center min-h-[40px] inline-flex items-center justify-center rounded-md text-sm font-medium <?= $mode === 'register' ? 'bg-white text-forest shadow-okv-1' : 'text-ink-60' ?>"
             aria-selected="<?= $mode === 'register' ? 'true' : 'false' ?>">Create account</a>
        </div>

        <?php if ($justReset): ?>
          <div class="rounded-md bg-foliage-tint text-forest text-sm px-4 py-3 mb-4" role="status">Your password is changed. Please sign in.</div>
        <?php endif; ?>

        <!-- Sign in -->
        <section data-okv-panel="signin" <?= $mode === 'signin' ? '' : 'hidden' ?>>
          <form action="/api/v1/auth.php" method="POST" class="space-y-4" autocomplete="on" data-okv-auth data-okv-panel-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="context" value="storefront">
            <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $mode === 'signin') ? '' : ' hidden' ?>><?= okv_e($mode === 'signin' ? $errorText : '') ?></div>
            <div>
              <label for="si_identifier" class="okv-label">Phone number or email</label>
              <input id="si_identifier" name="identifier" type="text" required autocomplete="username" class="okv-input"
                     value="<?= okv_e($prefill) ?>" placeholder="0803 000 0000 or you@example.com">
            </div>
            <div>
              <label for="si_password" class="okv-label">Password</label>
              <input id="si_password" name="password" type="password" required autocomplete="current-password" class="okv-input" placeholder="Your password">
            </div>
            <button type="submit" class="okv-btn w-full">Sign in</button>
          </form>
          <p class="mt-4 text-center text-sm">
            <a href="/public/auth/password_reset.php" class="okv-btn-text">Forgot your password?</a>
          </p>
        </section>

        <!-- Register -->
        <section data-okv-panel="register" <?= $mode === 'register' ? '' : 'hidden' ?>>
          <form action="/api/v1/auth.php" method="POST" class="space-y-4" autocomplete="on" data-okv-register data-okv-panel-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="context" value="storefront">
            <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= ($errorText !== '' && $mode === 'register') ? '' : ' hidden' ?>><?= okv_e($mode === 'register' ? $errorText : '') ?></div>

            <fieldset>
              <legend class="okv-label">Account type</legend>
              <div class="grid grid-cols-2 gap-2">
                <label class="border border-mist rounded-md p-3 flex items-center gap-2 cursor-pointer has-[:checked]:border-forest has-[:checked]:bg-forest-tint">
                  <input type="radio" name="account_type" value="household" checked data-okv-acctype><span class="text-sm font-medium">Household</span>
                </label>
                <label class="border border-mist rounded-md p-3 flex items-center gap-2 cursor-pointer has-[:checked]:border-forest has-[:checked]:bg-forest-tint">
                  <input type="radio" name="account_type" value="business" data-okv-acctype><span class="text-sm font-medium">Business</span>
                </label>
              </div>
            </fieldset>

            <div class="grid sm:grid-cols-2 gap-4">
              <div><label for="rg_first" class="okv-label">First name</label><input id="rg_first" name="first_name" class="okv-input" required autocomplete="given-name"></div>
              <div><label for="rg_last" class="okv-label">Last name</label><input id="rg_last" name="last_name" class="okv-input" required autocomplete="family-name"></div>
            </div>
            <div><label for="rg_email" class="okv-label">Email</label><input id="rg_email" name="email" type="email" class="okv-input" required autocomplete="email" placeholder="you@example.com"></div>
            <div><label for="rg_phone" class="okv-label">Phone number</label><input id="rg_phone" name="phone" type="tel" inputmode="tel" class="okv-input" required autocomplete="tel" placeholder="0803 000 0000"></div>
            <div><label for="rg_password" class="okv-label">Password</label><input id="rg_password" name="password" type="password" class="okv-input" required autocomplete="new-password" placeholder="At least 10 characters"><p class="text-xs text-ink-40 mt-1">Use at least 10 characters. Longer is stronger.</p></div>

            <div data-okv-business hidden class="space-y-4 rounded-md bg-forest-tint p-4">
              <div><label for="rg_bizname" class="okv-label">Business name</label><input id="rg_bizname" name="business_name" class="okv-input"></div>
              <div>
                <label for="rg_biztype" class="okv-label">Business type (optional)</label>
                <select id="rg_biztype" name="business_type" class="okv-input">
                  <option value="">Choose one</option>
                  <option value="Restaurant">Restaurant</option>
                  <option value="Hotel">Hotel</option>
                  <option value="Supermarket">Supermarket</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="flex items-start gap-2">
                <input type="checkbox" id="rg_credit" name="request_credit" value="1" class="mt-1 min-h-[20px] w-5">
                <label for="rg_credit" class="text-sm">I would like to apply for credit terms (7 to 10 days). Our team will review it.</label>
              </div>
            </div>

            <button type="submit" class="okv-btn w-full">Create account</button>
            <p class="text-xs text-ink-40 text-center">We send a 6 digit code to your email to activate your account.</p>
          </form>
        </section>
      </div>

      <p class="text-center text-sm text-ink-60 mt-6"><a href="/" class="okv-btn-text">Back to the shop</a></p>
    </div>
  </div>

  <!-- Existing account modal (shown by JS on a duplicate registration) -->
  <div class="okv-sheet-backdrop" id="okv-exists-modal" hidden>
    <div class="okv-sheet max-w-sm mx-auto" role="dialog" aria-modal="true" aria-labelledby="exists-h">
      <h2 id="exists-h" class="font-display font-bold text-lg">You already have an account</h2>
      <p class="text-ink-60 text-sm mt-2">It looks like these details are already registered. You can sign in with your phone or email.</p>
      <div class="mt-5 flex flex-col gap-2">
        <a href="#" class="okv-btn w-full" data-okv-exists-signin>Sign in</a>
        <button type="button" class="okv-btn-outline w-full" data-okv-close>Use different details</button>
      </div>
    </div>
  </div>
<?php endif; ?>

  <script>window.OKV = window.OKV || {}; window.OKV.csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="<?= okv_e(okv_asset('/assets/js/okv.min.js')) ?>" defer></script>
  <script src="<?= okv_e(okv_asset('/assets/js/account.min.js')) ?>" defer></script>
</body>
</html>
