<?php
/** Task B photo validation, persistence, privacy and cleanup over real HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8213';
$tests = 0; $passed = 0;
function iph_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function iph_eq($expected, $actual, string $label): void { iph_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function iph_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array {
    $ch = curl_init($base . $path);
    $headers = $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : ['Accept: text/html'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $multipart = false;
        foreach ($fields ?? [] as $value) {
            if ($value instanceof CURLFile) { $multipart = true; break; }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? ($fields ?? []) : http_build_query($fields ?? []));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$status, $json ? (json_decode($body, true) ?? []) : $body, $type];
}
function iph_csrf(string $html): string { return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $match) ? (string) $match[1] : ''; }
function iph_login(string $base, string $jar, string $page, string $email, string $password, bool $storefront): string {
    [, $html] = iph_req($base, $jar, 'GET', $page);
    $csrf = iph_csrf((string) $html);
    [$status] = iph_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => $storefront ? 'storefront' : '',
        'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ], true);
    iph_eq(200, $status, $email . ' signs in');
    return $csrf;
}

$serverLog = sys_get_temp_dir() . '/okv-issue-photos-http-server.log';
$server = proc_open(
    'exec env SMTP_PORT=1 php -d display_errors=0 -d upload_max_filesize=8M -d post_max_size=48M -S 127.0.0.1:8213 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the issue photo test server.\n");
    exit(2);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8213, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'issue-photo-88';
$userIds = []; $orderIds = []; $issueIds = []; $storedPaths = [];
$ownerJar = tempnam(sys_get_temp_dir(), 'okv-photo-owner-');
$otherJar = tempnam(sys_get_temp_dir(), 'okv-photo-other-');
$staffJar = tempnam(sys_get_temp_dir(), 'okv-photo-staff-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-photo-guest-');
$fixtureDir = sys_get_temp_dir() . '/okv-issue-photo-' . $suffix;
mkdir($fixtureDir, 0700);

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$validPng = $fixtureDir . '/valid.png';
$secondPng = $fixtureDir . '/second.png';
$wrongExtension = $fixtureDir . '/wrong.txt';
$disguisedJpg = $fixtureDir . '/disguised.jpg';
$truncatedPng = $fixtureDir . '/truncated.png';
$oversizedPng = $fixtureDir . '/oversized.png';
file_put_contents($validPng, $pngBytes);
file_put_contents($secondPng, $pngBytes);
file_put_contents($wrongExtension, $pngBytes);
file_put_contents($disguisedJpg, 'This is not an image.');
file_put_contents($truncatedPng, substr((string) $pngBytes, 0, -12));
file_put_contents(
    $oversizedPng,
    substr((string) $pngBytes, 0, -12) . str_repeat("\0", Uploads::maxBytes() + 1) . substr((string) $pngBytes, -12)
);

$makeUser = static function (string $first, string $type) use ($suffix, $password, &$userIds): array {
    $email = strtolower($first) . "-photo-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => $first, ':last' => 'Issue Photo', ':email' => $email,
         ':phone' => '+23472' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT),
         ':type' => $type, ':status' => 'active']
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $userIds[] = $id;
    return [$id, $email];
};
$makeOrder = static function (int $userId) use ($suffix, &$orderIds): array {
    $token = OrderTrail::newToken();
    $number = 'ZZ-PHOTO-' . count($orderIds) . '-' . $suffix;
    Database::run(
        'INSERT INTO orders
            (order_number, user_id, customer_type, order_status, payment_option, payment_status,
             subtotal_subunit, order_total_subunit, balance_due_subunit, preferred_delivery_date,
             order_trail_token_hash)
         VALUES (:number, :user, :customer_type, :status, :payment_option, :payment_status,
                 :subtotal, :total, :balance, :delivery_date, :token_hash)',
        [':number' => $number, ':user' => $userId, ':customer_type' => 'household', ':status' => 'dispatched',
         ':payment_option' => 'pay_on_delivery', ':payment_status' => 'unpaid', ':subtotal' => 500000,
         ':total' => 500000, ':balance' => 500000, ':delivery_date' => date('Y-m-d'),
         ':token_hash' => OrderTrail::hashToken($token)]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $orderIds[] = $id;
    Database::run(
        'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, created_at)
         VALUES (:order_id, :old_status, :new_status, :source, :actor, :created_at)',
        [':order_id' => $id, ':old_status' => 'packed', ':new_status' => 'dispatched',
         ':source' => 'admin', ':actor' => $userId, ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]
    );
    return [$id, $number, $token];
};
$clearRate = static function (): void { Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%'"); };
$submit = static function (int $orderId, string $csrf, array $files) use ($base, $ownerJar): array {
    return iph_req($base, $ownerJar, 'POST', '/api/v1/make_it_right.php', [
        'action' => 'report', 'okv_csrf' => $csrf, 'order_id' => $orderId,
        'category' => 'damaged', 'description' => 'Two tomato packs arrived crushed.',
    ] + $files, true);
};

try {
    [$ownerId, $ownerEmail] = $makeUser('Ada', 'household');
    [$otherId, $otherEmail] = $makeUser('Bola', 'household');
    [$staffId, $staffEmail] = $makeUser('Musa', 'staff');
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = :role', [':user' => $staffId, ':role' => 'manager']);
    [$orderId, $orderNumber, $trailToken] = $makeOrder($ownerId);

    iph_login($base, $ownerJar, '/account.php', $ownerEmail, $password, true);
    iph_login($base, $otherJar, '/account.php', $otherEmail, $password, true);
    iph_login($base, $staffJar, '/admin/login.php', $staffEmail, $password, false);
    [, $ownerPage] = iph_req($base, $ownerJar, 'GET', '/public/order.php?order=' . $orderId);
    $csrf = iph_csrf((string) $ownerPage);
    iph_ok(str_contains((string) $ownerPage, 'Choose up to 5 JPEG, PNG or WebP photos'), 'the form states the photo count and accepted types before selection');
    iph_ok(str_contains((string) $ownerPage, 'Each photo may be up to 5MB'), 'the form states the configured per-photo limit');
    iph_ok(str_contains((string) $ownerPage, 'enctype="multipart/form-data"'), 'the owner form sends multipart file data');

    $clearRate();
    [$status, $body] = $submit($orderId, $csrf, ['photos[0]' => new CURLFile($wrongExtension, 'image/png', 'evidence.txt')]);
    iph_eq(422, $status, 'an unsupported extension is refused');
    iph_eq('photo_unsupported_extension', (string) ($body['code'] ?? ''), 'the extension refusal is explicit and safe');

    $clearRate();
    [$status, $body] = $submit($orderId, $csrf, ['photos[0]' => new CURLFile($disguisedJpg, 'image/jpeg', 'evidence.jpg')]);
    iph_eq(422, $status, 'non-image content disguised as JPEG is refused');
    iph_eq('photo_unsupported_type', (string) ($body['code'] ?? ''), 'disguised content is identified without exposing a server path');

    $clearRate();
    [$status, $body] = $submit($orderId, $csrf, ['photos[0]' => new CURLFile($truncatedPng, 'image/png', 'evidence.png')]);
    iph_eq(422, $status, 'a truncated image is refused');
    iph_ok(in_array((string) ($body['code'] ?? ''), ['photo_unreadable_image', 'photo_truncated_image'], true), 'the truncated response is a safe image-integrity error');

    $clearRate();
    [$status, $body] = $submit($orderId, $csrf, ['photos[0]' => new CURLFile($oversizedPng, 'image/png', 'large.png')]);
    iph_eq(422, $status, 'an image over the configured cap is refused');
    iph_eq('photo_too_large', (string) ($body['code'] ?? ''), 'the oversized response identifies the limit safely');

    $clearRate();
    $sixPhotos = [];
    for ($index = 0; $index < 6; $index++) {
        $sixPhotos['photos[' . $index . ']'] = new CURLFile($validPng, 'image/png', 'evidence-' . $index . '.png');
    }
    [$status, $body] = $submit($orderId, $csrf, $sixPhotos);
    iph_eq(422, $status, 'a sixth photo is refused');
    iph_eq('too_many_photos', (string) ($body['code'] ?? ''), 'the excess-photo response names the fixed count rule');
    iph_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :id', [':id' => $orderId])['n'], 'every refused batch leaves the report absent');

    $clearRate();
    [$status, $body] = $submit($orderId, $csrf, [
        'photos[0]' => new CURLFile($validPng, 'image/png', 'basket.png'),
        'photos[1]' => new CURLFile($secondPng, 'image/png', 'packaging.png'),
    ]);
    iph_eq(201, $status, 'a valid 2-photo report is created');
    iph_eq(2, (int) ($body['photo_count'] ?? 0), 'the response confirms the persisted photo count');
    $issue = Database::one('SELECT id FROM issue_reports WHERE order_id = :id', [':id' => $orderId]);
    $issueId = (int) ($issue['id'] ?? 0);
    $issueIds[] = $issueId;
    $rows = Database::all('SELECT id, photo_url FROM issue_report_photos WHERE issue_id = :id ORDER BY id', [':id' => $issueId]);
    iph_eq(2, count($rows), 'both photo references are written to issue_report_photos');
    foreach ($rows as $row) {
        $storedPaths[] = (string) $row['photo_url'];
        iph_ok(preg_match('#^uploads/issues/[a-f0-9]{32}\.png$#', (string) $row['photo_url']) === 1, 'each stored photo has a randomised server name');
        iph_ok(is_file($root . '/' . $row['photo_url']), 'each stored photo exists under uploads/issues');
    }

    $photoId = (int) $rows[0]['id'];
    [$status, , $type] = iph_req($base, $ownerJar, 'GET', '/public/issue_photo.php?photo=' . $photoId);
    iph_eq(200, $status, 'the authenticated order owner can open their photo');
    iph_ok(str_starts_with($type, 'image/png'), 'the protected route sends the sniffed image type');
    [$status] = iph_req($base, $otherJar, 'GET', '/public/issue_photo.php?photo=' . $photoId);
    iph_eq(404, $status, 'another signed-in customer cannot open the photo');
    [$status] = iph_req($base, $guestJar, 'GET', '/public/issue_photo.php?photo=' . $photoId);
    iph_eq(404, $status, 'a guest cannot open the photo');
    [$status] = iph_req($base, $staffJar, 'GET', '/public/issue_photo.php?photo=' . $photoId);
    iph_eq(200, $status, 'staff with issues.view can open the photo');

    [$status, $adminPage] = iph_req($base, $staffJar, 'GET', '/admin/make_it_right.php?report=' . $issueId);
    iph_eq(200, $status, 'staff with issues.view can open the photo preview');
    iph_ok(str_contains((string) $adminPage, 'Customer photo 1 for order ' . $orderNumber), 'the admin thumbnail has meaningful alt text');
    iph_ok(str_contains((string) $adminPage, '/public/issue_photo.php?photo=' . $photoId), 'the admin thumbnail opens through the protected route');

    [$status, $publicTrail] = iph_req($base, $guestJar, 'GET', '/public/order.php?token=' . rawurlencode($trailToken));
    iph_eq(200, $status, 'the public Order Trail still opens');
    iph_ok(!str_contains((string) $publicTrail, 'issue_photo.php'), 'the public Order Trail contains no private photo route');
    iph_ok(!str_contains((string) $publicTrail, basename((string) $rows[0]['photo_url'])), 'the public Order Trail contains no stored photo identifier');

    [$failedOrderId] = $makeOrder($ownerId);
    [, $failedOrderPage] = iph_req($base, $ownerJar, 'GET', '/public/order.php?order=' . $failedOrderId);
    $failedCsrf = iph_csrf((string) $failedOrderPage);
    $beforeFiles = glob($root . '/uploads/issues/*') ?: [];
    Database::run('DROP TRIGGER IF EXISTS okv_issue_photo_fail_test');
    Database::run(
        'CREATE TRIGGER okv_issue_photo_fail_test BEFORE INSERT ON issue_report_photos
         FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced issue photo persistence failure\''
    );
    $clearRate();
    [$status, $body] = $submit($failedOrderId, $failedCsrf, ['photos[0]' => new CURLFile($validPng, 'image/png', 'failure.png')]);
    Database::run('DROP TRIGGER IF EXISTS okv_issue_photo_fail_test');
    iph_eq(500, $status, 'a photo-row persistence failure returns a generic server error');
    iph_eq('failed', (string) ($body['code'] ?? ''), 'the persistence failure exposes no database detail');
    iph_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM issue_reports WHERE order_id = :id', [':id' => $failedOrderId])['n'], 'the failed transaction rolls back its report');
    iph_eq($beforeFiles, glob($root . '/uploads/issues/*') ?: [], 'the failed transaction removes only its newly stored orphan');
} finally {
    Database::run('DROP TRIGGER IF EXISTS okv_issue_photo_fail_test');
    foreach ($storedPaths as $path) { Uploads::removeStoredFile($path, 'issues'); }
    foreach ($issueIds as $issueId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'issue_report', ':id' => $issueId]);
        Database::run('DELETE FROM issue_report_history WHERE issue_id = :id', [':id' => $issueId]);
    }
    foreach ($orderIds as $orderId) {
        Database::run('DELETE FROM issue_reports WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM order_status_history WHERE order_id = :id', [':id' => $orderId]);
        Database::run('DELETE FROM orders WHERE id = :id', [':id' => $orderId]);
    }
    if (isset($staffId) && $staffId > 0) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $staffId]); }
    foreach ($userIds as $userId) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'issues:%' OR bucket LIKE 'login:%'");
    foreach ([$ownerJar, $otherJar, $staffJar, $guestJar] as $jar) { if (is_file($jar)) { unlink($jar); } }
    foreach ([$validPng, $secondPng, $wrongExtension, $disguisedJpg, $truncatedPng, $oversizedPng] as $fixture) { if (is_file($fixture)) { unlink($fixture); } }
    if (is_dir($fixtureDir)) { rmdir($fixtureDir); }
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests issue photo HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
