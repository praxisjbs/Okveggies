<?php
/**
 * scripts/tests/zone_picker_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The delivery-area picker over local HTTP, against the running
 * site and a scratch database.
 *
 *   1. Zone administration refuses anyone without delivery.zones.edit: a
 *      signed-out caller, a signed-in customer, and a signed-in colleague
 *      whose role lacks the permission. Nothing changes in the table.
 *   2. The public zones read returns active zones only, with their notes, in
 *      sort order.
 *   3. Kitchen Runs renders the shared picker with active zones only, no
 *      default, and reopens on a zone carried back on the URL only while it is
 *      active; a refused plain (no JavaScript) post carries the zone back.
 *   4. A zone switched off after the page loaded is refused by the server with
 *      the existing clear message.
 *   5. A refused phone order goes back to the same customer and area, and the
 *      staff Kitchen Run panel names an area that was switched off instead of
 *      silently choosing another one.
 *
 *   php scripts/tests/zone_picker_http_test.php
 *
 * SCRATCH DATABASES ONLY. It writes throwaway zones, users, a role and a
 * Kitchen Run, and removes them again.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests = 0;
$passed = 0;

function zh_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
        return;
    }
    fwrite(STDERR, "  FAIL: $label\n");
}
function zh_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    zh_ok($same, $label . ($same ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'));
}
/** @return array{0:int,1:string,2:string} code, body, Location header */
function zh_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $location = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
            return strlen($header);
        },
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, $location];
}
function zh_csrf(string $jar, string $url): string
{
    [, $body] = zh_req($jar, $url, null, false);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}
function zh_login(string $jar, string $base, string $page, string $email, string $password): void
{
    $token = zh_csrf($jar, $base . $page);
    [$code] = zh_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $token]);
    zh_eq(200, $code, "$email signs in");
}
/** The zone id selected in the picker on a page, or 0. */
function zh_selected(string $html): int
{
    return preg_match('/<option value="(\d+)"[^>]*\sselected>/', $html, $m) ? (int) $m[1] : 0;
}
function zh_zone_row(int $id): array
{
    return Database::one('SELECT name, area_note, is_active, sort_order FROM delivery_zones WHERE id = :id', [':id' => $id]) ?? [];
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'zone-http-777';
$users    = [];
$zoneIds  = [];
$requestIds = [];
$roleId   = 0;
$jars = [];
foreach (['anon', 'customer', 'noperm', 'manager'] as $who) {
    $jars[$who] = tempnam(sys_get_temp_dir(), 'okv-zh-' . $who . '-');
}

try {
    // --- Fixtures --------------------------------------------------------------
    $top = Delivery::nextZoneSortOrder();
    foreach ([['Alpha', 'Quay Road, Ferry Lane', 1], ['Bravo', null, 2], ['Gone', 'Old Wharf', 3]] as [$label, $note, $offset]) {
        $name = "ZH $label $suffix";
        Database::run(
            'INSERT INTO delivery_zones (name, slug, area_note, is_active, sort_order) VALUES (:n, :s, :a, 1, :o)',
            [':n' => $name, ':s' => Delivery::uniqueZoneSlug($name), ':a' => $note, ':o' => $top + $offset]
        );
        $zoneIds[$label] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('UPDATE delivery_zones SET is_active = 0 WHERE id = :id', [':id' => $zoneIds['Gone']]);

    foreach ([['customer', 'household'], ['noperm', 'staff'], ['manager', 'staff']] as [$who, $type]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
             VALUES (\'Zone\', :last, :email, :phone, :hash, :type, \'active\', NOW())',
            [
                ':last'  => ucfirst($who),
                ':email' => "zh-$who-$suffix@example.test",
                ':phone' => '+23473' . random_int(10000000, 99999999),
                ':hash'  => password_hash($password, PASSWORD_BCRYPT),
                ':type'  => $type,
            ]
        );
        $users[$who] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $users['manager']]);
    // A colleague whose role carries no delivery permission at all.
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Zone test: no permissions\')', [':n' => "zh-noperm-$suffix"]);
    $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $users['noperm'], ':r' => $roleId]);
    Database::run('DELETE FROM rate_limits');

    zh_login($jars['customer'], $base, '/account.php', "zh-customer-$suffix@example.test", $password);
    zh_login($jars['noperm'], $base, '/admin/login.php', "zh-noperm-$suffix@example.test", $password);
    zh_login($jars['manager'], $base, '/admin/login.php', "zh-manager-$suffix@example.test", $password);

    // --- 1. Zone administration is gated ----------------------------------------
    $before = zh_zone_row($zoneIds['Alpha']);
    $zoneCount = (int) Database::one('SELECT COUNT(*) AS n FROM delivery_zones')['n'];
    $writes = [
        'create_zone'     => ['name' => "ZH Intruder $suffix", 'area_note' => 'x'],
        'update_zone'     => ['zone_id' => $zoneIds['Alpha'], 'name' => "ZH Renamed $suffix", 'area_note' => 'y', 'is_active' => 1],
        'set_zone_active' => ['zone_id' => $zoneIds['Alpha'], 'is_active' => 0],
    ];
    foreach ($writes as $action => $fields) {
        [$code] = zh_req($jars['anon'], $base . '/api/v1/delivery.php', ['action' => $action] + $fields);
        zh_eq(401, $code, "a signed-out caller cannot $action");
        foreach (['customer' => 'a signed-in customer', 'noperm' => 'a colleague without delivery.zones.edit'] as $who => $label) {
            $csrf = zh_csrf($jars[$who], $base . ($who === 'customer' ? '/kitchen-runs.php' : '/admin/index.php'));
            [$code, $body] = zh_req($jars[$who], $base . '/api/v1/delivery.php', ['action' => $action, 'okv_csrf' => $csrf] + $fields);
            zh_ok(in_array($code, [401, 403], true), "$label cannot $action (got $code)");
            zh_ok(!str_contains($body, 'SQLSTATE') && !str_contains($body, 'Exception'), "the $action refusal leaks nothing");
        }
        [$code] = zh_req($jars['manager'], $base . '/api/v1/delivery.php', ['action' => $action] + $fields);
        zh_eq(419, $code, "even a permitted colleague cannot $action without a CSRF token");
    }
    zh_eq($before, zh_zone_row($zoneIds['Alpha']), 'no refused write changed the zone');
    zh_eq($zoneCount, (int) Database::one('SELECT COUNT(*) AS n FROM delivery_zones')['n'], 'no refused write added a zone');
    [$code] = zh_req($jars['noperm'], $base . '/admin/delivery.php', null, false);
    zh_ok(in_array($code, [302, 303, 401, 403], true), "a colleague without delivery permission cannot open the Delivery screen (got $code)");

    // --- 2. The public read ---------------------------------------------------------
    [$code, $body] = zh_req($jars['anon'], $base . '/api/v1/delivery.php?action=zones');
    $zones = (array) (json_decode($body, true)['zones'] ?? []);
    $ids = array_map(static fn(array $z): int => (int) $z['id'], $zones);
    zh_eq(200, $code, 'the zones read answers');
    zh_ok(in_array($zoneIds['Alpha'], $ids, true) && in_array($zoneIds['Bravo'], $ids, true), 'it lists active zones');
    zh_ok(!in_array($zoneIds['Gone'], $ids, true), 'and never a switched-off one');
    zh_ok(array_search($zoneIds['Alpha'], $ids, true) < array_search($zoneIds['Bravo'], $ids, true), 'in sort order');
    $alpha = $zones[array_search($zoneIds['Alpha'], $ids, true)] ?? [];
    zh_eq('Quay Road, Ferry Lane', $alpha['area_note'] ?? null, 'with the note the search matches');

    // --- 3. Kitchen Runs: the picker, prefill and the round trip -------------------------
    [$code, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php', null, false);
    zh_eq(200, $code, 'Kitchen Runs opens for a customer');
    zh_ok(str_contains($page, 'data-zone-picker') && str_contains($page, 'role="combobox"'), 'it renders the shared searchable picker');
    zh_ok(str_contains($page, '/assets/js/zone-picker.min.js'), 'and loads its script');
    zh_ok(str_contains($page, 'value="' . $zoneIds['Alpha'] . '" data-note="Quay Road, Ferry Lane"'), 'active zones carry their notes');
    zh_ok(!str_contains($page, 'value="' . $zoneIds['Gone'] . '"'), 'a switched-off zone is not offered');
    zh_eq(0, zh_selected($page), 'a first-time customer gets no area chosen for them');
    zh_ok(!str_contains($page, 'data-kr-zone'), 'the old hidden first-zone field is gone');

    [, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php?zone=' . $zoneIds['Bravo'], null, false);
    zh_eq($zoneIds['Bravo'], zh_selected($page), 'an active zone carried back on the URL is reopened');
    [, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php?zone=' . $zoneIds['Gone'], null, false);
    zh_eq(0, zh_selected($page), 'a switched-off zone on the URL is not');
    [, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php?zone=' . rawurlencode($zoneIds['Bravo'] . '<x>'), null, false);
    zh_eq(0, zh_selected($page), 'junk on the URL is not read as a zone');

    $date = (string) (Delivery::nextEligibleDates('household', 1)[0]['date'] ?? '');
    $unitId = (int) Database::one('SELECT id FROM units_of_measurement WHERE is_active = 1 ORDER BY id LIMIT 1')['id'];
    $send = [
        'action' => 'submit', 'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'recipient_name' => 'Zone Customer', 'recipient_phone' => '08031234567',
        'address_line_1' => '4 Test Road', 'city' => 'Lagos', 'state' => 'Lagos',
    ];
    $csrf = zh_csrf($jars['customer'], $base . '/kitchen-runs.php');

    // A plain post refused for its items (no name on the line) comes back with the area.
    [$code, , $location] = zh_req($jars['customer'], $base . '/api/v1/kitchen_runs.php', $send + [
        'okv_csrf' => $csrf, 'delivery_zone_id' => (string) $zoneIds['Alpha'],
        'items' => [['item_name' => '', 'quantity' => '2', 'unit_id' => $unitId]],
    ], false);
    zh_eq(303, $code, 'a refused plain send is redirected back');
    zh_ok(str_contains($location, 'zone=' . $zoneIds['Alpha']) && str_contains($location, 'error='), 'with the chosen area and the reason on the URL');
    [, $page] = zh_req($jars['customer'], $base . $location, null, false);
    zh_eq($zoneIds['Alpha'], zh_selected($page), 'the page reopens on the area the customer had chosen');

    // --- 4. Switched off after the page loaded ---------------------------------------
    $csrf = zh_csrf($jars['customer'], $base . '/kitchen-runs.php');
    [$code, $body] = zh_req($jars['customer'], $base . '/api/v1/kitchen_runs.php', $send + [
        'okv_csrf' => $csrf, 'delivery_zone_id' => (string) $zoneIds['Gone'],
        'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
    ]);
    $answer = (array) json_decode($body, true);
    zh_ok($code >= 400 && $code < 500, "a switched-off area is refused by the server (got $code)");
    zh_eq('zone_unavailable', $answer['code'] ?? null, 'with the existing code');
    zh_eq('That delivery area is not available. Pick another one.', $answer['message'] ?? null, 'and the existing clear message');
    zh_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM kitchen_run_requests WHERE user_id = :u', [':u' => $users['customer']])['n'], 'no run was written');

    [$code, $body] = zh_req($jars['customer'], $base . '/api/v1/kitchen_runs.php', $send + [
        'okv_csrf' => $csrf, 'delivery_zone_id' => "ZH Alpha $suffix",
        'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
    ]);
    zh_ok($code >= 400 && $code < 500, 'a zone name posted in place of the id is refused');

    // --- 5. Staff surfaces --------------------------------------------------------------
    [$code, $page] = zh_req($jars['manager'], $base . '/admin/order_new.php?user_id=' . $users['customer'], null, false);
    zh_eq(200, $code, 'the phone order screen opens for the customer');
    zh_ok(str_contains($page, 'id="delivery-zone"') && str_contains($page, 'data-zone-picker'), 'it uses the shared picker');
    zh_ok(!str_contains($page, 'value="' . $zoneIds['Gone'] . '"'), 'without the switched-off zone');

    $csrf = zh_csrf($jars['manager'], $base . '/admin/order_new.php?user_id=' . $users['customer']);
    [$code, , $location] = zh_req($jars['manager'], $base . '/api/v1/orders.php', [
        'action' => 'create', 'okv_csrf' => $csrf, 'user_id' => $users['customer'], 'payment_option' => 'pay_in_full',
        'channel' => 'phone', 'delivery_date' => $date, 'delivery_zone_id' => (string) $zoneIds['Bravo'],
        'recipient_name' => 'Zone Customer', 'recipient_phone' => '08031234567', 'address_line_1' => '4 Test Road',
        'city' => 'Lagos', 'state' => 'Lagos', 'lines' => [],
    ], false);
    zh_eq(303, $code, 'a refused phone order is redirected back');
    zh_ok(str_contains($location, 'user_id=' . $users['customer']) && str_contains($location, 'zone=' . $zoneIds['Bravo']), 'to the same customer and area');
    [, $page] = zh_req($jars['manager'], $base . $location, null, false);
    zh_eq($zoneIds['Bravo'], zh_selected($page), 'the phone order screen reopens on that area');

    // A run whose area is switched off while it waits for a price.
    $run = KitchenRunWorkflow::submit($users['customer'], 'household', [
        'recipient_name' => 'Zone Customer', 'recipient_phone' => '08031234567',
        'address_line_1' => '4 Test Road', 'city' => 'Lagos', 'state' => 'Lagos',
        'input_mode' => 'custom', 'pricing_mode' => 'by_us',
        'preferred_delivery_date' => $date, 'delivery_zone_id' => $zoneIds['Bravo'],
        'items' => [['item_name' => 'Pepper', 'quantity' => '2', 'unit_id' => $unitId]],
    ]);
    $requestIds[] = (int) $run['id'];
    [, $page] = zh_req($jars['manager'], $base . '/admin/kitchen_runs.php?request=' . (int) $run['id'], null, false);
    zh_eq($zoneIds['Bravo'], zh_selected($page), 'the staff panel opens on the run\'s area while it is active');
    zh_ok(str_contains($page, '/assets/js/zone-picker.min.js'), 'the staff panel loads the picker script');

    Database::run('UPDATE delivery_zones SET is_active = 0 WHERE id = :id', [':id' => $zoneIds['Bravo']]);
    [, $page] = zh_req($jars['manager'], $base . '/admin/kitchen_runs.php?request=' . (int) $run['id'], null, false);
    zh_eq(0, zh_selected($page), 'once switched off, no other area is chosen in its place');
    zh_ok(str_contains($page, 'ZH Bravo ' . $suffix . ' is no longer on our delivery list'), 'and the panel names the area that needs replacing');

    [, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php', null, false);
    zh_eq(0, zh_selected($page), 'the customer\'s last-run area is not prefilled once switched off');
    Database::run('UPDATE delivery_zones SET is_active = 1 WHERE id = :id', [':id' => $zoneIds['Bravo']]);
    [, $page] = zh_req($jars['customer'], $base . '/kitchen-runs.php', null, false);
    zh_eq($zoneIds['Bravo'], zh_selected($page), 'and is prefilled again while active: the saved selection');
} finally {
    foreach ($requestIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id)', [':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = \'kitchen_run\' AND related_id = :id', [':id' => $id]);
        Database::run('DELETE FROM kitchen_run_requests WHERE id = :id', [':id' => $id]);
    }
    if (isset($users['customer'])) {
        Database::run('DELETE FROM kitchen_run_requests WHERE user_id = :u', [':u' => $users['customer']]);
    }
    Database::run('DELETE FROM delivery_zones WHERE name = :n', [':n' => "ZH Intruder $suffix"]);
    foreach ($zoneIds as $id) {
        Database::run('DELETE FROM delivery_zones WHERE id = :id', [':id' => $id]);
    }
    foreach ($users as $id) {
        Database::run('DELETE FROM customer_addresses WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE actor_user_id = :id OR entity_id = :entity', [':id' => $id, ':entity' => $id]);
        Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
    }
    if ($roleId > 0) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $roleId]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $roleId]);
    }
    foreach ($jars as $jar) {
        if (is_string($jar) && is_file($jar)) {
            unlink($jar);
        }
    }
}

fwrite(STDOUT, "\n$passed / $tests zone picker HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
