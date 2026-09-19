<?php
/**
 * scripts/tests/product_uploads_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The product photo upload, over real HTTP, with real files.
 *
 * `Uploads::` was referenced by two suites before this one and neither of them
 * touched a product photo, so the upload the Owner uses most often had no test
 * at all. What can go wrong is specific: a renamed script gets executed, a file
 * that is not an image is stored, a huge file fills the disk, or the stored name
 * is the one the uploader chose.
 *
 * This suite signs in as a staff member with products.edit, uploads four things
 * through the real controller, and checks what landed on disk:
 *
 *   - a genuine PNG is accepted and stored under a randomised name;
 *   - a PHP script wearing a .png name is refused, and nothing is written;
 *   - a text file claiming to be a JPEG is refused by the MIME sniff;
 *   - a file over the cap is refused before it is copied anywhere.
 *
 * It also asserts the upload folder itself: uploads/.htaccess must still deny
 * execution. Apache enforces that on the host; what can be checked here is that
 * the rule is present and has not been quietly deleted.
 *
 * Exit codes: 0 all assertions passed, 1 a failure, 2 the suite could not run.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8235';
$tests = 0;
$passed = 0;

function pu_ok(bool $condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
    } else {
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function pu_eq($expected, $actual, string $label): void
{
    pu_ok(
        $expected === $actual,
        $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

/** A request with a cookie jar; multipart is used when files are attached. */
function pu_req(string $base, string $jar, string $method, string $path, ?array $fields = null, ?array $files = null): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($method === 'POST') {
        $payload = $fields ?? [];
        foreach ($files ?? [] as $field => $path) {
            $payload[$field] = new CURLFile($path);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function pu_token(string $html): string
{
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : '';
}

function pu_rmdir_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_dir($path)) {
            pu_rmdir_tree($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
    @rmdir($dir);
}

$serverLog = sys_get_temp_dir() . '/okv-uploads-http-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8235 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the uploads test server.\n");
    exit(2);
}
$listening = false;
for ($attempt = 0; $attempt < 60; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8235, $errno, $error, 0.2);
    if ($socket) {
        fclose($socket);
        $listening = true;
        break;
    }
    usleep(100000);
}
// Say why, rather than letting every assertion fail against a dead port.
if (!$listening) {
    fwrite(STDERR, "Could not reach the uploads test server on 127.0.0.1:8235 after 6 seconds.\n");
    fwrite(STDERR, "Is something else holding that port? Server log: $serverLog\n");
    proc_terminate($server);
    proc_close($server);
    exit(2);
}

$suffix = bin2hex(random_bytes(5));
$workDir = sys_get_temp_dir() . '/okv-upload-suite-' . $suffix;
mkdir($workDir, 0777, true);

$password = 'uploads-http-55';
$email = "uploads-$suffix@example.test";
$userId = 0;
$roleId = 0;
$jar = tempnam(sys_get_temp_dir(), 'okv-uploads-');
$stored = [];
$productId = 0;

try {
    // A staff member with exactly the permission the upload needs.
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) '
        . 'VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Upload', ':last' => 'Probe', ':email' => $email,
         ':phone' => '+23472' . random_int(10000000, 99999999),
         ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'staff', ':status' => 'active']
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run(
        'INSERT INTO roles (name, description) VALUES (:name, :description)',
        [':name' => 'uploads_' . $suffix, ':description' => 'Product uploads test role']
    );
    $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    // products.view opens the screen the write token comes from; products.edit
    // is the permission the upload itself is gated on. Both are needed to walk
    // the path a person walks.
    foreach (['products.view', 'products.edit'] as $permission) {
        Database::run(
            'INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission',
            [':role' => $roleId, ':permission' => $permission]
        );
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $userId, ':role' => $roleId]);

    [, $loginPage] = pu_req($base, $jar, 'GET', '/admin/login.php');
    $csrf = pu_token($loginPage);
    [$status, $body] = pu_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'identifier' => $email, 'password' => $password, 'context' => 'admin', 'okv_csrf' => $csrf,
    ]);
    pu_eq(200, $status, 'the uploader signs in');
    $token = pu_token((string) $body) !== '' ? pu_token((string) $body) : $csrf;

    // The write token has to come from a page this session may open. The
    // products screen carries it; the login page is the last resort because a
    // session that has just been regenerated may not honour its older token.
    $writeToken = '';
    foreach (['/admin/products.php', '/admin/orders.php', '/admin/login.php'] as $tokenPath) {
        [, $tokenPage] = pu_req($base, $jar, 'GET', $tokenPath);
        $candidate = pu_token((string) $tokenPage);
        if ($candidate !== '') {
            $writeToken = $candidate;
            break;
        }
    }
    pu_ok($writeToken !== '', 'a write token is available to the session');

    $product = Database::one('SELECT id FROM products ORDER BY id LIMIT 1');
    $productId = (int) ($product['id'] ?? 0);
    pu_ok($productId > 0, 'there is a product to attach a photo to');

    // ---- The four files ------------------------------------------------------
    $png = $workDir . '/real.png';
    file_put_contents($png, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII='
    ));
    $fake = $workDir . '/not-an-image.png';
    file_put_contents($fake, "<?php echo 'executed'; ?>\n");
    $text = $workDir . '/notes.jpg';
    file_put_contents($text, "This is text pretending to be a photograph.\n");
    $large = $workDir . '/too-large.png';
    $cap = (int) env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
    $handle = fopen($large, 'wb');
    if ($handle !== false) {
        // A real PNG header, then enough bytes to pass the cap.
        fwrite($handle, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAIAQMAAAD+wSzIAAAABlBMVEX///+/v7+jQ3Y5AAAADklEQVQI12P4AIX8EAgALgAD/aNpbtEAAAAASUVORK5CYII='));
        $chunk = str_repeat('0', 1024 * 1024);
        $written = 30;
        for ($i = 0; $i < $written; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);
    }
    pu_ok(filesize($large) > $cap, 'the oversized fixture really is over the cap');

    [$status, $body] = pu_req($base, $jar, 'POST', '/api/v1/products.php?action=add_image', [
        'product_id' => $productId, 'okv_csrf' => $writeToken,
    ], ['image' => $png]);
    pu_eq(200, $status, 'a genuine PNG is accepted');
    $decoded = json_decode((string) $body, true);
    $imageId = (int) ($decoded['image_id'] ?? 0);
    pu_ok($imageId > 0, 'the accepted upload reports the image row it created');
    $row = $imageId > 0 ? Database::one('SELECT image_url FROM product_images WHERE id = :id', [':id' => $imageId]) : null;
    $storedPath = (string) ($row['image_url'] ?? '');
    pu_ok($storedPath !== '', 'the accepted upload recorded where it was stored');
    pu_ok(preg_match('#^uploads/products/[a-f0-9]{32}\.(png|jpg|jpeg|webp)$#', $storedPath) === 1,
        'the stored name is randomised, not the uploader\'s name', "($storedPath)");
    $absolute = $root . '/' . ltrim($storedPath, '/');
    pu_ok(is_file($absolute), 'the accepted file really exists on disk');
    if ($storedPath !== '') {
        $stored[] = $absolute;
        pu_ok(stripos($storedPath, basename($png)) === false, 'the uploader\'s own filename was not reused');
    }

    $before = count(glob($root . '/uploads/products/*') ?: []);

    [$status, $body] = pu_req($base, $jar, 'POST', '/api/v1/products.php?action=add_image', [
        'product_id' => $productId, 'okv_csrf' => $writeToken,
    ], ['image' => $fake]);
    pu_eq(422, $status, 'a script wearing a .png name is refused');
    pu_ok(stripos((string) $body, 'executed') === false, 'the refusal never echoes the script');

    [$status] = pu_req($base, $jar, 'POST', '/api/v1/products.php?action=add_image', [
        'product_id' => $productId, 'okv_csrf' => $writeToken,
    ], ['image' => $text]);
    pu_eq(422, $status, 'a text file claiming to be a JPEG is refused by the sniff');

    [$status, $body] = pu_req($base, $jar, 'POST', '/api/v1/products.php?action=add_image', [
        'product_id' => $productId, 'okv_csrf' => $writeToken,
    ], ['image' => $large]);
    pu_eq(422, $status, 'a file over the cap is refused');

    $after = count(glob($root . '/uploads/products/*') ?: []);
    pu_eq($before, $after, 'none of the refused files was written');

    // ---- The folder's own rules ---------------------------------------------
    $htaccess = (string) @file_get_contents($root . '/uploads/.htaccess');
    pu_ok(stripos($htaccess, 'php_flag engine off') !== false, 'uploads/.htaccess still turns the PHP engine off');
    pu_ok(preg_match('/FilesMatch.*(php|phtml|phar)/i', $htaccess) === 1, 'uploads/.htaccess still denies executable files');
} finally {
    if (isset($imageId) && $imageId > 0) {
        Database::run('DELETE FROM product_images WHERE id = :id', [':id' => $imageId]);
    }
    foreach ($stored as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
    if ($roleId > 0) {
        Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
    pu_rmdir_tree($workDir);
    if (is_file($jar)) {
        unlink($jar);
    }
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

fwrite(STDOUT, "\n$passed / $tests product upload assertions passed.\n");
exit($passed === $tests ? 0 : 1);
