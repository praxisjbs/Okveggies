<?php
/** Auth, scoping, CSRF and rendering checks for the customer notification bell. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8231';
$tests = 0; $passed = 0;
function cnh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cnh_eq($expected, $actual, string $label): void { cnh_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function cnh_request(string $base, string $jar, string $path, ?array $post = null): array {
    $ch = curl_init($base . $path);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json']];
    if ($post !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $options);
    $body = (string) curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, $body];
}

$serverLog = sys_get_temp_dir() . '/okv-customer-notifications-http-server.log';
$server = proc_open('exec php -d display_errors=0 -S 127.0.0.1:8231 -t ' . escapeshellarg($root), [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']], $pipes);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the notification test server.\n"); exit(2); }
$up = false;
for ($attempt = 0; $attempt < 50; $attempt++) { $socket = @fsockopen('127.0.0.1', 8231, $errno, $error, 0.2); if ($socket) { fclose($socket); $up = true; break; } usleep(100000); }
if (!$up) { proc_terminate($server); fwrite(STDERR, "The notification test server did not start.\n"); exit(2); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$email = 'shopper-http-' . $suffix . '@example.test'; $password = 'shopper-http-88';
$jar = tempnam(sys_get_temp_dir(), 'okv-shopper-http-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-shopper-guest-');
$userId = 0; $notificationIds = []; $deliveries = [];

try {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => 'Shopper', ':last' => 'Tester', ':email' => $email, ':phone' => '+23474' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'household', ':status' => 'active']
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();

    // One customer update (shown) and one staff alert to the same user id
    // (never shown), both delivered, to prove scoping over HTTP.
    foreach ([['order_placed', 'order', 61, 'We have your order'], ['admin_new_order', 'order', 61, 'Secret staff alert']] as [$event, $type, $related, $title]) {
        Database::run('INSERT INTO notifications (event_type, related_type, related_id, title, body, status) VALUES (:event, :type, :related, :title, :body, :status)', [':event' => $event, ':type' => $type, ':related' => $related, ':title' => $title, ':body' => $title . ' body', ':status' => 'sent']);
        $notificationId = (int) Database::getInstance()->getConnection()->lastInsertId(); $notificationIds[] = $notificationId;
        Database::run('INSERT INTO notification_deliveries (notification_id, user_id, channel, recipient_address, status, attempt_count, sent_at) VALUES (:notification, :user, :channel, :address, :status, :attempts, NOW())', [':notification' => $notificationId, ':user' => $userId, ':channel' => Notifications::CHANNEL_IN_APP, ':address' => 'in-app', ':status' => Notifications::STATUS_SENT, ':attempts' => 1]);
        $deliveries[$event] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    [$guestStatus] = cnh_request($base, $guestJar, '/api/v1/notifications.php?action=list');
    cnh_eq(401, $guestStatus, 'a guest cannot read the customer notification API');

    [, $accountHtml] = cnh_request($base, $jar, '/account.php');
    preg_match('/name="okv_csrf" value="([^"]+)"/', $accountHtml, $csrfMatch);
    $loginCsrf = (string) ($csrfMatch[1] ?? '');
    [$loginStatus] = cnh_request($base, $jar, '/api/v1/auth.php', ['action' => 'login', 'context' => 'storefront', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $loginCsrf]);
    cnh_eq(200, $loginStatus, 'the customer fixture signs in');

    [$shopStatus, $shop] = cnh_request($base, $jar, '/shop.php');
    cnh_eq(200, $shopStatus, 'the storefront renders for the signed-in customer');
    cnh_ok(str_contains($shop, 'data-customer-notifications'), 'the storefront header carries the customer bell');
    cnh_ok(str_contains($shop, 'aria-label="Updates, 1 unread"'), 'the server-rendered badge counts only the customer update, not the staff alert');
    cnh_ok(str_contains($shop, '/assets/js/notifications.js'), 'the storefront loads the customer notification controller');
    cnh_ok(str_contains($shop, 'role="dialog"') && str_contains($shop, 'aria-describedby="okv-customer-notification-description"'), 'the bell panel is a named and described dialog');
    cnh_ok(!str_contains($shop, 'Secret staff alert'), 'notification bodies are not leaked into the initial HTML');

    [$apiStatus, $apiBody] = cnh_request($base, $jar, '/api/v1/notifications.php?action=list');
    $payload = json_decode($apiBody, true);
    cnh_eq(200, $apiStatus, 'the signed-in customer can load the feed');
    cnh_eq(1, (int) ($payload['unread_count'] ?? -1), 'the API unread count excludes the staff alert');
    cnh_eq(1, count($payload['notifications'] ?? []), 'the API omits the staff alert row');
    cnh_eq('order_placed', (string) ($payload['notifications'][0]['event_type'] ?? ''), 'the customer update is returned');
    cnh_ok(!str_contains($apiBody, 'Secret staff alert'), 'staff content is absent from the customer JSON');

    [$postList] = cnh_request($base, $jar, '/api/v1/notifications.php', ['action' => 'list']);
    cnh_eq(405, $postList, 'the list read refuses a POST');

    [$missingCsrf] = cnh_request($base, $jar, '/api/v1/notifications.php', ['action' => 'mark_read', 'delivery_id' => $deliveries['order_placed']]);
    cnh_eq(419, $missingCsrf, 'a write rejects a missing CSRF token');

    [$readStatus, $readBody] = cnh_request($base, $jar, '/api/v1/notifications.php', ['action' => 'mark_read', 'delivery_id' => $deliveries['order_placed'], 'okv_csrf' => $loginCsrf]);
    cnh_eq(200, $readStatus, 'the customer can mark their own update read');
    cnh_eq(0, (int) (json_decode($readBody, true)['unread_count'] ?? -1), 'the API returns the updated badge count');
} finally {
    foreach ($notificationIds as $id) { Database::run('DELETE FROM notification_deliveries WHERE notification_id = :id', [':id' => $id]); Database::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]); }
    if ($userId > 0) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    @unlink($jar); @unlink($guestJar); proc_terminate($server); proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests customer notification HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
