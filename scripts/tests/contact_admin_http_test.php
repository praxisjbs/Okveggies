<?php
/** M9 contact-message RBAC and write checks through the real admin controller. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8211';
$tests = 0; $passed = 0;
function cah_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cah_eq($expected, $actual, string $label): void { cah_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function cah_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]);
    if ($json) { curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? [])); }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $json ? (json_decode($body, true) ?? []) : $body];
}
function cah_csrf(string $html): string { return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : ''; }
function cah_login(string $base, string $jar, string $email, string $password): string {
    [, $html] = cah_req($base, $jar, 'GET', '/admin/login.php');
    $csrf = cah_csrf($html);
    [$status] = cah_req($base, $jar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf,
    ], true);
    cah_eq(200, $status, $email . ' signs in');
    [, $page] = cah_req($base, $jar, 'GET', '/admin/content.php');
    return cah_csrf($page);
}

$serverLog = sys_get_temp_dir() . '/okv-contact-admin-http-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8211 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the contact admin test server.\n"); exit(2); }
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8211, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'messages-http-88';
$managerEmail = "messages-manager-$suffix@example.test";
$readerEmail = "messages-reader-$suffix@example.test";
$outsiderEmail = "messages-outsider-$suffix@example.test";
$managerId = 0; $readerId = 0; $outsiderId = 0; $readerRoleId = 0; $outsiderRoleId = 0; $messageIds = [];
$managerJar = tempnam(sys_get_temp_dir(), 'okv-msg-manager-');
$readerJar = tempnam(sys_get_temp_dir(), 'okv-msg-reader-');
$guestJar = tempnam(sys_get_temp_dir(), 'okv-msg-guest-');
$outsiderJar = tempnam(sys_get_temp_dir(), 'okv-msg-outsider-');

try {
    foreach ([['Manager', $managerEmail], ['Reader', $readerEmail], ['Outsider', $outsiderEmail]] as [$first, $email]) {
        Database::run(
            'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
             VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [':first' => $first, ':last' => 'Messages', ':email' => $email,
             ':phone' => '+23480' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT),
             ':type' => 'staff', ':status' => 'active']
        );
        $newId = (int) Database::getInstance()->getConnection()->lastInsertId();
        if ($first === 'Manager') { $managerId = $newId; }
        elseif ($first === 'Reader') { $readerId = $newId; }
        else { $outsiderId = $newId; }
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = :role', [':user' => $managerId, ':role' => 'manager']);
    Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => 'message_reader_' . $suffix, ':description' => 'M9 view-only test role']);
    $readerRoleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission', [':role' => $readerRoleId, ':permission' => 'messages.view']);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $readerId, ':role' => $readerRoleId]);

    // Staff with neither messages permission: they can reach the panel, but the
    // messages screen and every message action are closed to them.
    Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => 'message_outsider_' . $suffix, ':description' => 'M9 no-messages test role']);
    $outsiderRoleId = (int) Database::getInstance()->getConnection()->lastInsertId();
    Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission', [':role' => $outsiderRoleId, ':permission' => 'dashboard.view']);
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $outsiderId, ':role' => $outsiderRoleId]);

    for ($i = 1; $i <= 27; $i++) {
        Database::run(
            'INSERT INTO contact_messages (name, email, phone, subject, message, source, status, created_at)
             VALUES (:name, :email, :phone, :subject, :message, :source, :status, :created)',
            [
                ':name' => 'HTTP Contact ' . $suffix . ' ' . $i,
                ':email' => $i === 1 ? null : "http-sender-$i-$suffix@example.test",
                ':phone' => $i === 1 ? null : '+23481' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                ':subject' => $i === 1 ? 'Special filter subject' : 'HTTP subject ' . $i,
                ':message' => $i === 1 ? '<script>alert("unsafe")</script>' : 'HTTP message ' . $i,
                ':source' => 'contact_page', ':status' => 'new',
                ':created' => date('Y-m-d H:i:s', strtotime('-' . $i . ' minutes')),
            ]
        );
        $messageIds[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }
    $target = $messageIds[0];

    [$status] = cah_req($base, $guestJar, 'GET', '/admin/content.php');
    cah_eq(302, $status, 'a guest cannot open the message workspace');

    $managerCsrf = cah_login($base, $managerJar, $managerEmail, $password);
    [$status, $managerPage] = cah_req($base, $managerJar, 'GET', '/admin/content.php?message=' . $target);
    cah_eq(200, $status, 'a Manager with messages.view opens message detail');
    cah_ok(str_contains($managerPage, '&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;'), 'message content is escaped as text');
    cah_ok(!str_contains($managerPage, '<script>alert("unsafe")</script>'), 'stored message markup never executes as HTML');
    cah_ok(substr_count($managerPage, 'Not provided') >= 2, 'missing email and phone have a clear detail state');
    cah_ok(!str_contains($managerPage, 'mailto:'), 'a missing email creates no mail link');
    cah_ok(str_contains($managerPage, 'Mark handled'), 'a Manager sees the explicit handling action');
    cah_eq('new', (string) Database::one('SELECT status FROM contact_messages WHERE id = :id', [':id' => $target])['status'], 'opening a message does not change its status');

    [$status, $filtered] = cah_req($base, $managerJar, 'GET', '/admin/content.php?search=Special&status=new&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'));
    cah_eq(200, $status, 'combined search, status and date filters render');
    cah_ok(str_contains($filtered, 'Special filter subject'), 'combined filters return the matching message');
    cah_ok(str_contains($filtered, 'search=Special&amp;status=new'), 'message links preserve active filters');

    [$status, $pageOne] = cah_req($base, $managerJar, 'GET', '/admin/content.php');
    cah_ok(str_contains($pageOne, 'aria-label="Page 2"'), 'more than 25 messages render pagination');
    [$status, $pageTwo] = cah_req($base, $managerJar, 'GET', '/admin/content.php?page=2');
    cah_eq(200, $status, 'the second message page renders');
    cah_ok(str_contains($pageTwo, '26') && str_contains($pageTwo, '27'), 'the second page carries the remaining fixtures');

    [$status] = cah_req($base, $managerJar, 'GET', '/api/v1/contact.php?action=handle&message_id=' . $target, null, true);
    cah_eq(405, $status, 'message status cannot change over GET');
    [$status] = cah_req($base, $managerJar, 'POST', '/api/v1/contact.php', ['action' => 'handle', 'message_id' => $target, 'expected_status' => 'new'], true);
    cah_eq(419, $status, 'message status requires CSRF');

    [$status] = cah_req($base, $managerJar, 'POST', '/api/v1/contact.php', [
        'action' => 'save_note', 'message_id' => $target, 'expected_note' => '',
        'admin_note' => 'Call after the market run.', 'okv_csrf' => $managerCsrf,
    ], true);
    cah_eq(200, $status, 'a Manager can save an internal note');
    cah_eq('Call after the market run.', (string) Database::one('SELECT admin_note FROM contact_messages WHERE id = :id', [':id' => $target])['admin_note'], 'the note update reaches the row');

    $readerCsrf = cah_login($base, $readerJar, $readerEmail, $password);
    [$status, $readerPage] = cah_req($base, $readerJar, 'GET', '/admin/content.php?message=' . $target);
    cah_eq(200, $status, 'a user with messages.view can read the workspace');
    cah_ok(!str_contains($readerPage, 'Mark handled') && !str_contains($readerPage, '>Save note<'), 'a view-only user sees no write controls');
    [$status] = cah_req($base, $readerJar, 'POST', '/api/v1/contact.php', [
        'action' => 'handle', 'message_id' => $target, 'expected_status' => 'new', 'okv_csrf' => $readerCsrf,
    ], true);
    cah_eq(403, $status, 'a direct handle request without messages.handle is refused');
    cah_eq('new', (string) Database::one('SELECT status FROM contact_messages WHERE id = :id', [':id' => $target])['status'], 'the unauthorised request changes nothing');

    // 38, second half. Someone with neither permission sees nothing.
    $outsiderCsrf = cah_login($base, $outsiderJar, $outsiderEmail, $password);
    [$status, $outsiderPage] = cah_req($base, $outsiderJar, 'GET', '/admin/content.php?message=' . $target);
    cah_ok($status === 403 || $status === 302, 'a staff member with neither messages permission cannot open the workspace');
    cah_ok(!str_contains((string) $outsiderPage, 'Special filter subject'), 'and no message text reaches them');
    [$status, $outsiderDash] = cah_req($base, $outsiderJar, 'GET', '/admin/');
    cah_ok(!str_contains((string) $outsiderDash, 'href="/admin/content.php"'), 'the Messages link is not even in their sidebar');
    [$status] = cah_req($base, $outsiderJar, 'POST', '/api/v1/contact.php', [
        'action' => 'save_note', 'message_id' => $target, 'expected_note' => 'Call after the market run.',
        'admin_note' => 'Should never land.', 'okv_csrf' => $outsiderCsrf,
    ], true);
    cah_eq(403, $status, 'and a direct note write from them is refused');
    cah_eq('Call after the market run.', (string) Database::one('SELECT admin_note FROM contact_messages WHERE id = :id', [':id' => $target])['admin_note'], 'the note they tried to overwrite is untouched');

    // 21. The unanswered count is on the chrome staff always have open.
    [, $badgePage] = cah_req($base, $managerJar, 'GET', '/admin/');
    cah_ok(preg_match('/new, unanswered/', (string) $badgePage) === 1, 'a Manager carries the unanswered count on every admin screen');

    [$status] = cah_req($base, $managerJar, 'POST', '/api/v1/contact.php', [
        'action' => 'handle', 'message_id' => $target, 'expected_status' => 'new', 'okv_csrf' => $managerCsrf,
    ], true);
    cah_eq(200, $status, 'a Manager can mark the message handled');
    $handled = Database::one('SELECT status, handled_by, handled_at FROM contact_messages WHERE id = :id', [':id' => $target]);
    cah_eq('handled', $handled['status'], 'handled status is stored through HTTP');
    cah_eq($managerId, (int) $handled['handled_by'], 'HTTP handling records the Manager');
    cah_ok($handled['handled_at'] !== null, 'HTTP handling records its time');

    [$status] = cah_req($base, $managerJar, 'POST', '/api/v1/contact.php', [
        'action' => 'reopen', 'message_id' => $target, 'expected_status' => 'handled', 'okv_csrf' => $managerCsrf,
    ], true);
    cah_eq(200, $status, 'a Manager can reopen a handled message');
    $reopened = Database::one('SELECT status, handled_by, handled_at, admin_note FROM contact_messages WHERE id = :id', [':id' => $target]);
    cah_eq('new', $reopened['status'], 'reopen restores new status through HTTP');
    cah_eq(null, $reopened['handled_by'], 'reopen clears the recorded handler');
    cah_eq(null, $reopened['handled_at'], 'reopen clears the handling time');
    cah_eq('Call after the market run.', $reopened['admin_note'], 'reopen keeps the internal note');

    [$status] = cah_req($base, $managerJar, 'POST', '/api/v1/contact.php', [
        'action' => 'delete', 'message_id' => $target, 'okv_csrf' => $managerCsrf,
    ], true);
    cah_eq(400, $status, 'there is no hard-delete contact action');
    cah_ok((bool) Database::one('SELECT id FROM contact_messages WHERE id = :id', [':id' => $target]), 'the unsupported delete request leaves the message in place');
} finally {
    foreach ($messageIds as $id) {
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'contact_message', ':id' => $id]);
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $id]);
    }
    foreach ([$managerId, $readerId, $outsiderId] as $userId) {
        if ($userId > 0) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]); }
    }
    foreach ([$readerRoleId, $outsiderRoleId] as $roleId) {
        if ($roleId > 0) { Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]); }
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
    foreach ([$managerJar, $readerJar, $guestJar, $outsiderJar] as $jar) { if (is_file($jar)) { unlink($jar); } }
    proc_terminate($server);
    proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests contact admin HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
