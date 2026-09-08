<?php
/**
 * scripts/tests/kitchen_runs_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run HTTP contract: the method gate, CSRF, RBAC,
 * ownership, and the two screens loading as a browser loads them.
 *
 *   php -S 127.0.0.1:8123 -t . &
 *   OKV_TEST_BASE=http://127.0.0.1:8123 php scripts/tests/kitchen_runs_http_test.php
 *
 * The screen loads at the bottom are not padding. M6 shipped an orders filter
 * that answered 500 to every search because it bound one named placeholder
 * twice, and M7's first attempt shipped a conversion that threw for the same
 * reason. Both were invisible to unit tests and both would have been a single
 * red line here. A route nobody requests is a route nobody has tried.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs PHP curl.\n");
    exit(2);
}

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0;
$passed = 0;

function krh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}

function krh_eq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    krh_ok($ok, $label . ($ok ? '' : " (expected $expected, got $actual)"));
}

function krh_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $headers = $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function krh_csrf(string $jar, string $url): string
{
    [, $body] = krh_req($jar, $url, null, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

function krh_login(string $jar, string $base, string $page, string $email, string $password, string $after): string
{
    $token = krh_csrf($jar, $base . $page);
    [$code] = krh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token]);
    krh_eq(200, $code, "$email signs in");
    return krh_csrf($jar, $base . $after);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'kitchen-http-777';
$users = [];
$requestIds = [];
$jars = [
    tempnam(sys_get_temp_dir(), 'okv-krh-m-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-a-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-b-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-g-'),
    tempnam(sys_get_temp_dir(), 'okv-krh-p-'),
];

try {
    foreach ([['staff', 'Manager'], ['household', 'Owner'], ['household', 'Stranger'], ['business', 'Business']] as $index => [$type, $last]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'Kitchen\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':last' => $last,
                ':email' => "kr-http-$index-$suffix@example.test",
                ':phone' => '+23476' . random_int(10000000, 99999999),
                ':hash' => password_hash($password, PASSWORD_BCRYPT),
                ':type' => $type,
            ]
        );
        $users[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users[0]]);
    Database::run('DELETE FROM rate_limits');

    $unitId = (int) Database::one('SELECT id FROM units_of_measurement ORDER BY id LIMIT 1')['id'];
    $zoneId = (int) Database::one('SELECT id FROM delivery_zones WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $date = (string) Delivery::nextEligibleDates('household', 3)[0]['date'];

    // One request owned by the second user, so ownership can be tested.
    $owned = KitchenRunWorkflow::submit($users[1], 'household', [
        'recipient_name' => 'Kitchen Owner', 'recipient_phone' => '08031234567',
        'address_line_1' => '5 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId]],
    ]);
    $requestId = (int) $owned['id'];
    $ownedNumber = (string) $owned['request_number'];
    $requestIds[] = $requestId;
    $version = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];

    // --- 1. The gates that run before any action does ------------------------
    // Every action, not one of them. The method gate was only ever tested on
    // submit, which proves the gate exists rather than that it is on every
    // door; a seventh action added without it would have passed that test.
    $actions = ['submit', 'quote', 'approve', 'staff_approve', 'cancel', 'decline', 'convert', 'save_note'];

    foreach ($actions as $action) {
        [$code, $body] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php?action=' . $action . '&request_id=' . $requestId);
        krh_eq(405, $code, "the $action action refuses GET, because a write is not a link somebody can be sent");
        krh_ok(!str_contains($body, 'SQLSTATE'), "the $action method refusal leaks no driver message");
    }

    foreach ($actions as $action) {
        [$code, $body] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php', ['action' => $action, 'request_id' => $requestId, 'input_mode' => 'custom']);
        krh_eq(419, $code, "a signed-out caller cannot $action without a CSRF token");
        krh_ok(!str_contains($body, 'Exception') && !str_contains($body, 'SQLSTATE'), "the $action refusal never leaks an exception or a driver message");
    }

    // --- 2. Signed in, and gated by who you are ------------------------------
    $managerCsrf = krh_login($jars[0], $base, '/admin/login.php', "kr-http-0-$suffix@example.test", $password, '/admin/kitchen_runs.php');
    $ownerCsrf = krh_login($jars[1], $base, '/account.php', "kr-http-1-$suffix@example.test", $password, '/kitchen-runs.php');
    $strangerCsrf = krh_login($jars[2], $base, '/account.php', "kr-http-2-$suffix@example.test", $password, '/kitchen-runs.php');
    $businessCsrf = krh_login($jars[4], $base, '/account.php', "kr-http-3-$suffix@example.test", $password, '/kitchen-runs.php');

    krh_ok($managerCsrf !== '' && $ownerCsrf !== '' && $strangerCsrf !== '' && $businessCsrf !== '', 'every screen hands out a CSRF token');

    // A customer cannot do a staff job, whatever they post. Every staff action,
    // with a real session and a real token, so what refuses them is the
    // permission and nothing else.
    foreach (['quote', 'convert', 'decline', 'staff_approve', 'save_note'] as $action) {
        [$code, $body] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
            'action' => $action, 'request_id' => $requestId, 'state_version' => $version,
            'okv_csrf' => $ownerCsrf, 'payment_option' => 'deposit', 'admin_note' => 'no',
            'authorisation' => 'I approve my own quote', 'staff_note' => 'let me in',
        ]);
        krh_eq(403, $code, "a customer cannot $action a Kitchen Run");
        krh_ok(!str_contains($body, 'SQLSTATE'), "the refusal to $action tells the caller nothing about the database");
    }
    krh_eq(null, Database::one('SELECT staff_note FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['staff_note'], 'a refused save_note wrote nothing');

    // A customer cannot touch somebody else's request.
    [$code] = krh_req($jars[2], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'approve', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $strangerCsrf,
    ]);
    krh_eq(409, $code, 'another customer cannot approve a request that is not theirs');

    [$code] = krh_req($jars[2], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'cancel', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $strangerCsrf,
    ]);
    krh_eq(409, $code, 'another customer cannot withdraw a request that is not theirs');

    // Nor read its attachment route, which is a direct object reference.
    [$code] = krh_req($jars[2], $base . '/public/kitchen_run_attachment.php?request=' . $requestId, null, false);
    krh_eq(404, $code, 'another customer cannot reach somebody else\'s uploaded list');

    // --- 3. A staff member can do the staff job over the real route ----------
    [$code, $body] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'quote', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $managerCsrf,
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId, 'unit_price' => '4,000']],
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId, 'deposit' => '5,000',
    ]);
    krh_eq(200, $code, 'a manager quotes a Kitchen Run over the real route');
    $quoted = json_decode($body, true) ?: [];
    krh_eq(2000000, (int) ($quoted['total_subunit'] ?? 0), 'naira typed into the form become kobo exactly once, at the controller');

    // A second post carrying the version it already spent is refused, not
    // silently applied on top of somebody else's work.
    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'quote', 'request_id' => $requestId, 'state_version' => $version, 'okv_csrf' => $managerCsrf,
        'items' => [['item_name' => 'Pomo', 'quantity' => '5.000', 'unit_id' => $unitId, 'unit_price' => '9,000']],
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
    ]);
    krh_eq(409, $code, 'a stale quote post is refused rather than overwriting a colleague');

    $quotedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];
    [$code] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'approve', 'request_id' => $requestId, 'state_version' => $quotedVersion, 'okv_csrf' => $ownerCsrf,
    ]);
    krh_eq(200, $code, 'the customer who owns the request approves it');

    // Conversion over HTTP. This is the call the first attempt never once made.
    $approvedVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])['state_version'];
    [$code, $body] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'convert', 'request_id' => $requestId, 'state_version' => $approvedVersion,
        'payment_option' => 'deposit', 'okv_csrf' => $managerCsrf,
    ]);
    krh_eq(200, $code, 'a manager converts an approved Kitchen Run into an order over the real route');
    $order = json_decode($body, true) ?: [];
    krh_ok(!empty($order['order_number']), 'the conversion answers with the order number it made');
    krh_ok(!isset($order['trail_token']), 'the order trail token is the customer\'s, and is never handed back over the API');

    // --- 3b. A real submission over the real route, with the day and the area
    //     the customer chose on their own form (item 3).
    $submitCsrf = krh_csrf($jars[1], $base . '/kitchen-runs.php');
    [$code, $body] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'submit', 'okv_csrf' => $submitCsrf,
        'input_mode' => 'priced', 'pricing_mode' => 'already_priced',
        'recipient_name' => 'Kitchen Owner', 'recipient_phone' => '08031234567',
        'address_line_1' => '5 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
        'items' => [['item_name' => 'Dried fish', 'quantity' => '2.000', 'unit_id' => $unitId, 'unit_price' => '2,500']],
    ]);
    krh_eq(200, $code, 'a customer sends a list over the real route, with the day and the area on it');
    $sent = json_decode($body, true) ?: [];
    $phoneId = (int) ($sent['id'] ?? 0);
    krh_ok($phoneId > 0, 'the submission answers with the request it made');
    $requestIds[] = $phoneId;

    $storedAsk = Database::one('SELECT input_mode, preferred_delivery_date, delivery_zone_id FROM kitchen_run_requests WHERE id = :id', [':id' => $phoneId]);
    krh_eq($date, (string) $storedAsk['preferred_delivery_date'], 'the day the customer picked on the form is the day stored on the request');
    krh_eq($zoneId, (int) $storedAsk['delivery_zone_id'], 'so is the area they picked');
    krh_eq('priced', (string) $storedAsk['input_mode'], 'an already-priced list is recorded as its own mode over the real route too');

    [$code] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'submit', 'okv_csrf' => $submitCsrf,
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'recipient_name' => 'Kitchen Owner', 'recipient_phone' => '08031234567',
        'address_line_1' => '5 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'preferred_delivery_date' => '2020-01-01', 'delivery_zone_id' => $zoneId,
        'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]],
    ]);
    krh_eq(422, $code, 'a day the shop does not run is refused at the door, not stored and argued about later');

    // --- 3c. Staff approve for a customer who rang up (item 23), and the
    //     customer withdraws an approved run (item 26), both over the routes.
    $phoneVersion = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $phoneId])['state_version'];
    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'quote', 'request_id' => $phoneId, 'state_version' => $phoneVersion, 'okv_csrf' => $managerCsrf,
        'items' => [['item_name' => 'Dried fish', 'quantity' => '2.000', 'unit_id' => $unitId, 'unit_price' => '2,500']],
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneId,
    ]);
    krh_eq(200, $code, 'a manager prices the phoned-in list');

    $quotedPhone = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $phoneId])['state_version'];
    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'staff_approve', 'request_id' => $phoneId, 'state_version' => $quotedPhone,
        'okv_csrf' => $managerCsrf, 'authorisation' => '   ',
    ]);
    krh_eq(422, $code, 'staff cannot approve for a customer without saying who authorised it');

    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'staff_approve', 'request_id' => $phoneId, 'state_version' => $quotedPhone,
        'okv_csrf' => $managerCsrf, 'authorisation' => 'Approved by phone at 09:20 by the owner.',
    ]);
    krh_eq(200, $code, 'a manager records an approval the customer gave on the phone');
    $phoneTrail = KitchenRuns::history($phoneId);
    krh_eq('admin', (string) $phoneTrail[count($phoneTrail) - 1]['source'], 'the trail says a colleague did it, not the customer');

    $approvedPhone = (int) Database::one('SELECT state_version FROM kitchen_run_requests WHERE id = :id', [':id' => $phoneId])['state_version'];
    [$code] = krh_req($jars[1], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'cancel', 'request_id' => $phoneId, 'state_version' => $approvedPhone, 'okv_csrf' => $ownerCsrf,
    ]);
    krh_eq(200, $code, 'the customer withdraws a run that has already been approved');
    krh_eq('cancelled', (string) Database::one('SELECT status FROM kitchen_run_requests WHERE id = :id', [':id' => $phoneId])['status'], 'and the request is withdrawn rather than left approved');

    // --- 3d. The emails that go with all of that (items 33 and 26) ---------
    // A registered template nothing sends is the defect this milestone was
    // reviewed for, so these are read back from the dispatcher's own rows
    // rather than assumed from the call sites.
    $sentEvents = array_map(
        static fn(array $row): string => (string) $row['event_type'],
        Database::all('SELECT event_type FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $phoneId])
    );
    krh_ok(in_array('kitchen_run_received', $sentEvents, true), 'the customer who sent the list is told we have it');
    krh_ok(in_array('admin_new_kitchen_run', $sentEvents, true), 'and the team is told there is a list to price');
    krh_ok(in_array('kitchen_run_quoted', $sentEvents, true), 'the quote reaches the customer');
    krh_ok(in_array('admin_kitchen_run_cancelled', $sentEvents, true), 'withdrawing an approved run tells the team, because the produce may already be bought');

    $received = Database::one(
        'SELECT n.body FROM notifications n WHERE n.related_type = \'kitchen_run\' AND n.related_id = :id AND n.event_type = \'kitchen_run_received\' ORDER BY n.id LIMIT 1',
        [':id' => $phoneId]
    );
    krh_ok($received !== null, 'the received email was recorded, not only attempted');
    krh_ok(str_contains((string) $received['body'], '/kitchen-runs.php?request=' . $phoneId), 'and it links back to the request it is about');
    krh_ok(!str_contains((string) $received['body'], '{{'), 'with no unfilled token left in it');

    // The ordinary quiet withdrawal does not raise the alarm.
    $quietEvents = array_map(
        static fn(array $row): string => (string) $row['event_type'],
        Database::all('SELECT event_type FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $requestId])
    );
    krh_ok(!in_array('admin_kitchen_run_cancelled', $quietEvents, true), 'a run nobody withdrew never emailed the team about a withdrawal');

    // --- 4. The screens load. Both of them, with real data on them. ----------
    [$code, $body] = krh_req($jars[1], $base . '/kitchen-runs.php', null, false);
    krh_eq(200, $code, 'the customer Kitchen Runs screen loads');
    krh_ok(str_contains($body, 'Send us your list'), 'the customer screen renders its own copy');

    [$code, $body] = krh_req($jars[1], $base . '/kitchen-runs.php?request=' . $requestId, null, false);
    krh_eq(200, $code, 'a customer opens one of their own runs');
    krh_ok(str_contains($body, (string) $order['order_number']), 'a converted run links the customer to the order it became');

    foreach (KitchenRuns::MODES as $mode) {
        [$code] = krh_req($jars[1], $base . '/kitchen-runs.php?start=' . $mode, null, false);
        krh_eq(200, $code, "the $mode way of starting a list renders");
    }

    [$code, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php', null, false);
    krh_eq(200, $code, 'the staff queue loads');
    [$code] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?request=' . $requestId, null, false);
    krh_eq(200, $code, 'the staff screen opens one request');
    foreach (KitchenRuns::STATUSES as $status) {
        [$code] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?status=' . $status, null, false);
        krh_eq(200, $code, "the staff queue filters by $status without a server error");
    }

    // --- 5. A signed-out visitor is told, not bounced (item 2a) -------------
    [$code, $body] = krh_req($jars[3], $base . '/kitchen-runs.php', null, false);
    krh_eq(200, $code, 'a signed-out visitor is shown what a Kitchen Run is rather than a sign-in redirect');
    krh_ok(str_contains($body, 'A Kitchen Run needs an account'), 'and is told plainly why an account is needed');
    krh_ok(str_contains($body, '/account.php?mode=register') && str_contains($body, '/account.php?mode=signin'), 'with both ways to get one on the page');
    krh_ok(!str_contains($body, 'name="action" value="submit"'), 'the form itself is not rendered to somebody who cannot post it');
    krh_ok(str_contains($body, 'activate.php'), 'an unverified account is pointed at activation, which is the other reason a submit would fail');

    // The server, not the page, is still the gate. The token comes from a page
    // a signed-out visitor really can load, so what refuses the post is the
    // login check itself rather than a missing token.
    $visitorCsrf = krh_csrf($jars[3], $base . '/account.php');
    krh_ok($visitorCsrf !== '', 'a signed-out visitor can hold a valid CSRF token');
    [$code] = krh_req($jars[3], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'submit', 'input_mode' => 'custom', 'okv_csrf' => $visitorCsrf,
        'items' => [['item_name' => 'Pomo', 'quantity' => '1.000', 'unit_id' => $unitId]],
    ]);
    krh_eq(401, $code, 'a signed-out visitor still cannot post a list, whatever the page shows them');

    // --- 6. The staff queue's customer filter (item 17) ---------------------
    // M6 shipped an orders filter that answered 500 to every search, because it
    // bound one named placeholder twice. This is the same shape of query, so it
    // is requested here rather than trusted.
    $ownerEmail = "kr-http-1-$suffix@example.test";
    foreach ([$ownerEmail, 'Kitchen', '0803', $ownedNumber, "' OR 1=1 -- ", 'nobody-' . $suffix] as $term) {
        [$code, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?customer=' . rawurlencode($term), null, false);
        krh_eq(200, $code, 'the queue answers a customer search for ' . var_export($term, true) . ' rather than a server error');
        krh_ok(!str_contains($body, 'SQLSTATE'), 'the customer search never leaks a driver message');
    }
    [, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?customer=' . rawurlencode($ownerEmail), null, false);
    krh_ok(str_contains($body, $ownedNumber), 'a search by email finds that customer\'s run');
    [, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?customer=' . rawurlencode('nobody-' . $suffix), null, false);
    krh_ok(!str_contains($body, $ownedNumber), 'a search that matches nobody does not quietly return everybody');
    [, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?status=converted&customer=' . rawurlencode($ownerEmail), null, false);
    krh_ok(str_contains($body, $ownedNumber), 'the status filter and the customer filter work together');

    // --- 7. The two-way link between an order and its Kitchen Run (item 31) --
    [$code, $body] = krh_req($jars[0], $base . '/admin/orders.php?order=' . (int) $order['id'], null, false);
    krh_eq(200, $code, 'the staff order screen loads a converted Kitchen Run order');
    krh_ok(str_contains($body, '/admin/kitchen_runs.php?request=' . $requestId), 'and links back to the Kitchen Run it was made from');
    krh_ok(str_contains($body, $ownedNumber), 'naming the request number, so a colleague knows which list it was');

    // --- 8. The internal note is on the admin screen and nowhere else -------
    [$code] = krh_req($jars[0], $base . '/api/v1/kitchen_runs.php', [
        'action' => 'save_note', 'request_id' => $requestId, 'okv_csrf' => $managerCsrf,
        'staff_note' => 'INTERNAL ' . $suffix . ', watch this one.',
    ]);
    krh_eq(200, $code, 'a manager saves an internal note on a Kitchen Run');

    [, $body] = krh_req($jars[0], $base . '/admin/kitchen_runs.php?request=' . $requestId, null, false);
    krh_ok(str_contains($body, 'INTERNAL ' . $suffix), 'the team read their own note on the admin screen');

    [$code, $body] = krh_req($jars[1], $base . '/kitchen-runs.php?request=' . $requestId, null, false);
    krh_eq(200, $code, 'the customer opens the same run');
    krh_ok(!str_contains($body, 'INTERNAL ' . $suffix), 'and the internal note is nowhere on the page they are shown');
    krh_ok(!str_contains($body, 'staff_note'), 'not even as a field name in the markup');

    [, $body] = krh_req($jars[1], $base . '/kitchen-runs.php', null, false);
    krh_ok(!str_contains($body, 'INTERNAL ' . $suffix), 'nor on their list of runs');

    // --- 9. The M7 Pro history remains available to business customers ------
    $businessDate = (string) Delivery::nextEligibleDates('business', 3)[0]['date'];
    $businessRun = KitchenRunWorkflow::submit($users[3], 'business', [
        'recipient_name' => 'Business Kitchen', 'recipient_phone' => '08031234568',
        'address_line_1' => '6 Bourdillon Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $businessDate, 'delivery_zone_id' => $zoneId,
        'items' => [['item_name' => 'Stock fish', 'quantity' => '2.000', 'unit_id' => $unitId]],
    ]);
    $requestIds[] = (int) $businessRun['id'];

    [$code, $body] = krh_req($jars[4], $base . '/pro/kitchen_lists.php', null, false);
    krh_eq(200, $code, 'the Pro Portal Kitchen Lists screen loads for a business customer');
    krh_ok(!str_contains($body, 'Coming soon'), 'and is no longer the placeholder that named a milestone');
    krh_ok(str_contains($body, '/kitchen-runs.php'), 'with a route into starting a run');
    krh_ok(str_contains($body, (string) $businessRun['request_number']), 'with that business customer\'s own Kitchen Run history');
    krh_ok(!str_contains($body, $ownedNumber), 'without another customer\'s Kitchen Run');

    [$code] = krh_req($jars[1], $base . '/pro/kitchen_lists.php', null, false);
    krh_eq(302, $code, 'a household customer is sent to the storefront Kitchen Runs screen');
} finally {
    foreach ($requestIds as $id) {
        $orderRow = Database::one('SELECT converted_order_id FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
        $orderId = $orderRow && $orderRow['converted_order_id'] !== null ? (int) $orderRow['converted_order_id'] : 0;
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('UPDATE kitchen_run_requests SET converted_order_id = NULL WHERE id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
        if ($orderId > 0) {
            Database::run('DELETE FROM order_items WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM payment_status_history WHERE payment_id IN (SELECT id FROM payments WHERE order_id = :id)', [':id' => $orderId]);
            Database::run('DELETE FROM payments WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM delivery_schedules WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM order_addresses WHERE order_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'order\' AND related_id = :id)', [':id' => $orderId]);
            Database::run('DELETE FROM notifications WHERE related_type = \'order\' AND related_id = :id', [':id' => $orderId]);
            Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
        }
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    foreach ($jars as $jar) {
        if (is_file($jar)) {
            unlink($jar);
        }
    }
}

fwrite(STDOUT, "\n$passed / $tests Kitchen Run HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
