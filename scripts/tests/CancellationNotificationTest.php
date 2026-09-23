<?php
/**
 * scripts/tests/CancellationNotificationTest.php
 * -----------------------------------------------------------------------------
 * The staff half of a cancellation.
 *
 * A cancellation used to tell the customer and nobody else: one event,
 * `order_cancelled`, addressed to the order's customer. These assertions pin the
 * fix down to the parts that can be proved without a database, which is all of
 * the wording, all of the boundaries and all of the wiring. The rows themselves
 * are proved in cancellation_notification_db_test.php against MySQL 8.
 *
 * What is at stake here:
 *   1. the event exists, is staff-audience, names a template and declares the
 *      tokens that template uses, so it can never render with holes in it;
 *   2. the bell admits it only behind orders.view, the same gate as the order it
 *      links to, so a staff member who may not open Order 360 never reads it;
 *   3. the seven facts the Owner asked for are all in the message, and no naira
 *      figure is, because the amounts live on the order behind the same gate and
 *      a failed refund already reaches payments people with its figure attached;
 *   4. a mail failure records a reason somebody can act on, and that reason is a
 *      fixed sentence rather than the driver's own words, which can carry the
 *      SMTP host and the account name into a column a screen prints;
 *   5. nothing about recipients, addresses or wording is decided in the
 *      controller.
 * -----------------------------------------------------------------------------
 */

$okvRoot = dirname(__DIR__, 2);

// The unit runner loads the domain classes but not the mail shell, and this
// suite sorts before the one that requires it, so it brings its own.
require_once $okvRoot . '/includes/classes/Brand.php';
require_once $okvRoot . '/includes/classes/Mail.php';

// --- 1. The event is in the catalogue, and it is staff mail -------------------

okv_test_ok(isset(Notifications::EVENTS['admin_order_cancelled']), 'a cancellation announces itself to the team as a real event');
okv_test_eq('staff', Notifications::EVENTS['admin_order_cancelled']['audience'] ?? '', 'the cancellation alert is staff mail, so the customer feed cannot pick it up');
okv_test_eq('admin_order_cancelled', Notifications::EVENTS['admin_order_cancelled']['template'] ?? '', 'the event names its own template');
okv_test_eq('customer', Notifications::EVENTS['order_cancelled']['audience'] ?? '', 'the customer cancellation event is still customer mail');

$staffTokens = Notifications::TOKENS['admin_order_cancelled'] ?? [];
foreach ([
    'order_number'        => 'the order number',
    'customer_name'       => 'who the order belongs to',
    'cancellation_source' => 'who cancelled it',
    'cancellation_reason' => 'why',
    'delivery_day'        => 'the delivery date it was going out on',
    'refund_state'        => 'where the money now stands',
    'admin_url'           => 'the way into the order',
] as $token => $fact) {
    okv_test_ok(in_array($token, $staffTokens, true), 'the staff cancellation alert carries ' . $fact);
}
okv_test_ok(!in_array('order_trail_url', $staffTokens, true), 'the staff alert never carries the customer trail link');
okv_test_ok(!in_array('money_line', $staffTokens, true), 'the staff alert never carries the customer money sentence');

// The button. Mail::ctaFromVars() takes the first address it recognises, so a
// staff message that also carried order_trail_url would offer "Follow your
// order" and land the team on the customer's trail instead of the order.
$cta = Mail::ctaFromVars([
    'order_number' => 'OKV26014',
    'admin_url'    => 'https://okveggies.test/admin/orders.php?order=14',
]);
okv_test_eq('Open in admin', (string) ($cta['label'] ?? ''), 'the cancellation alert opens the admin order, not the customer trail');
okv_test_eq('https://okveggies.test/admin/orders.php?order=14', (string) ($cta['url'] ?? ''), 'the button carries the order address it was given');

// --- 2. The bell admits it behind orders.view --------------------------------

okv_test_eq('orders.view', AdminNotifications::permissionForEvent('admin_order_cancelled'), 'the cancellation alert is filtered by the order visibility permission');
okv_test_eq('order', AdminNotifications::EVENTS['admin_order_cancelled']['related_type'] ?? '', 'the bell knows the alert is about an order');
okv_test_eq('/admin/orders.php?order=14', AdminNotifications::hrefFor('order', 14), 'the bell deep-links the alert to Order 360');
okv_test_eq(Notifications::EVENTS['admin_new_order']['template'], 'admin_new_order', 'the existing new-order alert was not disturbed');

// --- 3. Who cancelled it ------------------------------------------------------

okv_test_eq('Cancelled by the customer.', Notifications::cancellationSourceLine('customer', 'Ada Obi'), 'a customer cancellation says so, and does not name the customer twice');
okv_test_eq('Cancelled by Ada Obi on our team.', Notifications::cancellationSourceLine('staff', 'Ada Obi'), 'a staff cancellation names the colleague who pressed the button');
okv_test_eq('Cancelled by our team.', Notifications::cancellationSourceLine('staff', '   '), 'a staff cancellation with no readable name still says who did it');
okv_test_eq('Cancelled.', Notifications::cancellationSourceLine('system', ''), 'an unknown actor is reported rather than guessed at');

// --- 4. Why -------------------------------------------------------------------

okv_test_eq(
    OrderCancellation::CUSTOMER_REASONS['delivery_date'],
    Notifications::cancellationReasonLine('customer', 'delivery_date', ''),
    'a customer reason is said in the words the reason list already uses'
);
okv_test_eq(
    OrderCancellation::STAFF_REASONS['stock_unavailable'],
    Notifications::cancellationReasonLine('staff', 'stock_unavailable', ''),
    'a staff reason is said in the words the staff reason list uses'
);
okv_test_eq(
    'Produce is unavailable. Note: The farm in Jos could not load it.',
    Notifications::cancellationReasonLine('staff', 'stock_unavailable', 'The farm in Jos could not load it.'),
    'the note a colleague typed is carried behind the reason'
);
okv_test_eq(
    'I changed my mind. Note: Plans changed',
    Notifications::cancellationReasonLine('customer', 'changed_mind', '  Plans changed  '),
    'the note is trimmed before it is carried'
);
okv_test_eq(
    'A reason was recorded and is not on the current list.',
    Notifications::cancellationReasonLine('customer', 'a_code_we_retired', ''),
    'a retired reason code says what it is rather than sending a blank line'
);
okv_test_eq(
    'A reason was recorded and is not on the current list.',
    Notifications::cancellationReasonLine('customer', '', ''),
    'a cancellation recorded with no reason at all still reads as a sentence'
);
$long = str_repeat('The customer rang twice about the same crate. ', 20);
$clipped = Notifications::cancellationReasonLine('customer', 'other', $long);
okv_test_ok(str_contains($clipped, 'Note: '), 'a long note is still introduced as a note');
okv_test_ok(mb_strlen($clipped) < mb_strlen($long), 'a thousand characters of free text is clipped for a bell and a subject line');
okv_test_ok(str_ends_with($clipped, '...'), 'a clipped note says it was clipped');
okv_test_ok(!str_ends_with(Notifications::cancellationReasonLine('customer', 'other', 'Short note.'), '...'), 'a note that fits is not marked as clipped');

// --- 5. Where the money stands, with no figure in it --------------------------

$states = [
    'not_required'   => ['Nothing had been paid on this order, so there is no refund to make.', true],
    'pending'        => ['A refund has been raised and is not confirmed yet.', true],
    'processed'      => ['The refund is confirmed. The money has gone back to the customer.', true],
    'failed'         => ['The refund failed. Open the order and check it before anything is tried again.', true],
    'failed_manual'  => ['The online refund failed, and money our team recorded still has to go back by hand. Open the order and check both.', true],
    'manual_required' => ['Money our team recorded still has to go back to the customer by hand.', true],
    'pending_manual' => ['Money our team recorded still has to go back to the customer by hand.', true],
    'requested'      => ['A refund has been raised and is not confirmed yet.', true],
    'processing'     => ['A refund has been raised and is not confirmed yet.', true],
    'something_new'  => ['The refund position is not settled yet. Open the order and check it.', true],
];
foreach ($states as $status => [$expected, $required]) {
    okv_test_eq($expected, Notifications::staffRefundStateLine((string) $status, $required), "the $status position is said plainly to the team");
}
okv_test_eq(
    'Nothing had been paid on this order, so there is no refund to make.',
    Notifications::staffRefundStateLine('pending', false),
    'an order that never asked for a refund says so whatever the status column holds'
);

// The Owner's answer to question 3 was a state word, not a figure. This is the
// assertion that keeps that decision true: no naira symbol and no digits from a
// money format can reach the team alert through this line.
foreach (array_keys($states) as $status) {
    foreach ([true, false] as $required) {
        $line = Notifications::staffRefundStateLine((string) $status, $required);
        okv_test_ok(!str_contains($line, "\u{20A6}"), "the $status position carries no naira symbol");
        okv_test_ok(!preg_match('/\d/', $line), "the $status position carries no figure at all");
    }
}

// --- 6. House law on the new copy --------------------------------------------

// The banned words are read out of scripts/brand-check.sh rather than typed
// again here, the way EmailTemplateTest does it, so the repository holds one
// list and this file cannot trip the guard on itself.
$guard = (string) file_get_contents($okvRoot . '/scripts/brand-check.sh');
preg_match('/\\(([a-z\\- ]+(?:\\|[a-z\\- ]+){5,})\\)/', $guard, $matched);
$banned = array_values(array_filter(explode('|', $matched[1] ?? '')));
okv_test_ok(count($banned) >= 11, 'the banned word list was read from the brand guard');

$copy = [
    Notifications::cancellationSourceLine('customer', ''),
    Notifications::cancellationSourceLine('staff', 'Ada Obi'),
    Notifications::cancellationReasonLine('customer', 'other', 'The delivery day moved.'),
    Notifications::cancellationReasonLine('staff', 'customer_requested', 'They rang us.'),
];
foreach (array_keys($states) as $status) {
    $copy[] = Notifications::staffRefundStateLine((string) $status, true);
}
foreach ($copy as $line) {
    okv_test_ok(!str_contains($line, "\u{2014}"), 'the cancellation alert copy carries no em dash');
    foreach ($banned as $word) {
        okv_test_ok(stripos($line, $word) === false, 'the cancellation alert copy carries no jargon: ' . $word);
    }
}

// --- 7. A failed send records a reason, and only a reason ---------------------

okv_test_eq(Mail::FAIL_UNREACHABLE, Mail::classifyFailure('SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting'), 'a mail host that is down is said to be down');
okv_test_eq(Mail::FAIL_UNREACHABLE, Mail::classifyFailure('stream_socket_client(): unable to connect to smtp host'), 'a refused connection is a reachability failure');
okv_test_eq(Mail::FAIL_AUTH, Mail::classifyFailure('SMTP Error: Could not authenticate.'), 'a wrong password is said to be a sign-in problem');
okv_test_eq(Mail::FAIL_AUTH, Mail::classifyFailure('535 5.7.8 Username and Password not accepted'), 'a 535 refusal is a sign-in problem');
okv_test_eq(Mail::FAIL_TIMEOUT, Mail::classifyFailure('SMTP Error: Could not connect to SMTP host. Connection timed out'), 'a timeout is reported as a timeout, which is more useful than a bare failure');
okv_test_eq(Mail::FAIL_RECIPIENT, Mail::classifyFailure('SMTP Error: The following recipients failed: ada@example.test'), 'a rejected address is said to be rejected');
okv_test_eq(Mail::FAIL_RECIPIENT, Mail::classifyFailure('550 5.1.1 Mailbox unavailable'), 'an unknown mailbox is an address problem');
okv_test_eq(Mail::FAIL_SENDER, Mail::classifyFailure('Invalid address: noreply at okveggies'), 'a broken sending address is its own category');
okv_test_eq(Mail::FAIL_UNKNOWN, Mail::classifyFailure('Something entirely unexpected happened'), 'an unrecognised complaint falls back to the plain sentence');
okv_test_eq(Mail::FAIL_UNKNOWN, Mail::classifyFailure('   '), 'no complaint at all still records the plain sentence');
okv_test_eq(Mail::FAIL_UNKNOWN, Mail::lastError(), 'before any send there is nothing to explain');

// The whole point of the classifier: whatever it is handed, what comes back is
// one of our own sentences. This column is printed on Order 360, so a driver
// message carrying the SMTP host, the port or the account name must never
// reach it.
$hostile = [
    'SMTP connect() failed to mail.okveggies.com.ng:465 with user noreply@okveggies.com.ng',
    'Could not authenticate using password hunter2 on smtp.example.test',
    'stream_socket_client() php_network_getaddresses: getaddrinfo for secret-relay.internal failed',
    '550 relay denied for hidden-customer@example.test',
    '',
    'A brand new failure nobody has seen before',
];
$allowed = [
    Mail::FAIL_UNKNOWN, Mail::FAIL_NO_TRANSPORT, Mail::FAIL_UNREACHABLE,
    Mail::FAIL_AUTH, Mail::FAIL_TIMEOUT, Mail::FAIL_RECIPIENT, Mail::FAIL_SENDER,
];
foreach ($hostile as $detail) {
    $sentence = Mail::classifyFailure($detail);
    okv_test_ok(in_array($sentence, $allowed, true), 'the recorded reason is always one of our own sentences');
    okv_test_ok(!str_contains($sentence, 'okveggies.com.ng'), 'no host or address from the driver reaches the recorded reason');
    okv_test_ok(!str_contains($sentence, 'hunter2'), 'no credential from the driver reaches the recorded reason');
    okv_test_ok(!str_contains($sentence, 'secret-relay.internal'), 'no internal name from the driver reaches the recorded reason');
    okv_test_ok(!str_contains($sentence, "\u{2014}"), 'the recorded reason carries no em dash');
}

// --- 8. The dispatcher guards itself against a second announcement -----------

$dispatcher = (string) file_get_contents($okvRoot . '/includes/classes/Notifications.php');
okv_test_ok(str_contains($dispatcher, 'public static function alreadyAnnounced('), 'the dispatcher can ask what it has already announced for a record');
$announce = new ReflectionMethod(Notifications::class, 'alreadyAnnounced');
okv_test_ok($announce->isPublic() && $announce->isStatic(), 'the idempotency check is callable, so a suite can prove it rather than trust it');
okv_test_eq(3, $announce->getNumberOfParameters(), 'the idempotency check asks for the event, the record type and the record');
okv_test_eq(
    2,
    substr_count($dispatcher, "self::alreadyAnnounced("),
    'both halves of a cancellation check before they write, the customer half and the team half'
);
okv_test_ok(str_contains($dispatcher, "self::send(\n            'admin_order_cancelled',"), 'the team alert goes through the one dispatcher');
okv_test_ok(str_contains($dispatcher, "self::staffRecipients('orders.view')"), 'the recipients come from active users holding the order visibility permission');
okv_test_ok(str_contains($dispatcher, 'FROM order_cancellations c'), 'the alert reads the committed cancellation row rather than trusting the caller');
okv_test_ok(str_contains($dispatcher, 'Mail::lastError()'), 'a failed send records the reason Mail classified');
okv_test_ok(!preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $dispatcher), 'no email address is written into the dispatcher');
okv_test_ok(!str_contains($dispatcher, "'admin_order_cancelled' =>\n        ["), 'the alert is not built from a private recipient list');

// --- 9. The migration carries the words, and only the words -------------------

$migration = (string) file_get_contents($okvRoot . '/migrations/059_admin_order_cancelled_notification.sql');
okv_test_ok($migration !== '', 'migration 059 exists');
okv_test_ok(str_contains($migration, 'START TRANSACTION;'), 'migration 059 opens a transaction');
okv_test_ok(str_contains($migration, 'COMMIT;'), 'migration 059 commits');
okv_test_ok(str_contains($migration, 'INSERT IGNORE INTO notification_templates'), 'migration 059 inserts rather than updates, so a missing row is created');
okv_test_ok(!str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'migration 059 never overwrites copy an Owner has since edited');
okv_test_ok(str_contains($migration, '-- Verification:'), 'migration 059 ends with verification queries');
okv_test_ok(!str_contains($migration, "\u{2014}"), 'no em dash in migration 059');
okv_test_ok(!str_contains($migration, 'ALTER TABLE'), 'migration 059 is data only, so there is no DDL to leave outside the transaction');
okv_test_ok(str_contains($migration, "('admin_order_cancelled', 'email',"), 'migration 059 seeds the admin_order_cancelled template');
foreach ($banned as $word) {
    okv_test_ok(stripos($migration, $word) === false, 'no banned jargon in the cancellation alert copy: ' . $word);
}
// Every token the event declares has to be filled by the words or by the
// button, or the email ships with a fact missing and the settings tab shows a
// token nothing supplies.
//
// The address token is deliberately not printed in the copy. Mail::brandedHtml()
// turns a declared admin_url into the one action button and prints the address
// underneath it, which is what migration 010 set as the rule and what the two
// existing staff alerts (refund_failed, admin_manual_payment_proof) both do:
// they declare the token and let the shell carry it, rather than burying a link
// in a sentence. So the words have to name every other token, and the address
// token has to be declared and used by the body's next step rather than quoted.
foreach ($staffTokens as $token) {
    if (str_ends_with($token, '_url')) {
        okv_test_ok(!str_contains($migration, '{{' . $token . '}}'), 'the address is carried by the shell button, not buried in a sentence');
        continue;
    }
    okv_test_ok(str_contains($migration, '{{' . $token . '}}'), 'migration 059 uses the {{' . $token . '}} token');
}
okv_test_ok(str_contains($migration, 'Open the order'), 'the words still say what the button is for');
foreach (['refund_failed', 'admin_manual_payment_proof'] as $existing) {
    okv_test_ok(in_array('admin_url', Notifications::TOKENS[$existing] ?? [], true), 'the existing staff alerts carry their address the same way');
}
okv_test_ok(!preg_match('/\{\{\s*[a-z0-9_]+\s*\}\}/i', str_replace(
    array_map(static fn(string $t): string => '{{' . $t . '}}', $staffTokens),
    '',
    (string) preg_replace('/^--.*$/m', '', $migration)
)), 'migration 059 names no token the event does not declare');
// A staff alert that quotes a naira figure is the thing question 3 ruled out.
okv_test_ok(!str_contains($migration, "\u{20A6}"), 'migration 059 puts no naira figure in the team copy');
// No recipient list, no address and no URL in the words either. The address is
// built from APP_URL and the order id at send time.
okv_test_ok(!preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $migration), 'migration 059 stores no recipient address');
okv_test_ok(!str_contains($migration, 'https://'), 'migration 059 stores no order address');

// --- 10. The controller decides nothing --------------------------------------

$orders = (string) file_get_contents($okvRoot . '/api/v1/orders.php');
okv_test_eq(2, substr_count($orders, 'Notifications::announceCancellation'), 'both cancellation paths go through the one announcement');
okv_test_ok(!str_contains($orders, 'Notifications::send('), 'the controller never addresses a message itself');
okv_test_ok(!str_contains($orders, 'staffRecipients'), 'the controller never builds a recipient list');
okv_test_ok(!str_contains($orders, 'admin_order_cancelled'), 'the controller does not name the team event, so the decision stays in one place');
okv_test_ok(!str_contains($orders, 'Cancelled by'), 'no notification copy is written in the controller');
okv_test_ok(!preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $orders), 'no staff address is hardcoded in the controller');
okv_test_ok(str_contains($orders, "\$result['code'] === 'cancelled'"), 'a cancellation that changed nothing announces nothing');
okv_test_ok(str_contains($orders, "Rbac::requirePermission('orders.cancel')"), 'the staff path is still permission gated');
okv_test_ok(str_contains($orders, "Rbac::requirePermission('notifications.resend')"), 'the resend path is still permission gated');

// --- 11. The template preview has a value for every token --------------------

$sample = SettingsEditor::sampleTokens('admin_order_cancelled');
foreach ($staffTokens as $token) {
    okv_test_ok(array_key_exists($token, $sample), 'the preview of the cancellation alert has a value for ' . $token);
    okv_test_ok(trim((string) $sample[$token]) !== '', 'the preview of ' . $token . ' is not an empty gap');
}
okv_test_eq('Cancelled by the customer.', (string) $sample['cancellation_source'], 'the preview shows wording that can really occur');
okv_test_ok(!str_contains((string) $sample['refund_state'], "\u{20A6}"), 'the preview of the money line carries no figure either');
