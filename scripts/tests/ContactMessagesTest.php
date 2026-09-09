<?php
/** Pure and static checks for the M9 contact submission seam. */

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
okv_test_ok(str_contains($widget, "Settings::str('support_whatsapp_number'"), 'WhatsApp reads the admin-configured number');
okv_test_ok(str_contains($widget, 'submission_token'), 'each contact form carries its one-time submission token');

$script = file_get_contents(dirname(__DIR__, 2) . '/assets/js/support-widget.js');
foreach (['Escape', 'returnFocus', "event.key !== 'Tab'", 'textContent', "method: 'POST'"] as $needle) {
    okv_test_ok(str_contains($script, $needle), "support script includes $needle");
}
okv_test_ok(!str_contains($script, 'innerHTML'), 'support JavaScript never inserts user data as HTML');

$endpoint = file_get_contents(dirname(__DIR__, 2) . '/api/v1/contact.php');
okv_test_ok(str_contains($endpoint, 'okv_is_post()'), 'contact submission refuses non-POST methods');
okv_test_ok(str_contains($endpoint, 'Csrf::validate()'), 'contact submission validates CSRF');
okv_test_ok(str_contains($endpoint, 'Notifications::announceContactMessage'), 'the committed message raises one staff alert');
okv_test_ok(strpos($endpoint, 'ContactMessages::submit') < strpos($endpoint, 'Notifications::announceContactMessage'), 'the message is saved before staff are alerted');

$footer = file_get_contents(dirname(__DIR__, 2) . '/includes/components/shop/footer.php');
okv_test_eq(1, substr_count($footer, 'okv_support_widget();'), 'shared shop chrome renders the widget exactly once');
foreach (['account.php', 'page.php', 'public/auth/activate.php', 'public/auth/password_reset.php'] as $route) {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $route);
    okv_test_eq(1, substr_count($source, 'okv_support_widget();'), "$route renders one widget outside shared shop chrome");
}

$adminPage = file_get_contents(dirname(__DIR__, 2) . '/admin/content.php');
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
