<?php
/**
 * scripts/tests/permission_admin_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Permissions screen driven over real HTTP, as an Owner and as a
 * Manager. This is the test that proves the gate holds from the outside rather
 * than only inside the class:
 *
 *   - the Owner opens the screen, and the Owner role is shown locked rather than
 *     editable, so the Owner cannot be locked out of the screen that hands out
 *     permissions;
 *   - a Manager is refused the screen outright, never sees it in the navigation,
 *     and is refused a write by the server, not merely by a hidden button;
 *   - a write with no CSRF token is refused, and a write over GET is refused;
 *   - a key that is not in the catalogue is refused rather than written;
 *   - a real change lands in the database and is recorded in the history.
 *
 * Needs a migrated database, the app .env, and the site answering on a local
 * server. Start one first:
 *   php -S 127.0.0.1:8123 -t . &
 *   php scripts/tests/permission_admin_http_test.php
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');

$tests = 0; $passed = 0; $fails = [];
function t_ok($cond, string $label): void
{
    global $tests, $passed, $fails;
    $tests++;
    if ($cond) { $passed++; } else { $fails[] = $label; fwrite(STDERR, "  FAIL: $label\n"); }
}
function t_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        $label .= '  (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($expected === $actual, $label);
}

/** One request with its own cookie jar. Returns [code, body]. */
function req(string $jar, string $url, ?array $post = null, string $method = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($method !== '') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/** Fetch a page and pull the CSRF token out of it. */
function csrf_from(string $jar, string $url): string
{
    [, $body] = req($jar, $url);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

const OKV_PA_ROLE = 'permission-http-test';

$jarOwner   = tempnam(sys_get_temp_dir(), 'okv-pa-owner-');
$jarManager = tempnam(sys_get_temp_dir(), 'okv-pa-mgr-');
$jarGuest   = tempnam(sys_get_temp_dir(), 'okv-pa-guest-');

// --- A scratch role for the Owner to edit ------------------------------------

Database::run('DELETE FROM roles WHERE name = :n', [':n' => OKV_PA_ROLE]);
Database::run(
    "INSERT INTO roles (name, slug, description, status) VALUES (:n, :s, :d, 'active')",
    [':n' => OKV_PA_ROLE, ':s' => OKV_PA_ROLE, ':d' => 'Scratch role for the permission HTTP suite. Safe to delete.']
);
$scratchId = (int) Database::one('SELECT id FROM roles WHERE name = :n', [':n' => OKV_PA_ROLE])['id'];

// --- Two staff accounts, one of each role -------------------------------------

$hash = password_hash('permission-http-777', PASSWORD_BCRYPT);
foreach ([
    ['owner',   'Ada',  'Owner',   'permissions-owner@okveggies.com.ng',   '+2348039990201'],
    ['manager', 'Bola', 'Manager', 'permissions-manager@okveggies.com.ng', '+2348039990202'],
] as [$role, $first, $last, $email, $phone]) {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, :l, :e, :p, :h, :t, :s, NOW())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = VALUES(status)',
        [':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone, ':h' => $hash, ':t' => 'staff', ':s' => 'active']
    );
    Database::run(
        'INSERT IGNORE INTO user_roles (user_id, role_id)
         SELECT u.id, r.id FROM users u CROSS JOIN roles r WHERE u.email = :e AND r.name = :r',
        [':e' => $email, ':r' => $role]
    );
}
Database::run('DELETE FROM rate_limits');

/** Sign in over the same endpoint the browser uses. */
function sign_in(string $jar, string $base, string $email): bool
{
    $token = csrf_from($jar, $base . '/admin/login.php');
    [$code, $body] = req($jar, $base . '/api/v1/auth.php', [
        'action'     => 'login',
        'identifier' => $email,
        'password'   => 'permission-http-777',
        'okv_csrf'   => $token,
    ]);
    return $code === 200 && str_contains($body, '"status":"ok"');
}

t_ok(sign_in($jarOwner, $base, 'permissions-owner@okveggies.com.ng'), 'the Owner signs in');
t_ok(sign_in($jarManager, $base, 'permissions-manager@okveggies.com.ng'), 'the Manager signs in');

try {
    // --- A guest gets nowhere near it ------------------------------------------

    [$code] = req($jarGuest, $base . '/admin/permissions.php');
    t_ok(in_array($code, [302, 401, 403], true), 'a signed-out visitor is refused the Permissions screen');
    [$code] = req($jarGuest, $base . '/api/v1/permissions.php?action=list');
    t_ok(in_array($code, [302, 401, 403], true), 'a signed-out visitor is refused the permissions API');

    // --- The Manager is refused, and never offered the door ----------------------

    [$code] = req($jarManager, $base . '/admin/permissions.php');
    t_eq(403, $code, 'the Manager is refused the Permissions screen');

    [$code, $ordersPage] = req($jarManager, $base . '/admin/orders.php');
    t_eq(200, $code, 'the Manager still reaches a screen they do hold');
    t_ok(!str_contains($ordersPage, '/admin/permissions.php'), 'the Permissions link is not in the Manager navigation');

    // --- The Owner sees a working screen, with the Owner role locked ------------

    [$code, $page] = req($jarOwner, $base . '/admin/permissions.php');
    t_eq(200, $code, 'the Owner opens the Permissions screen');
    t_ok(str_contains($page, 'Change history'), 'the history panel is on the page');
    t_ok(str_contains($page, 'data-perm-module="orders"'), 'the modules are grouped, and Orders is one of them');
    t_ok(str_contains($page, 'name="okv_csrf"'), 'the page carries a CSRF token');
    t_ok(str_contains($page, 'admin-permissions.js'), 'the screen loads its script');
    t_ok(!str_contains($page, 'okv_head_meta') && str_contains($page, 'site.webmanifest'), 'the brand head block rendered');

    // The Owner role opens first, and it is drawn read only: the whole grid is
    // there so the screen answers "what does the Owner hold?", every box is
    // ticked, and every box is locked so nothing can be posted by hand.
    t_ok(str_contains($page, 'data-perm-readonly'), 'the Owner role is drawn read only');
    t_ok(!str_contains($page, 'data-perm-save'), 'the Owner role offers nothing to save');
    t_ok(!str_contains($page, 'data-perm-all='), 'the Owner role offers none of the module tools');

    preg_match_all('/<input type="checkbox"[^>]*>/', $page, $boxMatches);
    $boxes  = $boxMatches[0];
    $ticked = 0;
    $locked = 0;
    foreach ($boxes as $box) {
        if (str_contains($box, 'checked')) { $ticked++; }
        if (str_contains($box, 'disabled')) { $locked++; }
    }
    t_ok(count($boxes) > 50, 'the Owner grid carries the whole catalogue, not a sample');
    t_eq(count($boxes), $ticked, 'every box in the Owner grid is ticked');
    t_eq(count($boxes), $locked, 'every box in the Owner grid is locked');

    // The Manager is protected too, and the read-only grid is the only place its
    // set is visible at all, so it has to be rendered rather than merely counted.
    [$code, $managerPage] = req($jarOwner, $base . '/admin/permissions.php?role=' . (int) Database::one("SELECT id FROM roles WHERE name = 'manager'")['id']);
    t_eq(200, $code, 'the Owner can open the Manager role read only');
    t_ok(str_contains($managerPage, 'data-perm-readonly'), 'the Manager role is drawn read only');
    t_ok(!str_contains($managerPage, 'data-perm-save'), 'the Manager role offers nothing to save');
    preg_match_all('/<input type="checkbox"[^>]*>/', $managerPage, $managerMatches);
    t_ok(count($managerMatches[0]) > 50, 'the Manager grid lists the catalogue');
    t_ok(
        str_contains($managerPage, 'orders.view'),
        'the Manager grid shows a permission the Manager really holds'
    );

    // --- A role of the Owner's own making is editable ----------------------------

    [$code, $scratchPage] = req($jarOwner, $base . '/admin/permissions.php?role=' . $scratchId);
    t_eq(200, $code, 'the Owner opens a custom role');
    t_ok(str_contains($scratchPage, 'data-perm-form'), 'a custom role gets a form');
    t_ok(str_contains($scratchPage, 'data-perm-save'), 'a custom role gets a save button');
    t_ok(str_contains($scratchPage, 'data-perm-preset='), 'a custom role gets the quick presets');
    t_ok(str_contains($scratchPage, 'value="orders.cancel"'), 'the keys are rendered as checkboxes');

    // --- The Manager is refused a write by the server ----------------------------

    $managerToken = csrf_from($jarManager, $base . '/admin/orders.php');
    [$code, $body] = req($jarManager, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => $scratchId,
        'permissions' => ['orders.view'],
        'okv_csrf'    => $managerToken,
    ]);
    t_eq(403, $code, 'the Manager is refused a save by the server');
    t_ok(str_contains($body, 'owner_only'), 'the refusal says it is the Owner\'s alone');

    // The Users screen writes roles through a second endpoint, and it has to
    // answer to the same rule. It used to carry a Manager bypass, so a colleague
    // the catalogue says may not touch a role could still rewrite one by posting
    // straight at api/v1/rbac.php.
    foreach ([
        ['update_role', ['role_id' => $scratchId, 'name' => 'permission-http-test', 'permissions' => ['orders.view']]],
        ['create_role', ['name' => 'manager-made-role', 'slug' => 'manager-made-role']],
        ['set_status',  ['role_id' => $scratchId, 'status' => 'disabled']],
    ] as [$oldAction, $fields]) {
        [$code, ] = req($jarManager, $base . '/api/v1/rbac.php', $fields + [
            'action'   => $oldAction,
            'okv_csrf' => $managerToken,
        ]);
        t_eq(403, $code, 'the Manager is refused ' . $oldAction . ' on the roles endpoint');
    }

    t_eq(
        null,
        Database::one('SELECT id FROM roles WHERE name = :n', [':n' => 'manager-made-role']),
        'the role the Manager tried to create does not exist'
    );
    t_eq([], PermissionMatrix::grants($scratchId), 'the Manager changed nothing on the way past');
    t_eq(
        'active',
        (string) Database::one('SELECT status FROM roles WHERE id = :id', [':id' => $scratchId])['status'],
        'the Manager did not switch the role off'
    );

    // Even if the Manager somehow held rbac.roles.edit, the Owner role is protected.
    [$code, $body] = req($jarManager, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => (int) Database::one("SELECT id FROM roles WHERE name = 'owner'")['id'],
        'permissions' => [],
        'okv_csrf'    => $managerToken,
    ]);
    t_eq(403, $code, 'the Manager cannot empty the Owner role');

    // --- Writes the Owner makes that must not land ------------------------------

    [$code] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'  => 'save',
        'role_id' => $scratchId,
    ]);
    t_eq(419, $code, 'a save with no CSRF token is refused');

    [$code] = req($jarOwner, $base . '/api/v1/permissions.php?action=save&role_id=' . $scratchId, null, 'GET');
    t_eq(405, $code, 'a save over GET is refused');

    $ownerToken = csrf_from($jarOwner, $base . '/admin/permissions.php?role=' . $scratchId);
    [$code, $body] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => $scratchId,
        'permissions' => ['not.a.real.permission'],
        'okv_csrf'    => $ownerToken,
    ]);
    t_eq(422, $code, 'a key that is not in the catalogue is refused');
    t_ok(str_contains($body, 'unknown_permission'), 'the refusal says which kind of problem it is');
    t_eq([], PermissionMatrix::grants($scratchId), 'the refused key was not written');

    [$code] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => 999999999,
        'permissions' => [],
        'okv_csrf'    => $ownerToken,
    ]);
    t_eq(404, $code, 'a role that does not exist is a not-found, not a silent success');

    [$code] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => (int) Database::one("SELECT id FROM roles WHERE name = 'manager'")['id'],
        'permissions' => [],
        'okv_csrf'    => $ownerToken,
    ]);
    t_eq(403, $code, 'the Manager role is protected from the Owner too');

    // --- A real change lands -----------------------------------------------------

    // Cleaned the same way the save path cleans it, so the list is sorted the way
    // grants() reads it back and the comparison below is about the write.
    $keys = PermissionMatrix::normaliseKeys(array_slice(PermissionMatrix::grantableKeys(), 0, 2), PermissionMatrix::grantableKeys());
    [$code, $body] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'      => 'save',
        'role_id'     => $scratchId,
        'permissions' => $keys,
        'okv_csrf'    => $ownerToken,
    ]);
    t_eq(200, $code, 'the Owner saves a real change');
    $json = json_decode($body, true);
    t_ok(($json['changed'] ?? false) === true, 'the save reports that something changed');
    t_eq($keys, PermissionMatrix::grants($scratchId), 'the role carries what was saved');
    t_eq(1, PermissionMatrix::versionCount($scratchId), 'the change was recorded in the history');

    // --- An empty form means an empty role ---------------------------------------

    [$code, ] = req($jarOwner, $base . '/api/v1/permissions.php', [
        'action'   => 'save',
        'role_id'  => $scratchId,
        'okv_csrf' => $ownerToken,
    ]);
    t_eq(200, $code, 'saving an unticked form is accepted');
    t_eq([], PermissionMatrix::grants($scratchId), 'an unticked form takes every permission off, rather than being read as no answer');

    // --- Reading the history ------------------------------------------------------

    [$code, $body] = req($jarOwner, $base . '/api/v1/permissions.php?action=versions&role_id=' . $scratchId);
    t_eq(200, $code, 'the Owner can read the history over the API');
    $json = json_decode($body, true);
    t_eq(2, count((array) ($json['versions'] ?? [])), 'both changes are on record');

} finally {
    Database::run('DELETE FROM roles WHERE name = :n', [':n' => OKV_PA_ROLE]);
    Database::run("DELETE FROM users WHERE email IN (:a, :b)", [
        ':a' => 'permissions-owner@okveggies.com.ng',
        ':b' => 'permissions-manager@okveggies.com.ng',
    ]);
    foreach ([$jarOwner, $jarManager, $jarGuest] as $jar) {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\n$passed / $tests assertions passed.\n");
if ($passed !== $tests) {
    fwrite(STDERR, count($fails) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
exit(0);
