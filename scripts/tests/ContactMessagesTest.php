<?php
/**
 * scripts/tests/ContactMessagesTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Pure validation and static checks over the M9 contact seam: the
 * form, the widget, the controller, the admin screen and the two templates.
 * Nothing here needs a database.
 * -----------------------------------------------------------------------------
 */

$validEmail = ContactMessages::validateFields([
    'name' => 'Ada Obi',
    'email' => 'ADA@example.test',
    'message' => "Please help with my Saturday order.\r\nThank you.",
]);
okv_test_ok($validEmail['ok'], 'a name, email and message are accepted');
okv_test_eq('ada@example.test', $validEmail['email'], 'email is normalised to lowercase');
okv_test_ok(!str_contains($validEmail['message'], "\r"), 'message line endings are normalised');

$validPhone = ContactMessages::validateFields([
    'name' => 'Ada Obi',
    'phone' => '0801 234 5678',
    'message' => 'Please call me about fresh tomatoes.',
]);
okv_test_ok($validPhone['ok'], 'a name, Nigerian phone and message are accepted');
okv_test_eq('+2348012345678', $validPhone['phone'], 'the stored phone uses the shared E.164 normaliser');

foreach ([
    [['email' => 'a@example.test', 'message' => 'Help'], 'name_required'],
    [['name' => 'Ada', 'message' => 'Help'], 'contact_required'],
    [['name' => 'Ada', 'email' => 'wrong', 'message' => 'Help'], 'bad_email'],
    [['name' => 'Ada', 'phone' => '123', 'message' => 'Help'], 'bad_phone'],
    [['name' => 'Ada', 'email' => 'a@example.test'], 'message_required'],
    [['name' => str_repeat('A', 151), 'email' => 'a@example.test', 'message' => 'Help'], 'name_too_long'],
    [['name' => 'Ada', 'email' => 'a@example.test', 'subject' => str_repeat('S', 201), 'message' => 'Help'], 'subject_too_long'],
    [['name' => 'Ada', 'email' => 'a@example.test', 'message' => str_repeat('M', 5001)], 'message_too_long'],
] as [$input, $code]) {
    $result = ContactMessages::validateFields($input);
    okv_test_eq($code, $result['code'], "contact validation returns $code");
}

$widget = file_get_contents(dirname(__DIR__, 2) . '/includes/components/shop/support_widget.php');
okv_test_ok(str_contains($widget, 'Chat on WhatsApp'), 'the shared widget offers WhatsApp');
okv_test_ok(str_contains($widget, 'Contact us'), 'the shared widget offers the contact form');
okv_test_ok(str_contains($widget, 'role="dialog"'), 'the support surface is announced as a dialog');
okv_test_ok(str_contains($widget, 'aria-modal="true"'), 'the support dialog is modal to assistive technology');
okv_test_ok(str_contains($widget, 'href="/contact.php"'), 'the support trigger keeps a no-JavaScript contact route');
okv_test_ok(str_contains($widget, 'novalidate'), 'the form is novalidate, so a person meets our wording and not the browser bubble');
okv_test_ok(!preg_match('/<(input|textarea)[^>]*\srequired/i', $widget), 'no field leans on a native required bubble');
okv_test_ok(!str_contains($widget, 'type="email"'), 'the email field does not raise a native type bubble either');
okv_test_ok(str_contains($widget, 'name="website"'), 'the form carries the honeypot');
okv_test_ok(str_contains($widget, 'name="okv_csrf"') || str_contains($widget, 'Csrf::field()'), 'the form carries a CSRF token');
okv_test_ok(str_contains($widget, 'name="source"'), 'the form records where it was sent from');
okv_test_ok(!str_contains($widget, 'submission_token'), 'no per-page-view session token is minted on every render');

// Both choices PRD 4.1 asks for, and the widget on every storefront route.
okv_test_eq(2, substr_count($widget, 'data-support-close'), 'the sheet can be closed from the header and from the success state');
okv_test_ok(str_contains($widget, 'okv_support_whatsapp_url()'), 'the widget uses the one shared WhatsApp link builder');

$helpers = file_get_contents(dirname(__DIR__, 2) . '/includes/functions/helpers.php');
okv_test_ok(str_contains($helpers, 'function okv_support_whatsapp_url'), 'the WhatsApp link lives in one shared place');
okv_test_ok(str_contains($helpers, "Settings::str('support_whatsapp_number'"), 'and it reads the admin-configured number');

// Contact reaches the footer, which is where PRD 4.1 puts the secondary links.
$nav = file_get_contents(dirname(__DIR__, 2) . '/includes/config/nav.php');
okv_test_ok(preg_match("/'label' => 'Contact',\s*'href' => '\/contact\.php'/", $nav) === 1, 'Contact is a footer link');
okv_test_ok(str_contains($nav, "'count' => 'messages.new'"), 'the Messages nav item carries a counter for staff');

$sidebar = file_get_contents(dirname(__DIR__, 2) . '/includes/components/admin/sidebar.php');
okv_test_ok(str_contains($sidebar, 'ContactMessages::countNew()'), 'the sidebar shows how many messages are unanswered');
okv_test_ok(str_contains($sidebar, "Rbac::can('messages.view')"), 'and it only counts for someone allowed to read them');

$script = file_get_contents(dirname(__DIR__, 2) . '/assets/js/support-widget.js');
foreach (['Escape', 'returnFocus', "event.key !== 'Tab'", 'textContent', "method: 'POST'"] as $needle) {
    okv_test_ok(str_contains($script, $needle), "support script includes $needle");
}
okv_test_ok(!str_contains($script, 'innerHTML'), 'support JavaScript never inserts user data as HTML');

$endpoint = file_get_contents(dirname(__DIR__, 2) . '/api/v1/contact.php');
okv_test_ok(str_contains($endpoint, 'okv_is_post()'), 'contact submission refuses non-POST methods');
okv_test_ok(str_contains($endpoint, 'Csrf::validate()'), 'contact submission validates CSRF');
okv_test_ok(str_contains($endpoint, 'Notifications::announceContactMessage'), 'the committed message raises its notices');
okv_test_ok(strpos($endpoint, 'ContactMessages::submit') < strpos($endpoint, 'Notifications::announceContactMessage'), 'the message is saved before anything is mailed');
okv_test_ok(!str_contains($endpoint, 'Mail::send'), 'the controller goes through the M6 dispatcher, never Mail::send directly');
okv_test_ok(str_contains($endpoint, "okv_redirect('/contact.php?sent=1'"), 'a plain form post is answered with a redirect, so it works with JavaScript off');
okv_test_ok(str_contains($endpoint, '$e->getMessage()') && str_contains($endpoint, 'error_log('), 'an exception is logged');
okv_test_ok(!preg_match('/okv_json\([^)]*\$e->getMessage\(\)/', $endpoint), 'and no exception message is ever returned to the sender');

// Both templates, and both wired through the dispatcher.
$notifications = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Notifications.php');
foreach (['admin_new_contact', 'contact_acknowledgement'] as $templateKey) {
    okv_test_ok(str_contains($notifications, "'" . $templateKey . "' => ['template' => '" . $templateKey . "'"), "$templateKey is a registered notification event");
    okv_test_ok(isset(Notifications::TOKENS[$templateKey]), "$templateKey declares the tokens its words may use");
}
$migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/040_contact_messages.sql');
foreach (['admin_new_contact', 'contact_acknowledgement'] as $templateKey) {
    okv_test_ok(str_contains($migration, "('" . $templateKey . "', 'email'"), "$templateKey is seeded by the M9 migration");
}
okv_test_ok(str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'the template seed is idempotent');
okv_test_ok(str_contains($migration, 'START TRANSACTION') && str_contains($migration, 'COMMIT;'), 'the migration wraps its writes in a transaction');
okv_test_ok(str_contains($migration, '-- Verification:'), 'the migration ends with verification queries');

// The acknowledgement goes to an address nobody has verified, so it must not
// carry anything the sender typed back out to that address.
foreach (Notifications::TOKENS['contact_acknowledgement'] as $ackToken) {
    okv_test_ok(!in_array($ackToken, ['message_preview', 'subject', 'contact_method'], true), "the acknowledgement never repeats $ackToken back to an unverified address");
}

$footer = file_get_contents(dirname(__DIR__, 2) . '/includes/components/shop/footer.php');
okv_test_eq(1, substr_count($footer, 'okv_support_widget();'), 'shared shop chrome renders the widget exactly once');
foreach (['account.php', 'page.php', 'public/auth/activate.php', 'public/auth/password_reset.php'] as $route) {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $route);
    okv_test_eq(1, substr_count($source, 'okv_support_widget();'), "$route renders one widget outside shared shop chrome");
}

$adminPage = file_get_contents(dirname(__DIR__, 2) . '/admin/content.php');
okv_test_ok(str_contains($adminPage, "'page-copy'"), 'the screen keeps a page-copy tab for M12');
okv_test_ok(str_contains($adminPage, 'milestone M12'), 'and says plainly that M12 owns it');
okv_test_ok(str_contains($adminPage, "\$okv_admin_title = 'Content and Messages'"), 'the screen is titled as the nav and PRD 4.3 name it');
okv_test_ok(!preg_match('/UPDATE\s+content_pages/i', $adminPage), 'M9 leaves the page-copy half untouched');
okv_test_ok(str_contains($adminPage, "Rbac::requirePermission('messages.view')"), 'every message workspace read requires messages.view');
okv_test_ok(!str_contains($adminPage, "content.view"), 'the message workspace never substitutes content.view');
okv_test_ok(str_contains($adminPage, 'okv_pagination'), 'the message list uses the shared accessible pagination');
okv_test_ok(str_contains($adminPage, 'okv_e($selected[\'message\'])'), 'message content is escaped when rendered');
okv_test_ok(str_contains($adminPage, 'The website does not send or record a reply.'), 'response links do not imply the website sent anything');
okv_test_ok(!preg_match('/DELETE\s+FROM\s+contact_messages/i', $adminPage), 'the admin page has no hard-delete path');

okv_test_ok(str_contains($endpoint, "Rbac::requirePermission('messages.handle')"), 'every admin message write requires messages.handle');
okv_test_ok(!str_contains($endpoint, "content.edit"), 'message writes never substitute content.edit');
foreach (['save_note', 'handle', 'reopen'] as $action) {
    okv_test_ok(str_contains($endpoint, "'$action'"), "$action is an explicit contact controller action");
}
okv_test_ok(!preg_match('/DELETE\s+FROM\s+contact_messages/i', $endpoint), 'the contact controller has no hard-delete action');
