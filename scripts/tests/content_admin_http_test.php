<?php
/** M12 Page Copy route, RBAC, CSRF, conflict and PRG checks over real HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }
$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8212';
$tests = 0; $passed = 0;
function cph_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cph_eq($expected, $actual, string $label): void { cph_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function cph_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array {
    $headers = [];
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) { $headers[] = trim($line); return strlen($line); }]);
    if ($json) { curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? [])); }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$status, $json ? (json_decode($body, true) ?? []) : $body, $location, $headers];
}
function cph_token(string $html): string { return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : ''; }
function cph_fingerprint(string $html): string { return preg_match('/name="fingerprint" value="([a-f0-9]{64})"/', $html, $m) ? (string) $m[1] : ''; }
function cph_login(string $base, string $jar, string $email, string $password): string {
    [, $html] = cph_req($base, $jar, 'GET', '/admin/login.php');
    $csrf = cph_token($html);
    [$status] = cph_req($base, $jar, 'POST', '/api/v1/auth.php', ['action' => 'login', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf], true);
    cph_eq(200, $status, $email . ' signs in');
    return $csrf;
}

$serverLog = sys_get_temp_dir() . '/okv-content-admin-http-server.log';
$server = proc_open('exec php -d display_errors=0 -S 127.0.0.1:8212 -t ' . escapeshellarg($root), [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']], $pipes);
if (!is_resource($server)) { fwrite(STDERR, "Could not start the content admin test server.\n"); exit(2); }
for ($attempt = 0; $attempt < 50; $attempt++) { $socket = @fsockopen('127.0.0.1', 8212, $errno, $error, 0.2); if ($socket) { fclose($socket); break; } usleep(100000); }

$before = Database::one('SELECT * FROM content_pages WHERE slug = :slug', [':slug' => 'about']);
$suffix = bin2hex(random_bytes(5));
$password = 'content-http-88';
$users = []; $roles = []; $jars = []; $contactMessageId = 0;
$profiles = [
    'viewer' => ['content.view'],
    'editor' => ['content.view', 'content.edit'],
    'messages' => ['messages.view'],
    'outsider' => ['dashboard.view'],
];

try {
    Database::run(
        'UPDATE content_pages SET title = :public_title, body = :public_body, draft_title = :draft_title, draft_body = :draft_body, '
        . 'draft_meta_title = :meta_title, draft_meta_description = :meta_description, draft_content_data = NULL, '
        . 'draft_image_url = NULL, draft_image_alt = NULL, image_url = NULL, image_alt = NULL, is_published = :published, '
        . 'published_at = NULL, published_by = NULL, updated_by = NULL WHERE slug = :slug',
        [':public_title' => 'Old public story', ':public_body' => 'Old public body.', ':draft_title' => 'Secret draft ' . $suffix,
         ':draft_body' => 'Saved draft body.', ':meta_title' => 'Draft search title', ':meta_description' => 'Draft search description.',
         ':published' => 0, ':slug' => 'about']
    );
    Database::run(
        'INSERT INTO contact_messages (name, email, subject, message, source, status) VALUES (:name, :email, :subject, :message, :source, :status)',
        [':name' => 'M12 permission probe', ':email' => 'm12-' . $suffix . '@example.test', ':subject' => 'MESSAGE SECRET ' . $suffix,
         ':message' => 'This belongs only to messages.view.', ':source' => 'contact_page', ':status' => 'new']
    );
    $contactMessageId = (int) Database::getInstance()->getConnection()->lastInsertId();
    foreach ($profiles as $name => $permissions) {
        $email = "content-$name-$suffix@example.test";
        Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [':first' => ucfirst($name), ':last' => 'Content', ':email' => $email, ':phone' => '+23479' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'staff', ':status' => 'active']);
        $users[$name] = ['id' => (int) Database::getInstance()->getConnection()->lastInsertId(), 'email' => $email];
        Database::run('INSERT INTO roles (name, description) VALUES (:name, :description)', [':name' => 'content_' . $name . '_' . $suffix, ':description' => 'M12 HTTP test role']);
        $roles[$name] = (int) Database::getInstance()->getConnection()->lastInsertId();
        foreach ($permissions as $permission) {
            Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE `key` = :permission', [':role' => $roles[$name], ':permission' => $permission]);
        }
        Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', [':user' => $users[$name]['id'], ':role' => $roles[$name]]);
        $jars[$name] = tempnam(sys_get_temp_dir(), 'okv-content-' . $name . '-');
    }
    foreach (['owner', 'manager'] as $roleName) {
        $email = "content-$roleName-$suffix@example.test";
        Database::run('INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status) VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
            [':first' => ucfirst($roleName), ':last' => 'Content', ':email' => $email, ':phone' => '+23478' . random_int(10000000, 99999999), ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'staff', ':status' => 'active']);
        $users[$roleName] = ['id' => (int) Database::getInstance()->getConnection()->lastInsertId(), 'email' => $email];
        Database::run(
            'INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE name = :role',
            [':user' => $users[$roleName]['id'], ':role' => $roleName]
        );
        $jars[$roleName] = tempnam(sys_get_temp_dir(), 'okv-content-' . $roleName . '-');
    }
    $jars['guest'] = tempnam(sys_get_temp_dir(), 'okv-content-guest-');

    [$status] = cph_req($base, $jars['guest'], 'GET', '/admin/content.php?tab=page-copy&page=about');
    cph_eq(302, $status, 'a guest cannot open Page Copy');

    $viewerCsrf = cph_login($base, $jars['viewer'], $users['viewer']['email'], $password);
    [$status, $viewerPage] = cph_req($base, $jars['viewer'], 'GET', '/admin/content.php?page=about');
    cph_eq(200, $status, 'content-only viewer lands on Page Copy without a tab parameter');
    cph_ok(str_contains($viewerPage, 'Secret draft ' . $suffix), 'content viewer receives the selected saved draft');
    cph_ok(!str_contains($viewerPage, '>Save draft</button>'), 'content viewer receives no enabled save action');
    cph_ok(!str_contains($viewerPage, '>Messages<'), 'content-only viewer receives no Messages tab');
    cph_ok(!str_contains($viewerPage, 'MESSAGE SECRET ' . $suffix), 'content-only HTML contains no customer-message data');
    cph_ok(str_contains($viewerPage, 'Content and Messages'), 'content-only viewer receives the shared navigation item');
    [$status, $preview, , $previewHeaders] = cph_req($base, $jars['viewer'], 'GET', '/admin/content-preview.php?page=about');
    cph_eq(200, $status, 'content viewer can open the staff-only saved-draft preview');
    cph_ok(str_contains($preview, 'Secret draft ' . $suffix), 'preview contains the latest saved draft');
    cph_ok((bool) array_filter($previewHeaders, static fn(string $line): bool => strcasecmp($line, 'X-Robots-Tag: noindex, nofollow') === 0), 'staff preview sends the HTTP noindex directive');
    [$status] = cph_req($base, $jars['viewer'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'okv_csrf' => $viewerCsrf], true);
    cph_eq(403, $status, 'content.view alone cannot write through the controller');

    $messagesCsrf = cph_login($base, $jars['messages'], $users['messages']['email'], $password);
    [$status, $messagesPage] = cph_req($base, $jars['messages'], 'GET', '/admin/content.php');
    cph_eq(200, $status, 'messages-only staff land on Messages');
    cph_ok(!str_contains($messagesPage, 'Secret draft ' . $suffix), 'messages-only HTML contains no page-copy data');
    cph_ok(!str_contains($messagesPage, '>Page copy<'), 'messages-only staff receive no Page Copy tab');
    [$status, $forbiddenCopy] = cph_req($base, $jars['messages'], 'GET', '/admin/content.php?tab=page-copy&page=about');
    cph_eq(403, $status, 'a guessed Page Copy URL is forbidden to messages-only staff');
    cph_ok(!str_contains($forbiddenCopy, 'Secret draft ' . $suffix), 'the forbidden response embeds no draft copy');
    [$status, $forbiddenJson] = cph_req($base, $jars['messages'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'okv_csrf' => $messagesCsrf], true);
    cph_eq(403, $status, 'messages-only staff cannot write or retrieve content through the content API');
    cph_ok(!str_contains(json_encode($forbiddenJson), 'Secret draft ' . $suffix), 'the forbidden JSON response embeds no draft copy');

    cph_login($base, $jars['outsider'], $users['outsider']['email'], $password);
    [$status] = cph_req($base, $jars['outsider'], 'GET', '/admin/content.php');
    cph_eq(403, $status, 'staff with neither viewing permission cannot open the shared route');
    [, $outsideDash] = cph_req($base, $jars['outsider'], 'GET', '/admin/');
    cph_ok(!str_contains($outsideDash, 'href="/admin/content.php"'), 'staff with neither permission receive no shared nav link');

    foreach (['owner' => 'Owner', 'manager' => 'Manager'] as $roleName => $roleLabel) {
        cph_login($base, $jars[$roleName], $users[$roleName]['email'], $password);
        [$status, $rolePage] = cph_req($base, $jars[$roleName], 'GET', '/admin/content.php?tab=page-copy&page=about');
        cph_eq(200, $status, "the seeded $roleLabel opens Page Copy");
        cph_ok(str_contains($rolePage, '>Save draft</button>'), "the seeded $roleLabel receives content.edit controls");
        cph_ok(str_contains($rolePage, 'href="/admin/content.php"') && str_contains($rolePage, 'href="/admin/content.php?tab=page-copy"'), "the seeded $roleLabel receives both permitted tabs");
        cph_ok(str_contains($rolePage, 'Secret draft ' . $suffix), "the seeded $roleLabel receives authorised draft copy");
    }

    cph_login($base, $jars['editor'], $users['editor']['email'], $password);
    [$status, $editorPage] = cph_req($base, $jars['editor'], 'GET', '/admin/content.php?tab=page-copy&page=about');
    cph_eq(200, $status, 'content editor opens the shareable selected-page URL');
    cph_ok(str_contains($editorPage, '>Save draft</button>'), 'content editor receives the save action');
    $csrf = cph_token($editorPage); $fingerprint = cph_fingerprint($editorPage);
    cph_ok($csrf !== '' && $fingerprint !== '', 'editor forms carry CSRF and optimistic-lock tokens');
    [$status] = cph_req($base, $jars['editor'], 'GET', '/api/v1/content.php?action=save_draft&slug=about', null, true);
    cph_eq(405, $status, 'content cannot change over GET');
    [$status] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'fingerprint' => $fingerprint], true);
    cph_eq(419, $status, 'content writes require CSRF');
    [$status, $invalid] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'title' => 'Unsafe', 'body' => '<script>alert(1)</script>', 'fingerprint' => $fingerprint, 'okv_csrf' => $csrf], true);
    cph_eq(422, $status, 'unsafe unrestricted HTML is refused');
    cph_ok(isset($invalid['errors']['body']), 'validation response identifies the unsafe body field');
    cph_ok(!str_contains(json_encode($invalid), '<script>alert(1)</script>'), 'validation JSON never reflects submitted script markup');
    [, $afterInvalidPreview] = cph_req($base, $jars['editor'], 'GET', '/admin/content-preview.php?page=about');
    cph_ok(!str_contains($afterInvalidPreview, '<script>alert(1)</script>'), 'refused script markup never reaches staff preview HTML');
    [$status, $saved] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'title' => 'Edited story ' . $suffix, 'body' => "Safe restricted Markdown.\n\n- One farm", 'meta_title' => 'Edited story', 'meta_description' => 'A safe search description.', 'fingerprint' => $fingerprint, 'okv_csrf' => $csrf], true);
    cph_eq(200, $status, 'a valid editor draft saves through HTTP');
    cph_eq('Edited story ' . $suffix, ContentPages::findPreview('about')['title'], 'saved editor copy reaches the draft row');
    [$status] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'title' => 'Stale overwrite', 'body' => 'No.', 'fingerprint' => $fingerprint, 'okv_csrf' => $csrf], true);
    cph_eq(409, $status, 'an outdated editor cannot silently overwrite newer work');

    [, $freshPage] = cph_req($base, $jars['editor'], 'GET', '/admin/content.php?tab=page-copy&page=about');
    $freshFingerprint = cph_fingerprint($freshPage);
    [$status] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'publish', 'slug' => 'about', 'fingerprint' => $freshFingerprint, 'okv_csrf' => $csrf], true);
    cph_eq(422, $status, 'publishing requires an explicit confirmation');
    [$status] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'publish', 'slug' => 'about', 'fingerprint' => $freshFingerprint, 'confirm' => '1', 'okv_csrf' => $csrf], true);
    cph_eq(200, $status, 'a confirmed valid saved draft publishes');
    cph_eq('Edited story ' . $suffix, ContentPages::findPublished('about')['title'], 'publication exposes only the saved snapshot');
    [$status] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'unpublish', 'slug' => 'about', 'fingerprint' => $freshFingerprint, 'confirm' => '1', 'okv_csrf' => $csrf], true);
    cph_eq(200, $status, 'a confirmed unpublish succeeds');
    cph_eq(null, ContentPages::findPublished('about'), 'unpublish immediately removes public retrieval');

    [, $prgPage] = cph_req($base, $jars['editor'], 'GET', '/admin/content.php?tab=page-copy&page=about');
    $prgFingerprint = cph_fingerprint($prgPage);
    [$status, , $location] = cph_req($base, $jars['editor'], 'POST', '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'title' => 'PRG story ' . $suffix, 'body' => 'Works without JavaScript.', 'meta_title' => '', 'meta_description' => '', 'fingerprint' => $prgFingerprint, 'okv_csrf' => $csrf], false);
    cph_eq(303, $status, 'a no-JavaScript save uses Post-Redirect-Get');
    cph_ok(str_contains($location, 'tab=page-copy') && str_contains($location, 'page=about') && str_contains($location, 'notice=updated'), 'PRG returns to the selected page with a useful notice');
} finally {
    foreach ($users as $user) {
        Database::run('DELETE FROM audit_logs WHERE entity_type = :entity AND actor_user_id = :actor', [':entity' => ContentPages::AUDIT_ENTITY, ':actor' => $user['id']]);
    }
    if ($before) {
        $columns = ['title','body','draft_title','draft_body','draft_meta_title','draft_meta_description','meta_title','meta_description','draft_content_data','content_data','draft_image_url','draft_image_alt','image_url','image_alt','is_published','published_at','published_by','updated_by','created_at','updated_at'];
        $sets = []; $params = [':id' => $before['id']];
        foreach ($columns as $column) { $sets[] = $column . ' = :' . $column; $params[':' . $column] = $before[$column]; }
        Database::run('UPDATE content_pages SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }
    if ($contactMessageId > 0) { Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $contactMessageId]); }
    foreach ($users as $user) { Database::run('DELETE FROM users WHERE id = :id', [':id' => $user['id']]); }
    foreach ($roles as $roleId) { Database::run('DELETE FROM roles WHERE id = :id', [':id' => $roleId]); }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
    foreach ($jars as $jar) { if (is_file($jar)) { unlink($jar); } }
    proc_terminate($server); proc_close($server);
}

fwrite(STDOUT, "\n$passed / $tests content admin HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
