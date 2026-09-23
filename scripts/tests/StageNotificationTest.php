<?php
/**
 * scripts/tests/StageNotificationTest.php
 * Pure checks on dispatched and delivered stage emails: the catalogue, the
 * exactly-once rule, the honest partial status, the staff alert on failure,
 * the masked logging and the migration that carries the new words.
 *
 * A dispatched email that never arrived used to fail four quiet ways at once:
 * no idempotency in the dispatcher, a trail link that 404s for a guest, a
 * missing address that wrote nothing at all, and a parent row that read sent
 * while the email had failed. Each section below pins one of those shut.
 */

$okvRoot = dirname(__DIR__, 2);

// --- 1. The event catalogue ------------------------------------------------

okv_test_ok(isset(Notifications::EVENTS['admin_stage_email_failed']), 'a failed stage email is a registered event');
okv_test_eq('admin_stage_email_failed', Notifications::EVENTS['admin_stage_email_failed']['template'], 'the failure alert names its template');
okv_test_eq('staff', Notifications::EVENTS['admin_stage_email_failed']['audience'], 'the failure alert is for staff, not the customer');
okv_test_ok(!empty(Notifications::TOKENS['admin_stage_email_failed']), 'the failure alert declares the tokens it accepts');
foreach (['customer_name', 'order_number', 'stage_label', 'delivery_day', 'failure_reason', 'admin_url'] as $token) {
    okv_test_ok(in_array($token, Notifications::TOKENS['admin_stage_email_failed'], true), "the failure alert declares $token");
}
okv_test_ok(!in_array('order_trail_url', Notifications::TOKENS['admin_stage_email_failed'], true), 'the staff alert never receives the customer trail address');

okv_test_eq('order_dispatched', Notifications::STAGE_EVENTS['dispatched'], 'dispatching announces the dispatched event');
okv_test_eq('order_delivered', Notifications::STAGE_EVENTS['delivered'], 'delivering announces the delivered event');
okv_test_ok(!isset(Notifications::STAGE_EVENTS['cancelled']), 'cancellation still announces on its own path');

// --- 2. The stage label ----------------------------------------------------

okv_test_eq('Confirmed', Notifications::stageLabel('confirmed'), 'the alert names the confirmed stage');
okv_test_eq('Packed', Notifications::stageLabel('packed'), 'the alert names the packed stage');
okv_test_eq('Dispatched', Notifications::stageLabel('dispatched'), 'the alert names the dispatched stage');
okv_test_eq('Delivered', Notifications::stageLabel('delivered'), 'the alert names the delivered stage');
okv_test_eq('Order update', Notifications::stageLabel('cancelled'), 'an unknown stage still reads as an order update');
okv_test_eq('Order update', Notifications::stageLabel(''), 'a blank stage still reads as an order update');

// --- 3. The honest summary -------------------------------------------------

okv_test_eq('partial', Notifications::STATUS_PARTIAL, 'partial is a real notification status');
okv_test_eq('sent', Notifications::summariseDelivery(true, false), 'every channel landing reads as sent');
okv_test_eq('partial', Notifications::summariseDelivery(true, true), 'in-app sent with email failed reads as partial, never sent');
okv_test_eq('failed', Notifications::summariseDelivery(false, true), 'every channel failing reads as failed');
okv_test_eq('failed', Notifications::summariseDelivery(false, false), 'nothing attempted reads as failed, never sent');

// --- 4. Order 360 under a partial notification ------------------------------

$partialFailed = Notifications::deliveryState(['status' => 'partial', 'delivery_status' => 'failed', 'channel' => 'email']);
okv_test_eq('Not sent', $partialFailed['label'], 'a failed email under a partial notification reads as not sent');
okv_test_ok($partialFailed['may_resend'], 'and it can be sent again');

$partialSent = Notifications::deliveryState(['status' => 'partial', 'delivery_status' => 'sent', 'channel' => 'email']);
okv_test_eq('Sent', $partialSent['label'], 'the channel that landed still reads as sent');
okv_test_ok(!$partialSent['may_resend'], 'and it is not offered again');

okv_test_ok(
    !Notifications::deliveryState(['status' => 'partial', 'delivery_status' => 'failed', 'channel' => 'in_app'])['may_resend'],
    'only an email can be sent again, even under a partial notification'
);

// --- 5. The dispatcher -----------------------------------------------------

$dispatcher = (string) file_get_contents($okvRoot . '/includes/classes/Notifications.php');

okv_test_ok(str_contains($dispatcher, 'public static function announceStage'), 'stages announce through the one dispatcher');
okv_test_ok(
    preg_match('/function announceStage.*?alreadyAnnounced\(\\$event, \'order\', \\$orderId\)/s', $dispatcher) === 1,
    'a stage announcement checks what it has already announced, so a repeat writes nothing new'
);
okv_test_ok(str_contains($dispatcher, 'OrderTrail::issueForOrder($orderId, $actorId)'), 'a stage email mints a fresh share token for its trail link');
okv_test_ok(str_contains($dispatcher, 'self::orderContext($orderId, $token)'), 'the minted token reaches the order context');
okv_test_ok(str_contains($dispatcher, 'self::recordStageWithoutEmail('), 'an order with no usable address is recorded, not skipped');
okv_test_ok(str_contains($dispatcher, 'self::alertStageEmailFailed('), 'a stage email that fails tells the team');
okv_test_ok(str_contains($dispatcher, 'self::staffRecipients(\'orders.view\')'), 'the failure alert goes to whoever may open the order');
okv_test_ok(
    substr_count($dispatcher, 'self::summariseDelivery(') >= 3,
    'the immediate send, the held send and the resend all summarise the same way'
);
okv_test_ok(str_contains($dispatcher, 'self::refreshNotificationStatus('), 'a resend recomputes the notification from its deliveries');
okv_test_ok(str_contains($dispatcher, 'self::orderEmailAddress('), 'a resend re-reads the order when the stored address is unusable');
okv_test_ok(str_contains($dispatcher, 'FAIL_NO_ADDRESS'), 'the missing-address diagnostic is one fixed sentence');

// The recipients still come from the order, dynamically: the account address
// when there is an account, otherwise the one typed at checkout.
okv_test_ok(
    str_contains($dispatcher, "trim((string) (\$order['user_email'] ?? '')) ?: trim((string) (\$order['contact_email'] ?? ''))"),
    'the customer address resolves from the account first, then the checkout address'
);

// --- 6. The trail issuance -------------------------------------------------

$trail = (string) file_get_contents($okvRoot . '/includes/classes/OrderTrail.php');
okv_test_ok(str_contains($trail, 'public static function issueForOrder'), 'the platform can issue a share token without a customer session');
okv_test_ok(str_contains($trail, 'order_trail_share_links'), 'stage tokens reuse the share-link table, never the original token');
okv_test_ok(str_contains($trail, 'hashToken($token)'), 'only the hash is stored, the way every trail token works');

// --- 7. The bell boundary --------------------------------------------------

okv_test_eq('orders.view', AdminNotifications::permissionForEvent('admin_stage_email_failed'), 'the failure alert sits behind the orders gate');
okv_test_eq('/admin/orders.php?order=14', AdminNotifications::hrefFor('order', 14), 'the failure alert links to Order 360');

// --- 8. The preview --------------------------------------------------------

$stageSample = SettingsEditor::sampleTokens('admin_stage_email_failed');
foreach (Notifications::TOKENS['admin_stage_email_failed'] as $token) {
    okv_test_ok(array_key_exists($token, $stageSample), "the preview of the failure alert has a value for $token");
    okv_test_ok(trim((string) $stageSample[$token]) !== '', "the preview value for $token is real words, not a gap");
}
okv_test_eq('Dispatched', $stageSample['stage_label'], 'the preview stage comes from the same helper as the real message');

// The staff vars carry the admin address and no trail, so the button is the
// admin order rather than a customer link on a staff email.
$staffCta = Mail::ctaFromVars(['admin_url' => 'https://okveggies.test/admin/orders.php?order=14']);
okv_test_eq('Open in admin', (string) ($staffCta['label'] ?? ''), 'the failure alert button opens the admin order');

// --- 9. Masked logging -----------------------------------------------------

okv_test_eq('a***@example.com', Mail::maskAddress('ada@example.com'), 'a customer address masks to first letter plus domain');
okv_test_eq('a***@mail.example.co.uk', Mail::maskAddress('ada.obi@mail.example.co.uk'), 'masking keeps a multi-part domain intact');
okv_test_eq('(no address)', Mail::maskAddress(''), 'a missing address masks to a fixed label');
okv_test_eq('(invalid address)', Mail::maskAddress('not-an-email'), 'an invalid address masks to a fixed label');
okv_test_eq('(invalid address)', Mail::maskAddress('a@'), 'an address with no domain masks to a fixed label');
okv_test_ok(!str_contains(Mail::maskAddress('ada.obi@example.com'), 'da.obi'), 'masking keeps nothing identifiable from the name part');

$mail = (string) file_get_contents($okvRoot . '/includes/classes/Mail.php');
okv_test_ok(str_contains($mail, 'self::maskAddress($to)'), 'the send failure log masks the recipient');
okv_test_ok(!str_contains($mail, "'Mail send failed to=' . \$to"), 'the raw recipient never reaches the log line');
okv_test_ok(!str_contains($mail, 'to=$to'), 'no log line interpolates the raw recipient');
okv_test_ok(str_contains($mail, 'addReplyTo($replyTo, $fromName)'), 'replies go to the support inbox');
okv_test_ok(str_contains($mail, "Settings::str('support_email', '')"), 'the reply-to address comes from settings, not a literal');

// --- 10. The migration -----------------------------------------------------

$migration = (string) file_get_contents($okvRoot . '/migrations/060_stage_email_failure_alert.sql');
okv_test_ok(str_contains($migration, 'INSERT IGNORE INTO notification_templates'), 'migration 060 seeds without overwriting an edited copy');
okv_test_ok(str_contains($migration, 'admin_stage_email_failed'), 'migration 060 seeds the failure alert words');
okv_test_ok(!str_contains($migration, 'CREATE TABLE') && !str_contains($migration, 'ALTER TABLE'), 'migration 060 is data only, no DDL');
okv_test_ok(str_contains($migration, 'START TRANSACTION') && str_contains($migration, 'COMMIT'), 'migration 060 runs in one transaction');
okv_test_ok(str_contains($migration, '-- Verification:'), 'migration 060 ends with verification queries');
foreach (['{{customer_name}}', '{{order_number}}', '{{stage_label}}', '{{delivery_day}}', '{{failure_reason}}'] as $token) {
    okv_test_ok(str_contains($migration, $token), "migration 060 names $token");
}
okv_test_ok(!str_contains($migration, "\u{2014}"), 'migration 060 carries no em dash');
okv_test_ok(!str_contains($migration, 'https://'), 'migration 060 stores no order address');
okv_test_ok(!preg_match('/[\\w.+-]+@[\\w-]+\\.[\\w.]+/', $migration), 'migration 060 hardcodes no address');

// --- 11. The controller decides nothing ------------------------------------

$orders = (string) file_get_contents($okvRoot . '/api/v1/orders.php');
okv_test_eq(1, substr_count($orders, 'Notifications::announceStage'), 'the transition path announces through the one stage call');
okv_test_ok(str_contains($orders, 'Notifications::announceStage($orderId, (string) $result[\'status\']'), 'the announced stage is the committed one, not a request value');
okv_test_ok(str_contains($orders, "\$result['code'] === 'transitioned'"), 'a transition that changed nothing announces nothing');
okv_test_ok(!str_contains($orders, 'Notifications::send('), 'the controller never addresses a message itself');
okv_test_ok(!str_contains($orders, 'order_dispatched') && !str_contains($orders, 'order_delivered'), 'the controller names no stage event, so the mapping stays in one place');
okv_test_ok(!str_contains($orders, 'admin_stage_email_failed'), 'the controller does not name the team event either');
okv_test_ok(!str_contains($orders, 'issueForOrder'), 'the controller mints no token, the dispatcher does');
okv_test_ok(!preg_match('/[\\w.+-]+@[\\w-]+\\.[\\w.]+/', $orders), 'no address is hardcoded in the controller');
okv_test_ok(str_contains($orders, "Rbac::requirePermission('orders.status.update')"), 'stage writes are still permission gated');
okv_test_ok(str_contains($orders, "Rbac::requirePermission('notifications.resend')"), 'the resend path is still permission gated');
