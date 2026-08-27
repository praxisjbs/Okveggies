<?php
/**
 * scripts/tests/customer_auth_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Customer auth checks that need the database: finding a person by
 * phone (in every shape it might be typed) or email, where each account type
 * lands after sign in, and the full one-time-code lifecycle (issue, single use,
 * the attempt cap, expiry and purpose scoping). Run against a scratch database:
 *
 *   php scripts/tests/customer_auth_db_test.php
 *
 * It creates throwaway customers, asserts, then removes them (which cascades to
 * their codes). It never touches real accounts. Registration and reset over HTTP
 * are proved by scripts/smoke_customers.sh.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Password.php';
require_once $root . '/includes/classes/Phone.php';
require_once $root . '/includes/classes/Rbac.php';
require_once $root . '/includes/classes/Auth.php';
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

$_SESSION = [];
$pdo = Database::getInstance()->getConnection();

$hhEmail  = 'dbtest-household@okveggies.com.ng';
$bizEmail = 'dbtest-business@okveggies.com.ng';
$hhPhone  = '+2348090000001';
$bizPhone = '+2348090000002';
$password = 'stew-kit-strong-9';

// Remove any leftovers from a previous run. business_customers restricts a user
// delete, so clear that child first; codes and addresses cascade on their own.
$cleanup = static function (PDO $pdo, array $emails): void {
    $in = implode(', ', array_fill(0, count($emails), '?'));
    $pdo->prepare("DELETE bc FROM business_customers bc JOIN users u ON u.id = bc.user_id WHERE u.email IN ($in)")->execute($emails);
    $pdo->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails);
};
$cleanup($pdo, [$hhEmail, $bizEmail]);

// A household customer, stored the way registration stores it (E.164 phone).
$pdo->prepare(
    "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
     VALUES ('Ada', 'Household', ?, ?, ?, 'household', 'active', NULL)"
)->execute([$hhEmail, $hhPhone, Password::hash($password)]);
$hhId = (int) $pdo->lastInsertId();

// A business customer with a business profile.
$pdo->prepare(
    "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
     VALUES ('Bola', 'Business', ?, ?, ?, 'business', 'active', NULL)"
)->execute([$bizEmail, $bizPhone, Password::hash($password)]);
$bizId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO business_customers (user_id, business_name, business_type, contact_person, credit_requested, credit_status)
     VALUES (?, 'Bola Kitchen', 'Restaurant', 'Bola Business', 1, 'requested')"
)->execute([$bizId]);

// --- 1. Password verifies against the stored hash ----------------------------
$row = Database::one('SELECT password_hash FROM users WHERE id = :id', [':id' => $hhId]);
t_ok(Password::verify($password, $row['password_hash']), 'the right password verifies');
t_ok(!Password::verify('wrong-password', $row['password_hash']), 'the wrong password does not verify');

// --- 2. Find by email (login by email) --------------------------------------
$byEmail = Auth::findByIdentifier($hhEmail);
t_ok($byEmail !== null && (int) $byEmail['id'] === $hhId, 'found by email');
$byEmailCase = Auth::findByIdentifier('DBTEST-Household@OKVeggies.com.NG');
t_ok($byEmailCase !== null && (int) $byEmailCase['id'] === $hhId, 'email match is case-insensitive');

// --- 3. Find by phone, however it was typed (login by phone) -----------------
foreach (['+2348090000001', '08090000001', '2348090000001', '0809 000 0001', '0809-000-0001'] as $form) {
    $u = Auth::findByIdentifier($form);
    t_ok($u !== null && (int) $u['id'] === $hhId, "found by phone typed as \"$form\"");
}
t_eq(null, Auth::findByIdentifier('08099999999'), 'an unknown phone finds nobody');
t_eq(null, Auth::findByIdentifier('not-a-thing'), 'nonsense finds nobody');

// --- 4. Landing path per account type ---------------------------------------
$hhRow  = Database::one('SELECT user_type FROM users WHERE id = :id', [':id' => $hhId]);
$bizRow = Database::one('SELECT user_type FROM users WHERE id = :id', [':id' => $bizId]);
t_eq('/',      Auth::landingPath($hhRow),  'a household lands on the shop');
t_eq('/pro',   Auth::landingPath($bizRow), 'a business lands on the Pro Portal');
t_eq('/admin', Auth::landingPath(['user_type' => 'staff']), 'staff land on the admin panel');

// The business profile was written.
$biz = Database::one('SELECT business_name, credit_status FROM business_customers WHERE user_id = :u', [':u' => $bizId]);
t_ok($biz !== null && $biz['business_name'] === 'Bola Kitchen', 'the business profile is saved');
t_eq('requested', $biz['credit_status'], 'an opt-in credit request is recorded');

// --- 5. OTP issue and single-use verify -------------------------------------
$code = Otp::issue($hhEmail, 'email', 'account_activation', $hhId);
t_ok(preg_match('/^\d{6}$/', $code) === 1, 'issue returns a six digit code');
t_ok(Otp::verify($hhEmail, 'account_activation', $code), 'the right code verifies once');
t_ok(!Otp::verify($hhEmail, 'account_activation', $code), 'the same code cannot be used twice');

// --- 6. Wrong purpose does not match ----------------------------------------
$code2 = Otp::issue($hhEmail, 'email', 'account_activation', $hhId);
t_ok(!Otp::verify($hhEmail, 'password_reset', $code2), 'a code is scoped to its purpose');
t_ok(Otp::verify($hhEmail, 'account_activation', $code2), 'the same code still works for its own purpose');

// --- 7. The attempt cap ------------------------------------------------------
$code3 = Otp::issue($hhEmail, 'email', 'account_activation', $hhId);
for ($i = 0; $i < 5; $i++) {
    t_ok(!Otp::verify($hhEmail, 'account_activation', '000000'), 'a wrong code fails (' . ($i + 1) . ')');
}
t_ok(!Otp::verify($hhEmail, 'account_activation', $code3), 'the right code is refused once the attempts run out');

// --- 8. Expiry ---------------------------------------------------------------
$code4 = Otp::issue($hhEmail, 'email', 'account_activation', $hhId);
Database::run(
    "UPDATE otp_verifications SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
      WHERE identifier = :id AND purpose = 'account_activation' AND consumed_at IS NULL",
    [':id' => $hhEmail]
);
t_ok(!Otp::verify($hhEmail, 'account_activation', $code4), 'an expired code does not verify');

// Cleanup.
$cleanup($pdo, [$hhEmail, $bizEmail]);

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
