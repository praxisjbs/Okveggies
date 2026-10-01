<?php
/**
 * scripts/tests/stock_status_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR3 of the 23 Sep review, item 19: "changing a product back to in
 * stock triggered an unspecified issue". Reproduced end to end and pinned here so
 * a real regression would show.
 *
 * A colleague takes a product from available to out of stock, to restocking with
 * a date, and back to available, through the real endpoint. After every step the
 * stored row, the storefront product page and the add to basket state must agree,
 * the restock date must clear when it stops meaning anything, and nothing else on
 * the product (its price, whether it is on the shop, whether it is featured) may
 * move. A colleague without the permission, a post without CSRF and an unknown
 * status are all refused and change nothing.
 *
 *   php -S 127.0.0.1:8123 -t .
 *   php scripts/tests/stock_status_http_test.php
 *
 * Creates its own product and staff, then removes them.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }

$base   = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests  = 0;
$passed = 0;

function ss_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function ss_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    ss_ok($same, $label);
}

function ss_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, json_decode($body, true)];
}
function ss_csrf(string $jar, string $base): string
{
    [, $body] = ss_req($jar, $base . '/account.php', null, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'stock-http-3318';
$users = []; $roles = []; $productId = 0;
$jarS = tempnam(sys_get_temp_dir(), 'okv-ss-s-');   // may change availability
$jarV = tempnam(sys_get_temp_dir(), 'okv-ss-v-');   // may only look
$jarG = tempnam(sys_get_temp_dir(), 'okv-ss-g-');   // the public

$makeStaff = static function (string $first, array $permissions) use ($suffix, $password, &$users, &$roles): array {
    $email = strtolower($first) . "-ss-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, \'Stock\', :e, :p, :h, \'staff\', \'active\', NOW())',
        [':f' => $first, ':e' => $email, ':p' => '+23477' . random_int(10000000, 99999999), ':h' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Stock status HTTP fixture\')', [':n' => 'stock_http_' . $suffix . '_' . count($roles)]);
    $role = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roles[] = $role;
    foreach ($permissions as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE `key` = :p', [':r' => $role, ':p' => $permission]);
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $id, ':r' => $role]);
    return [$id, $email];
};
$signIn = static function (string $jar, string $email) use ($base, $password): int {
    $csrf = ss_csrf($jar, $base);
    [$code] = ss_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'context' => 'admin', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf]);
    return $code;
};
$row = static fn(): array => Database::one(
    'SELECT p.current_price_subunit, p.is_active, p.is_featured, pa.availability_status, pa.restock_date, pa.updated_by
       FROM products p LEFT JOIN product_availability pa ON pa.product_id = p.id WHERE p.id = :id',
    [':id' => $GLOBALS['ss_product']]
);

try {
    Database::run('DELETE FROM rate_limits');
    $category = Database::one('SELECT id FROM product_categories WHERE is_active = 1 ORDER BY id LIMIT 1');
    $unit     = Database::one('SELECT id FROM units_of_measurement WHERE is_active = 1 ORDER BY id LIMIT 1');
    ss_ok($category !== null && $unit !== null, 'the shop has a category and a unit to hang a product on');

    $slug = 'stock-fixture-' . $suffix;
    Database::run(
        'INSERT INTO products (category_id, unit_id, name, slug, sku, description, current_price_subunit, minimum_quantity, quantity_increment, is_active, is_featured)
         VALUES (:c, :u, :n, :s, :sku, \'Stock status fixture\', 125000, 1, 1, 1, 1)',
        [':c' => $category['id'], ':u' => $unit['id'], ':n' => 'Stock Fixture ' . $suffix, ':s' => $slug, ':sku' => 'STK-' . strtoupper($suffix)]
    );
    $productId = (int) Database::getInstance()->getConnection()->lastInsertId();
    $GLOBALS['ss_product'] = $productId;
    Database::run('INSERT INTO product_availability (product_id, availability_status) VALUES (:p, \'available\')', [':p' => $productId]);

    [$stockId, $stockEmail]   = $makeStaff('Stocker', ['dashboard.view', 'products.view', 'products.availability.update']);
    [$viewerId, $viewerEmail] = $makeStaff('Looker', ['dashboard.view', 'products.view']);
    ss_eq(200, $signIn($jarS, $stockEmail), 'a colleague who may change availability signs in');
    ss_eq(200, $signIn($jarV, $viewerEmail), 'a colleague who may only view the catalogue signs in');

    $page = static function () use ($base, $slug, $jarG): string {
        [, $html] = ss_req($jarG, $base . '/product.php?slug=' . $slug, null, false);
        return $html;
    };
    $before = $row();
    ss_eq('available', (string) $before['availability_status'], 'setup: the product starts available');
    ss_ok(str_contains($page(), 'Add to basket'), 'setup: so the public page offers Add to basket');

    $csrf = ss_csrf($jarS, $base);
    $set = static fn(string $status, string $date = '', ?string $token = null) => ss_req($GLOBALS['ss_jarS'], $GLOBALS['ss_base'] . '/api/v1/products.php', [
        'action' => 'set_availability', 'product_id' => $GLOBALS['ss_product'], 'availability_status' => $status, 'restock_date' => $date, 'okv_csrf' => $token ?? $GLOBALS['ss_csrf'],
    ]);
    $GLOBALS['ss_jarS'] = $jarS; $GLOBALS['ss_base'] = $base; $GLOBALS['ss_csrf'] = $csrf;

    // ---- available to out of stock -------------------------------------------------------------------
    [$code, , $result] = $set('out_of_stock');
    ss_eq(200, $code, 'a colleague marks it out of stock');
    ss_eq('Out of stock', (string) ($result['label'] ?? ''), 'and is told what customers will read');
    $r = $row();
    ss_eq('out_of_stock', (string) $r['availability_status'], 'the row says out of stock');
    ss_eq($stockId, (int) $r['updated_by'], 'and who changed it');
    $html = $page();
    ss_ok(str_contains($html, 'Out of stock') && !str_contains($html, 'Add to basket'), 'the public page says so and offers no Add to basket');

    // ---- out of stock to restocking, with a date -----------------------------------------------------
    $backOn = date('Y-m-d', strtotime('+9 days'));
    [$code] = $set('restocking', $backOn);
    ss_eq(200, $code, 'it is then marked restocking with a date');
    $r = $row();
    ss_eq('restocking', (string) $r['availability_status'], 'the row says restocking');
    ss_eq($backOn, (string) $r['restock_date'], 'with the date');
    $html = $page();
    ss_ok(str_contains($html, 'Restocking') && str_contains($html, date('jS F', strtotime($backOn))) && !str_contains($html, 'Add to basket'), 'the public page shows the date and offers no Add to basket');

    // ---- and back to available ------------------------------------------------------------------------
    // The admin form still submits the date field while the status is Available. It must be dropped.
    [$code, , $result] = $set('available', $backOn);
    ss_eq(200, $code, 'it is marked available again, with the old date still in the form');
    ss_eq('Available', (string) ($result['label'] ?? ''), 'and the label is Available');
    $r = $row();
    ss_eq('available', (string) $r['availability_status'], 'the row says available');
    ss_eq(null, $r['restock_date'], 'the restock date is cleared, because it means nothing now');
    $html = $page();
    ss_ok(str_contains($html, 'Add to basket'), 'the public page offers Add to basket again');
    ss_ok(!str_contains($html, 'Out of stock') && !str_contains($html, 'Restocking'), 'and no longer says out of stock or restocking');

    // ---- nothing else moved --------------------------------------------------------------------------------
    ss_eq((int) $before['current_price_subunit'], (int) $r['current_price_subunit'], 'the price is exactly as it was');
    ss_eq((int) $before['is_active'], (int) $r['is_active'], 'it is still on the shop');
    ss_eq((int) $before['is_featured'], (int) $r['is_featured'], 'and still featured');
    ss_eq(1, (int) Database::one('SELECT COUNT(*) AS c FROM product_availability WHERE product_id = :p', [':p' => $productId])['c'], 'and there is still exactly one availability row');

    // ---- a few round trips in a row, as a busy stall would ------------------------------------------------
    foreach (['out_of_stock', 'available', 'out_of_stock', 'available', 'available'] as $status) {
        [$code] = $set($status);
        ss_eq(200, $code, "setting $status again is accepted");
    }
    ss_eq('available', (string) $row()['availability_status'], 'after the round trips it is available');
    ss_ok(str_contains($page(), 'Add to basket'), 'and sellable');

    // ---- refusals leave it alone ---------------------------------------------------------------------------
    $viewerCsrf = ss_csrf($jarV, $base);
    [$code] = ss_req($jarV, $base . '/api/v1/products.php', ['action' => 'set_availability', 'product_id' => $productId, 'availability_status' => 'out_of_stock', 'okv_csrf' => $viewerCsrf]);
    ss_eq(403, $code, 'a colleague without products.availability.update is refused');
    [$code] = ss_req($jarS, $base . '/api/v1/products.php', ['action' => 'set_availability', 'product_id' => $productId, 'availability_status' => 'out_of_stock']);
    ss_eq(419, $code, 'a post without the CSRF token is refused');
    [$code, , $result] = $set('sold_out_forever');
    ss_ok($code === 422 || $code === 400, 'a status we do not recognise is refused');
    [$code] = ss_req($jarG, $base . '/api/v1/products.php', ['action' => 'set_availability', 'product_id' => $productId, 'availability_status' => 'out_of_stock', 'okv_csrf' => 'x']);
    ss_ok(in_array($code, [401, 403], true), 'the public is refused');
    ss_eq('available', (string) $row()['availability_status'], 'and none of the refusals changed the product');

    // ---- the admin screen reads the same truth ---------------------------------------------------------------
    [, $admin] = ss_req($jarS, $base . '/admin/products.php', null, false);
    ss_ok(preg_match('/id="avail-' . $productId . '"[^>]*>\s*<option value="available" selected/', $admin) === 1, 'the admin screen shows Available selected');
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/admin-products.js');
    ss_ok(str_contains($js, 'AbortController') && str_contains($js, 'TIMEOUT_MS'), 'and a stalled request gives up after a time limit instead of leaving the button disabled');
} finally {
    $ids = $users ? implode(',', array_map('intval', $users)) : '0';
    if ($productId > 0) {
        Database::run('DELETE FROM product_availability WHERE product_id = :p', [':p' => $productId]);
        Database::run('DELETE FROM products WHERE id = :p', [':p' => $productId]);
    }
    Database::run("DELETE FROM audit_logs WHERE actor_user_id IN ($ids)");
    Database::run("DELETE FROM user_roles WHERE user_id IN ($ids)");
    foreach ($roles as $role) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $role]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $role]);
    }
    Database::run("DELETE FROM users WHERE id IN ($ids)");
    foreach ([$jarS, $jarV, $jarG] as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
}

fwrite(STDOUT, "\n$passed / $tests stock status HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
