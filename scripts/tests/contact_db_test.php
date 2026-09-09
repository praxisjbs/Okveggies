<?php
/** M9 contact persistence, deduplication, audit and notification checks. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function cdb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cdb_eq($expected, $actual, string $label): void { cdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$ip = '192.0.2.' . random_int(1, 200);
$_SERVER['REMOTE_ADDR'] = $ip;
$messageId = 0;
$token = ContactMessages::newSubmissionToken();
$tokenHash = hash('sha256', $token);
$_SESSION['okv_contact_submission_tokens'][$tokenHash] = time() - 3;

try {
    $result = ContactMessages::submit([
        'submission_token' => $token,
        'source' => 'support_widget',
        'website' => '',
        'name' => 'Contact Test ' . $suffix,
        'email' => "contact-$suffix@example.test",
        'subject' => 'Saturday delivery',
        'message' => '<script>alert(1)</script> Please check my delivery day.',
    ]);
    cdb_ok($result['ok'], 'a valid guest submission is stored');
    $messageId = (int) ($result['message_id'] ?? 0);
    cdb_ok($messageId > 0, 'the submission returns its message id');

    $row = Database::one('SELECT * FROM contact_messages WHERE id = :id', [':id' => $messageId]);
    cdb_eq('new', (string) $row['status'], 'a new message starts new');
    cdb_eq('support_widget', (string) $row['source'], 'the approved source is stored');
    cdb_eq($ip, (string) $row['ip_address'], 'a valid IP fits the existing privacy column');
    cdb_eq($tokenHash, (string) $row['submission_token_hash'], 'the one-time token is stored only as a hash');
    cdb_ok(str_contains((string) $row['message'], '<script>'), 'message text is preserved for escaped rendering rather than treated as HTML');

    $audit = Database::one(
        'SELECT id FROM audit_logs WHERE action = :action AND entity_type = :type AND entity_id = :id',
        [':action' => 'contact_messages.create', ':type' => 'contact_message', ':id' => $messageId]
    );
    cdb_ok((bool) $audit, 'the insert and its audit record land together');

    $again = ContactMessages::submit([
        'submission_token' => $token,
        'name' => 'Contact Test ' . $suffix,
        'email' => "contact-$suffix@example.test",
        'message' => 'Repeated request.',
    ]);
    cdb_ok(!$again['ok'], 'a used submission token is rejected');
    cdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM contact_messages WHERE submission_token_hash = :token', [':token' => $tokenHash])['n'], 'a repeated request creates no second row');

    $realPort = $_ENV['SMTP_PORT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    Notifications::announceContactMessage($messageId);
    if ($realPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $realPort; }
    cdb_eq(1, (int) Database::one('SELECT COUNT(*) AS n FROM notifications WHERE related_type = :type AND related_id = :id AND event_type = :event', [':type' => 'contact_message', ':id' => $messageId, ':event' => 'admin_new_contact'])['n'], 'one staff notification is recorded');
    cdb_ok((bool) Database::one('SELECT id FROM contact_messages WHERE id = :id', [':id' => $messageId]), 'mail failure does not remove the saved message');
} finally {
    if ($messageId > 0) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'contact_message', ':id' => $messageId]);
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $messageId]);
    }
    Database::run('DELETE FROM rate_limits WHERE bucket IN (:ip, :identity)', [
        ':ip' => 'contact:ip:' . hash('sha256', $ip),
        ':identity' => 'contact:id:' . hash('sha256', "contact-$suffix@example.test"),
    ]);
}

fwrite(STDOUT, "\n$passed / $tests contact database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
