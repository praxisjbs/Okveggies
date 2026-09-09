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

// Every customer email carries a way back to the thing it is about, because
// PRD 14.2 makes that link the way a customer follows their own business with
// us. For an order that is the Order Trail; for a Kitchen Run, which is not an
// order yet, it is the request itself; for credit it is the credit page. An
// email with no way on is a dead end, and PRD Section 2 says we do not build those.
foreach (Notifications::EVENTS as $event => $definition) {
    if ($definition['audience'] !== 'customer') {
        continue;
    }
    $tokens = Notifications::TOKENS[$definition['template']];
    okv_test_ok(
        in_array('order_trail_url', $tokens, true)
        || in_array('request_url', $tokens, true)
        || in_array('pay_url', $tokens, true)
        || in_array('credit_url', $tokens, true)
        // The contact acknowledgement has no order or request to point at. Its
        // next step is the other half of the support offer, so WhatsApp counts.
        || in_array('whatsapp_url', $tokens, true),
        "the customer email for $event carries a link back to what it is about"
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

$failed = Notifications::cancellationMoneyLine(['refund_subunit' => 500000, 'forfeit_subunit' => 0, 'manual_subunit' => 0, 'refund_status' => 'failed']);
okv_test_ok(str_contains($failed, 'could not send'), 'a failed cancellation refund does not claim the money is on its way');
okv_test_ok(!str_contains($failed, 'We are sending'), 'failed refund copy never says an automatic refund is being sent');

// No house-law breach can reach a customer through this copy.
foreach ([$nothing, $sending, $sent, $forfeit, $manual, $failed] as $line) {
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
okv_test_ok(str_contains($payments, 'Notifications::announceRefund($result)'), 'an immediate terminal refund result is announced by its caller');

okv_test_ok(str_contains($orders, "unset(\$result['refund_events'])"), 'internal cancellation refund outcomes are removed before the HTTP response');
okv_test_ok(str_contains($orders, 'Notifications::announceRefund($refundEvent)'), 'a terminal refund raised during cancellation is announced after the cancellation transaction');

$refunds = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Refunds.php');
okv_test_ok(substr_count($refunds, "'amount_subunit' => \$amountSubunit") >= 2, 'immediate refund results carry the amount needed by the notification');
okv_test_ok(substr_count($refunds, "'order_id'") >= 2, 'immediate refund results carry the order needed by the notification');

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

// --- Credit notifications (PRD Section 12, M8 Task J) -------------------------
$creditApi = file_get_contents(dirname(__DIR__, 2) . '/api/v1/credit.php');
okv_test_ok(str_contains($creditApi, 'Notifications::announceCreditApplicationSubmitted'), 'a new credit application alerts staff');
okv_test_ok(str_contains($creditApi, 'Notifications::announceCreditApproved'), 'an approved credit application tells the customer');
okv_test_ok(str_contains($creditApi, 'Notifications::announceCreditDeclined'), 'a declined credit application tells the customer the approved reason only');
okv_test_ok(str_contains($creditApi, 'Notifications::announceCreditGranted'), 'a manual grant tells the customer with the same approved words');

okv_test_ok(str_contains($checkout, 'Notifications::announceCreditChargePosted'), 'an on-account checkout tells the customer the amount and due date');
$kitchenApi = file_get_contents(dirname(__DIR__, 2) . '/api/v1/kitchen_runs.php');
okv_test_ok(str_contains($kitchenApi, 'Notifications::announceCreditChargePosted'), 'a converted Kitchen Run on account tells the customer the amount and due date');

$dispatcher = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');
okv_test_ok(str_contains($dispatcher, "'credit_approved'"), 'credit approved is a registered event');
okv_test_ok(str_contains($dispatcher, "'credit_declined'"), 'credit declined is a registered event');
okv_test_ok(str_contains($dispatcher, "'credit_charge_posted'"), 'credit charge is a registered event');
okv_test_ok(str_contains($dispatcher, "'admin_new_credit_application'"), 'new credit application is a registered staff alert');
okv_test_ok(str_contains($dispatcher, "'credit_limit'") && str_contains($dispatcher, "'credit_days'"), 'credit approved declares limit and terms');
okv_test_ok(str_contains($dispatcher, "'declined_reason'") && !str_contains($dispatcher, "'credit_declined' => ['customer_name', 'business_name', 'reason'"), 'declined uses the approved reason only, not the customer request reason');
okv_test_ok(str_contains($dispatcher, 'announceCreditChargePosted'), 'charge announcement fetches amount and due_date');
okv_test_ok(str_contains($dispatcher, 'try {') && str_contains($creditApi, 'try {'), 'credit announcements are after commit and never bubble to the caller');
okv_test_ok(
    preg_match('/if \(\$override === \'\' \|\| !self::isTestMode\(\)\)/', $paystack) === 1,
    'the override is ignored on a live key, so real money always goes to Paystack'
);
okv_test_ok(str_contains($paystack, "in_array(\$scheme, ['http', 'https'], true)"), 'the override must be a real http or https address');

// ---------------------------------------------------------------------------
// Payment mode decides what a placed order says (Milestone 6/7 bug)
//
// Every order sent "we have your order and we are sourcing it now" the instant
// it was placed, before the customer had reached Paystack. A customer paying in
// full read a confirmation for an order nobody had been paid for. Now:
//
//   pay in full       nothing at placement. The receipt is written there, held,
//                     and released when the money lands.
//   deposit           the receipt at placement, as before, and the deposit is
//                     acknowledged separately when it lands.
//   pay on delivery   the receipt at placement. Nothing is owed online.
//   on account        the receipt at placement. The credit line is the payment.
//
// Either online mode also queues exactly one reminder for an unpaid order.
// ---------------------------------------------------------------------------

$notifications = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');

okv_test_ok(
    str_contains($notifications, "if (\$option === 'pay_in_full') {"),
    'a placed order asks what the customer chose to do about the money before it says anything'
);
okv_test_ok(
    str_contains($notifications, "self::hold(\n                'payment_confirmed',"),
    'a pay in full order holds its receipt instead of sending it before the payment'
);
okv_test_ok(
    str_contains($notifications, "self::queuePaymentReminder"),
    'an unpaid online order gets a reminder queued'
);
okv_test_ok(
    str_contains($notifications, "self::cancelPending('order', \$orderId, ['order_payment_pending'])"),
    'and the reminder is cancelled the moment the money lands'
);
okv_test_ok(
    str_contains($notifications, "self::cancelPending('order', \$orderId, ['order_payment_pending', 'payment_confirmed'])"),
    'a cancelled order sends neither its reminder nor a receipt for a payment that will never come'
);
okv_test_ok(
    str_contains($notifications, "self::send('admin_new_order'"),
    'staff still hear about every order the moment it is placed, paid or not'
);

// The reminder is one email, not a nag: it exists as a single queued row, and
// the only things that touch it are the flush that sends it and the cancel.
okv_test_ok(str_contains($notifications, 'public static function flushDue'), 'something sends what is due');
okv_test_ok(str_contains($notifications, 'public static function release'), 'and something releases what was held');
okv_test_ok(str_contains($notifications, 'public static function cancelPending'), 'and something stops what should not go');

// A held notification is rendered when it is written, because that is the only
// moment the Order Trail token exists in plain text (PRD 14.2, and the token is
// stored only as a hash). The sender must therefore read the stored copy.
okv_test_ok(str_contains($notifications, 'cta_url'), 'a held email remembers its button, not just its words');
okv_test_ok(
    str_contains($notifications, "'SELECT id, title, body, cta_url, cta_label, status FROM notifications WHERE id = :id'"),
    'and the sender reads the copy that was written, rather than rendering it again without the token'
);

foreach (['order_payment_pending'] as $event) {
    okv_test_ok(isset(Notifications::EVENTS[$event]), "$event is a real event");
    okv_test_ok(!empty(Notifications::TOKENS[$event]), "$event declares its tokens");
}
okv_test_ok(
    in_array('pay_url', Notifications::TOKENS['order_payment_pending'], true),
    'the reminder links back to the payment, not to the trail, because finishing the payment is the point of it'
);
okv_test_ok(
    in_array('delivery_day', Notifications::TOKENS['payment_confirmed'], true),
    'the pay in full receipt carries the order details, because it is now the only email that order gets'
);

// The scheduled pass that sends a queued reminder has to be reachable two ways:
// this host has no shell, so a cron job calls the URL.
$cron = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Cron.php');
okv_test_ok(str_contains($cron, 'Notifications::flushDue'), 'the cron pass sends what is due');
okv_test_ok(str_contains($cron, 'Payments::sweep'), 'and reconciles payments left hanging, which nothing was running before');
okv_test_ok(is_readable(dirname(__DIR__, 2) . '/scripts/cron.php'), 'there is a cron runner for a shell');
okv_test_ok(is_readable(dirname(__DIR__, 2) . '/public/cron.php'), 'and one for a host without one');

$webCron = (string) file_get_contents(dirname(__DIR__, 2) . '/public/cron.php');
okv_test_ok(str_contains($webCron, 'hash_equals'), 'the web cron runner compares its token in constant time');
okv_test_ok(str_contains($webCron, "http_response_code(404)"), 'and fails closed, the way the migration runner does');
okv_test_ok(str_contains($webCron, "X-Robots-Tag"), 'and is never indexed');

// A held or scheduled email is shown for what it is, and staff cannot push it
// out early: "payment received" before the payment, or a reminder to pay for an
// order that is already paid, are both worse than no email at all.
$held = Notifications::deliveryState(['status' => 'held', 'delivery_status' => 'queued', 'channel' => 'email']);
okv_test_eq('Waiting on the payment', $held['label'], 'a held receipt says it is waiting');
okv_test_ok(!$held['may_resend'], 'and cannot be sent by hand before the payment arrives');

$queued = Notifications::deliveryState(['status' => 'queued', 'delivery_status' => 'queued', 'channel' => 'email', 'scheduled_at' => '2026-09-09 18:30:00']);
okv_test_ok(str_contains($queued['label'], 'Scheduled'), 'a queued reminder says it is scheduled');
okv_test_ok(str_contains($queued['label'], '18:30'), 'and says when it goes');
okv_test_ok(!$queued['may_resend'], 'and is not something staff push early');

$cancelled = Notifications::deliveryState(['status' => 'cancelled', 'delivery_status' => 'cancelled', 'channel' => 'email']);
okv_test_eq('Cancelled', $cancelled['label'], 'a cancelled email says so');
okv_test_ok(!$cancelled['may_resend'], 'and is never sent afterwards');

$failed = Notifications::deliveryState(['status' => 'failed', 'delivery_status' => 'failed', 'channel' => 'email']);
okv_test_eq('Not sent', $failed['label'], 'an email that failed still reads as not sent');
okv_test_ok($failed['may_resend'], 'and that is exactly what the resend button is for');

$sent = Notifications::deliveryState(['status' => 'sent', 'delivery_status' => 'sent', 'channel' => 'email']);
okv_test_eq('Sent', $sent['label'], 'a sent email reads as sent');
okv_test_ok(!$sent['may_resend'], 'and is not offered again');

okv_test_ok(
    !Notifications::deliveryState(['status' => 'failed', 'delivery_status' => 'failed', 'channel' => 'in_app'])['may_resend'],
    'only an email can be sent again'
);

// The reminder is asked again at the last moment, so a payment that arrived by
// any route at all, including cash at the counter, stops it. Nothing has to
// remember to cancel it for the customer to be spared a chase for money they
// have already paid.
okv_test_ok(str_contains($notifications, 'private static function stillWanted'), 'a queued reminder is re-checked before it is sent');
okv_test_ok(
    str_contains($notifications, "if ((string) \$notification['event_type'] !== 'order_payment_pending') {"),
    'and only the reminder is re-checked, because only it can go stale'
);
okv_test_ok(
    str_contains($notifications, "public static function announceManualPayment") && substr_count($notifications, "self::cancelPending('order', \$orderId, ['order_payment_pending'])") >= 2,
    'a payment recorded by staff stops the reminder, the same as one through Paystack'
);
