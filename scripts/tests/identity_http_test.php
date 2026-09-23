<?php
/**
 * scripts/tests/identity_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Identity journeys end to end over HTTP against the real
 * controllers. It starts a throwaway PHP server on the scratch database, then
 * drives the endpoints a browser uses:
 *
 *   - register with a mixed case email, then sign in with a different casing
 *   - sign in by phone in every accepted equivalent form
 *   - invalid identifiers, wrong passwords and suspended accounts are refused
 *     with the same plain shape, revealing nothing about who is registered
 *   - duplicate registrations name the field that collided
 *   - two registrations of the same identity racing produce exactly one account
 *   - staff creation stores canonical identifiers, and the Owner can sign a new
 *     team member in by any phone form afterwards
 *   - making a customer staff attaches the role to the same row (one identity)
 *   - the login rate limit still locks an identifier after five failed tries
 *   - the password reset "send a code" answer never confirms an account exists
 *
 *   php scripts/tests/identity_http_test.php
 *
 * It creates throwaway users, asserts, then removes them. It never touches
 * real accounts. Run it after php scripts/migrate.php on a scratch database.
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
require_once $root . '/includes/classes/RateLimiter.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

define('BASE', 'http://127.0.0.1:8197');

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

/** One HTTP call with a per-jar cookie file. Returns [status, decoded-or-body, headers]. */
function http(string $method, string $path, ?array $fields, string $jar, bool $json = true): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $headers = [];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    if ($json) { $headers[] = 'X-Requested-With: fetch'; $headers[] = 'Accept: application/json'; }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $resp  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = $resp === false ? '' : substr($resp, $hsize);
    $head = $resp === false ? '' : substr($resp, 0, $hsize);
    return [$code, $json ? (json_decode($body, true) ?? []) : $body, $head];
}

/** The storefront CSRF token for a jar, from the account page meta tag. */
function storefront_token(string $jar): string {
    [, $html] = http('GET', '/account.php', null, $jar, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', (string) $html, $m) ? $m[1] : '';
}

/** An admin CSRF token for a signed-in staff jar, from a form hidden field. */
function admin_token(string $jar): string {
    [, $html] = http('GET', '/admin/users.php', null, $jar, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', (string) $html, $m) ? $m[1] : '';
}

/** A CSRF token from the staff sign-in page, for jars that are not signed in. */
function admin_token_from_login(string $jar): string {
    [, $html] = http('GET', '/admin/login.php', null, $jar, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', (string) $html, $m) ? $m[1] : '';
}

/** The session cookie value a jar currently holds, or an empty string. */
function session_cookie(string $jar): string {
    if (!is_file($jar)) {
        return '';
    }
    foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') { continue; }
        $parts = preg_split('/\t/', $line);
        if (count($parts) >= 7 && $parts[5] === 'okv_session') {
            return (string) $parts[6];
        }
    }
    return '';
}

// ---- Start a throwaway server on the scratch database ----------------------
$log = '/tmp/okv_identity_http_test_server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8197 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
    $pipes
);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the test server.\n"); exit(2); }
$up = false;
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', 8197, $errno, $errstr, 0.2);
    if ($c) { fclose($c); $up = true; break; }
    usleep(100000);
}
if (!$up) { proc_terminate($server); fwrite(STDERR, "The test server did not come up.\n"); exit(2); }

$pdo = Database::getInstance()->getConnection();

$ownerEmail   = 'idhttp-owner@okveggies.com.ng';
$ownerPhone   = '+2348096000001';
$ownerPass    = 'stall-holder-strong-4';
$mixedEmail   = 'idhttp-mixed@example.test';   // registered in mixed case
$mixedPhone   = '+2348096000002';
$mixedPass    = 'pepper-soup-strong-6';
$staffEmail   = 'idhttp-staff@example.test';
$staffPhone   = '+2348096000003';
$staffPass    = 'yam-basket-strong-8';
$offEmail     = 'idhttp-off@example.test';
$offPhone     = '+2348096000004';
$raceEmail    = 'idhttp-race@example.test';
$racePhone    = '+2348096000005';
$rateEmail    = 'idhttp-rate@example.test';

$emails = [$ownerEmail, $mixedEmail, $staffEmail, $offEmail, $raceEmail, $rateEmail];
$cleanup = static function (PDO $pdo, array $emails): void {
    $in = implode(', ', array_fill(0, count($emails), '?'));
    $pdo->prepare("DELETE bc FROM business_customers bc JOIN users u ON u.id = bc.user_id WHERE u.email IN ($in)")->execute($emails);
    $pdo->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails);
};

try {
    $cleanup($pdo, $emails);

    // The Owner, seeded the way a first setup would store them.
    $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES ('Id', 'Owner', ?, ?, ?, 'staff', 'active', NOW())"
    )->execute([$ownerEmail, $ownerPhone, Password::hash($ownerPass)]);
    $ownerId = (int) $pdo->lastInsertId();
    $ownerRole = Database::one('SELECT id FROM roles WHERE name = :n', [':n' => 'owner']);
    $pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, NULL)')->execute([$ownerId, (int) $ownerRole['id']]);

    // A suspended customer, for the refusal path.
    $pdo->prepare(
        "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES ('Id', 'Off', ?, ?, ?, 'household', 'disabled')"
    )->execute([$offEmail, $offPhone, Password::hash($mixedPass)]);

    $jarDir = sys_get_temp_dir();

    // ---- 1. Register with a mixed case email; it is stored lower cased -----
    $jarReg = tempnam($jarDir, 'okvrg');
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => storefront_token($jarReg),
        'first_name' => 'Mixed', 'last_name' => 'Case', 'email' => 'IdHttp-Mixed@Example.TEST',
        'phone' => '0809 600 0002', 'password' => $mixedPass, 'account_type' => 'household',
    ], $jarReg);
    t_eq(201, $code, 'registration with a mixed case email succeeds');
    $mixed = Database::one('SELECT id, email, phone FROM users WHERE email = :e', [':e' => $mixedEmail]);
    t_ok($mixed !== null, 'the account is stored under the lower case email');
    t_eq($mixedEmail, (string) ($mixed['email'] ?? ''), 'the stored email is lower case');
    t_eq($mixedPhone, (string) ($mixed['phone'] ?? ''), 'the stored phone is E.164');

    // ---- 2. Sign in by email in a different casing than stored --------------
    $jarMix = tempnam($jarDir, 'okvmx');
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => storefront_token($jarMix),
        'identifier' => 'IDHTTP-mixed@EXAMPLE.test', 'password' => $mixedPass,
    ], $jarMix);
    t_eq(200, $code, 'sign in works with a mixed case email');
    t_eq('/', $res['redirect'] ?? '', 'a household lands on the shop');

    // ---- 3. Sign in by phone in every accepted equivalent form --------------
    foreach ([
        '08096000002'       => 'the plain local form',
        '0809 600 0002'     => 'the spaced local form',
        '2348096000002'     => 'the country code without a plus',
        '+2348096000002'    => 'E.164',
        '002348096000002'   => 'the 00 international prefix',
        '+234 809 600 0002' => 'E.164 with spaces',
    ] as $form => $label) {
        $jar = tempnam($jarDir, 'okvph');
        [$code, $res] = http('POST', '/api/v1/auth.php', [
            'action' => 'login', 'context' => 'storefront', 'okv_csrf' => storefront_token($jar),
            'identifier' => $form, 'password' => $mixedPass,
        ], $jar);
        t_eq(200, $code, "sign in works with $label ($form)");
        @unlink($jar);
    }

    // ---- 4. Invalid identifiers and wrong passwords reveal nothing ----------
    $jarBad = tempnam($jarDir, 'okvbd');
    $tokBad = storefront_token($jarBad);
    [$codeA, $resA] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => $tokBad,
        'identifier' => '06096000002', 'password' => $mixedPass,
    ], $jarBad);
    t_eq(401, $codeA, 'an identifier outside the phone policy is refused');
    [$codeB, $resB] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => $tokBad,
        'identifier' => $mixedEmail, 'password' => 'not-the-password',
    ], $jarBad);
    t_eq(401, $codeB, 'a wrong password is refused');
    [$codeC, $resC] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => $tokBad,
        'identifier' => 'idhttp-nobody@example.test', 'password' => $mixedPass,
    ], $jarBad);
    t_eq(401, $codeC, 'an unknown email is refused');
    t_eq('invalid_credentials', $resA['code'] ?? '', 'the invalid phone refusal carries the generic code');
    t_eq($resA['message'] ?? '', $resB['message'] ?? '', 'the invalid phone and wrong password messages are identical');
    t_eq($resB['message'] ?? '', $resC['message'] ?? '', 'the wrong password and unknown email messages are identical');

    // ---- 5. A suspended account is refused with its own plain message -------
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => storefront_token($jarBad),
        'identifier' => $offEmail, 'password' => $mixedPass,
    ], $jarBad);
    t_eq(403, $code, 'a suspended account cannot sign in');
    t_eq('inactive', $res['code'] ?? '', 'the suspension is reported as inactive');

    // ---- 6. Duplicate registrations name the field that collided ------------
    $jarDup = tempnam($jarDir, 'okvdp');
    $tokDup = storefront_token($jarDup);
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => $tokDup,
        'first_name' => 'Dup', 'last_name' => 'Email', 'email' => $mixedEmail,
        'phone' => '08096000091', 'password' => $mixedPass, 'account_type' => 'household',
    ], $jarDup);
    t_eq(409, $code, 'a duplicate email registration is refused');
    t_eq('email', $res['field'] ?? '', 'the refusal names the email');
    t_ok(stripos((string) ($res['message'] ?? ''), 'email') !== false, 'the message says the email is the problem');

    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => $tokDup,
        'first_name' => 'Dup', 'last_name' => 'Phone', 'email' => 'idhttp-fresh@example.test',
        'phone' => '002348096000002', 'password' => $mixedPass, 'account_type' => 'household',
    ], $jarDup);
    t_eq(409, $code, 'a duplicate phone registration is refused, in an equivalent form');
    t_eq('phone', $res['field'] ?? '', 'the refusal names the phone');
    t_ok(stripos((string) ($res['message'] ?? ''), 'phone') !== false, 'the message says the phone is the problem');

    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => $tokDup,
        'first_name' => 'Dup', 'last_name' => 'Both', 'email' => 'IDHTTP-MIXED@example.TEST',
        'phone' => '0809 600 0002', 'password' => $mixedPass, 'account_type' => 'household',
    ], $jarDup);
    t_eq(409, $code, 'a registration duplicating both fields is refused');
    t_eq('both', $res['field'] ?? '', 'the refusal names both fields');
    t_eq('account_exists', $res['code'] ?? '', 'the duplicate keeps the account_exists code the register form already handles');

    // ---- 7. Two registrations of the same identity racing -------------------
    $jarR1 = tempnam($jarDir, 'okvr1');
    $jarR2 = tempnam($jarDir, 'okvr2');
    $fields = [
        'action' => 'register', 'context' => 'storefront',
        'first_name' => 'Race', 'last_name' => 'Pair', 'email' => $raceEmail,
        'phone' => '08096000005', 'password' => $mixedPass, 'account_type' => 'household',
    ];
    $multi = curl_multi_init();
    $handles = [];
    foreach ([$jarR1, $jarR2] as $i => $jar) {
        $fields['okv_csrf'] = storefront_token($jar);
        $ch = curl_init(BASE . '/api/v1/auth.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['X-Requested-With: fetch', 'Accept: application/json'],
        ]);
        $handles[$i] = $ch;
        curl_multi_add_handle($multi, $ch);
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) { curl_multi_select($multi, 0.2); }
    } while ($running > 0 && $status === CURLM_OK);
    $raceCodes = [];
    foreach ($handles as $ch) {
        $raceCodes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    sort($raceCodes);
    t_eq([201, 409], $raceCodes, 'two racing registrations of the same identity produce exactly one account');
    $raceCount = (int) Database::one('SELECT COUNT(*) AS c FROM users WHERE email = :e', [':e' => $raceEmail])['c'];
    t_eq(1, $raceCount, 'the database holds exactly one row for the raced identity');

    // ---- 8. Staff sign-in by phone, session regeneration, RBAC loaded -------
    $jarOwner = tempnam($jarDir, 'okvow');
    http('GET', '/admin/login.php', null, $jarOwner, false);
    $beforeCookie = session_cookie($jarOwner);
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'okv_csrf' => admin_token_from_login($jarOwner),
        'identifier' => '0809 600 0001', 'password' => $ownerPass,
    ], $jarOwner);
    t_eq(200, $code, 'the Owner signs in with a spaced phone number');
    t_eq('/admin', $res['redirect'] ?? '', 'staff land on the admin panel');
    $afterCookie = session_cookie($jarOwner);
    t_ok($beforeCookie !== '' && $afterCookie !== '' && $beforeCookie !== $afterCookie, 'signing in regenerates the session id');
    [$code, $dash] = http('GET', '/admin/', null, $jarOwner, false);
    t_eq(200, $code, 'the signed-in Owner reaches the dashboard (RBAC loaded)');

    // ---- 9. Staff creation canonicalises both identifiers -------------------
    [$code, $res] = http('POST', '/api/v1/users.php', [
        'action' => 'create', 'okv_csrf' => admin_token($jarOwner),
        'first_name' => 'New', 'last_name' => 'Staff', 'email' => 'IdHttp-Staff@Example.TEST',
        'phone' => '0809 600 0003', 'password' => $staffPass, 'role' => 'manager',
    ], $jarOwner);
    t_eq(201, $code, 'the Owner adds a staff member');
    $staffRow = Database::one('SELECT id, email, phone FROM users WHERE email = :e', [':e' => $staffEmail]);
    t_eq($staffEmail, (string) ($staffRow['email'] ?? ''), 'the new staff email is stored lower case');
    t_eq($staffPhone, (string) ($staffRow['phone'] ?? ''), 'the new staff phone is stored in E.164');

    [$code, $res] = http('POST', '/api/v1/users.php', [
        'action' => 'create', 'okv_csrf' => admin_token($jarOwner),
        'first_name' => 'Bad', 'last_name' => 'Phone', 'email' => 'idhttp-badphone@example.test',
        'phone' => '01234567890', 'password' => $staffPass, 'role' => 'manager',
    ], $jarOwner);
    t_eq(422, $code, 'a staff phone outside the phone policy is refused');
    t_eq('bad_phone', $res['code'] ?? '', 'the refusal is the phone field');

    // The new staff member signs in by another equivalent form of the number.
    $jarStaff = tempnam($jarDir, 'okvsf');
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'okv_csrf' => admin_token_from_login($jarStaff),
        'identifier' => '002348096000003', 'password' => $staffPass,
    ], $jarStaff);
    t_eq(200, $code, 'the new staff member signs in by the 00 prefix form of their phone');
    t_eq('/admin', $res['redirect'] ?? '', 'the new staff member lands on the admin panel');

    // ---- 10. Making a customer staff attaches the role to the same row ------
    [$code, $res] = http('POST', '/api/v1/users.php', [
        'action' => 'create', 'okv_csrf' => admin_token($jarOwner),
        'first_name' => 'Mixed', 'last_name' => 'Case', 'email' => $mixedEmail,
        'phone' => '08096000002', 'password' => $staffPass, 'role' => 'manager',
    ], $jarOwner);
    t_eq(201, $code, 'making an existing customer staff succeeds');
    t_ok(stripos((string) ($res['message'] ?? ''), 'customer account') !== false, 'the answer says the customer account was used');
    $mixedCount = (int) Database::one('SELECT COUNT(*) AS c FROM users WHERE email = :e', [':e' => $mixedEmail])['c'];
    t_eq(1, $mixedCount, 'no second identity row was created');
    $roleCount = (int) Database::one(
        'SELECT COUNT(*) AS c FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.email = :e',
        [':e' => $mixedEmail]
    )['c'];
    t_eq(1, $roleCount, 'the staff role was attached to the existing row');

    // One sign-in serves both halves of the dual identity.
    $jarDual = tempnam($jarDir, 'okvdl');
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'okv_csrf' => admin_token_from_login($jarDual),
        'identifier' => $mixedEmail, 'password' => $staffPass,
    ], $jarDual);
    t_eq(200, $code, 'the dual identity signs in with the password the Owner set');
    t_eq('/admin', $res['redirect'] ?? '', 'the dual identity lands on the admin panel');
    [$code] = http('GET', '/admin/', null, $jarDual, false);
    t_eq(200, $code, 'the dual identity reaches the admin dashboard');
    [$code] = http('GET', '/account.php', null, $jarDual, false);
    t_eq(200, $code, 'the dual identity is still a customer on the storefront');

    // ---- 11. Staff duplicates are refused field by field --------------------
    [$code, $res] = http('POST', '/api/v1/users.php', [
        'action' => 'create', 'okv_csrf' => admin_token($jarOwner),
        'first_name' => 'Dup', 'last_name' => 'Staff', 'email' => $ownerEmail,
        'phone' => '08096000093', 'password' => $staffPass, 'role' => 'manager',
    ], $jarOwner);
    t_eq(409, $code, 'staff creation over a team member email is refused');
    t_eq('email_taken', $res['code'] ?? '', 'the refusal names the email field');

    [$code, $res] = http('POST', '/api/v1/users.php', [
        'action' => 'create', 'okv_csrf' => admin_token($jarOwner),
        'first_name' => 'Dup', 'last_name' => 'Staff', 'email' => 'idhttp-dupstaff@example.test',
        'phone' => '0809 600 0001', 'password' => $staffPass, 'role' => 'manager',
    ], $jarOwner);
    t_eq(409, $code, 'staff creation over a team member phone is refused, in an equivalent form');
    t_eq('phone_taken', $res['code'] ?? '', 'the refusal names the phone field');

    // ---- 12. The login rate limit still locks an identifier -----------------
    $jarRate = tempnam($jarDir, 'okvrt');
    $tokRate = storefront_token($jarRate);
    for ($i = 1; $i <= 5; $i++) {
        [$code] = http('POST', '/api/v1/auth.php', [
            'action' => 'login', 'context' => 'storefront', 'okv_csrf' => $tokRate,
            'identifier' => $rateEmail, 'password' => 'wrong-password-' . $i,
        ], $jarRate);
        t_eq(401, $code, "failed try $i is a plain refusal");
    }
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'okv_csrf' => $tokRate,
        'identifier' => $rateEmail, 'password' => 'wrong-password-6',
    ], $jarRate);
    t_eq(429, $code, 'the sixth failed try is rate limited');
    t_eq('rate_limited', $res['code'] ?? '', 'the lockout says rate limited');

    // ---- 13. The reset flow never confirms who has an account ---------------
    $jarReset = tempnam($jarDir, 'okvrs');
    $tokReset = storefront_token($jarReset);
    [, $known] = http('POST', '/api/v1/auth.php', [
        'action' => 'forgot_password', 'context' => 'storefront', 'okv_csrf' => $tokReset,
        'email' => $mixedEmail,
    ], $jarReset);
    [, $unknown] = http('POST', '/api/v1/auth.php', [
        'action' => 'forgot_password', 'context' => 'storefront', 'okv_csrf' => $tokReset,
        'email' => 'idhttp-ghost@example.test',
    ], $jarReset);
    t_eq($known['message'] ?? '', $unknown['message'] ?? '', 'the reset answer is identical for a known and an unknown email');

    // Release the buckets this suite spent, so the next suite starts clean.
    RateLimiter::reset('login:id:' . Auth::rateBucket($rateEmail));
    RateLimiter::reset('login:ip:127.0.0.1');
    RateLimiter::reset('pwreset:cool:' . sha1($mixedEmail));
    RateLimiter::reset('pwreset:win:' . sha1($mixedEmail));
    RateLimiter::reset('pwreset:cool:' . sha1('idhttp-ghost@example.test'));
    RateLimiter::reset('pwreset:win:' . sha1('idhttp-ghost@example.test'));
} finally {
    $cleanup($pdo, $emails);
    proc_terminate($server);
}

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
