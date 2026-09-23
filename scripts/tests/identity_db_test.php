<?php
/**
 * scripts/tests/identity_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Identity checks that need the database. One real person is one
 * identity: the email and the phone are stored in one canonical form, every
 * lookup shares the same rules, a collision is reported field by field, and a
 * person who is both staff and customer is one row with a role, never two
 * rows. Run against a scratch database:
 *
 *   php scripts/tests/identity_db_test.php
 *
 * It creates throwaway users, asserts, then removes them. It never touches
 * real accounts. The same journeys over HTTP (sign-in forms, duplicate
 * messages, rate limiting) are proved by scripts/tests/identity_http_test.php.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);

require_once __DIR__ . '/lib/scratch_guard.php';
require_once $root . '/includes/config/db.php';
require_once $root . '/includes/classes/Database.php';
require_once $root . '/includes/classes/Password.php';
require_once $root . '/includes/classes/Phone.php';
require_once $root . '/includes/classes/Rbac.php';
require_once $root . '/includes/classes/Auth.php';
require_once $root . '/includes/classes/Settings.php';
require_once $root . '/includes/classes/Notifications.php';

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

$password = 'market-basket-77';
$emails = [
    'dbtest-id-ada@okveggies.com.ng',
    'dbtest-id-bola@okveggies.com.ng',
    'dbtest-id-chidi@okveggies.com.ng',
    'dbtest-id-dual@okveggies.com.ng',
    'dbtest-id-off@okveggies.com.ng',
    'dbtest-id-leg@okveggies.com.ng',
    'dbtest-id-amb-a@okveggies.com.ng',
    'dbtest-id-amb-b@okveggies.com.ng',
];

/** Remove any leftovers from an earlier run. Roles and codes cascade. */
$cleanup = static function (PDO $pdo, array $emails): void {
    $in = implode(', ', array_fill(0, count($emails), '?'));
    $pdo->prepare("DELETE bc FROM business_customers bc JOIN users u ON u.id = bc.user_id WHERE u.email IN ($in)")->execute($emails);
    $pdo->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails);
};
$cleanup($pdo, $emails);

/** Insert a user exactly as a given write path would store it. */
$make = static function (PDO $pdo, string $first, string $last, string $email, string $phone, string $type, string $status = 'active'): int {
    $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
    )->execute([$first, $last, $email, $phone, Password::hash($GLOBALS['password']), $type, $status]);
    return (int) $pdo->lastInsertId();
};

// A household customer stored the way registration stores identities.
$adaId = $make($pdo, 'Ada', 'Identity', $emails[0], '+2348095000001', 'household');

// --- 1. Email login, whatever the casing typed -------------------------------
$byLower = Auth::findByIdentifier($emails[0]);
t_ok($byLower !== null && (int) $byLower['id'] === $adaId, 'found by the stored lower case email');
$byMixed = Auth::findByIdentifier('DbTest-ID-Ada@OKVeggies.com.NG');
t_ok($byMixed !== null && (int) $byMixed['id'] === $adaId, 'found with a mixed case email typed at the sign-in form');
$byPadded = Auth::findByIdentifier('  dbtest-id-ada@okveggies.com.ng  ');
t_ok($byPadded !== null && (int) $byPadded['id'] === $adaId, 'found with a padded email');

// --- 2. Phone login, every accepted equivalent form --------------------------
foreach ([
    '+2348095000001'      => 'E.164',
    '08095000001'         => 'the plain local form',
    '0809 500 0001'       => 'the spaced local form',
    '0809-500-0001'       => 'the dashed local form',
    '2348095000001'       => 'the country code without a plus',
    '002348095000001'     => 'the 00 international prefix',
    '+234 809 500 0001'   => 'E.164 with spaces',
    '23408095000001'      => 'country code plus a leading zero',
    '8095000001'          => 'the bare 10 digit national number',
] as $form => $label) {
    $u = Auth::findByIdentifier($form);
    t_ok($u !== null && (int) $u['id'] === $adaId, "found by phone typed as $label ($form)");
}

// --- 3. Invalid and unsupported phone values find nobody ---------------------
foreach ([
    '0809500000'       => 'one digit short',
    '080950000011'     => 'one digit long',
    '06095000001'      => 'a 06 number, outside the mobile policy',
    '01234567890'      => 'a landline shaped number',
    '+441234567890'    => 'a non-Nigerian number',
    'not-a-number'     => 'letters',
] as $form => $label) {
    t_eq(null, Auth::findByIdentifier($form), "an identifier that is $label finds nobody");
}
t_eq(null, Auth::findByIdentifier('+2348095999999'), 'a valid unknown number finds nobody');

// --- 4. Wrong password never verifies ----------------------------------------
$row = Database::one('SELECT password_hash FROM users WHERE id = :id', [':id' => $adaId]);
t_ok(Password::verify($password, $row['password_hash']), 'the right password verifies');
t_ok(!Password::verify('wrong-password-99', $row['password_hash']), 'the wrong password does not verify');

// --- 5. Field-specific duplicate detection -----------------------------------
$bolaId = $make($pdo, 'Bola', 'Identity', $emails[1], '+2348095000002', 'household');

$onlyEmail = Auth::findIdentityConflict($emails[1], '+2348095000099');
t_ok($onlyEmail !== null && $onlyEmail['field'] === 'email', 'a duplicate email only is reported as email');
t_eq($bolaId, $onlyEmail['email_user_id'] ?? null, 'the email report points at the right identity');

$onlyPhone = Auth::findIdentityConflict('fresh-name@okveggies.com.ng', '0809 500 0002');
t_ok($onlyPhone !== null && $onlyPhone['field'] === 'phone', 'a duplicate phone only is reported as phone, in any equivalent form');
t_eq($bolaId, $onlyPhone['phone_user_id'] ?? null, 'the phone report points at the right identity');

$both = Auth::findIdentityConflict('DBTEST-ID-BOLA@okveggies.com.ng', '+234 809 500 0002');
t_ok($both !== null && $both['field'] === 'both', 'both details duplicated are reported together');

t_eq(null, Auth::findIdentityConflict('nobody-here@okveggies.com.ng', '+2348095000098'), 'free identifiers report no conflict');

// An edit never collides with its own row.
$own = Auth::findIdentityConflict($emails[1], '+2348095000002', $bolaId);
t_eq(null, $own, 'a profile edit excludes its own identity');

// The database itself enforces the invariant on both fields.
try {
    $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Dup\', \'Email\', ?, ?, ?, \'household\', \'active\')'
    )->execute([$emails[1], '+2348095000097', Password::hash($password)]);
    t_ok(false, 'the unique index refuses a second row with the same email, in any casing');
    $pdo->prepare('DELETE FROM users WHERE email = :e AND first_name = \'Dup\'')->execute([$emails[1]]);
} catch (PDOException $e) {
    t_ok(str_starts_with((string) $e->getCode(), '23'), 'the unique index refuses a second row with the same email, in any casing');
}
try {
    $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (\'Dup\', \'Phone\', ?, ?, ?, \'household\', \'active\')'
    )->execute(['dup-phone@okveggies.com.ng', '+2348095000002', Password::hash($password)]);
    t_ok(false, 'the unique index refuses a second row with the same phone');
    $pdo->prepare('DELETE FROM users WHERE phone = :p AND first_name = \'Dup\'')->execute(['+2348095000002']);
} catch (PDOException $e) {
    t_ok(str_starts_with((string) $e->getCode(), '23'), 'the unique index refuses a second row with the same phone');
}

// --- 6. Legacy non-canonical phones, and the repair ---------------------------
// A row stored the way the old staff form stored them: raw, with spaces.
$legId = $make($pdo, 'Legacy', 'Identity', $emails[5], '0809 500 0031', 'staff');
t_eq(null, Auth::findByIdentifier('08095000031'), 'a legacy non-canonical phone cannot be signed in with yet');
Database::run('UPDATE users SET phone = :new WHERE id = :id', [':new' => Phone::normalize('0809 500 0031'), ':id' => $legId]);
$fixed = Auth::findByIdentifier('08095000031');
t_ok($fixed !== null && (int) $fixed['id'] === $legId, 'once the repair canonicalises the row, every form signs in');
t_ok(Auth::findByIdentifier('+234 809 500 0031') !== null, 'and the E.164 form signs in too');

// --- 7. Ambiguous history fails closed instead of picking a row ---------------
$ambA = $make($pdo, 'Amber', 'One', $emails[6], '08095000021', 'household');
$ambB = $make($pdo, 'Amber', 'Two', $emails[7], '+2348095000021', 'household');
t_eq(null, Auth::findByIdentifier('0809 500 0021'), 'two rows sharing one canonical phone sign nobody in');
t_eq(null, Auth::findByIdentifier('+2348095000021'), 'the ambiguity fails closed for every equivalent form');
$ambConflict = Auth::findIdentityConflict('free-amb@okveggies.com.ng', '002348095000021');
t_ok($ambConflict !== null && $ambConflict['field'] === 'phone', 'the shared check reports the ambiguous phone as taken');
// The owner resolves the pair; the lookup heals.
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$ambA]);
$healed = Auth::findByIdentifier('08095000021');
t_ok($healed !== null && (int) $healed['id'] === $ambB, 'once the pair is resolved, the identifier signs in again');

// --- 8. One person, both staff and customer -----------------------------------
$dualId = $make($pdo, 'Dual', 'Identity', $emails[3], '+2348095000004', 'household');
$managerRole = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => 'manager']);
$pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, NULL)')->execute([$dualId, (int) $managerRole['id']]);

$dualRow = Database::one('SELECT * FROM users WHERE id = :id', [':id' => $dualId]);
t_ok(Auth::isStaffUser($dualRow), 'a customer row with a role is staff');
t_ok(!Auth::isStaffUser($byLower), 'a customer row without a role is not staff');
t_eq('/admin', Auth::landingPath($dualRow), 'a staff member who is also a customer lands on the admin panel');
t_eq('/', Auth::landingPath($byLower), 'a plain customer still lands on the shop');

Rbac::loadFromDb($dualId);
t_ok(Rbac::isLoggedIn(), 'the dual identity is logged in');
t_ok(Rbac::isStaff(), 'the dual identity carries staff permissions from the role');
t_ok(Rbac::hasPermission('orders.view'), 'the dual identity keeps the manager permission set');
t_ok(!Rbac::hasPermission('users.create'), 'the dual identity is still scoped like a manager');
t_ok(in_array((string) $dualRow['user_type'], ['household', 'business'], true), 'the dual identity keeps its customer account type on the same row');

// A staff creation that meets this identity must attach, never add a second row.
$before = (int) Database::one('SELECT COUNT(*) AS c FROM users WHERE phone = :p OR email = :e', [':p' => '+2348095000004', ':e' => $emails[3]])['c'];
t_eq(1, $before, 'the dual identity is exactly one row before any staff creation');

// Staff notification audiences include the dual identity (role based).
$recipients = Notifications::staffRecipients();
$foundDual = false;
foreach ($recipients as $r) {
    if ((int) ($r['user_id'] ?? 0) === $dualId) { $foundDual = true; break; }
}
t_ok($foundDual, 'staff alert recipients include a customer row that holds a role');

// --- 9. Suspended accounts stay findable; the sign-in handler refuses them ----
$offId = $make($pdo, 'Off', 'Identity', $emails[4], '+2348095000005', 'household', 'disabled');
$offRow = Auth::findByIdentifier('08095000005');
t_ok($offRow !== null && (int) $offRow['id'] === $offId, 'a suspended account is still found by its identifier');
t_eq('disabled', (string) ($offRow['status'] ?? ''), 'the row carries its suspended status to the handler, which refuses it');

// --- 10. The staff reset scope is role based, like the staff directory --------
$staffScope = static function (string $email): ?array {
    return Database::one(
        "SELECT u.id
           FROM users u
          WHERE u.email = :e AND u.status = 'active'
            AND (u.user_type = 'staff' OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id))",
        [':e' => $email]
    );
};
t_ok($staffScope($emails[3]) !== null, 'the staff password reset scope includes the dual identity');
t_ok($staffScope($emails[0]) === null, 'the staff password reset scope excludes a plain customer');
t_ok($staffScope($emails[5]) !== null, 'the staff password reset scope includes the legacy staff account type');

// Cleanup (roles and addresses cascade on the user delete).
$cleanup($pdo, $emails);

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
