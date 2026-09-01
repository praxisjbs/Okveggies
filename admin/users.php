<?php
/**
 * admin/users.php
 * OK Veggies. Staff accounts and roles, Owner only. This is where the Owner adds
 * the Manager, resets a password, switches an account on or off, and sets a
 * person's role. Every change posts to api/v1/users.php, which re-checks the
 * users.* and rbac.* permissions on the server. See docs/PRD.md Section 17.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('users.view');

$meId  = (int) Rbac::userId();
$roles = Database::all('SELECT id, name, description FROM roles ORDER BY name');

$staff = Database::all(
    "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.last_login_at,
            GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',') AS roles
       FROM users u
       LEFT JOIN user_roles ur ON ur.user_id = u.id
       LEFT JOIN roles r ON r.id = ur.role_id
      WHERE u.user_type = 'staff'
      GROUP BY u.id
      ORDER BY u.created_at ASC"
);

$okv_admin_title = 'Users and Roles';
$okv_admin_note  = 'Add staff, set what each person can do, reset a password, or switch an account off. Owner only.';
require __DIR__ . '/../includes/components/admin/header.php';
?>
  <div class="grid gap-6 lg:grid-cols-3">

    <!-- Add a staff member -->
    <section class="lg:col-span-1">
      <div class="okv-panel okv-panel-body" data-perm="users.create">
        <h2 class="okv-panel-title">Add a staff member</h2>
        <p class="text-sm text-ink-60 mt-1">They can change their own password after they sign in.</p>

        <form action="/api/v1/users.php" method="POST" class="mt-4 space-y-4" data-okv-json autocomplete="off">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="create">

          <div data-okv-error role="alert" aria-live="polite" class="okv-note-bad" hidden></div>

          <div class="grid grid-cols-2 gap-3">
            <div><label for="first_name" class="okv-label">First name</label>
              <input id="first_name" name="first_name" type="text" required class="okv-input"></div>
            <div><label for="last_name" class="okv-label">Last name</label>
              <input id="last_name" name="last_name" type="text" required class="okv-input"></div>
          </div>
          <div><label for="email" class="okv-label">Email</label>
            <input id="email" name="email" type="email" required class="okv-input"></div>
          <div><label for="phone" class="okv-label">Phone number</label>
            <input id="phone" name="phone" type="text" required class="okv-input"></div>
          <div><label for="role" class="okv-label">Role</label>
            <select id="role" name="role" required class="okv-input">
              <?php foreach ($roles as $r): ?>
                <option value="<?= okv_e($r['name']) ?>"><?= okv_e(okv_role_label($r['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label for="password" class="okv-label">Starting password</label>
            <input id="password" name="password" type="text" required autocomplete="off" class="okv-input">
            <p class="text-xs text-ink-40 mt-1">At least <?= (int) Password::minLength() ?> characters. Give it to them to change later.</p>
          </div>
          <button type="submit" class="okv-btn w-full">Add staff member</button>
        </form>
      </div>
    </section>

    <!-- The staff list -->
    <section class="lg:col-span-2 space-y-3">
      <h2 class="okv-eyebrow">Staff<?= $staff ? ', ' . count($staff) : '' ?></h2>

      <?php if (!$staff): ?>
        <div class="okv-panel okv-panel-body"><p class="text-ink-60">No staff yet. Add the first person on the left.</p></div>
      <?php endif; ?>

      <?php foreach ($staff as $s):
          $id       = (int) $s['id'];
          $name     = trim(((string) $s['first_name']) . ' ' . ((string) $s['last_name']));
          $roleName = (string) ($s['roles'] ?? '');
          $isSelf   = $id === $meId;
          $isActive = $s['status'] === 'active';
      ?>
        <div class="okv-panel">
          <div class="okv-panel-head">
            <div class="min-w-0">
              <p class="okv-panel-title"><?= okv_e($name) ?><?php if ($isSelf): ?> <span class="text-xs text-ink-40 font-normal">(you)</span><?php endif; ?></p>
              <p class="text-sm text-ink-60 break-all mt-0.5"><?= okv_e($s['email']) ?></p>
              <p class="text-sm text-ink-60 font-mono"><?= okv_e($s['phone']) ?></p>
            </div>
            <div class="text-right shrink-0">
              <span class="okv-badge <?= $isActive ? 'okv-badge-available' : 'okv-badge-out' ?>"><?= $isActive ? 'Active' : 'Switched off' ?></span>
              <p class="text-xs text-ink-60 mt-2"><?= okv_e(okv_role_label($roleName)) ?></p>
              <p class="text-xs text-ink-40 mt-1"><?= $s['last_login_at'] ? 'Last in on ' . okv_e(date('j M Y', strtotime((string) $s['last_login_at']))) : 'Not signed in yet' ?></p>
            </div>
          </div>

          <?php if ($isSelf): ?>
            <p class="okv-panel-body text-sm text-ink-60">This is your own account. <a href="/admin/account.php" class="text-forest underline underline-offset-2">Change your password</a>.</p>
          <?php else: ?>
            <details class="okv-panel-body group">
              <summary class="okv-btn-text text-sm cursor-pointer select-none">Manage</summary>
              <div class="mt-4 grid gap-4 sm:grid-cols-2">

                <!-- Reset password -->
                <form action="/api/v1/users.php" method="POST" class="space-y-2" data-okv-json autocomplete="off">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="set_password">
                  <input type="hidden" name="user_id" value="<?= $id ?>">
                  <div data-okv-error role="alert" aria-live="polite" class="rounded-md bg-tomato-tint text-tomato text-xs px-3 py-2" hidden></div>
                  <label class="okv-label" for="pw-<?= $id ?>">New password</label>
                  <input id="pw-<?= $id ?>" name="new_password" type="text" required class="okv-input-sm" placeholder="At least <?= (int) Password::minLength() ?> characters">
                  <button type="submit" class="okv-btn-outline-sm w-full">Set password</button>
                </form>

                <div class="space-y-4">
                  <!-- Change role -->
                  <form action="/api/v1/users.php" method="POST" class="space-y-2" data-okv-json>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="user_id" value="<?= $id ?>">
                    <label class="okv-label" for="role-<?= $id ?>">Role</label>
                    <select id="role-<?= $id ?>" name="role" class="okv-input-sm">
                      <?php foreach ($roles as $r): $sel = ($r['name'] === $roleName) ? ' selected' : ''; ?>
                        <option value="<?= okv_e($r['name']) ?>"<?= $sel ?>><?= okv_e(okv_role_label($r['name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="okv-btn-outline-sm w-full">Update role</button>
                  </form>

                  <!-- Switch on/off -->
                  <form action="/api/v1/users.php" method="POST" data-okv-json>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="user_id" value="<?= $id ?>">
                    <input type="hidden" name="status" value="<?= $isActive ? 'disabled' : 'active' ?>">
                    <button type="submit" class="okv-btn-outline-sm w-full"><?= $isActive ? 'Switch off this account' : 'Switch this account on' ?></button>
                  </form>
                </div>

              </div>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

  </div>
<?php
$okv_admin_script = '/assets/js/admin-users.js';
require __DIR__ . '/../includes/components/admin/footer.php';
