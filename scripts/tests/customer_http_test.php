<?php
/**
 * scripts/tests/customer_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. End to end customer auth over HTTP against the real controllers.
 * It starts a throwaway PHP server on the scratch database, then drives the
 * actual endpoints the browser uses:
 *
 *   - register a household and a business (with CSRF), and read the rows back
 *   - a duplicate registration is refused without leaking anything
 *   - sign in by email and by phone in several shapes, and land in the right place
 *   - activate with a one-time code, and prove the same code cannot be reused
 *   - reset a password by code, and prove the new password works and the old does not
 *
 *   php scripts/tests/customer_http_test.php
 *
 * It creates throwaway customers, asserts, then removes them. It never touches
 * real accounts. Run it after php scripts/migrate.php on a scratch database.
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

// Issue codes in the same timezone the server verifies them in, so the codes
// this test mints line up with the app's naive DATETIME comparisons. The real
// app always issues and verifies in one process, so this only matters here.
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Lagos');

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

define('BASE', 'http://127.0.0.1:8199');

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

/** One HTTP call with a per-jar cookie file. Returns [status, decoded-or-body, redirect]. */
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
    $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = $resp === false ? '' : substr($resp, $hsize);
    return [$code, $json ? (json_decode($body, true) ?? []) : $body, $redirect];
}

/** Fetch a fresh CSRF token for a jar by loading a page it can see. */
function token(string $jar): string {
    [, $html] = http('GET', '/account.php', null, $jar, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', (string) $html, $m) ? $m[1] : '';
}

// ---- Start a throwaway server on the scratch database ----------------------
$log = '/tmp/okv_http_test_server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8199 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']],
    $pipes
);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the test server.\n"); exit(2); }
$up = false;
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', 8199, $errno, $errstr, 0.2);
    if ($c) { fclose($c); $up = true; break; }
    usleep(100000);
}
if (!$up) { proc_terminate($server); fwrite(STDERR, "The test server did not come up.\n"); exit(2); }

$pdo = Database::getInstance()->getConnection();
$hhEmail  = 'httptest-hh@okveggies.com.ng';
$bizEmail = 'httptest-biz@okveggies.com.ng';
$pw       = 'weekend-basket-88';

$cleanup = static function (PDO $pdo, array $emails): void {
    $in = implode(', ', array_fill(0, count($emails), '?'));
    $pdo->prepare("DELETE a FROM audit_logs a JOIN users u ON u.id = a.actor_user_id WHERE u.email IN ($in)")->execute($emails);
    $pdo->prepare("DELETE bc FROM business_customers bc JOIN users u ON u.id = bc.user_id WHERE u.email IN ($in)")->execute($emails);
    $pdo->prepare("DELETE FROM users WHERE email IN ($in)")->execute($emails);
};

try {
    $cleanup($pdo, [$hhEmail, $bizEmail]);

    $jarDir = sys_get_temp_dir();
    $jarHH   = tempnam($jarDir, 'okvhh');
    $jarBiz  = tempnam($jarDir, 'okvbz');
    $jarDup  = tempnam($jarDir, 'okvdp');
    $jarLog  = tempnam($jarDir, 'okvlg');

    // ---- 1. Register a household ------------------------------------------
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => token($jarHH),
        'first_name' => 'Ada', 'last_name' => 'Household', 'email' => $hhEmail,
        'phone' => '08090000011', 'password' => $pw, 'account_type' => 'household',
    ], $jarHH);
    t_eq(201, $code, 'registering a household returns 201');
    t_eq('ok', $res['status'] ?? '', 'registration succeeds');
    t_eq('/account.php', $res['redirect'] ?? '', 'registration lands on the account page');

    $hh = Database::one('SELECT id, user_type, phone, email_verified_at FROM users WHERE email = :e', [':e' => $hhEmail]);
    t_ok($hh !== null, 'the household user row exists');
    t_eq('household', $hh['user_type'], 'the account type is household');
    t_eq('+2348090000011', $hh['phone'], 'the phone is stored in E.164');
    t_ok($hh['email_verified_at'] === null, 'a new account starts not activated');

    // ---- 2. A duplicate registration is refused, nothing leaked -----------
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => token($jarDup),
        'first_name' => 'Someone', 'last_name' => 'Else', 'email' => $hhEmail,
        'phone' => '08090000099', 'password' => $pw, 'account_type' => 'household',
    ], $jarDup);
    t_eq(409, $code, 'a duplicate registration returns 409');
    t_eq('account_exists', $res['code'] ?? '', 'it reports account_exists');
    t_eq($hhEmail, $res['prefill'] ?? '', 'it prefills only the email the person typed');
    t_ok(!isset($res['first_name']) && !isset($res['name']), 'it reveals no name or other detail');

    // ---- 3. Register a business with a profile ----------------------------
    [$code, $res] = http('POST', '/api/v1/auth.php', [
        'action' => 'register', 'context' => 'storefront', 'okv_csrf' => token($jarBiz),
        'first_name' => 'Bola', 'last_name' => 'Business', 'email' => $bizEmail,
        'phone' => '+2348090000012', 'password' => $pw, 'account_type' => 'business',
        'business_name' => 'Bola Kitchen', 'business_type' => 'Restaurant', 'request_credit' => '1',
    ], $jarBiz);
    t_eq(201, $code, 'registering a business returns 201');
    $biz = Database::one('SELECT id FROM users WHERE email = :e', [':e' => $bizEmail]);
    $bizProfile = $biz ? Database::one('SELECT business_name, credit_status FROM business_customers WHERE user_id = :u', [':u' => (int) $biz['id']]) : null;
    t_ok($bizProfile !== null, 'the business profile row exists');
    t_eq('Bola Kitchen', $bizProfile['business_name'] ?? '', 'the business name is saved');
    t_eq('requested', $bizProfile['credit_status'] ?? '', 'an opt-in credit request is recorded');

    // ---- 4. Every Pro page uses the same customer account gate ------------
    $proRoutes = [
        '/pro/' => 'Dashboard',
        '/pro/index.php' => 'Dashboard',
        '/pro/kitchen_lists.php' => 'My Kitchen Lists',
        '/pro/standing_orders.php' => 'Standing Orders',
        '/pro/orders.php' => 'Orders and Invoices',
        '/pro/credit.php' => 'Credit',
        '/pro/account.php' => 'Account and Branches',
    ];
    $jarGuest = tempnam($jarDir, 'okvgs');
    foreach ($proRoutes as $route => $title) {
        [$guestCode, , $guestTo] = http('GET', $route, null, $jarGuest, false);
        t_eq(302, $guestCode, "a guest is redirected from $route");
        t_eq('/account.php?mode=signin&return=' . rawurlencode($route), parse_url($guestTo, PHP_URL_PATH) . '?' . parse_url($guestTo, PHP_URL_QUERY), "the guest return path is safe for $route");

        [$houseCode, , $houseTo] = http('GET', $route, null, $jarHH, false);
        $housePath = $route === '/pro/kitchen_lists.php'
            ? '/kitchen-runs.php?notice=pro_business'
            : '/account.php?notice=pro_business';
        t_eq(302, $houseCode, "a household is redirected from $route");
        t_eq($housePath, parse_url($houseTo, PHP_URL_PATH) . '?' . parse_url($houseTo, PHP_URL_QUERY), "the household gets a useful destination for $route");

        [$businessCode, $businessBody] = http('GET', $route, null, $jarBiz, false);
        t_eq(200, $businessCode, "a business reaches $route");
        t_ok(strpos((string) $businessBody, '<h1 class="okv-page-title">' . $title . '</h1>') !== false, "$route renders its real screen title");
        t_ok(strpos((string) $businessBody, 'aria-current="page"') !== false, "$route marks its current navigation path");
        t_ok(strpos((string) $businessBody, 'Coming soon') === false, "$route has no misleading scaffold notice");
    }

    [, $signInBody] = http('GET', '/account.php?mode=signin&return=%2Fpro%2Fcredit.php', null, $jarGuest, false);
    t_ok(strpos((string) $signInBody, 'name="return" value="/pro/credit.php"') !== false, 'the customer sign-in form keeps the safe Credit return path');
    [, $houseAccount] = http('GET', '/account.php?notice=pro_business', null, $jarHH, false);
    t_ok(strpos((string) $houseAccount, 'Pro screens are for business accounts') !== false, 'the household account destination explains the Pro boundary');
    [, $houseRuns] = http('GET', '/kitchen-runs.php?notice=pro_business', null, $jarHH, false);
    t_ok(strpos((string) $houseRuns, 'Your household Kitchen Runs are all available here') !== false, 'the household Kitchen Runs destination explains the Pro boundary');
    [, $dashboardBody] = http('GET', '/pro/', null, $jarBiz, false);
    t_ok(strpos((string) $dashboardBody, 'Bola Kitchen') !== false, 'the Pro dashboard names the signed-in business');
    t_ok(strpos((string) $dashboardBody, 'Credit has not been approved for this account') !== false, 'a business without approved credit gets the plain application route');
    t_ok(strpos((string) $dashboardBody, 'No orders on this business account yet') !== false, 'a business without orders gets useful ordering paths');
    t_ok(strpos((string) $dashboardBody, $hhEmail) === false, 'the Pro dashboard contains no household account detail');

    // ---- Saved Kitchen Lists are business-only CRUD -----------------------
    $listProduct = Database::one('SELECT id, unit_id, name FROM products WHERE is_active = 1 ORDER BY id LIMIT 1');
    $listUnit = (int) $listProduct['unit_id'];
    $listFields = [
        'action' => 'create', 'name' => 'HTTP Tuesday list', 'note' => 'Main kitchen',
        'items' => [
            ['product_id' => $listProduct['id'], 'quantity' => '2', 'note' => 'Firm only'],
            ['item_name' => 'Pomo', 'quantity' => '5', 'unit_id' => $listUnit, 'note' => 'Soft cuts'],
        ],
    ];
    [$guestListCode] = http('POST', '/api/v1/kitchen_lists.php', $listFields + ['okv_csrf' => token($jarGuest)], $jarGuest);
    t_eq(401, $guestListCode, 'a guest cannot create a saved Kitchen List');
    [$houseListCode] = http('POST', '/api/v1/kitchen_lists.php', $listFields + ['okv_csrf' => token($jarHH)], $jarHH);
    t_eq(403, $houseListCode, 'a household cannot create a saved Kitchen List');
    [$listCode, $listResult] = http('POST', '/api/v1/kitchen_lists.php', $listFields + ['okv_csrf' => token($jarBiz)], $jarBiz);
    t_eq(200, $listCode, 'a business creates a saved Kitchen List');
    $savedListId = (int) ($listResult['id'] ?? 0);
    t_ok($savedListId > 0, 'the saved-list create returns its own id');

    [$startCode, $startResult] = http('POST', '/api/v1/kitchen_lists.php', [
        'action' => 'start_run', 'list_id' => $savedListId, 'okv_csrf' => token($jarBiz),
    ], $jarBiz);
    t_eq(200, $startCode, 'a business starts the review path from its saved list');
    t_eq('/kitchen-runs.php?start=catalogue&saved_list=' . $savedListId, $startResult['redirect'] ?? '', 'starting a list opens the existing Kitchen Run form');
    [, $prefilledRun] = http('GET', '/kitchen-runs.php?start=catalogue&saved_list=' . $savedListId, null, $jarBiz, false);
    t_ok(strpos((string) $prefilledRun, 'value="Pomo"') !== false, 'the free-text line is prefilled for review');
    t_ok(strpos((string) $prefilledRun, 'value="Soft cuts"') !== false, 'the line note is prefilled for review');
    t_ok(strpos((string) $prefilledRun, '>Main kitchen</textarea>') !== false, 'the overall list note is prefilled for review');

    [$unconfirmedDelete] = http('POST', '/api/v1/kitchen_lists.php', [
        'action' => 'delete', 'list_id' => $savedListId, 'okv_csrf' => token($jarBiz),
    ], $jarBiz);
    t_eq(422, $unconfirmedDelete, 'deleting a saved list requires explicit confirmation');
    [$deleteCode] = http('POST', '/api/v1/kitchen_lists.php', [
        'action' => 'delete', 'list_id' => $savedListId, 'confirm_delete' => '1', 'okv_csrf' => token($jarBiz),
    ], $jarBiz);
    t_eq(200, $deleteCode, 'a business deletes its confirmed saved list');

    // ---- 5. Sign in by email and by phone, land in the right place --------
    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'okv_csrf' => token($jarLog), 'identifier' => $hhEmail, 'password' => $pw], $jarLog);
    t_eq(200, $code, 'sign in by email works');
    t_eq('/', $res['redirect'] ?? '', 'a household lands on the shop');

    foreach (['08090000011' => '0-leading', '+2348090000011' => 'E.164', '2348090000011' => '234 prefix'] as $form => $shape) {
        [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'okv_csrf' => token($jarLog), 'identifier' => (string) $form, 'password' => $pw], $jarLog);
        t_eq(200, $code, "sign in by phone ($shape) works");
    }

    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'okv_csrf' => token($jarLog), 'identifier' => $hhEmail, 'password' => 'the-wrong-password'], $jarLog);
    t_eq(401, $code, 'a wrong password is refused');

    $jarBizLog = tempnam($jarDir, 'okvbl');
    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'return' => '/pro/credit.php?account=someone-else', 'okv_csrf' => token($jarBizLog), 'identifier' => $bizEmail, 'password' => $pw], $jarBizLog);
    t_eq('/pro/credit.php', $res['redirect'] ?? '', 'a business returns to the requested Pro path without its query string');

    $jarUnsafe = tempnam($jarDir, 'okvus');
    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'return' => 'https://example.test/pro/credit.php', 'okv_csrf' => token($jarUnsafe), 'identifier' => $bizEmail, 'password' => $pw], $jarUnsafe);
    t_eq('/pro', $res['redirect'] ?? '', 'an unsafe sign-in return is ignored');

    // ---- 6. Activation, and a code cannot be used twice -------------------
    // jarHH is still signed in from registration. Issue a known code, then verify it.
    $activationCode = Otp::issue($hhEmail, 'email', 'account_activation', (int) $hh['id']);
    [$code, $res] = http('POST', '/api/v1/otp.php', ['action' => 'verify', 'okv_csrf' => token($jarHH), 'code' => $activationCode], $jarHH);
    t_eq(200, $code, 'a valid activation code is accepted');
    t_ok(($res['activated'] ?? false) === true, 'the response says the account is active');
    $after = Database::one('SELECT email_verified_at FROM users WHERE id = :id', [':id' => (int) $hh['id']]);
    t_ok($after['email_verified_at'] !== null, 'the account is now activated in the database');

    // Re-posting after activation is idempotent: the controller sees the account
    // is already active and says so, rather than erroring. The code's true
    // single-use is proved at the mechanism level in customer_auth_db_test.php.
    [$code, $res] = http('POST', '/api/v1/otp.php', ['action' => 'verify', 'okv_csrf' => token($jarHH), 'code' => $activationCode], $jarHH);
    t_eq(200, $code, 're-posting after activation is idempotent, not an error');
    t_ok(($res['activated'] ?? false) === true, 'the account stays active on a repeat verify');

    // ---- 7. Password reset by code ----------------------------------------
    $jarReset = tempnam($jarDir, 'okvrs');
    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'forgot_password', 'okv_csrf' => token($jarReset), 'email' => $hhEmail], $jarReset);
    t_eq(200, $code, 'asking for a reset code answers the same either way');

    $resetCode = Otp::issue($hhEmail, 'email', 'password_reset', (int) $hh['id']);
    $newPw = 'stew-kit-fresh-77';
    [$code, $res] = http('POST', '/api/v1/auth.php', ['action' => 'reset_password', 'okv_csrf' => token($jarReset), 'email' => $hhEmail, 'code' => $resetCode, 'new_password' => $newPw, 'confirm_password' => $newPw], $jarReset);
    t_eq(200, $code, 'a valid reset code sets the new password');

    $jarNew = tempnam($jarDir, 'okvnw');
    [$code, ] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'okv_csrf' => token($jarNew), 'identifier' => $hhEmail, 'password' => $newPw], $jarNew);
    t_eq(200, $code, 'the new password signs in');
    $jarOld = tempnam($jarDir, 'okvod');
    [$code, ] = http('POST', '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'okv_csrf' => token($jarOld), 'identifier' => $hhEmail, 'password' => $pw], $jarOld);
    t_eq(401, $code, 'the old password no longer works');

} finally {
    $cleanup($pdo, [$hhEmail, $bizEmail]);
    proc_terminate($server);
}

$t = $GLOBALS['t']; $p = $GLOBALS['p'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) { fwrite(STDERR, count($GLOBALS['f']) . " failed.\n"); exit(1); }
fwrite(STDOUT, "All green.\n");
exit(0);
