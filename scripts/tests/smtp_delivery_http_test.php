<?php
/**
 * scripts/tests/smtp_delivery_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. What the application actually sends, captured at the socket.
 *
 * Every other suite proves that a notification row was written. None of them
 * proved that a person would receive anything, because there was no mail server
 * to send to. `scripts/tests/fake/smtp_sink.php` is that server: it answers the
 * SMTP dialogue and writes each message to disk, so this suite can read the
 * subject, the recipient and the body of the mail the application really sent.
 *
 * Three things are proved here:
 *   1. a message that should go out is delivered, and the delivery is recorded
 *      as sent in notification_deliveries;
 *   2. the acknowledgement to a contact form sender repeats nothing that person
 *      typed, because nobody has proved they own that address;
 *   3. when the mail server is unreachable the submission still succeeds and the
 *      failure is recorded in notification_deliveries rather than swallowed.
 *
 * The suite never touches the real .env: it writes variants beside it and points
 * a separate site process at each one with OKV_ENV_PATH, so the mail settings of
 * the running site are untouched.
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
$sinkPort = 2535;
$siteA = 'http://127.0.0.1:8233';
$siteB = 'http://127.0.0.1:8234';

$tests = 0;
$passed = 0;

function sdt_ok(bool $condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) {
        $passed++;
    } else {
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function sdt_eq($expected, $actual, string $label): void
{
    sdt_ok(
        $expected === $actual,
        $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')
    );
}

function sdt_req(string $base, string $jar, string $method, string $path, ?array $fields = null, bool $json = false): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: fetch', 'Accept: application/json']);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields ?? []));
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function sdt_token(string $html): string
{
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $html, $m) ? (string) $m[1] : '';
}

/** A copy of .env with the mail settings rewritten, without touching .env. */
function sdt_env_variant(string $source, string $target, array $overrides): void
{
    $lines = is_file($source) ? (array) file($source, FILE_IGNORE_NEW_LINES) : [];
    $applied = [];
    foreach ($lines as $index => $line) {
        foreach ($overrides as $key => $value) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', (string) $line) === 1) {
                $lines[$index] = $key . '=' . $value;
                $applied[$key] = true;
            }
        }
    }
    foreach ($overrides as $key => $value) {
        if (!isset($applied[$key])) {
            $lines[] = $key . '=' . $value;
        }
    }
    file_put_contents($target, implode("\n", $lines) . "\n");
}

/** Every message the sink has written, newest last. */
function sdt_captured(string $dir): array
{
    $files = glob($dir . '/message-*.eml') ?: [];
    sort($files);
    $messages = [];
    foreach ($files as $file) {
        $raw = (string) file_get_contents($file);
        $recipient = '';
        if (preg_match('/^X-OKV-SINK-RCPT:\s*(.*)$/m', $raw, $m) === 1) {
            $recipient = trim($m[1]);
        }
        $messages[] = ['file' => basename($file), 'raw' => $raw, 'recipient' => $recipient];
    }
    return $messages;
}

/** Wait for a captured message addressed to $email, or give up. */
function sdt_wait_for(string $dir, string $email, int $seconds = 15): ?array
{
    $deadline = time() + $seconds;
    do {
        foreach (sdt_captured($dir) as $message) {
            if (stripos($message['recipient'], $email) !== false) {
                return $message;
            }
        }
        usleep(300000);
    } while (time() < $deadline);
    return null;
}

function sdt_delivery(string $address): ?array
{
    return Database::one(
        'SELECT status, last_error, sent_at FROM notification_deliveries WHERE recipient_address = :address ORDER BY id DESC LIMIT 1',
        [':address' => $address]
    );
}

function sdt_wait_for_delivery(string $address, array $wanted, int $seconds = 15): ?array
{
    $deadline = time() + $seconds;
    do {
        $row = sdt_delivery($address);
        if ($row !== null && in_array((string) $row['status'], $wanted, true)) {
            return $row;
        }
        usleep(300000);
    } while (time() < $deadline);
    return null;
}

$suffix  = bin2hex(random_bytes(5));
$workDir = sys_get_temp_dir() . '/okv-smtp-suite-' . $suffix;
$sinkDir = $workDir . '/sink';
mkdir($workDir, 0777, true);
mkdir($sinkDir, 0777, true);

$envGood = $workDir . '/good.env';
$envDead = $workDir . '/dead.env';

$senderA = "smtp-a-$suffix@example.test";
$senderB = "smtp-b-$suffix@example.test";
$secret  = 'ZZSINKMARKER' . $suffix;

sdt_env_variant($root . '/.env', $envGood, [
    'SMTP_HOST'       => '127.0.0.1',
    'SMTP_PORT'       => (string) $sinkPort,
    'SMTP_ENCRYPTION' => 'none',
    'SMTP_USER'       => 'sink@example.test',
    'SMTP_PASS'       => 'sink',
]);
// Port 9 is the discard port: nothing listens there, so the send has to fail.
sdt_env_variant($root . '/.env', $envDead, [
    'SMTP_HOST'       => '127.0.0.1',
    'SMTP_PORT'       => '9',
    'SMTP_ENCRYPTION' => 'none',
    'SMTP_USER'       => 'sink@example.test',
    'SMTP_PASS'       => 'sink',
]);

$procs = [];

$start = static function (string $command, int $port, string $log) use (&$procs, $root): void {
    $procs[$port] = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        $root
    );
};

$waitFor = static function (int $port): bool {
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket) {
            fclose($socket);
            return true;
        }
        usleep(100000);
    }
    return false;
};

$jars = [tempnam(sys_get_temp_dir(), 'okv-smtp-a-'), tempnam(sys_get_temp_dir(), 'okv-smtp-b-')];

try {
    // Nothing else may hold the contact rate-limit budget.
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");

    $start('SMTP_SINK_PORT=' . $sinkPort . ' SMTP_SINK_DIR=' . escapeshellarg($sinkDir)
        . ' php ' . escapeshellarg($root . '/scripts/tests/fake/smtp_sink.php'), $sinkPort, $workDir . '/sink.log');
    $start('OKV_ENV_PATH=' . escapeshellarg($envGood)
        . ' php -d display_errors=0 -S 127.0.0.1:8233 -t ' . escapeshellarg($root), 8233, $workDir . '/site-good.log');
    $start('OKV_ENV_PATH=' . escapeshellarg($envDead)
        . ' php -d display_errors=0 -S 127.0.0.1:8234 -t ' . escapeshellarg($root), 8234, $workDir . '/site-dead.log');

    sdt_ok($waitFor($sinkPort), 'the mail sink is listening');
    sdt_ok($waitFor(8233), 'the site with the mail sink configured is answering');
    sdt_ok($waitFor(8234), 'the site with an unreachable mail server is answering');

    // ---- 1. A message that should go out, goes out --------------------------
    [, $contactPage] = sdt_req($siteA, $jars[0], 'GET', '/contact.php');
    $csrf = sdt_token($contactPage);
    sdt_ok($csrf !== '', 'the contact form carries a CSRF token');

    [$status, $body] = sdt_req($siteA, $jars[0], 'POST', '/api/v1/contact.php', [
        'name'     => 'Sink Probe',
        'email'    => $senderA,
        'phone'    => '08030000001',
        'subject'  => 'A captured message',
        'message'  => 'Please call me back about a delivery. ' . $secret,
        'source'   => 'contact_page',
        'okv_csrf' => $csrf,
    ], true);
    sdt_eq(201, $status, 'a valid contact message is accepted');
    $decoded = json_decode($body, true);
    $messageA = (int) ($decoded['message_id'] ?? 0);
    sdt_ok($messageA > 0, 'the submission returns the stored message id');

    $captured = sdt_wait_for($sinkDir, $senderA);
    sdt_ok($captured !== null, 'the acknowledgement reached the mail sink');
    if ($captured !== null) {
        sdt_ok(preg_match('/^Subject:\s*(.*)$/mi', $captured['raw'], $subject) === 1
            && stripos($subject[1], 'We have your message') !== false,
            'the captured subject is the acknowledgement subject');
        sdt_ok(stripos($captured['raw'], 'There is nothing for you to do') !== false,
            'the captured body is the acknowledgement body');
        sdt_ok(stripos($captured['raw'], $secret) === false,
            'the acknowledgement repeats nothing the sender typed');
        sdt_ok(stripos($captured['recipient'], $senderA) !== false,
            'the captured recipient is the person who wrote in');
    }

    $sent = sdt_wait_for_delivery($senderA, ['sent']);
    sdt_ok($sent !== null, 'the delivery is recorded as sent in notification_deliveries');

    // ---- 2. A dead mail server cannot lose the customer's message ------------
    [, $contactPageB] = sdt_req($siteB, $jars[1], 'GET', '/contact.php');
    $csrfB = sdt_token($contactPageB);
    sdt_ok($csrfB !== '', 'the second site carries a CSRF token');

    [$status, $bodyB] = sdt_req($siteB, $jars[1], 'POST', '/api/v1/contact.php', [
        'name'     => 'Sink Probe Two',
        'email'    => $senderB,
        'phone'    => '08030000002',
        'subject'  => 'A message when the mail server is down',
        'message'  => 'The row must survive a mail failure.',
        'source'   => 'contact_page',
        'okv_csrf' => $csrfB,
    ], true);
    sdt_eq(201, $status, 'the message is still accepted while the mail server is down');
    $decodedB = json_decode($bodyB, true);
    $messageB = (int) ($decodedB['message_id'] ?? 0);
    sdt_ok($messageB > 0, 'the second submission returns the stored message id');
    sdt_ok((int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE id = :id', [':id' => $messageB])['n'] === 1,
        'the message row was committed despite the mail failure');

    $failed = sdt_wait_for_delivery($senderB, ['failed', 'failed_manual']);
    sdt_ok($failed !== null, 'the failure is recorded in notification_deliveries');
    sdt_ok($failed !== null && trim((string) ($failed['last_error'] ?? '')) !== '',
        'the failed delivery keeps the reason');
} finally {
    foreach ($procs as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    foreach ($jars as $jar) {
        if (is_file($jar)) {
            unlink($jar);
        }
    }
    foreach ([$senderA, $senderB] as $address) {
        Database::run('DELETE FROM notification_deliveries WHERE recipient_address = :address', [':address' => $address]);
    }
    foreach ([$messageA ?? 0, $messageB ?? 0] as $messageId) {
        if ($messageId > 0) {
            Database::run('DELETE FROM notifications WHERE entity_type = :entity AND entity_id = :id', [':entity' => 'contact_message', ':id' => $messageId]);
            Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $messageId]);
        }
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    foreach (glob($workDir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach (glob($sinkDir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    @rmdir($sinkDir);
    @rmdir($workDir);
}

fwrite(STDOUT, "\n$passed / $tests SMTP delivery assertions passed.\n");
exit($passed === $tests ? 0 : 1);
