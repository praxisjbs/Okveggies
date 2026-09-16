<?php
/** Permission, CSRF and rendering checks for the shared admin notification bell. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8226';
$tests = 0; $passed = 0;
function anh_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function anh_eq($expected, $actual, string $label): void { anh_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function anh_request(string $base, string $jar, string $path, ?array $post = null): array {
    $ch = curl_init($base . $path);
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json']];
    if ($post !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $options);
    $body = (string) curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, $body];
}

$serverLog = sys_get_temp_dir() . '/okv-admin-notifications-http-server.log';
$server = proc_open('exec php -d display_errors=0 -S 127.0.0.1:8226 -t ' . escapeshellarg($root), [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']], $pipes);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the notification test server.\n"); exit(2); }
$up = false;
for ($attempt = 0; $attempt < 50; $attempt++) { $socket = @fsockopen('127.0.0.1', 8226, $errno, $error, 0.2); if ($socket) { fclose($socket); $up = true; break; } usleep(100000); }
if (!$up) { proc_terminate($server); fwrite(STDERR, "The notification test server did not start.\n"); exit(2); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$email = 'bell-http-' . $suffix . '@example.test'; $password = 'bell-http-88';
$jar = tempnam(sys_get_temp_dir(), 'okv-bell-http-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-bell-guest-');
$roleId = 0; $userId = 0; $notificationIds = []; $deliveries = [];

try {
    Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => 'bell_http_' . $suffix, ':description' => 'Notification HTTP test']);
    $roleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    foreach (['dashboard.view', 'orders.view'] as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission', [':role' => $roleId, ':permission' => $permission]);
    }
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Bell', ':last' => 'Tester', ':email' => $email, ':phone' => '+23473' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'staff', ':status' => 'active']
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $userId, ':role' => $roleId]);

    foreach ([['admin_new_order', 'order', 41, 'Order needs attention'], ['admin_manual_payment_proof', 'payment_proof', 42, 'Secret payment proof']] as [$event, $type, $related, $title]) {
        Database::run('INSERT INTO notifications (event_type, related_type, related_id, title, body, status) VALUES (:event, :type, :related, :title, :body, :status)', [':event' => $event, ':type' => $type, ':related' => $related, ':title' => $title, ':body' => $title . ' body', ':status' => 'sent']);
        $notificationId = (int) Database::getInstance()->getConnection()->lastInsertId(); $notificationIds[] = $notificationId;
        Database::run('INSERT INTO notification_deliveries (notification_id, user_id, channel, recipient_address, status, attempt_count, sent_at) VALUES (:notification, :user, :channel, :address, :status, :attempts, NOW())', [':notification' => $notificationId, ':user' => $userId, ':channel' => Notifications::CHANNEL_IN_APP, ':address' => 'in-app', ':status' => Notifications::STATUS_SENT, ':attempts' => 1]);
        $deliveries[$event] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    [$guestStatus] = anh_request($base, $guestJar, '/api/v1/admin_notifications.php?action=list');
    anh_eq(401, $guestStatus, 'a guest cannot read the staff notification API');

    [, $loginHtml] = anh_request($base, $jar, '/admin/login.php');
    preg_match('/name="okv_csrf" value="([^"]+)"/', $loginHtml, $csrfMatch);
    [$loginStatus] = anh_request($base, $jar, '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrfMatch[1] ?? '']);
    anh_eq(200, $loginStatus, 'the restricted staff fixture signs in');

    [$pageStatus, $page] = anh_request($base, $jar, '/admin/');
    anh_eq(200, $pageStatus, 'the admin dashboard renders');
    anh_ok(strpos($page, 'data-admin-notifications') < strpos($page, 'View shop'), 'the shared bell renders beside and before View shop');
    anh_ok(str_contains($page, 'aria-label="Notifications, 1 unread"'), 'the server-rendered badge excludes the forbidden payment alert');
    anh_ok(str_contains($page, 'role="dialog"') && str_contains($page, 'aria-describedby="okv-notification-description"'), 'the bell panel is a named and described dialog');
    anh_ok(str_contains($page, '/assets/js/admin-notifications.js'), 'the shared admin page loads the notification controller');
    anh_ok(!str_contains($page, 'Secret payment proof'), 'notification message bodies are not leaked into the initial HTML');

    [$apiStatus, $apiBody] = anh_request($base, $jar, '/api/v1/admin_notifications.php?action=list');
    $payload = json_decode($apiBody, true);
    anh_eq(200, $apiStatus, 'the signed-in staff member can load the feed');
    anh_eq(1, (int) ($payload['unread_count'] ?? -1), 'the API unread count is permission-filtered');
    anh_eq(1, count($payload['notifications'] ?? []), 'the API omits forbidden notification rows');
    anh_eq('admin_new_order', (string) ($payload['notifications'][0]['event_type'] ?? ''), 'the permitted order update is returned');
    anh_ok(!str_contains($apiBody, 'Secret payment proof'), 'forbidden payment content is absent from the JSON response');
    anh_ok(!in_array('Payment proofs to review', array_column($payload['attention'] ?? [], 'label'), true), 'the live attention response omits payment queues');

    [$missingCsrf] = anh_request($base, $jar, '/api/v1/admin_notifications.php', ['action' => 'mark_read', 'delivery_id' => $deliveries['admin_new_order']]);
    anh_eq(419, $missingCsrf, 'read changes reject a missing CSRF token');
    [, $freshPage] = anh_request($base, $jar, '/admin/');
    preg_match('/window\.OKV\.csrf\s*=\s*"([^"]+)"/', $freshPage, $pageCsrfMatch);
    $csrf = html_entity_decode((string) ($pageCsrfMatch[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    anh_ok($csrf !== '', 'the shared shell supplies a CSRF value to its scripts');

    [$forbiddenStatus, $forbiddenBody] = anh_request($base, $jar, '/api/v1/admin_notifications.php', ['action' => 'mark_read', 'delivery_id' => $deliveries['admin_manual_payment_proof'], 'okv_csrf' => $csrf]);
    anh_eq(200, $forbiddenStatus, 'a guessed forbidden delivery fails without an information leak');
    anh_eq(false, (bool) (json_decode($forbiddenBody, true)['changed'] ?? true), 'the forbidden delivery remains unchanged');
    [$readStatus, $readBody] = anh_request($base, $jar, '/api/v1/admin_notifications.php', ['action' => 'mark_read', 'delivery_id' => $deliveries['admin_new_order'], 'okv_csrf' => $csrf]);
    anh_eq(200, $readStatus, 'an allowed notification can be marked read');
    anh_eq(0, (int) (json_decode($readBody, true)['unread_count'] ?? -1), 'the API returns the updated badge count');

    [$historyStatus, $history] = anh_request($base, $jar, '/admin/notifications.php');
    anh_eq(200, $historyStatus, 'the full notification history renders');
    anh_ok(str_contains($history, 'Order needs attention'), 'history contains the permitted update');
    anh_ok(!str_contains($history, 'Secret payment proof'), 'history excludes the forbidden update');
} finally {
    foreach ($notificationIds as $id) { Database::run('DELETE FROM notification_deliveries WHERE notification_id = :id', [':id' => $id]); Database::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]); }
    if ($userId > 0) { Database::run('DELETE FROM user_roles WHERE user_id = :id', [':id' => $userId]); Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    if ($roleId > 0) { Database::run('DELETE FROM role_permissions WHERE role_id = :id', [':id' => $roleId]); Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]); }
    @unlink($jar); @unlink($guestJar); proc_terminate($server); proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests admin notification HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
