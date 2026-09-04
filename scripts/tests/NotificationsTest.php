<?php
/**
 * Pure checks on the M6 notification dispatcher: the event catalogue, the money
 * sentence a cancelled customer reads, token filling, and the controller wiring
 * that makes every one of these actually fire.
 */

// Every event names a template, and every template declares its tokens. A new
// event added without tokens would render an email full of empty gaps.
foreach (Notifications::EVENTS as $event => $definition) {
    okv_test_ok(isset($definition['template']), "$event names a template");
    okv_test_ok(in_array($definition['audience'], ['customer', 'staff'], true), "$event says who it is for");
    okv_test_ok(!empty(Notifications::TOKENS[$definition['template']]), "$event declares the tokens it accepts");
}

// Every customer email carries the trail link, because PRD 14.2 makes that link
// the way a customer follows their order.
foreach (Notifications::EVENTS as $event => $definition) {
    if ($definition['audience'] !== 'customer') {
        continue;
    }
    okv_test_ok(
        in_array('order_trail_url', Notifications::TOKENS[$definition['template']], true),
        "the customer email for $event carries the Order Trail link"
    );
}

// Every stage a staff member can move an order to announces itself. A stage
// that quietly changed nothing for the customer is the bug this catches.
foreach (['confirmed', 'packed', 'dispatched', 'delivered'] as $stage) {
    okv_test_ok(isset(Notifications::STAGE_EVENTS[$stage]), "the $stage stage tells the customer");
    okv_test_ok(
        isset(Notifications::EVENTS[Notifications::STAGE_EVENTS[$stage]]),
        "the $stage announcement is a real event"
    );
}
okv_test_ok(!isset(Notifications::STAGE_EVENTS['cancelled']), 'cancellation is announced on its own path, with its money outcome');

// The money sentence. This is the paragraph a cancelled customer reads, so each
// branch is checked rather than assumed.
$nothing = Notifications::cancellationMoneyLine(['refund_subunit' => 0, 'forfeit_subunit' => 0, 'manual_subunit' => 0, 'refund_status' => 'not_required']);
okv_test_ok(str_contains($nothing, 'Nothing had been paid'), 'an unpaid cancellation says there is no refund to wait for');

$sending = Notifications::cancellationMoneyLine(['refund_subunit' => 500000, 'forfeit_subunit' => 0, 'manual_subunit' => 0, 'refund_status' => 'pending']);
okv_test_ok(str_contains($sending, 'We are sending'), 'a raised refund is described as on its way, not as arrived');
okv_test_ok(!str_contains($sending, 'have sent'), 'a pending refund never claims the money has already gone');

$sent = Notifications::cancellationMoneyLine(['refund_subunit' => 500000, 'forfeit_subunit' => 0, 'manual_subunit' => 0, 'refund_status' => 'processed']);
okv_test_ok(str_contains($sent, 'have sent'), 'a confirmed refund says the money has gone');

$forfeit = Notifications::cancellationMoneyLine(['refund_subunit' => 0, 'forfeit_subunit' => 200000, 'manual_subunit' => 0, 'refund_status' => 'not_required']);
okv_test_ok(str_contains($forfeit, 'kept'), 'a forfeited deposit is stated plainly rather than left out');
okv_test_ok(str_contains($forfeit, 'farmer'), 'the reason the deposit is kept is given');

$manual = Notifications::cancellationMoneyLine(['refund_subunit' => 300000, 'forfeit_subunit' => 0, 'manual_subunit' => 300000, 'refund_status' => 'pending_manual']);
okv_test_ok(str_contains($manual, 'by hand'), 'money paid outside the gateway is said to need a person');

// No house-law breach can reach a customer through this copy.
foreach ([$nothing, $sending, $sent, $forfeit, $manual] as $line) {
    okv_test_ok(!str_contains($line, "\u{2014}"), 'the cancellation money line carries no em dash');
}

// Token filling, the same routine the preview and the send both use.
okv_test_eq(
    'Order OKV26001 is packed',
    SettingsEditor::fillTemplate('Order {{order_number}} is packed', ['order_number' => 'OKV26001']),
    'a known token is replaced'
);
okv_test_eq(
    'Hello',
    SettingsEditor::fillTemplate('Hello {{unknown_token}}', ['order_number' => 'X']),
    'an unknown token leaves no {{placeholder}} behind'
);
okv_test_ok(
    !str_contains(SettingsEditor::fillTemplate('{{a}} {{b}} {{c}}', []), '{{'),
    'nothing that looks like a token survives a render'
);

// The preview offers a value for every token a template declares, so an Owner
// never previews an email with holes in it.
foreach (Notifications::TOKENS as $key => $tokens) {
    $sample = SettingsEditor::sampleTokens($key);
    foreach ($tokens as $token) {
        okv_test_ok(array_key_exists($token, $sample), "the preview of $key has a value for $token");
    }
}

// The wiring. Each of these is a place M5 or M6 does real work and then has to
// tell somebody; a silent one is the failure this milestone was built to end.
$checkout = file_get_contents(dirname(__DIR__, 2) . '/api/v1/checkout.php');
okv_test_ok(str_contains($checkout, 'Notifications::announceOrderPlaced'), 'a placed order is announced');

$orders = file_get_contents(dirname(__DIR__, 2) . '/api/v1/orders.php');
okv_test_ok(str_contains($orders, 'Notifications::announceStage'), 'every lifecycle stage is announced');
okv_test_ok(str_contains($orders, 'Notifications::announceCancellation'), 'a cancellation is announced');
okv_test_ok(str_contains($orders, "Rbac::requirePermission('notifications.resend')"), 'resending an email is permission gated');

$webhook = file_get_contents(dirname(__DIR__, 2) . '/api/v1/paystack_webhook.php');
okv_test_ok(str_contains($webhook, 'Notifications::announceCharge'), 'a verified charge is announced from the webhook');
okv_test_ok(str_contains($webhook, 'Notifications::announceRefund'), 'a refund result is announced from the webhook');

$callback = file_get_contents(dirname(__DIR__, 2) . '/public/payment/callback.php');
okv_test_ok(str_contains($callback, 'Notifications::announceCharge'), 'a verified charge is announced from the callback');

$payments = file_get_contents(dirname(__DIR__, 2) . '/api/v1/payments.php');
okv_test_ok(str_contains($payments, 'Notifications::announceManualPayment'), 'a manually recorded payment is announced');

$settingsApi = file_get_contents(dirname(__DIR__, 2) . '/api/v1/settings.php');
okv_test_ok(str_contains($settingsApi, "settings_guard_write('settings.notifications.edit')"), 'editing the words is Owner gated');
okv_test_ok(str_contains($settingsApi, 'em_dash'), 'the template editor refuses an em dash before it can ship');

$dispatcher = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');
okv_test_ok(str_contains($dispatcher, 'catch (Throwable $e)'), 'a failed send is caught rather than thrown at the caller');
okv_test_ok(str_contains($dispatcher, 'notification_deliveries'), 'every send records a delivery row');

// --- Proving email works, safely ----------------------------------------------
// The test send exists so a team can confirm mail leaves the server without
// placing an order. Its safety property is that the address is read from the
// signed-in staff row, never from the request: a "send to this address" box
// would turn this platform into a relay for anyone holding a staff session.
$dispatcher = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');
okv_test_ok(str_contains($dispatcher, 'public static function sendTest'), 'there is a way to prove email without placing an order');
okv_test_ok(
    str_contains($dispatcher, 'FROM users WHERE id = :id'),
    'the test send reads its address from the staff row'
);
okv_test_ok(
    !preg_match("/sendTest\\([^)]*\\\$address|sendTest\\([^)]*email/i", $dispatcher),
    'the test send takes no address from its caller'
);

$settingsApi = file_get_contents(dirname(__DIR__, 2) . '/api/v1/settings.php');
okv_test_ok(str_contains($settingsApi, "case 'send_test_email'"), 'the test send has a controller action');
okv_test_ok(
    preg_match("/case 'send_test_email':\\s*\\n\\s*settings_guard_write\\('settings.notifications.edit'\\)/", $settingsApi) === 1,
    'the test send is permission, POST and CSRF gated before anything else happens'
);
okv_test_ok(
    !str_contains($settingsApi, "okv_input('to'") && !str_contains($settingsApi, "okv_input('recipient'"),
    'the controller never reads a recipient from the request'
);

// The health check has to be able to say whether email is configured at all,
// because "it did not arrive" and "it was never configured" are different jobs.
$health = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/PaymentHealth.php');
foreach (['SMTP host is set', 'SMTP user is set', 'SMTP password is set', 'Every event has words to send'] as $check) {
    okv_test_ok(str_contains($health, $check), 'the health check reports: ' . $check);
}
okv_test_ok(str_contains($health, 'PHPMailer is installed'), 'the health check notices a missing mailer rather than silently logging every email');

// --- The stand-in gateway guard -----------------------------------------------
// The refund path can only be proved end to end against a stand-in. The guard
// on that override is what stops it being a way to redirect real money.
$paystack = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Paystack.php');
okv_test_ok(str_contains($paystack, 'PAYSTACK_BASE_URL'), 'the API base can be pointed at a stand-in for testing');
okv_test_ok(
    preg_match('/if \(\$override === \'\' \|\| !self::isTestMode\(\)\)/', $paystack) === 1,
    'the override is ignored on a live key, so real money always goes to Paystack'
);
okv_test_ok(str_contains($paystack, "in_array(\$scheme, ['http', 'https'], true)"), 'the override must be a real http or https address');
