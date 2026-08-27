<?php
/**
 * admin/account.php
 * OK Veggies. A signed-in staff member's own account: their details and a
 * change-password form. Any staff role can reach this; the Owner resets other
 * people's passwords in Users and Roles. See docs/PRD.md Section 10.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requireAuth();

$me = Database::one('SELECT first_name, last_name, email, phone FROM users WHERE id = :id', [':id' => (int) Rbac::userId()]);
$fullName  = trim(((string) ($me['first_name'] ?? '')) . ' ' . ((string) ($me['last_name'] ?? '')));
$roleLabel = in_array('owner', Rbac::roles(), true) ? 'Owner' : (in_array('manager', Rbac::roles(), true) ? 'Manager' : 'Staff');

$changed = isset($_GET['changed']);
$errorMessages = [
    'wrong_current' => 'Your current password is not right.',
    'mismatch'      => 'The two new passwords do not match.',
    'weak_password' => 'Please choose a stronger password.',
    'csrf_expired'  => 'Your session expired. Reload the page and try again.',
];
$errorCode = (string) ($_GET['error'] ?? '');
$errorText = $errorCode !== '' ? ($errorMessages[$errorCode] ?? 'We could not change your password. Try again.') : '';

$okv_admin_title = 'Your account';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="grid gap-6 lg:grid-cols-2 max-w-4xl">

    <div class="okv-card">
      <h2 class="font-display font-extrabold text-xl text-ink">Your details</h2>
      <dl class="mt-4 space-y-3 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-ink-60">Name</dt><dd class="text-ink font-medium text-right"><?= okv_e($fullName) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-60">Email</dt><dd class="text-ink font-medium text-right break-all"><?= okv_e($me['email'] ?? '') ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-60">Phone</dt><dd class="text-ink font-medium text-right"><?= okv_e($me['phone'] ?? '') ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-60">Role</dt><dd class="text-ink font-medium text-right"><?= okv_e($roleLabel) ?></dd></div>
      </dl>
      <p class="text-xs text-ink-40 mt-4">To change your name, email or phone, ask the Owner in Users and Roles.</p>
    </div>

    <div class="okv-card">
      <h2 class="font-display font-extrabold text-xl text-ink">Change your password</h2>

      <?php if ($changed): ?>
        <div role="status" class="rounded-md bg-foliage-tint text-forest text-sm px-4 py-3 mt-4">Your password is changed.</div>
      <?php endif; ?>

      <form action="/api/v1/auth.php" method="POST" class="mt-4 space-y-4" data-okv-auth autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="change_password">

        <div data-okv-error role="alert" aria-live="polite"
             class="rounded-md bg-tomato-tint text-tomato text-sm px-4 py-3"<?= $errorText === '' ? ' hidden' : '' ?>><?= okv_e($errorText) ?></div>

        <div>
          <label for="current_password" class="okv-label">Current password</label>
          <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="okv-input">
        </div>
        <div>
          <label for="new_password" class="okv-label">New password</label>
          <input id="new_password" name="new_password" type="password" required autocomplete="new-password" class="okv-input">
          <p class="text-xs text-ink-40 mt-1">At least <?= (int) Password::minLength() ?> characters. Not a common password.</p>
        </div>
        <div>
          <label for="confirm_password" class="okv-label">Confirm new password</label>
          <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password" class="okv-input">
        </div>
        <button type="submit" class="okv-btn w-full">Change password</button>
      </form>
    </div>

  </div>
<?php
$okv_admin_script = '/assets/js/auth.js';
require __DIR__ . '/../includes/components/admin/footer.php';
