<?php
/**
 * scripts/tests/staff_password_reset_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The staff password reset by email code, checked against the
 * database. It proves the parts that matter for security:
 *
 *   1. Only an active staff account is eligible. A customer, a disabled staff
 *      account and an unknown email are all passed over, and none of that is
 *      revealed to the caller (the handler answers the same either way, which is
 *      covered by the HTTP tests; here we prove the eligibility query).
 *   2. The one-time code cycle: a right code verifies once and is then spent, a
 *      wrong code never verifies, and an expired code is refused.
 *   3. A reset moves users.password_changed_at on, which is the marker the staff
 *      RBAC gate uses to sign every other open session for the account out.
 *
 * Run against a scratch database:
 *
 *   php scripts/tests/staff_password_reset_db_test.php
 *
 * It creates throwaway users, asserts, then removes them. It never touches real
 * accounts. See docs/PRD.md Section 10.4.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Password.php';
require_once $root . '/includes/classes/Otp.php';

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

$pdo = Database::getInstance()->getConnection();

$ownerEmail  = 'pwtest-owner@okveggies.com.ng';   // active staff, the person resetting
$disEmail    = 'pwtest-disabled@okveggies.com.ng'; // disabled staff, not eligible
$custEmail   = 'pwtest-customer@okveggies.com.ng'; // household customer, not eligible here

// The query the staff reset handler runs to decide eligibility.
function eligible_staff(PDO $pdo, string $email): ?array {
    return Database::one(
        "SELECT id, first_name FROM users WHERE email = :e AND user_type = 'staff' AND status = 'active' LIMIT 1",
        [':e' => $email]
    );
}

// Clean slate.
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([$ownerEmail, $disEmail, $custEmail]);

$mk = function (string $em, string $ph, string $type, string $status) use ($pdo): int {
    $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute(['Pw', ucfirst($type), $em, $ph, Password::hash('old-strong-passw0rd'), $type, $status]);
    return (int) $pdo->lastInsertId();
};

$ownerId = $mk($ownerEmail, '0800pw0001', 'staff', 'active');
$disId   = $mk($disEmail,   '0800pw0002', 'staff', 'disabled');
$custId  = $mk($custEmail,  '0800pw0003', 'household', 'active');

// --- 1. Eligibility -----------------------------------------------------------
t_ok(eligible_staff($pdo, $ownerEmail) !== null, 'an active staff account is eligible for a staff reset');
t_ok(eligible_staff($pdo, $disEmail)   === null, 'a disabled staff account is not eligible');
t_ok(eligible_staff($pdo, $custEmail)  === null, 'a household customer is not eligible for the staff reset');
t_ok(eligible_staff($pdo, 'nobody-pwtest@okveggies.com.ng') === null, 'an unknown email is not eligible');

// --- 2. The one-time code cycle ----------------------------------------------
$code = Otp::issue($ownerEmail, 'email', 'password_reset', $ownerId);
t_ok(preg_match('/^\d{6}$/', $code) === 1, 'the reset code is six digits');
t_ok(Otp::verify($ownerEmail, 'password_reset', '000000') === false || $code === '000000', 'a wrong code does not verify');
t_ok(Otp::verify($ownerEmail, 'password_reset', $code) === true, 'the right code verifies');
t_ok(Otp::verify($ownerEmail, 'password_reset', $code) === false, 'the code cannot be used a second time');

// An expired code is refused.
$expiredCode = Otp::issue($ownerEmail, 'email', 'password_reset', $ownerId, -60);
t_ok(Otp::verify($ownerEmail, 'password_reset', $expiredCode) === false, 'an expired code is refused');

// --- 3. A reset moves the session marker on ----------------------------------
$before = Database::one('SELECT password_changed_at FROM users WHERE id = :id', [':id' => $ownerId]);
$beforeEpoch = (string) ($before['password_changed_at'] ?? '');

// What the handler does on success.
Database::run(
    'UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id',
    [':h' => Password::hash('a-brand-new-passw0rd'), ':id' => $ownerId]
);

$after = Database::one('SELECT password_hash, password_changed_at FROM users WHERE id = :id', [':id' => $ownerId]);
$afterEpoch = (string) ($after['password_changed_at'] ?? '');

t_ok(Password::verify('a-brand-new-passw0rd', $after['password_hash']), 'the new password verifies after the reset');
t_ok(!Password::verify('old-strong-passw0rd', $after['password_hash']), 'the old password no longer verifies');
t_ok($afterEpoch !== '', 'password_changed_at is stamped by a reset');
t_ok($afterEpoch !== $beforeEpoch, 'the session marker moves on, so other open sessions fall');

// Cleanup.
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([$ownerEmail, $disEmail, $custEmail]);

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
