<?php
/** M9 staff list, filter, pagination, note and handling domain checks. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$tests = 0; $passed = 0;
function cadb_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function cadb_eq($expected, $actual, string $label): void { cadb_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }

$suffix = substr(bin2hex(random_bytes(5)), 0, 10);
$ids = [];
$staffId = 0;
try {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:first, :last, :email, :phone, :hash, :type, :status)',
        [':first' => 'Message', ':last' => 'Handler', ':email' => "handler-$suffix@example.test",
         ':phone' => '+23480' . random_int(10000000, 99999999), ':hash' => password_hash('test-only', PASSWORD_BCRYPT),
         ':type' => 'staff', ':status' => 'active']
    );
    $staffId = (int) Database::getInstance()->getConnection()->lastInsertId();

    for ($i = 1; $i <= 28; $i++) {
        Database::run(
            'INSERT INTO contact_messages
                (name, email, phone, subject, message, source, status, created_at, submission_token_hash)
             VALUES (:name, :email, :phone, :subject, :message, :source, :status, :created, :token)',
            [
                ':name' => 'Contact ' . $suffix . ' ' . $i,
                ':email' => $i === 1 ? null : "sender-$i-$suffix@example.test",
                ':phone' => $i === 1 ? null : '+23481' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                ':subject' => $i === 2 ? 'Plantain delivery' : 'Message ' . $i,
                ':message' => $i === 3 ? 'The phrase needle-in-message is here.' : '<script>message ' . $i . '</script>',
                ':source' => 'contact_page',
                ':status' => $i % 2 === 0 ? 'handled' : 'new',
                ':created' => date('Y-m-d H:i:s', strtotime('-' . $i . ' hours')),
                ':token' => hash('sha256', $suffix . ':' . $i),
            ]
        );
        $ids[] = (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    cadb_eq(28, ContactMessages::countForStaff($suffix), 'search counts every matching fixture');
    cadb_eq(25, count(ContactMessages::forStaff($suffix, '', '', '', 1)), 'the first page contains 25 messages');
    cadb_eq(3, count(ContactMessages::forStaff($suffix, '', '', '', 2)), 'the second page contains the remaining 3 messages');
    cadb_eq($ids[0], (int) ContactMessages::forStaff($suffix, '', '', '', 1)[0]['id'], 'the list is newest first');
    cadb_eq(1, ContactMessages::countForStaff('Plantain delivery'), 'subject search finds a message');
    cadb_eq(1, ContactMessages::countForStaff('needle-in-message'), 'message-body search finds a message');
    $idMatches = ContactMessages::forStaff((string) $ids[4]);
    cadb_ok(in_array($ids[4], array_map('intval', array_column($idMatches, 'id')), true), 'numeric ID search includes the exact message');
    cadb_eq(14, ContactMessages::countForStaff($suffix, 'new'), 'status filtering returns new messages only');
    cadb_eq(14, ContactMessages::countForStaff($suffix, 'handled'), 'status filtering returns handled messages only');
    cadb_ok(ContactMessages::countForStaff($suffix, '', date('Y-m-d', strtotime('-1 day')), date('Y-m-d')) > 0, 'date range filtering includes recent messages');

    $target = $ids[0];
    $detail = ContactMessages::findForStaff($target);
    cadb_eq(null, $detail['email'], 'detail tolerates a missing email');
    cadb_eq(null, $detail['phone'], 'detail tolerates a missing phone');

    $saved = ContactMessages::saveNote($target, 'Call after the morning market run.', '', $staffId);
    cadb_eq('note_saved', $saved['code'], 'an authorised note update succeeds');
    cadb_eq('Call after the morning market run.', (string) ContactMessages::findForStaff($target)['admin_note'], 'the internal note is stored');
    $stale = ContactMessages::saveNote($target, 'Overwrite', '', $staffId);
    cadb_eq('stale', $stale['code'], 'a stale note form is refused');
    cadb_eq('Call after the morning market run.', (string) ContactMessages::findForStaff($target)['admin_note'], 'a stale note does not overwrite the current note');

    $handled = ContactMessages::handle($target, 'new', $staffId);
    cadb_eq('handled', $handled['code'], 'a new message can be marked handled');
    $handledRow = ContactMessages::findForStaff($target);
    cadb_eq('handled', $handledRow['status'], 'handled status is stored');
    cadb_eq($staffId, (int) $handledRow['handled_by'], 'handled_by records the authorised staff member');
    cadb_ok($handledRow['handled_at'] !== null, 'handled_at records when handling finished');

    $reopened = ContactMessages::reopen($target, 'handled', $staffId);
    cadb_eq('reopened', $reopened['code'], 'a handled message can be reopened');
    $openRow = ContactMessages::findForStaff($target);
    cadb_eq('new', $openRow['status'], 'reopening restores new status');
    cadb_eq(null, $openRow['handled_by'], 'reopening clears handled_by');
    cadb_eq(null, $openRow['handled_at'], 'reopening clears handled_at');
    cadb_eq('Call after the morning market run.', (string) $openRow['admin_note'], 'reopening retains the internal note');

    $history = ContactMessages::handlingHistory($target);
    cadb_eq(3, count($history), 'note, handle and reopen actions form the handling history');
    cadb_eq('contact_messages.reopen', $history[0]['action'], 'handling history is newest first');
} finally {
    foreach ($ids as $id) {
        Database::run('DELETE FROM audit_logs WHERE entity_type = :type AND entity_id = :id', [':type' => 'contact_message', ':id' => $id]);
        Database::run('DELETE FROM contact_messages WHERE id = :id', [':id' => $id]);
    }
    if ($staffId > 0) {
        Database::run('DELETE FROM users WHERE id = :id', [':id' => $staffId]);
    }
}

fwrite(STDOUT, "\n$passed / $tests contact admin database assertions passed.\n");
exit($passed === $tests ? 0 : 1);
