<?php
/**
 * scripts/tests/contact_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The contact form over the real route: the page, the session, CSRF,
 * the honeypot, the rate limit, prefill for a signed-in customer, and the plain
 * form post that has to work with JavaScript switched off. M9 items 34 to 36
 * and 39, proved through HTTP rather than by calling the class.
 *
 * It starts its own PHP server on 8209 and cleans up every row it writes.
 * SCRATCH DATABASE ONLY.
 * -----------------------------------------------------------------------------
 */
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
/** A plain browser form post: no fetch headers, redirects not followed. */
function ch_plain_post(string $base, string $jar, string $path, array $fields): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', $raw, $location);
    return [$status, (string) ($location[1] ?? '')];
}

/** A plain browser GET, redirects not followed. */
function ch_plain_get(string $base, string $jar, string $path): int {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function ch_form(string $base, string $jar): array {
    $ch = curl_init($base . '/contact.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $csrf);
    return [(string) ($csrf[1] ?? ''), $html];
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
    ch_eq(405, $status, 'a GET from the widget script is refused');
    ch_eq(303, ch_plain_get($base, $jar, '/api/v1/contact.php'), 'a GET from a browser is sent to the form rather than answered');
    ch_eq(0, (int) Database::one("SELECT COUNT(*) AS n FROM contact_messages WHERE name = 'HTTP Guest'")['n'], 'no GET has ever put a row in the table');

    [$csrf, $html] = ch_form($base, $jar);
    ch_ok($csrf !== '', 'the no-JavaScript page carries a CSRF token');
    ch_eq(1, substr_count($html, 'data-support-trigger'), 'the contact page carries one floating support trigger');
    ch_ok(str_contains($html, 'novalidate'), 'the served form is novalidate, so the browser raises no bubble of its own');
    ch_ok(str_contains($html, 'name="website"'), 'the served form carries the honeypot');
    ch_ok(str_contains($html, '>Contact</a>') || str_contains($html, 'href="/contact.php"'), 'the footer reaches Contact');

    // 36. CSRF refuses, and writes nothing.
    [$status] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'name' => 'HTTP Guest',
        'email' => $email, 'message' => 'Please check my delivery.',
    ]);
    ch_eq(419, $status, 'missing CSRF is refused');
    ch_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE email = :email', [':email' => $email])['n'], 'a request without CSRF writes nothing');

    // 36. The honeypot refuses, and writes nothing.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$spamCsrf] = ch_form($base, $jar);
    [$status, $spam] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $spamCsrf,
        'website' => 'https://spam.example', 'name' => 'Spam Request',
        'email' => $email, 'message' => 'Honeypot submission.',
    ]);
    ch_eq(422, $status, 'a filled honeypot is refused');
    ch_eq('spam_rejected', (string) ($spam['code'] ?? ''), 'the honeypot returns a plain rejection code');
    ch_eq(0, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE email = :email', [':email' => $email])['n'], 'a trapped request writes nothing');

    // 35. No way to reply, refused in our own words.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$status, $invalid] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $csrf,
        'name' => 'HTTP Guest', 'message' => 'No reply details.',
    ]);
    ch_eq(422, $status, 'missing contact details are refused');
    ch_eq('contact_required', (string) ($invalid['code'] ?? ''), 'validation returns a plain field code');
    ch_ok(str_contains((string) ($invalid['message'] ?? ''), 'so we can reply'), 'and it is refused in our own words, not the browser\'s');

    // 34. A valid message, one row, both notices.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$status, $saved] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $csrf,
        'source' => 'contact_page', 'website' => '', 'name' => 'HTTP Guest',
        'email' => $email, 'message' => '<b>Keep this as text</b>',
    ]);
    ch_eq(201, $status, 'a valid guest message is accepted');
    $messageIds[] = (int) ($saved['message_id'] ?? 0);
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE id = :id', [':id' => $messageIds[0]])['n'], 'one valid request creates exactly one row');
    ch_eq('contact_page', (string) Database::one('SELECT source FROM contact_messages WHERE id = :id', [':id' => $messageIds[0]])['source'], 'the row records which surface it came from');
    ch_ok((string) Database::one('SELECT ip_address FROM contact_messages WHERE id = :id', [':id' => $messageIds[0]])['ip_address'] !== '', 'the row records the sending address');
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event', [':type' => 'contact_message', ':id' => $messageIds[0], ':event' => 'admin_new_contact'])['n'], 'one valid request records exactly one staff alert');
    ch_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event', [':type' => 'contact_message', ':id' => $messageIds[0], ':event' => 'contact_acknowledgement'])['n'], 'and the sender is acknowledged');
    ch_ok((bool) Database::one('SELECT id FROM contact_messages WHERE id = :id', [':id' => $messageIds[0]]), 'the mail server being unreachable did not take the message with it');

    // 16. The same thing again with no JavaScript at all: a plain form post.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    $plainEmail = "contact-plain-$suffix@example.test";
    [$plainCsrf] = ch_form($base, $jar);
    [$status, $location] = ch_plain_post($base, $jar, '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $plainCsrf, 'source' => 'contact_page',
        'website' => '', 'name' => 'Plain Guest', 'email' => $plainEmail,
        'message' => 'Sent with JavaScript switched off.',
    ]);
    ch_eq(303, $status, 'a plain form post is answered with a redirect, not JSON');
    ch_ok(str_contains($location, '/contact.php?sent=1'), 'and it lands on the thank-you');
    $plainRow = Database::one('SELECT id, source FROM contact_messages WHERE email = :email', [':email' => $plainEmail]);
    ch_ok((bool) $plainRow, 'a plain POST lands the same row');
    if ($plainRow) { $messageIds[] = (int) $plainRow['id']; ch_eq('contact_page', (string) $plainRow['source'], 'and it carries the same source'); }

    // A refused plain post says so on the page rather than dead-ending.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    [$badCsrf] = ch_form($base, $jar);
    [$status, $location] = ch_plain_post($base, $jar, '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $badCsrf, 'name' => 'Plain Refused',
        'message' => 'No way to answer this one.',
    ]);
    ch_eq(303, $status, 'a refused plain post also redirects');
    ch_ok(str_contains($location, 'error=contact_required'), 'and it carries the reason back to the form');
    ch_eq(0, (int) Database::one("SELECT COUNT(*) AS n FROM contact_messages WHERE name = 'Plain Refused'")['n'], 'the refused plain post wrote nothing');

    // 10. A signed-in customer gets their details filled in, and can correct them.
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
    [$signedCsrf, $signedHtml] = ch_form($base, $signedJar);
    ch_ok(str_contains($signedHtml, 'value="Signed Customer"'), 'the signed-in name is safely prefilled');
    ch_ok(str_contains($signedHtml, 'value="' . $signedEmail . '"'), 'the signed-in email is safely prefilled');
    ch_ok(!str_contains($signedHtml, 'readonly') && !str_contains($signedHtml, 'disabled'), 'and every prefilled field can still be corrected');

    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    $correctedEmail = "contact-corrected-$suffix@example.test";
    [$status, $signedSaved] = ch_req($base, $signedJar, 'POST', '/api/v1/contact.php', [
        'action' => 'submit', 'okv_csrf' => $signedCsrf,
        'source' => 'support_widget', 'name' => 'Signed Customer', 'email' => $correctedEmail,
        'phone' => $signedPhone, 'message' => 'This came from my signed-in account.',
    ]);
    ch_eq(201, $status, 'a signed-in customer can submit the same form');
    $messageIds[] = (int) ($signedSaved['message_id'] ?? 0);
    ch_eq($correctedEmail, (string) Database::one('SELECT email FROM contact_messages WHERE id = :id', [':id' => (int) $signedSaved['message_id']])['email'], 'a corrected address is the one we store');
    ch_eq('support_widget', (string) Database::one('SELECT source FROM contact_messages WHERE id = :id', [':id' => (int) $signedSaved['message_id']])['source'], 'and the widget is told apart from the page');

    // 36. The rate limit refuses, and writes nothing.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    $burstEmail = "contact-burst-$suffix@example.test";
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        [$loopCsrf] = ch_form($base, $jar);
        [$status, $limited] = ch_req($base, $jar, 'POST', '/api/v1/contact.php', [
            'action' => 'submit', 'okv_csrf' => $loopCsrf, 'website' => '',
            'name' => 'Burst Guest', 'email' => $burstEmail, 'message' => 'Rate check ' . $attempt . '.',
        ]);
        if ($status === 201) { $messageIds[] = (int) ($limited['message_id'] ?? 0); }
    }
    ch_eq(429, $status, 'the fourth message in 15 minutes is rate limited');
    ch_eq(3, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE email = :email', [':email' => $burstEmail])['n'], 'and the refused one wrote nothing');
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
    Database::run("DELETE FROM contact_messages WHERE name IN ('Plain Refused', 'Burst Guest', 'Plain Guest', 'HTTP Guest', 'Spam Request')");
    if (is_file($jar)) { unlink($jar); }
    if (is_file($signedJar)) { unlink($signedJar); }
    proc_terminate($server);
    proc_close($server);
    if ($previousPort === false) { putenv('SMTP_PORT'); } else { putenv('SMTP_PORT=' . $previousPort); }
}

fwrite(STDOUT, "\n$passed / $tests contact HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
