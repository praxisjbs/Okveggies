<?php
/**
 * scripts/tests/contact_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. M9 contact persistence, the rate limit, the honeypot, and the two
 * notices a committed message raises. Covers M9 test items 34, 35, 36 and 39.
 *
 * SCRATCH DATABASE ONLY. This writes rows and cleans up after itself.
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function cdb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cdb_eq($expected, $actual, string $label): void { cdb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$ip       = '192.0.2.' . random_int(1, 200);
$_SERVER['REMOTE_ADDR'] = $ip;
$email    = "contact-$suffix@example.test";
$messageIds = [];
$buckets  = ['contact:ip:' . hash('sha256', $ip)];

// A fresh connection per scenario, so one scenario's stored messages never
// spend the allowance another scenario is testing.
$freshIp = static function () use (&$buckets): string {
    static $n = 0;
    $next = '203.0.113.' . (++$n);
    $_SERVER['REMOTE_ADDR'] = $next;
    $buckets[] = 'contact:ip:' . hash('sha256', $next);
    return $next;
};

$countRows = static fn(string $like): int => (int) Database::one(
    'SELECT COUNT(*) AS n FROM contact_messages WHERE name LIKE :like',
    [':like' => $like]
)['n'];

try {
    // 34. A valid submission writes exactly one row, with the right source.
    $result = ContactMessages::submit([
        'source'  => 'support_widget',
        'website' => '',
        'name'    => 'Contact Test ' . $suffix,
        'email'   => $email,
        'subject' => 'Saturday delivery',
        'message' => '<script>alert(1)</script> Please check my delivery day.',
    ]);
    cdb_ok($result['ok'], 'a valid guest submission is stored');
    $messageId = (int) ($result['message_id'] ?? 0);
    cdb_ok($messageId > 0, 'the submission returns its message id');
    $messageIds[] = $messageId;
    $buckets[] = 'contact:id:' . hash('sha256', $email);
    cdb_eq(1, $countRows('Contact Test ' . $suffix . '%'), 'a valid submission writes exactly one row');

    $row = Database::one('SELECT * FROM contact_messages WHERE id = :id', [':id' => $messageId]);
    cdb_eq('new', (string) $row['status'], 'a new message starts new');
    cdb_eq('support_widget', (string) $row['source'], 'the source is recorded, so the widget and the page are told apart');
    cdb_eq($ip, (string) $row['ip_address'], 'ip_address is recorded on the one public write');
    cdb_ok(str_contains((string) $row['message'], '<script>'), 'message text is stored as typed, for escaped rendering rather than as HTML');
    cdb_eq(null, $row['handled_by'], 'nobody has handled a message that just arrived');
    cdb_eq(null, $row['handled_at'], 'and there is no handled time on it');

    // A page submission is told apart from a widget one.
    $fromPage = ContactMessages::submit([
        'source'  => 'contact_page',
        'name'    => 'Contact Page ' . $suffix,
        'phone'   => '0801 234 5678',
        'message' => 'Please call me about tomatoes.',
    ]);
    cdb_ok($fromPage['ok'], 'a submission from the contact page is stored');
    $messageIds[] = (int) $fromPage['message_id'];
    $buckets[] = 'contact:id:' . hash('sha256', '+2348012345678');
    cdb_eq('contact_page', (string) Database::one('SELECT source FROM contact_messages WHERE id = :id', [':id' => (int) $fromPage['message_id']])['source'], 'the contact page records its own source');

    // An unknown source is never trusted into the column.
    $spoofed = ContactMessages::submit([
        'source'  => 'admin_panel',
        'name'    => 'Spoofed Source ' . $suffix,
        'email'   => "spoof-$suffix@example.test",
        'message' => 'Where does this say it came from?',
    ]);
    cdb_ok($spoofed['ok'], 'an unrecognised source still lands the message');
    $messageIds[] = (int) $spoofed['message_id'];
    $buckets[] = 'contact:id:' . hash('sha256', "spoof-$suffix@example.test");
    cdb_eq('storefront_contact_form', (string) Database::one('SELECT source FROM contact_messages WHERE id = :id', [':id' => (int) $spoofed['message_id']])['source'], 'an unrecognised source falls back rather than being stored');

    // 35. A submission with no way to reply is refused.
    $freshIp();
    $before = $countRows('No Reply ' . $suffix . '%');
    $noReply = ContactMessages::submit([
        'name'    => 'No Reply ' . $suffix,
        'message' => 'You will never be able to answer this.',
    ]);
    cdb_ok(empty($noReply['ok']), 'a message with no email and no phone is refused');
    cdb_eq('contact_required', (string) $noReply['code'], 'and it says which rule it broke');
    cdb_eq($before, $countRows('No Reply ' . $suffix . '%'), 'a refused message writes nothing');

    // 36 (honeypot). It refuses, and writes nothing.
    $freshIp();
    $trapped = ContactMessages::submit([
        'website' => 'http://spam.example',
        'name'    => 'Honeypot ' . $suffix,
        'email'   => "trap-$suffix@example.test",
        'message' => 'Filled every box on the page.',
    ]);
    cdb_ok(empty($trapped['ok']), 'a filled honeypot is refused');
    cdb_eq('spam_rejected', (string) $trapped['code'], 'and it is refused as spam');
    cdb_eq(0, $countRows('Honeypot ' . $suffix . '%'), 'a trapped submission writes nothing');

    // 36 (rate limit). Three from one connection, then the fourth is refused.
    $freshIp();
    $accepted = 0;
    for ($i = 1; $i <= 4; $i++) {
        $burst = ContactMessages::submit([
            'name'    => 'Burst ' . $i . ' ' . $suffix,
            'email'   => "burst-$i-$suffix@example.test",
            'message' => 'Message number ' . $i . '.',
        ]);
        $buckets[] = 'contact:id:' . hash('sha256', "burst-$i-$suffix@example.test");
        if (!empty($burst['ok'])) { $accepted++; $messageIds[] = (int) $burst['message_id']; }
        if ($i === 4) {
            cdb_ok(empty($burst['ok']), 'the fourth message from one connection is refused');
            cdb_eq('rate_limited', (string) $burst['code'], 'and it is refused as rate limited');
            cdb_ok(str_contains((string) $burst['message'], '15 minutes'), 'the rate-limit message says how long to wait');
        }
    }
    cdb_eq(3, $accepted, 'three messages get through before the limit bites');
    cdb_eq(3, $countRows('Burst % ' . $suffix), 'the refused fourth message writes nothing');

    // A refused attempt must not spend the allowance: three bad tries, then a
    // good one from the same connection, still lands.
    $freshIp();
    for ($i = 0; $i < 3; $i++) {
        ContactMessages::submit(['name' => 'Typo ' . $suffix, 'email' => 'not-an-address', 'message' => 'Please help.']);
    }
    $afterTypos = ContactMessages::submit([
        'name'    => 'Recovered ' . $suffix,
        'email'   => "recovered-$suffix@example.test",
        'message' => 'I got my address right this time.',
    ]);
    $buckets[] = 'contact:id:' . hash('sha256', "recovered-$suffix@example.test");
    cdb_ok(!empty($afterTypos['ok']), 'three mistyped attempts do not lock a person out of the only support channel');
    if (!empty($afterTypos['ok'])) { $messageIds[] = (int) $afterTypos['message_id']; }

    $_SERVER['REMOTE_ADDR'] = $ip;

    // The audit record lands in the same transaction as the row.
    $audit = Database::one(
        'SELECT id FROM audit_logs WHERE action = :action AND entity_type = :type AND entity_id = :id',
        [':action' => 'contact_messages.create', ':type' => 'contact_message', ':id' => $messageId]
    );
    cdb_ok((bool) $audit, 'the insert and its audit record land together');

    // 39. The staff alert fires, the sender is acknowledged, and a mail server
    // that refuses the connection still leaves the message on record.
    $realPort = $_ENV['SMTP_PORT'] ?? null;
    $_ENV['SMTP_PORT'] = 1;
    Notifications::announceContactMessage($messageId);
    if ($realPort === null) { unset($_ENV['SMTP_PORT']); } else { $_ENV['SMTP_PORT'] = $realPort; }

    $notices = Database::all(
        'SELECT event_type FROM notifications WHERE related_type = :type AND related_id = :id ORDER BY event_type',
        [':type' => 'contact_message', ':id' => $messageId]
    );
    $events = array_column($notices, 'event_type');
    cdb_ok(in_array('admin_new_contact', $events, true), 'the staff alert fires on a new message');
    cdb_ok(in_array('contact_acknowledgement', $events, true), 'the sender who left an email is acknowledged');
    cdb_ok((bool) Database::one('SELECT id FROM contact_messages WHERE id = :id', [':id' => $messageId]), 'a failed alert still leaves the message on record');
    cdb_ok(
        (int) Database::one('SELECT COUNT(*) AS n FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id WHERE n.related_type = :type AND n.related_id = :id', [':type' => 'contact_message', ':id' => $messageId])['n'] > 0,
        'the failed send is written down rather than thrown away'
    );

    // Only a phone number, so there is nowhere to send an acknowledgement.
    $phoneOnlyId = (int) $fromPage['message_id'];
    Notifications::announceContactMessage($phoneOnlyId);
    $phoneEvents = array_column(Database::all(
        'SELECT event_type FROM notifications WHERE related_type = :type AND related_id = :id',
        [':type' => 'contact_message', ':id' => $phoneOnlyId]
    ), 'event_type');
    cdb_ok(in_array('admin_new_contact', $phoneEvents, true), 'a phone-only message still alerts staff');
    cdb_ok(!in_array('contact_acknowledgement', $phoneEvents, true), 'and no acknowledgement is sent when there is no email address');
} finally {
    foreach ($messageIds as $id) {
        Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :type AND related_id = :id)', [':type' => 'contact_message', ':id' => $id]);
        Database::run('DELETE FROM notifications WHERE related_type = :type AND related_id = :id', [':type' => 'contact_message', ':id' => $id]);
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'contact_message', ':id' => $id]);
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $id]);
    }
    foreach (array_unique($buckets) as $bucket) {
        Database::run('DELETE FROM rate_limits WHERE bucket = :bucket', [':bucket' => $bucket]);
    }
}

fwrite(STDOUT, "\n$passed / $tests contact database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
