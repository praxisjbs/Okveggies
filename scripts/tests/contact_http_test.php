<?php
/** Contact form checks through the real page, session and public controller. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "This test needs the PHP curl extension.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8209';
$tests = 0; $passed = 0;
function ch_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function ch_eq($expected, $actual, string $label): void { ch_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function ch_req(string $base, string $jar, string $method, string $path, ?array $fields = null): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true) ?? $body];
}
function ch_form(string $base, string $jar): array {
    $ch = curl_init($base . '/contact.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $csrf);
    preg_match('/name="submission_token" value="([^"]+)"/', $html, $submission);
    return [(string) ($csrf[1] ?? ''), (string) ($submission[1] ?? ''), $html];
}

$previousPort = getenv('SMTP_PORT');
putenv('SMTP_PORT=1');
$serverLog = sys_get_temp_dir() . '/okv-contact-http-server.log';
$server = proc_open(
    'exec php -d display_errors=0 -S 127.0.0.1:8209 -t ' . escapeshellarg($root),
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the contact test server.\n");
    exit(2);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', 8209, $errno, $error, 0.2);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$email = "contact-http-$suffix@example.test";
$jar = tempnam(sys_get_temp_dir(), 'okv-contact-');
$signedJar = tempnam(sys_get_temp_dir(), 'okv-contact-signed-');
$messageIds = [];
$userId = 0;

try {
    [$status] = ch_req($base, $jar, 'GET', '/api/v1/contact.php');
    ch_eq(405, $status, 'GET contact submission is refused');

    [$csrf, $token, $html] = ch_form($base, $jar);
    ch_ok($csrf !== '' && $token !== '', 'the no-JavaScript page provides both security tokens');
    ch_eq(1, substr_count($html, 'data-support-trigger'), 'the contact page carries one floating support trigger');

    [$status] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'submission_token' => $token, 'name' => 'HTTP Guest',
        'email' => $email, 'message' => 'Please check my delivery.',
    ]);
    ch_eq(419, $status, 'missing CSRF is refused');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$status, $invalid] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $csrf, 'submission_token' => $token,
        'name' => 'HTTP Guest', 'message' => 'No reply details.',
    ]);
    ch_eq(422, $status, 'missing contact details are refused');
    ch_eq('contact_required', (string) ($invalid['code'] ?? ''), 'validation returns a plain field code');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    usleep(2100000);
    [$status, $saved] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $csrf, 'submission_token' => $token,
        'source' => 'contact_page', 'website' => '', 'name' => 'HTTP Guest',
        'email' => $email, 'message' => '<b>Keep this as text</b>',
    ]);
    ch_eq(201, $status, 'a valid guest message is accepted');
    $messageIds[] = (int) ($saved['message_id'] ?? 0);
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE id = :id', [':id' => $messageIds[0]])['n'], 'one valid request creates exactly one row');
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event', [':type' => 'contact_message', ':id' => $messageIds[0], ':event' => 'admin_new_contact'])['n'], 'one valid request records exactly one staff alert');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$status] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $csrf, 'submission_token' => $token,
        'name' => 'HTTP Guest', 'email' => $email, 'message' => 'Repeated.',
    ]);
    ch_eq(422, $status, 'a repeated one-time token is refused');
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE email = :email', [':email' => $email])['n'], 'the repeated request creates no duplicate message');

    $signedEmail = "contact-signed-$suffix@example.test";
    $signedPhone = '+23480' . random_int(10000000, 99999999);
    $password = 'contact-http-88';
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status, NOW())',
        [':first' => 'Signed', ':last' => 'Customer', ':email' => $signedEmail, ':phone' => $signedPhone,
         ':hash' => password_hash($password, PASSWORD_BCRYPT), ':type' => 'household', ':status' => 'active']
    );
    $userId = (int) Database::getInstance()->getConnection()->lastInsertId();
    [, $accountHtml] = ch_req($base, $signedJar, 'GET', '/account.php');
    preg_match('/name="okv_csrf" value="([^"]+)"/', (string) $accountHtml, $loginToken);
    [$status] = ch_req($base, $signedJar, 'POST', '/api/v1/auth.php', [
        'action' => 'login', 'context' => 'storefront', 'identifier' => $signedEmail,
        'password' => $password, 'okv_csrf' => (string) ($loginToken[1] ?? ''),
    ]);
    ch_eq(200, $status, 'a fixture customer signs in');
    [$signedCsrf, $signedToken, $signedHtml] = ch_form($base, $signedJar);
    ch_ok(str_contains($signedHtml, 'value="Signed Customer"'), 'the signed-in name is safely prefilled');
    ch_ok(str_contains($signedHtml, 'value="' . $signedEmail . '"'), 'the signed-in email is safely prefilled');
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    usleep(2100000);
    [$status, $signedSaved] = ch_req($base, $signedJar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $signedCsrf, 'submission_token' => $signedToken,
        'source' => 'support_widget', 'name' => 'Signed Customer', 'email' => $signedEmail,
        'phone' => $signedPhone, 'message' => 'This came from my signed-in account.',
    ]);
    ch_eq(201, $status, 'a signed-in customer can submit the same form');
    $messageIds[] = (int) ($signedSaved['message_id'] ?? 0);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        [$loopCsrf, $loopToken] = ch_form($base, $jar);
        usleep(2100000);
        [$status] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
            'action' => 'submit', 'okv_csrf' => $loopCsrf, 'submission_token' => $loopToken,
            'name' => '', 'email' => $email, 'message' => 'Rate check.',
        ]);
    }
    ch_eq(429, $status, 'the fourth attempt in 15 minutes is rate limited');
} finally {
    foreach ($messageIds as $messageId) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $messageId]);
    }
    if ($userId > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $userId]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    if (is_file($jar)) { unlink($jar); }
    if (is_file($signedJar)) { unlink($signedJar); }
    proc_terminate($server);
    proc_close($server);
    if ($previousPort === false) { putenv('SMTP_PORT'); } else { putenv('SMTP_PORT=' . $previousPort); }
}

fwrite(STDOUT, "\n$passed / $tests contact HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
