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

/**
 * The staff user matching an id, or null. Staff is anyone who holds a role:
 * one person is one identity, so a customer who joined the team keeps their
 * household or business account type and gains their role on the same row.
 * Rows created before that policy carry the staff account type instead.
 */
function users_find_staff(int $id): ?array
{
    return Database::one(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.user_type
           FROM users u
          WHERE u.id = :id
            AND (u.user_type = 'staff' OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id))",
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

/** True when a write failed because a UNIQUE identity key already exists. */
function users_is_duplicate_key(Throwable $e): bool
{
    return $e instanceof PDOException && str_starts_with((string) ($e->getCode() ?: ''), '23');
}

/**
 * The typed identifier belongs to an identity that already exists. If the email
 * and the phone point at two different rows, or the match is already on the
 * team, refuse and say which field collided. Otherwise attach the staff role to
 * the existing customer identity: one person, one row, many roles. Returns the
 * id of the identity that is now staff.
 */
function users_create_resolve_conflict(array $conflict, string $pass, int $roleId): int
{
    $emailId = $conflict['email_user_id'] !== null ? (int) $conflict['email_user_id'] : null;
    $phoneId = $conflict['phone_user_id'] !== null ? (int) $conflict['phone_user_id'] : null;

    if ($emailId !== null && $phoneId !== null && $emailId !== $phoneId) {
        okv_error('That email and that phone number belong to two different accounts. Check both, then try again.', 409, 'duplicate');
    }
    $matchId = $emailId ?? $phoneId;
    $match   = Database::one('SELECT id, user_type, status FROM users WHERE id = :id', [':id' => $matchId]);
    if ($match === null) {
        okv_error('We could not add this person. Please try again.', 500, 'create_failed');
    }
    if (Auth::isStaffUser($match)) {
        $field    = (string) $conflict['field'];
        $messages = [
            'email' => 'That email already belongs to a team member.',
            'phone' => 'That phone number already belongs to a team member.',
            'both'  => 'Those details already belong to a team member.',
        ];
        $codes = ['email' => 'email_taken', 'phone' => 'phone_taken', 'both' => 'duplicate'];
        okv_error($messages[$field] ?? $messages['both'], 409, $codes[$field] ?? 'duplicate');
    }
    if ((string) $match['status'] !== 'active') {
        okv_error('That customer account is switched off. Switch it on before making it staff.', 409, 'account_off');
    }

    $pdo = Database::getInstance()->getConnection();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (:u, :r, :by)')
            ->execute([':u' => $matchId, ':r' => $roleId, ':by' => (int) Rbac::userId()]);
        // The Owner chose the password this person signs in with. Moving the
        // marker on signs any of their older sessions out everywhere at once.
        $pdo->prepare('UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id')
            ->execute([':h' => Password::hash($pass), ':id' => $matchId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // A racing attach already gave this person the role: they are staff.
        if (users_is_duplicate_key($e)) {
            okv_error('That person is already on the team.', 409, 'duplicate');
        }
        error_log('users.create attach failed: ' . $e->getMessage());
        okv_error('We could not add this person. Please try again.', 500, 'create_failed');
    }
    return $matchId;
}

/** After the unique index catches a racing duplicate, name the field that collided. */
function users_create_refuse_duplicate(Throwable $e, string $email, string $phone): void
{
    if (!users_is_duplicate_key($e)) {
        return;
    }
    $conflict = Auth::findIdentityConflict($email, $phone);
    if ($conflict === null) {
        return;
    }
    $field    = (string) $conflict['field'];
    $messages = [
        'email' => 'That email is already in use.',
        'phone' => 'That phone number is already in use.',
        'both'  => 'That email and that phone number are already in use.',
    ];
    okv_error($messages[$field] ?? $messages['both'], 409, $field === 'both' ? 'duplicate' : $field . '_taken');
}

switch ($action) {

    case 'list': {
        Rbac::requirePermission('users.view');
        $rows = Database::all(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.last_login_at, u.user_type,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ',') AS roles
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.user_type = 'staff' OR ur.role_id IS NOT NULL
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
                'user_type'     => (string) ($r['user_type'] ?? 'staff'),
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
        $pass  = (string) okv_input('password', '');
        $role  = trim((string) okv_input('role', ''));

        if ($first === '' || $last === '') {
            okv_error('Enter the first and last name.', 422, 'missing_name');
        }
        // The same canonicalisation every identity path shares, so the person
        // added here signs in by phone or email however they type either one.
        $email = Auth::canonicalEmail((string) okv_input('email', ''));
        if ($email === null) {
            okv_error('Enter a valid email address.', 422, 'bad_email');
        }
        $phone = Phone::normalize((string) okv_input('phone', ''));
        if ($phone === null) {
            okv_error('Enter a valid phone number, for example 0803 000 0000.', 422, 'bad_phone');
        }
        $policy = Password::policyError($pass, $email, $phone);
        if ($policy !== null) {
            okv_error($policy, 422, 'weak_password');
        }
        $roleRow = Database::one("SELECT id FROM roles WHERE name = :n AND status = 'active'", [':n' => $role]);
        if (!$roleRow) {
            okv_error('Choose a role for this person.', 422, 'bad_role');
        }
        // Assigning a role needs the role permission too.
        if (!Rbac::can('users.roles.edit')) {
            okv_error('You cannot assign roles.', 403, 'forbidden');
        }

        $conflict = Auth::findIdentityConflict($email, $phone);
        if ($conflict !== null) {
            $newId = users_create_resolve_conflict($conflict, $pass, (int) $roleRow['id']);
            okv_json([
                'status'  => 'ok',
                'message' => 'Staff member added. They already had a customer account, so the team role was added to it: one sign-in for both.',
                'id'      => $newId,
            ], 201);
            break;
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
            // Two creations of the same person racing: the unique index caught
            // the second one. Re-run the shared check and name the field.
            users_create_refuse_duplicate($e, $email, $phone);
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
        $roleRow = Database::one("SELECT id FROM roles WHERE name = :n AND status = 'active'", [':n' => $role]);
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
