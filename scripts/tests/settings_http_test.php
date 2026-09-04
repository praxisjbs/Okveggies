<?php
/**
 * scripts/tests/settings_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Settings screen driven over real HTTP, as an Owner and as a
 * Manager. This is the test that proves the gates hold from the outside rather
 * than only in the class: a Manager is refused a write by the server and not
 * merely by a hidden button, a write without a CSRF token is refused, a write
 * over GET is refused, and a value out of range changes nothing.
 *
 * Needs a migrated database, the app .env, and the site answering on a local
 * server. Start one first:
 *   php -S 127.0.0.1:8123 -t . &
 *   php scripts/tests/settings_http_test.php
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');

$tests = 0; $passed = 0; $fails = [];
function t_ok($cond, string $label): void
{
    global $tests, $passed, $fails;
    $tests++;
    if ($cond) { $passed++; } else { $fails[] = $label; fwrite(STDERR, "  FAIL: $label\n"); }
}
function t_eq($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        $label .= '  (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
    t_ok($expected === $actual, $label);
}

/** One request with its own cookie jar. Returns [code, body]. */
function req(string $jar, string $url, ?array $post = null, string $method = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: fetch', 'Accept: application/json'],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($method !== '') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/** Fetch a page and pull the CSRF token out of its form. */
function csrf_from(string $jar, string $url): string
{
    [, $body] = req($jar, $url);
    return preg_match('/name="okv_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
}

$jarOwner   = tempnam(sys_get_temp_dir(), 'okv-owner-');
$jarManager = tempnam(sys_get_temp_dir(), 'okv-mgr-');
$jarGuest   = tempnam(sys_get_temp_dir(), 'okv-guest-');

// --- Two staff accounts, one of each role -------------------------------------

$hash = password_hash('settings-http-777', PASSWORD_BCRYPT);
foreach ([
    ['owner',   'Ada',  'Owner',   'settings-owner@okveggies.com.ng',   '+2348039990101'],
    ['manager', 'Bola', 'Manager', 'settings-manager@okveggies.com.ng', '+2348039990102'],
] as [$role, $first, $last, $email, $phone]) {
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, :l, :e, :p, :h, :t, :s, NOW())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), status = VALUES(status)',
        [':f' => $first, ':l' => $last, ':e' => $email, ':p' => $phone, ':h' => $hash, ':t' => 'staff', ':s' => 'active']
    );
    Database::run(
        'INSERT IGNORE INTO user_roles (user_id, role_id)
         SELECT u.id, r.id FROM users u CROSS JOIN roles r WHERE u.email = :e AND r.name = :r',
        [':e' => $email, ':r' => $role]
    );
}
Database::run('DELETE FROM rate_limits');

/** Sign in over the same endpoint the browser uses. */
function sign_in(string $jar, string $base, string $email): bool
{
    $token = csrf_from($jar, $base . '/admin/login.php');
    [$code, $body] = req($jar, $base . '/api/v1/auth.php', [
        'action'     => 'login',
        'identifier' => $email,
        'password'   => 'settings-http-777',
        'okv_csrf'   => $token,
    ]);
    return $code === 200 && str_contains($body, '"status":"ok"');
}

t_ok(sign_in($jarOwner, $base, 'settings-owner@okveggies.com.ng'), 'the Owner signs in');
t_ok(sign_in($jarManager, $base, 'settings-manager@okveggies.com.ng'), 'the Manager signs in');

// --- A guest gets nowhere near the screen --------------------------------------

[$code] = req($jarGuest, $base . '/admin/settings.php');
t_ok(in_array($code, [302, 401, 403], true), 'a signed-out visitor is refused the Settings screen');
[$code] = req($jarGuest, $base . '/api/v1/settings.php?action=get_group&group=order');
t_ok(in_array($code, [302, 401, 403], true), 'a signed-out visitor is refused the settings API');

// --- The Owner sees a working screen --------------------------------------------

[$code, $page] = req($jarOwner, $base . '/admin/settings.php');
t_eq(200, $code, 'the Owner opens the Settings screen');
t_ok(str_contains($page, 'Order settings'), 'the order tab is on the page');
t_ok(str_contains($page, 'Site details'), 'the site tab is on the page');
t_ok(str_contains($page, 'Deposit percentage'), 'the deposit field is rendered');
t_ok(str_contains($page, 'data-settings-save'), 'the Owner gets a save button');
t_ok(str_contains($page, 'name="okv_csrf"'), 'the form carries a CSRF token');
t_ok(!str_contains($page, 'scaffolded'), 'the screen no longer says it is scaffolded');
t_ok(str_contains($page, 'admin-settings.js'), 'the screen loads its script');
t_ok(str_contains($page, 'okv_head_meta') === false && str_contains($page, 'site.webmanifest'), 'the brand head block rendered');

// --- Preview says what a save would do, and writes nothing ------------------------

// Snapshot every setting on every tab before this file posts anything, derived
// from the tab definitions rather than typed out, and put it all back at the
// end. Saving a tab over HTTP writes every field on it, and a bool that is not
// submitted saves as off, so without this a run against a real database leaves
// customer self service cancellation and the dispatched cancellation rules
// switched off with nothing to say so.
$settingsBefore = [];
foreach (SettingsEditor::groups() as $group) {
    foreach ($group['fields'] as $key => $field) {
        $type = (string) ($field['value_type'] ?? 'string');
        $settingsBefore[$key] = [$type, match ($type) {
            'int'  => Settings::int($key),
            'bool' => Settings::bool($key),
            default => Settings::str($key),
        }];
    }
}

$before = (int) Database::one("SELECT setting_value v FROM site_settings WHERE setting_key = 'deposit_percentage_default'")['v'];
$token  = csrf_from($jarOwner, $base . '/admin/settings.php');

[$code, $body] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action'   => 'preview',
    'group'    => 'order',
    'okv_csrf' => $token,
    'deposit_percentage_default' => (string) ($before + 5),
]);
$json = json_decode($body, true);
t_eq(200, $code, 'preview answers');
t_ok(!empty($json['needs_confirm']), 'a deposit change is flagged as needing confirmation');
t_ok(isset($json['changed']['deposit_percentage_default']), 'preview names the value that would change');
$after = (int) Database::one("SELECT setting_value v FROM site_settings WHERE setting_key = 'deposit_percentage_default'")['v'];
t_eq($before, $after, 'preview wrote nothing');

// --- The Owner saves --------------------------------------------------------------

$target = $before === 35 ? 40 : 35;
[$code, $body] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action'   => 'save_order_settings',
    'okv_csrf' => $token,
    'rendered_fields' => 'deposit_percentage_default',
    'deposit_percentage_default' => (string) $target,
]);
$json = json_decode($body, true);
t_eq(200, $code, 'the Owner saves the order tab');
t_ok(isset($json['changed']['deposit_percentage_default']), 'the save reports the change');
$stored = (int) Database::one("SELECT setting_value v FROM site_settings WHERE setting_key = 'deposit_percentage_default'")['v'];
t_eq($target, $stored, 'the new deposit is stored');

$audit = Database::one("SELECT COUNT(*) c FROM audit_logs WHERE entity_type = 'site_settings' AND action = 'settings.update'");
t_ok((int) $audit['c'] > 0, 'the save left an audit row behind');

[, $page] = req($jarOwner, $base . '/admin/settings.php');
t_ok(str_contains($page, 'What changed'), 'the history panel is on the screen');
t_ok(str_contains($page, 'Ada Owner'), 'the history names who made the change');

// --- A value out of range changes nothing -------------------------------------------

[$code, $body] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action'   => 'save_order_settings',
    'okv_csrf' => $token,
    'rendered_fields' => 'deposit_percentage_default',
    'deposit_percentage_default' => '150',
]);
$json = json_decode($body, true);
t_eq(422, $code, 'a deposit over 100 is refused');
t_ok(isset($json['errors']['deposit_percentage_default']), 'the refusal names the field');
$stillThere = (int) Database::one("SELECT setting_value v FROM site_settings WHERE setting_key = 'deposit_percentage_default'")['v'];
t_eq($target, $stillThere, 'the refused save changed nothing');

// --- The gates, checked from the outside ---------------------------------------------

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_order_settings',
    'rendered_fields' => 'deposit_percentage_default',
    'deposit_percentage_default' => '10',
]);
t_eq(419, $code, 'a write with no CSRF token is refused');

[$code] = req($jarOwner, $base . '/api/v1/settings.php?action=save_order_settings&deposit_percentage_default=10');
t_eq(405, $code, 'a write over GET is refused');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_site_settings', 'group' => 'nonsense', 'okv_csrf' => $token,
]);
t_ok($code === 200 || $code === 422, 'a bad group name on a real action does not crash the endpoint');

// --- The Manager reads everything and writes nothing -------------------------------------

[$code, $page] = req($jarManager, $base . '/admin/settings.php');
t_eq(200, $code, 'the Manager opens the Settings screen');
t_ok(str_contains($page, 'Deposit percentage'), 'the Manager can read the deposit');
t_ok(str_contains($page, 'The Owner makes the changes'), 'the Manager is told who makes the change');
t_ok(!str_contains($page, 'data-settings-save'), 'the Manager gets no save button');
t_ok(substr_count($page, 'disabled') >= 10, 'every field is disabled for the Manager');

$mgrToken = csrf_from($jarManager, $base . '/admin/settings.php');
[$code] = req($jarManager, $base . '/api/v1/settings.php', [
    'action'   => 'save_order_settings',
    'okv_csrf' => $mgrToken,
    'rendered_fields' => 'deposit_percentage_default',
    'deposit_percentage_default' => '5',
]);
t_eq(403, $code, 'the Manager is refused the write by the server, not by a hidden button');

[$code] = req($jarManager, $base . '/api/v1/settings.php', [
    'action'   => 'save_site_settings',
    'okv_csrf' => $mgrToken,
    'rendered_fields' => 'business_name',
    'business_name' => 'Not OK Veggies',
]);
t_eq(403, $code, 'the Manager is refused the site tab too');

[$code] = req($jarManager, $base . '/api/v1/settings.php', [
    'action' => 'preview', 'group' => 'order', 'okv_csrf' => $mgrToken,
    'deposit_percentage_default' => '5',
]);
t_eq(403, $code, 'the Manager cannot even preview a change');

$unchanged = (int) Database::one("SELECT setting_value v FROM site_settings WHERE setting_key = 'deposit_percentage_default'")['v'];
t_eq($target, $unchanged, 'nothing the Manager sent changed a value');

[$code, $body] = req($jarManager, $base . '/api/v1/settings.php?action=get_group&group=order');
$json = json_decode($body, true);
t_eq(200, $code, 'the Manager can read a tab through the API');
t_eq(false, $json['can_edit'], 'the API tells the Manager they cannot edit');

[$code, $body] = req($jarManager, $base . '/api/v1/settings.php?action=history');
t_eq(200, $code, 'the Manager can read the history');
t_ok(is_array(json_decode($body, true)['changes'] ?? null), 'the history comes back as a list');

// --- An unregistered key cannot be written -----------------------------------------------

$prefixBefore = Settings::str('order_number_prefix');
$token = csrf_from($jarOwner, $base . '/admin/settings.php');
[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action'   => 'save_site_settings',
    'okv_csrf' => $token,
    'rendered_fields' => 'business_name',
    'business_name'       => 'OK Veggies',
    'order_number_prefix' => 'HACK',
    'currency'            => 'USD',
]);
t_eq(200, $code, 'a save carrying unregistered keys still succeeds for the keys it may write');
Settings::flushCache();
t_eq($prefixBefore, Settings::str('order_number_prefix'), 'the order number prefix was not written');
t_eq('NGN', Settings::str('currency'), 'the currency was not written');

// --- Put the deposit back the way it was --------------------------------------------------

// --- M6 notification templates ---------------------------------------------
// The words an automated email sends are content an Owner edits, so the gate
// here is the same shape as the settings gates above: Owner writes, Manager
// reads, and neither can smuggle an em dash into a customer's inbox.
$templateBefore = Database::one('SELECT subject_template, body_template FROM notification_templates WHERE template_key = :k', [':k' => 'order_packed']);

[$code, $body] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'preview_template', 'template_key' => 'order_packed',
    'subject_template' => 'Order {{order_number}} is packed',
    'body_template' => 'Hi {{customer_name}}, it is packed.',
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
$preview = (string) (json_decode($body, true)['preview'] ?? '');
t_eq(200, $code, 'an Owner can preview a template');
t_ok(str_contains($preview, 'OKV26014'), 'the preview fills the tokens with sample values');
t_ok(!str_contains($preview, '{{'), 'the preview leaves no placeholder on screen');
t_ok(str_contains($preview, 'lockup-white-720.png'), 'the preview shows the real branded letterhead');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_template', 'template_key' => 'order_packed',
    'subject_template' => 'Order {{order_number}} is packed and waiting',
    'body_template' => "Hi {{customer_name}}, order {{order_number}} is packed.\n\nDelivery is set for {{delivery_day}}.",
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
t_eq(200, $code, 'an Owner can save the words an email sends');
$stored = Database::one('SELECT subject_template FROM notification_templates WHERE template_key = :k', [':k' => 'order_packed']);
t_ok(str_contains((string) $stored['subject_template'], 'and waiting'), 'the saved words are the ones the next email will use');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_template', 'template_key' => 'order_packed',
    'subject_template' => 'Order {{order_number}} is packed',
    'body_template' => "Hi {{customer_name}} \u{2014} it is packed.",
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
t_eq(422, $code, 'an em dash is refused before it can reach a customer');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_template', 'template_key' => 'not_a_template',
    'subject_template' => 'Hello', 'body_template' => 'Hello',
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
t_eq(422, $code, 'a template key we do not send is refused');

[$code] = req($jarManager, $base . '/api/v1/settings.php', [
    'action' => 'save_template', 'template_key' => 'order_packed',
    'subject_template' => 'Manager edit', 'body_template' => 'Manager edit',
    'okv_csrf' => csrf_from($jarManager, $base . '/admin/settings.php'),
]);
t_eq(403, $code, 'a Manager cannot rewrite what a customer is sent');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'save_template', 'template_key' => 'order_packed',
    'subject_template' => 'No token', 'body_template' => 'No token',
    'okv_csrf' => 'wrong-token',
]);
t_eq(419, $code, 'a template save without a CSRF token is refused');

[$code] = req($jarOwner, $base . '/api/v1/settings.php?action=save_template&template_key=order_packed', null, 'GET');
t_eq(405, $code, 'a template cannot be rewritten by a GET');

Database::run(
    'UPDATE notification_templates SET subject_template = :s, body_template = :b WHERE template_key = :k',
    [':s' => $templateBefore['subject_template'], ':b' => $templateBefore['body_template'], ':k' => 'order_packed']
);

// --- Proving that email actually leaves this server -------------------------
// This one really does post a message through whatever SMTP the environment is
// pointed at, to the signed-in Owner's own address. That is the point of it: it
// is the only way a team can confirm mail works without placing an order.
[$code, $body] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'send_test_email', 'template_key' => 'order_dispatched',
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
t_eq(200, $code, 'an Owner can send themselves a test email');
t_ok(str_contains($body, 'settings-owner@okveggies.com.ng'), 'the test goes to the Owner own address and says so');

$testRow = Database::one(
    'SELECT n.event_type, n.related_type, d.recipient_address, d.status
       FROM notifications n JOIN notification_deliveries d ON d.notification_id = n.id
      WHERE n.related_type = :type AND d.channel = :channel ORDER BY n.id DESC LIMIT 1',
    [':type' => 'settings_test', ':channel' => 'email']
);
t_ok((bool) $testRow, 'a test send is recorded like any other notification');
t_eq('settings-owner@okveggies.com.ng', (string) ($testRow['recipient_address'] ?? ''), 'the recorded address is the Owner own, never one from the request');
t_eq('sent', (string) ($testRow['status'] ?? ''), 'the test email reached the mail server');

[$code] = req($jarManager, $base . '/api/v1/settings.php', [
    'action' => 'send_test_email', 'template_key' => 'order_dispatched',
    'okv_csrf' => csrf_from($jarManager, $base . '/admin/settings.php'),
]);
t_eq(403, $code, 'a Manager cannot send test email from this platform');

[$code] = req($jarGuest, $base . '/api/v1/settings.php', [
    'action' => 'send_test_email', 'template_key' => 'order_dispatched', 'okv_csrf' => 'x',
]);
t_ok($code === 403 || $code === 401, 'a signed-out visitor cannot send test email');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'send_test_email', 'template_key' => 'not_a_template',
    'okv_csrf' => csrf_from($jarOwner, $base . '/admin/settings.php'),
]);
t_eq(422, $code, 'a template we do not send cannot be test sent');

[$code] = req($jarOwner, $base . '/api/v1/settings.php', [
    'action' => 'send_test_email', 'template_key' => 'order_dispatched', 'okv_csrf' => 'wrong-token',
]);
t_eq(419, $code, 'a test send without a CSRF token is refused');

[$code] = req($jarOwner, $base . '/api/v1/settings.php?action=send_test_email&template_key=order_dispatched', null, 'GET');
t_eq(405, $code, 'a test send cannot be triggered by a GET');

Database::run('DELETE FROM notification_deliveries WHERE notification_id IN (SELECT id FROM notifications WHERE related_type = :t)', [':t' => 'settings_test']);
Database::run('DELETE FROM notifications WHERE related_type = :t', [':t' => 'settings_test']);

Settings::set('deposit_percentage_default', $before, 'int', null);

// Put every setting back exactly as it was found.
foreach ($settingsBefore as $key => [$type, $value]) {
    Settings::set($key, $value, $type, null);
}
Settings::flushCache();
foreach (['cancellation_customer_allowed', 'cancellation_after_dispatch_allowed', 'cancellation_dispatched_forfeit_deposit', 'cancellation_deposit_forfeit_after_cutoff'] as $rule) {
    t_eq($settingsBefore[$rule][1], Settings::bool($rule), "$rule is left exactly as this test found it");
}

@unlink($jarOwner); @unlink($jarManager); @unlink($jarGuest);

fwrite(STDOUT, "\n$passed / $tests HTTP assertions passed.\n");
if ($passed !== $tests) {
    fwrite(STDERR, count($fails) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
exit(0);
