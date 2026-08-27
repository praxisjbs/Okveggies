<?php
/**
 * scripts/tests/auth_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Auth checks that need the database: password verification against
 * a stored hash, the login rate-limit lockout, and RBAC gating for the Owner and
 * the Manager. Run against a scratch database:
 *
 *   php scripts/tests/auth_db_test.php
 *
 * It creates two throwaway staff users, asserts, then removes them. It never
 * touches real accounts. The guest redirect is proved by scripts/smoke_roles.sh.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/RateLimiter.php';
require_once $root . '/includes/classes/Password.php';
require_once $root . '/includes/classes/Rbac.php';

$GLOBALS['t'] = 0; $GLOBALS['p'] = 0; $GLOBALS['f'] = [];
function t_ok($cond, string $label): void {
    $GLOBALS['t']++;
    if ($cond) { $GLOBALS['p']++; } else { $GLOBALS['f'][] = $label; fwrite(STDERR, "  FAIL: $label\n"); }
}
function t_eq($expected, $actual, string $label): void {
    $ok = ($expected === $actual);
    if (!$ok) { $label .= '  (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    t_ok($ok, $label);
}

$_SESSION = [];
$pdo = Database::getInstance()->getConnection();

$ownerEmail = 'dbtest-owner@okveggies.com.ng';
$mgrEmail   = 'dbtest-manager@okveggies.com.ng';

/** Create a staff user with a role and return the new id. */
function make_staff(PDO $pdo, string $fn, string $ln, string $em, string $ph, string $pw, string $role): int {
    $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (?, ?, ?, ?, ?, 'staff', 'active', NOW())"
    )->execute([$fn, $ln, $em, $ph, Password::hash($pw)]);
    $id = (int) $pdo->lastInsertId();
    $role = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => $role]);
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, (int) $role['id']]);
    return $id;
}

// Clean slate for the two test users (cascade removes their roles).
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute([$ownerEmail, $mgrEmail]);

$ownerId = make_staff($pdo, 'Test', 'Owner',   $ownerEmail, '0800test001', 'owner-strong-777',   'owner');
$mgrId   = make_staff($pdo, 'Test', 'Manager', $mgrEmail,   '0800test002', 'manager-strong-777', 'manager');

// --- 1. Password verification against the stored hash (the login check) ------
$row = Database::one('SELECT password_hash FROM users WHERE id = :id', [':id' => $ownerId]);
t_ok(Password::verify('owner-strong-777', $row['password_hash']), 'the right password verifies against the stored hash');
t_ok(!Password::verify('not-the-password', $row['password_hash']), 'the wrong password does not verify');

// --- 2. Rate-limit lockout (5 tries per identifier) --------------------------
$bucket = 'dbtest:login:lockme';
$max = 5; $window = 900;
RateLimiter::reset($bucket);
t_ok(!RateLimiter::isLocked($bucket, $max), 'a fresh identifier is not locked');
for ($i = 1; $i <= 5; $i++) {
    RateLimiter::hit($bucket, $max, $window);
    if ($i < 5) {
        t_ok(!RateLimiter::isLocked($bucket, $max), "not locked after $i failed tries");
    }
}
t_ok(RateLimiter::isLocked($bucket, $max), 'locked after 5 failed tries');
t_ok(RateLimiter::retryAfter($bucket) > 0, 'a lockout reports seconds to wait');
RateLimiter::reset($bucket);
t_ok(!RateLimiter::isLocked($bucket, $max), 'a reset clears the lockout (a correct sign in would do this)');

// --- 3. RBAC gating: Owner is a superuser, Manager is scoped -----------------
Rbac::loadFromDb($ownerId);
t_ok(Rbac::isStaff(), 'owner counts as staff');
t_ok(in_array('owner', Rbac::roles(), true), 'owner holds the owner role');
t_ok(Rbac::hasPermission('users.view'), 'owner can view users');
t_ok(Rbac::hasPermission('rbac.roles.view'), 'owner can view roles');
t_ok(Rbac::hasPermission('anything.not.seeded'), 'owner is a superuser (star permission)');

Rbac::loadFromDb($mgrId);
t_ok(Rbac::isStaff(), 'manager counts as staff');
t_ok(!Rbac::hasPermission('users.view'), 'manager cannot view users');
t_ok(!Rbac::hasPermission('users.create'), 'manager cannot create users');
t_ok(!Rbac::hasPermission('rbac.roles.view'), 'manager cannot view roles');
t_ok(!Rbac::hasPermission('settings.edit'), 'manager cannot edit settings');
t_ok(!Rbac::hasPermission('payments.refund'), 'manager cannot issue refunds');
t_ok(Rbac::hasPermission('orders.view'), 'manager can view orders');
t_ok(Rbac::hasPermission('pricing.update'), 'manager can update pricing');
t_ok(Rbac::hasPermission('delivery.manifest.view'), 'manager can view the delivery manifest');

// --- 4. A guest holds no session identity ------------------------------------
$_SESSION = [];
t_ok(Rbac::userId() === null, 'no session means no user id');
t_ok(!Rbac::isLoggedIn(), 'a guest is not logged in');

// Cleanup.
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute([$ownerEmail, $mgrEmail]);

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
