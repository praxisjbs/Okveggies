<?php
/**
 * scripts/tests/pricing_exports_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The three price-list downloads over real HTTP, with real
 * sessions and real permissions.
 *
 * The pricing screen offers the list as .xlsx, as a PDF and as PNG pages in a
 * ZIP. Every one of them is gated on the server by pricing.export, and this
 * suite drives all three the way a browser does: a Manager signs in and
 * downloads, a colleague whose role holds pricing.view but not pricing.export
 * is refused, and a guest who is not signed in at all is refused too. The
 * responses are checked for what a download must carry: the right content
 * type, a no-store cache header, a filename that names the day, a real file
 * signature, and a PNG bundle whose first entry really is a PNG of the page
 * size the renderer promises.
 *
 * The pricing.export responses are built in memory before any header is sent,
 * so a failure answers as a JSON error rather than a half file. Exit codes:
 * 0 all assertions passed, 1 a failure, 2 the suite could not run.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "This test needs the ZIP extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$port = 8236;
$base = 'http://127.0.0.1:' . $port;
$tests = 0;
$passed = 0;

function pex_ok(bool $condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
    } else {
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function pex_eq($expected, $actual, string $label): void
{
    pex_ok(
        $expected === $actual,
        $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

/** One request with a cookie jar, returning [status, headers, body]. */
function pex_req(string $base, ?string $jar, string $path): array
{
    $ch = curl_init($base . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HEADER         => true,
    ];
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $options);
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = [];
    foreach (explode("\r\n", substr($response, 0, $headerSize)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))][] = trim($value);
        }
    }
    return [$status, $headers, substr($response, $headerSize)];
}

/** Sign in through the real login form flow and keep the session cookie. */
function pex_login(string $base, string $jar, string $email, string $password): bool
{
    $ch = curl_init($base . '/admin/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    if (!preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m)) {
        return false;
    }
    $ch = curl_init($base . '/api/v1/auth.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'action'     => 'login',
            'identifier' => $email,
            'password'   => $password,
            'okv_csrf'   => $m[1],
        ]),
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status === 200;
}

// --- The suite's own web server, the way the leak matrix runs ----------------

$serverLog = sys_get_temp_dir() . '/okv-pricing-exports-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the pricing exports server.\n");
    exit(2);
}
$listening = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
    if ($socket) {
        fclose($socket);
        $listening = true;
        break;
    }
    usleep(100000);
}
if (!$listening) {
    fwrite(STDERR, "Could not reach the pricing exports server on 127.0.0.1:$port after 5 seconds.\n");
    fwrite(STDERR, "Is something else holding that port? Server log: $serverLog\n");
    proc_terminate($server);
    proc_close($server);
    exit(2);
}

// --- Fixtures: a tiny catalogue, a Manager, a viewer without export ----------

$suffix = bin2hex(random_bytes(5));
$password = 'pricing-exports-77';
$categoryName = 'ZZ Export Test ' . $suffix;
$hostileName = 'Export pepper =HYPERLINK("http://evil.example","x") <script>alert(1)</script>';
$categorySlug = 'zz-export-' . $suffix;
$managerJar = tempnam(sys_get_temp_dir(), 'okv-exp-mgr-');
$viewerJar = tempnam(sys_get_temp_dir(), 'okv-exp-view-');
$managerId = 0;
$viewerId = 0;
$narrowRoleId = 0;
$categoryId = 0;
$productIds = [];
$pdfFilenamePattern = '/^okveggies-price-list-\d{4}-\d{2}-\d{2}\.pdf$/';
$pngFilenamePattern = '/^okveggies-price-list-\d{4}-\d{2}-\d{2}-png\.zip$/';

try {
    Database::run(
        'INSERT INTO product_categories (name, slug, description, sort_order, is_active)
         VALUES (:name, :slug, :description, 950, 1)',
        [':name' => $categoryName, ':slug' => $categorySlug, ':description' => 'Throwaway category for the pricing export test.']
    );
    $categoryId = (int) Database::getInstance()->getConnection()->lastInsertId();

    $makeProduct = static function (string $name, string $sku, int $priceSubunit) use ($categoryId, &$productIds): int {
        [$clean, $errors] = Products::validate([
            'name'        => $name,
            'sku'         => $sku,
            'category_id' => $categoryId,
            'unit_id'     => 1,
            'price'       => $priceSubunit > 0 ? (string) Money::toNaira($priceSubunit) : '',
            'minimum_quantity'   => '1',
            'quantity_increment' => '1',
            'is_active'   => 1,
        ]);
        if ($errors) {
            fwrite(STDERR, 'fixture failed: ' . json_encode($errors) . "\n");
            exit(2);
        }
        $id = Products::create($clean, null);
        $productIds[] = $id;
        return $id;
    };

    $makeProduct('Export tomato ' . $suffix, 'ZZ-EXP-TOM-' . $suffix, 270000);
    $unpricedId = $makeProduct('Export draft ' . $suffix, 'ZZ-EXP-DRF-' . $suffix, 0);
    $oosId = $makeProduct('Export okra ' . $suffix, 'ZZ-EXP-OKR-' . $suffix, 45000);
    $makeProduct($hostileName, 'ZZ-EXP-BAD-' . $suffix, 30000);
    Products::setAvailability($oosId, 'out_of_stock', null, null);

    // The Manager role carries pricing.export out of the seed.
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (\'Export\', \'Manager\', :email, :phone, :hash, \'staff\', \'active\', NOW())',
        [':email' => "export-manager-$suffix@example.test", ':phone' => '+23477' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $managerId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = \'manager\'', [':user' => $managerId]);

    // A colleague who may look at prices but never hand the list out.
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (\'Export\', \'Viewer\', :email, :phone, :hash, \'staff\', \'active\', NOW())',
        [':email' => "export-viewer-$suffix@example.test", ':phone' => '+23477' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $viewerId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO roles (name, description) VALUES (:name, :description)',
        [':name' => 'zz_export_viewer_' . $suffix, ':description' => 'Pricing export test role, view only']
    );
    $narrowRoleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission',
        [':role' => $narrowRoleId, ':permission' => 'pricing.view']
    );
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $viewerId, ':role' => $narrowRoleId]);
    Database::run('DELETE FROM rate_limits');

    pex_ok(pex_login($base, $managerJar, "export-manager-$suffix@example.test", $password), 'the Manager signs in');
    pex_ok(pex_login($base, $viewerJar, "export-viewer-$suffix@example.test", $password), 'the view-only colleague signs in');

    // --- The guest is refused before anything is built ----------------------
    [$status] = pex_req($base, null, '/api/v1/pricing.php?action=export_pdf');
    pex_eq(401, $status, 'a guest cannot download the PDF');
    [$status] = pex_req($base, null, '/api/v1/pricing.php?action=export_png');
    pex_eq(401, $status, 'a guest cannot download the PNG bundle');

    // --- The view-only colleague is refused on the server -------------------
    foreach (['export', 'export_pdf', 'export_png'] as $action) {
        [$status, , $body] = pex_req($base, $viewerJar, '/api/v1/pricing.php?action=' . $action);
        pex_eq(403, $status, "pricing.export is refused on the server for $action");
        $decoded = json_decode((string) $body, true);
        pex_eq('error', $decoded['status'] ?? null, "the refusal to the viewer is a JSON error for $action");
    }

    // --- The Manager downloads the PDF --------------------------------------
    [$status, $headers, $body] = pex_req($base, $managerJar, '/api/v1/pricing.php?action=export_pdf');
    pex_eq(200, $status, 'the Manager downloads the PDF');
    pex_eq(['application/pdf'], $headers['content-type'] ?? [], 'the PDF answers with the PDF content type');
    pex_ok((bool) preg_match($pdfFilenamePattern, $headers['content-disposition'][0] ?? ''), 'the PDF names itself and the day: ' . ($headers['content-disposition'][0] ?? 'none'));
    pex_ok(str_starts_with($headers['content-disposition'][0] ?? '', 'attachment;'), 'the PDF is an attachment');
    pex_ok(str_contains($headers['cache-control'][0] ?? '', 'no-store'), 'the PDF is never cached');
    pex_ok(str_starts_with($body, '%PDF-'), 'the body is a real PDF file');
    pex_ok(str_contains($body, '/Type /Page') && !str_contains($body, '/Type /Pages /Count 0'), 'the PDF carries at least one page');
    pex_ok(!str_contains($body, sys_get_temp_dir()), 'the PDF carries no temp path');

    // --- The Manager downloads the PNG bundle -------------------------------
    [$status, $headers, $body] = pex_req($base, $managerJar, '/api/v1/pricing.php?action=export_png');
    pex_eq(200, $status, 'the Manager downloads the PNG bundle');
    pex_eq(['application/zip'], $headers['content-type'] ?? [], 'the PNG bundle answers with the ZIP content type');
    pex_ok((bool) preg_match($pngFilenamePattern, $headers['content-disposition'][0] ?? ''), 'the bundle names itself and the day: ' . ($headers['content-disposition'][0] ?? 'none'));
    pex_ok(str_contains($headers['cache-control'][0] ?? '', 'no-store'), 'the bundle is never cached');
    pex_ok(str_starts_with($body, "PK\x03\x04"), 'the body is a real ZIP');

    $probe = tempnam(sys_get_temp_dir(), 'okv-exp-zip-');
    try {
        file_put_contents($probe, $body);
        $zip = new ZipArchive();
        pex_ok($zip->open($probe, ZipArchive::CHECKCONS) === true, 'the downloaded archive opens clean');
        pex_ok($zip->numFiles >= 1, 'the archive holds at least one page');
        $first = (string) $zip->getFromIndex(0);
        pex_ok(str_starts_with($first, "\x89PNG\r\n\x1a\n"), 'entry one is a real PNG');
        $size = getimagesizefromstring($first);
        pex_eq(PriceListPng::PAGE_W, $size[0] ?? 0, 'the PNG page is the promised width');
        pex_eq(PriceListPng::PAGE_H, $size[1] ?? 0, 'the PNG page is the promised height');
        $zip->close();
    } finally {
        @unlink($probe);
    }

    // --- The .xlsx export is exactly what it always was ---------------------
    [$status, $headers, $body] = pex_req($base, $managerJar, '/api/v1/pricing.php?action=export');
    pex_eq(200, $status, 'the Manager downloads the spreadsheet');
    pex_eq(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $headers['content-type'] ?? [], 'the spreadsheet keeps its own content type');
    pex_ok(str_starts_with($body, "PK\x03\x04"), 'the spreadsheet is still a real xlsx container');

    // --- The pricing screen carries the three choices, gated by permission --
    [$status, , $body] = pex_req($base, $managerJar, '/admin/pricing.php');
    pex_eq(200, $status, 'the Manager opens the pricing screen');
    pex_ok(str_contains($body, 'action=export"'), 'the screen offers the spreadsheet');
    pex_ok(str_contains($body, 'action=export_pdf'), 'the screen offers the PDF');
    pex_ok(str_contains($body, 'action=export_png'), 'the screen offers the PNG bundle');
    [$status, , $body] = pex_req($base, $viewerJar, '/admin/pricing.php');
    pex_eq(200, $status, 'the view-only colleague opens the pricing screen');
    pex_ok(!str_contains($body, 'action=export_pdf'), 'and the screen offers them no download at all');

} finally {
    foreach ($productIds as $id) {
        Database::run('DELETE FROM product_price_history WHERE product_id = :id', [':id' => $id]);
        Database::run('DELETE FROM product_availability WHERE product_id = :id', [':id' => $id]);
        Database::run('DELETE FROM products WHERE id = :id', [':id' => $id]);
    }
    if ($categoryId) {
        Database::run('DELETE FROM product_categories WHERE id = :id', [':id' => $categoryId]);
    }
    foreach ([$managerId, $viewerId] as $id) {
        if ($id) {
            Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $id]);
            Database::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
        }
    }
    if ($narrowRoleId) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $narrowRoleId]);
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $narrowRoleId]);
    }
    foreach ([$managerJar, $viewerJar] as $jar) {
        if (is_string($jar) && is_file($jar)) {
            @unlink($jar);
        }
    }
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests pricing export HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
