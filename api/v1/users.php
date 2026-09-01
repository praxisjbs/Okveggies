<?php
/**
 * api/v1/users.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Staff accounts. This is how the Owner adds the Manager, resets a
 * password, switches an account on or off, and sets a person's role. Built in
 * milestone M1. See docs/PRD.md Section 17.
 *
 * Every action is gated on the server with the users.* permissions (the Owner
 * holds them; the Manager does not). Reads are GET; every change is a POST with
 * a valid CSRF token. Prepared statements only. No exception ever reaches the
 * client.
 *
 * Actions:
 *   list          (GET,  users.view)         staff users with their roles
 *   create        (POST, users.create)       add a staff user and give them a role
 *   set_password  (POST, users.edit)         set a new password for a staff user
 *   set_status    (POST, users.edit)         switch an account active or disabled
 *   set_role      (POST, users.roles.edit)   change a staff user's role
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** Guard a write: must be POST with a valid CSRF token and the permission. */
function users_guard_write(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission); // stops with 401 or 403 JSON
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

/** The staff user matching an id, or null. Customers are never returned here. */
function users_find_staff(int $id): ?array
{
    return Database::one(
        "SELECT id, first_name, last_name, email, phone, status, user_type FROM users WHERE id = :id AND user_type = 'staff'",
        [':id' => $id]
    );
}

/** True if this staff user is the only active Owner left. */
function users_is_last_active_owner(int $id): bool
{
    $row = Database::one(
        "SELECT COUNT(*) AS c
           FROM user_roles ur
           JOIN roles r ON r.id = ur.role_id
           JOIN users u ON u.id = ur.user_id
          WHERE r.name = 'owner' AND u.status = 'active'",
        []
    );
    $activeOwners = (int) ($row['c'] ?? 0);
    $isOwner = Database::one(
        "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.name = 'owner'",
        [':id' => $id]
    );
    return $isOwner !== null && $activeOwners <= 1;
}

switch ($action) {

    case 'list': {
        Rbac::requirePermission('users.view');
        $rows = Database::all(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.last_login_at,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',') AS roles
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.user_type = 'staff'
              GROUP BY u.id
              ORDER BY u.created_at ASC"
        );
        $staff = array_map(static function ($r) {
            return [
                'id'            => (int) $r['id'],
                'first_name'    => $r['first_name'],
                'last_name'     => $r['last_name'],
                'email'         => $r['email'],
                'phone'         => $r['phone'],
                'status'        => $r['status'],
                'last_login_at' => $r['last_login_at'],
                'roles'         => $r['roles'] ? explode(',', $r['roles']) : [],
            ];
        }, $rows);
        okv_json(['status' => 'ok', 'staff' => $staff]);
        break;
    }

    case 'create': {
        users_guard_write('users.create');

        $first = trim((string) okv_input('first_name', ''));
        $last  = trim((string) okv_input('last_name', ''));
        $email = trim((string) okv_input('email', ''));
        $phone = trim((string) okv_input('phone', ''));
        $pass  = (string) okv_input('password', '');
        $role  = trim((string) okv_input('role', ''));

        if ($first === '' || $last === '') {
            okv_error('Enter the first and last name.', 422, 'missing_name');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            okv_error('Enter a valid email address.', 422, 'bad_email');
        }
        if ($phone === '' || strlen(preg_replace('/[^0-9]/', '', $phone)) < 7) {
            okv_error('Enter a valid phone number.', 422, 'bad_phone');
        }
        $policy = Password::policyError($pass, $email, $phone);
        if ($policy !== null) {
            okv_error($policy, 422, 'weak_password');
        }
        $roleRow = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => $role]);
        if (!$roleRow) {
            okv_error('Choose a role for this person.', 422, 'bad_role');
        }
        // Assigning a role needs the role permission too.
        if (!Rbac::can('users.roles.edit')) {
            okv_error('You cannot assign roles.', 403, 'forbidden');
        }
        if (Database::one('SELECT id FROM users WHERE email = :e OR phone = :p LIMIT 1', [':e' => $email, ':p' => $phone])) {
            okv_error('That email or phone is already in use.', 409, 'duplicate');
        }

        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
                 VALUES (:fn, :ln, :em, :ph, :pw, 'staff', 'active', NOW())"
            )->execute([
                ':fn' => $first, ':ln' => $last, ':em' => $email, ':ph' => $phone, ':pw' => Password::hash($pass),
            ]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, :by)')
                ->execute([':u' => $newId, ':r' => (int) $roleRow['id'], ':by' => (int) Rbac::userId()]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('users.create failed: ' . $e->getMessage());
            okv_error('We could not add this person. Please try again.', 500, 'create_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Staff member added.', 'id' => $newId], 201);
        break;
    }

    case 'set_password': {
        users_guard_write('users.edit');
        $id   = (int) okv_input('user_id', 0);
        $pass = (string) okv_input('new_password', '');

        $target = users_find_staff($id);
        if (!$target) {
            okv_error('That staff member was not found.', 404, 'not_found');
        }
        $policy = Password::policyError($pass, (string) $target['email'], (string) $target['phone']);
        if ($policy !== null) {
            okv_error($policy, 422, 'weak_password');
        }
        // Move the password marker on too, so any session that person still has
        // open elsewhere is signed out the next time it touches a staff page.
        Database::run('UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id', [':h' => Password::hash($pass), ':id' => $id]);
        okv_json(['status' => 'ok', 'message' => 'Password set for ' . ($target['first_name'] ?? 'that person') . '.']);
        break;
    }

    case 'set_status': {
        users_guard_write('users.edit');
        $id     = (int) okv_input('user_id', 0);
        $status = (string) okv_input('status', '');

        if (!in_array($status, ['active', 'disabled'], true)) {
            okv_error('Choose active or disabled.', 422, 'bad_status');
        }
        $target = users_find_staff($id);
        if (!$target) {
            okv_error('That staff member was not found.', 404, 'not_found');
        }
        if ($id === (int) Rbac::userId()) {
            okv_error('You cannot change your own account here.', 422, 'self');
        }
        if ($status === 'disabled' && users_is_last_active_owner($id)) {
            okv_error('You cannot switch off the last active Owner.', 422, 'last_owner');
        }
        Database::run('UPDATE users SET status = :s WHERE id = :id', [':s' => $status, ':id' => $id]);
        okv_json(['status' => 'ok', 'message' => 'Account ' . ($status === 'active' ? 'switched on.' : 'switched off.')]);
        break;
    }

    case 'set_role': {
        users_guard_write('users.roles.edit');
        $id   = (int) okv_input('user_id', 0);
        $role = trim((string) okv_input('role', ''));

        $target = users_find_staff($id);
        if (!$target) {
            okv_error('That staff member was not found.', 404, 'not_found');
        }
        $roleRow = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => $role]);
        if (!$roleRow) {
            okv_error('Choose a valid role.', 422, 'bad_role');
        }
        if ($id === (int) Rbac::userId()) {
            okv_error('You cannot change your own role here.', 422, 'self');
        }
        if ($role !== 'owner' && users_is_last_active_owner($id)) {
            okv_error('You cannot move the last active Owner off the Owner role.', 422, 'last_owner');
        }
        $pdo = Database::getInstance()->getConnection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :u')->execute([':u' => $id]);
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, :by)')
                ->execute([':u' => $id, ':r' => (int) $roleRow['id'], ':by' => (int) Rbac::userId()]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('users.set_role failed: ' . $e->getMessage());
            okv_error('We could not change the role. Please try again.', 500, 'role_failed');
        }
        okv_json(['status' => 'ok', 'message' => 'Role updated.']);
        break;
    }

    default:
        okv_error('This action is not available.', 400, 'unknown_action');
}
