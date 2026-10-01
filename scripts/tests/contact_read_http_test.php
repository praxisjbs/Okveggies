<?php
/**
 * scripts/tests/contact_read_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR3 of the 23 Sep review. "Content and Messages has notifications
 * showing meanwhile I have already read the messages."
 *
 * The badge used to count messages whose status was still "new", and a message
 * only left "new" when somebody pressed Mark handled, so reading changed
 * nothing. Reading and answering are separate now. This proves, over the real
 * admin screens:
 *
 *   - an unread message lights the badge, and opening it puts the badge down on
 *     that very screen, not on the next one;
 *   - opening it again, or a second colleague opening it, changes nothing, and
 *     the first reader is the one on record;
 *   - the list shows which messages are unread, and the opened one stops;
 *   - a message a colleague started, and one that was handled, never light it;
 *   - reopening a message that was read does not light it again;
 *   - Mark all read clears the rest, needs the view permission and the CSRF
 *     token, and refuses everyone else;
 *   - a brand new message from the contact form lights it again.
 *
 *   php -S 127.0.0.1:8123 -t .
 *   php scripts/tests/contact_read_http_test.php
 *
 * Creates its own staff and messages, then removes them. Counts are compared
 * against the database's own baseline, so other unread messages do not matter,
 * except that Mark all read clears them, as it should.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }

$base   = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests  = 0;
$passed = 0;

function cr_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function cr_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    cr_ok($same, $label);
}
function cr_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, json_decode($body, true)];
}
function cr_csrf(string $jar, string $base): string
{
    [, $body] = cr_req($jar, $base . '/account.php', null, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}
/** The number on the Content and Messages link in the sidebar, or 0 when there is no badge. */
function cr_badge(string $html): int
{
    return preg_match('/(\d+)\+?<span class="sr-only"> unread<\/span>/', $html, $m) ? (int) $m[1] : 0;
}

$suffix   = substr(bin2hex(random_bytes(5)), 0, 10);
$password = 'read-http-5521';
$users = []; $roles = []; $messageIds = [];
$jarA = tempnam(sys_get_temp_dir(), 'okv-cr-a-');   // may view and handle messages
$jarB = tempnam(sys_get_temp_dir(), 'okv-cr-b-');   // a second colleague, view only
$jarO = tempnam(sys_get_temp_dir(), 'okv-cr-o-');   // has no messages permission
$jarG = tempnam(sys_get_temp_dir(), 'okv-cr-g-');   // the public

$makeStaff = static function (string $first, array $permissions) use ($suffix, $password, &$users, &$roles): array {
    $email = strtolower($first) . "-cr-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, \'Reader\', :e, :p, :h, \'staff\', \'active\', NOW())',
        [':f' => $first, ':e' => $email, ':p' => '+23476' . random_int(10000000, 99999999), ':h' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Contact read HTTP fixture\')', [':n' => 'contact_read_' . $suffix . '_' . count($roles)]);
    $role = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roles[] = $role;
    foreach ($permissions as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE `key` = :p', [':r' => $role, ':p' => $permission]);
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $id, ':r' => $role]);
    return [$id, $email];
};
$signIn = static function (string $jar, string $email) use ($base, $password): string {
    $csrf = cr_csrf($jar, $base);
    cr_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'context' => 'admin', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf]);
    return cr_csrf($jar, $base);
};
$newMessage = static function (string $status = 'new', int $minutesAgo = 5) use ($suffix, &$messageIds): int {
    Database::run(
        'INSERT INTO contact_messages (name, email, subject, message, source, status, created_at)
         VALUES (:n, :e, :s, :m, \'contact_page\', :st, :c)',
        [':n' => 'Reader Fixture ' . count($messageIds), ':e' => "cr-$suffix-" . count($messageIds) . '@example.test', ':s' => 'Reader subject ' . count($messageIds),
         ':m' => 'Reading fixture message', ':st' => $status, ':c' => date('Y-m-d H:i:s', strtotime("-$minutesAgo minutes"))]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $messageIds[] = $id;
    return $id;
};
$readRow = static fn(int $id): array => Database::one('SELECT status, read_at, read_by, handled_by FROM contact_messages WHERE id = :id', [':id' => $id]);

try {
    Database::run('DELETE FROM rate_limits');
    cr_ok(ContactMessages::hasReadTracking(), 'setup: the database has the read tracking columns (migration 069)');

    [$handlerId, $handlerEmail] = $makeStaff('Handler', ['dashboard.view', 'messages.view', 'messages.handle']);
    [$viewerId, $viewerEmail]   = $makeStaff('Viewer', ['dashboard.view', 'messages.view']);
    [$outsiderId, $outsiderEmail] = $makeStaff('Outsider', ['dashboard.view']);
    $csrfA = $signIn($jarA, $handlerEmail);
    $csrfB = $signIn($jarB, $viewerEmail);
    $csrfO = $signIn($jarO, $outsiderEmail);

    // Three unread messages. The newest is the one the screen opens by default,
    // so the test always asks for a specific message and never relies on that.
    $m1 = $newMessage('new', 1);
    $m2 = $newMessage('new', 2);
    $m3 = $newMessage('new', 3);
    $baseline = ContactMessages::countNew();
    cr_ok($baseline >= 3, 'setup: three unread messages are counted');
    cr_eq(null, $readRow($m2)['read_at'], 'setup: nobody has read the second one');

    [, $dash] = cr_req($jarA, $base . '/admin/', null, false);
    cr_eq($baseline, cr_badge($dash), 'the badge on any admin screen shows every unread message');

    // ---- opening a message is reading it, and the badge already knows ------------------------------------------
    [$code, $page] = cr_req($jarA, $base . '/admin/content.php?message=' . $m2, null, false);
    cr_eq(200, $code, 'a colleague opens the second message');
    $row = $readRow($m2);
    cr_ok($row['read_at'] !== null, 'it is marked read');
    cr_eq($handlerId, (int) $row['read_by'], 'by the colleague who opened it');
    cr_eq('new', (string) $row['status'], 'but it is still awaiting a reply: reading is not answering');
    cr_eq($baseline - 1, cr_badge($page), 'and the badge is already one lower on that very screen');
    cr_eq($baseline - 1, ContactMessages::countNew(), 'as is the count the database gives');
    $listed = preg_match_all('/<span class="sr-only">Unread\. <\/span>/', $page);
    cr_ok($listed >= 2, 'the list still marks the other unread messages');
    preg_match('/<a href="[^"]*message=' . $m2 . '"[^>]*aria-current="page".*?<\/a>/s', $page, $openItem);
    cr_ok($openItem !== [] && !str_contains($openItem[0], 'Unread. '), 'but the one that is open has lost its unread dot');

    // ---- again, and by someone else: nothing changes ------------------------------------------------------------------
    $firstReadAt = (string) $row['read_at'];
    cr_req($jarA, $base . '/admin/content.php?message=' . $m2, null, false);
    [, $viewerPage] = cr_req($jarB, $base . '/admin/content.php?message=' . $m2, null, false);
    $again = $readRow($m2);
    cr_eq($firstReadAt, (string) $again['read_at'], 'opening it again keeps the first time it was read');
    cr_eq($handlerId, (int) $again['read_by'], 'and a second colleague opening it does not take the credit');
    cr_eq($baseline - 1, cr_badge($viewerPage), 'and the badge does not move');

    // ---- a different message goes down by exactly one -------------------------------------------------------------------
    [, $thirdPage] = cr_req($jarB, $base . '/admin/content.php?message=' . $m3, null, false);
    cr_eq($baseline - 2, cr_badge($thirdPage), 'opening the next one takes the badge down by one more');
    cr_eq($viewerId, (int) $readRow($m3)['read_by'], 'recorded against the colleague who opened it, who can view but not handle');

    // ---- handled and staff started messages never count ------------------------------------------------------------------
    $handledId = $newMessage('handled', 4);
    cr_eq($baseline - 2, ContactMessages::countNew(), 'a handled message does not count, even if nobody opened it');
    $started = ContactMessages::staffInitiate(['name' => 'Started By Staff', 'email' => "started-$suffix@example.test", 'subject' => 'Hello', 'message' => 'A thread staff began'], $handlerId);
    $messageIds[] = (int) $started['message_id'];
    cr_ok($readRow((int) $started['message_id'])['read_at'] !== null, 'a thread a colleague started is read from the start');
    cr_eq($baseline - 2, ContactMessages::countNew(), 'and does not light the badge');

    // ---- reopening something that was read does not light it again ----------------------------------------------------------
    [$code] = cr_req($jarA, $base . '/api/v1/contact.php', ['action' => 'handle', 'message_id' => $m2, 'expected_status' => 'new', 'okv_csrf' => $csrfA]);
    cr_eq(200, $code, 'a colleague marks the second message handled');
    [$code] = cr_req($jarA, $base . '/api/v1/contact.php', ['action' => 'reopen', 'message_id' => $m2, 'expected_status' => 'handled', 'okv_csrf' => $csrfA]);
    cr_eq(200, $code, 'and reopens it');
    cr_eq('new', (string) $readRow($m2)['status'], 'it is awaiting a reply again');
    cr_eq($baseline - 2, ContactMessages::countNew(), 'but it was read, so the badge stays down');

    // ---- a message that arrives from the contact form lights it ------------------------------------------------------------------
    $guestCsrf = '';
    [, $contactPage] = cr_req($jarG, $base . '/contact.php', null, false);
    if (preg_match('/name="okv_csrf" value="([^"]+)"/', $contactPage, $m)) { $guestCsrf = $m[1]; }
    $before = ContactMessages::countNew();
    [$code, , $sent] = cr_req($jarG, $base . '/api/v1/contact.php', [
        'action' => 'submit', 'name' => 'Reader Public ' . $suffix, 'email' => "public-$suffix@example.test", 'subject' => 'A real question',
        'message' => 'Do you deliver to Lekki on Fridays?', 'okv_csrf' => $guestCsrf, 'website' => '',
    ]);
    if (!empty($sent['message_id'])) { $messageIds[] = (int) $sent['message_id']; }
    cr_ok($code === 200 || $code === 201, 'a visitor sends a message through the contact form');
    cr_eq($before + 1, ContactMessages::countNew(), 'and it lights the badge');

    // ---- Mark all read ------------------------------------------------------------------------------------------------------
    [$code] = cr_req($jarB, $base . '/api/v1/contact.php', ['action' => 'mark_all_read']);
    cr_eq(419, $code, 'Mark all read without the CSRF token is refused');
    [$code] = cr_req($jarO, $base . '/api/v1/contact.php', ['action' => 'mark_all_read', 'okv_csrf' => $csrfO]);
    cr_eq(403, $code, 'a colleague without messages.view is refused');
    [$code] = cr_req($jarG, $base . '/api/v1/contact.php', ['action' => 'mark_all_read', 'okv_csrf' => $guestCsrf]);
    cr_ok(in_array($code, [401, 403], true), 'the public is refused');
    cr_ok(ContactMessages::countNew() > 0, 'and none of those refusals cleared anything');
    [$code, , $marked] = cr_req($jarB, $base . '/api/v1/contact.php', ['action' => 'mark_all_read', 'okv_csrf' => $csrfB]);
    cr_eq(200, $code, 'a colleague who can view messages marks everything read');
    cr_ok(((int) ($marked['marked'] ?? 0)) >= 1, 'and is told how many it cleared');
    cr_eq(0, ContactMessages::countNew(), 'the count is zero');
    [, $quiet] = cr_req($jarA, $base . '/admin/', null, false);
    cr_eq(0, cr_badge($quiet), 'and the badge is gone from every screen');
    [, $none] = cr_req($jarA, $base . '/admin/content.php?message=' . $m1, null, false);
    cr_ok(!str_contains($none, 'Mark all read'), 'with nothing unread the button is not offered');
    cr_ok(!str_contains($none, '(' . $baseline . ' unread)') && !preg_match('/Messages \(\d+ unread\)/', $none), 'and the tab no longer says unread');
    [$code, , $twice] = cr_req($jarB, $base . '/api/v1/contact.php', ['action' => 'mark_all_read', 'okv_csrf' => $csrfB]);
    cr_ok($code === 200 && (int) ($twice['marked'] ?? -1) === 0, 'pressing it again is harmless and clears nothing more');

    // ---- the button is offered when there is something to clear ---------------------------------------------------------------
    $fresh = $newMessage('new', 0);
    [, $withUnread] = cr_req($jarA, $base . '/admin/content.php?message=' . $m1, null, false);
    cr_ok(str_contains($withUnread, 'name="action" value="mark_all_read"'), 'a new unread message brings the Mark all read button back');
    cr_ok(preg_match('/Messages \(1 unread\)/', $withUnread) === 1, 'and the tab says how many are unread');
    unset($fresh);
} finally {
    $ids = $users ? implode(',', array_map('intval', $users)) : '0';
    $mids = $messageIds ? implode(',', array_map('intval', $messageIds)) : '0';
    Database::run("DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = 'contact_message' AND related_id IN ($mids))");
    Database::run("DELETE FROM notifications WHERE related_type = 'contact_message' AND related_id IN ($mids)");
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'contact_message' AND entity_id IN ($mids)) OR actor_user_id IN ($ids)");
    Database::run("DELETE FROM contact_messages WHERE id IN ($mids)");
    Database::run("DELETE FROM user_roles WHERE user_id IN ($ids)");
    foreach ($roles as $role) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $role]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $role]);
    }
    Database::run("DELETE FROM users WHERE id IN ($ids)");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'contact:%'");
    foreach ([$jarA, $jarB, $jarO, $jarG] as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
}

fwrite(STDOUT, "\n$passed / $tests contact read tracking HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
