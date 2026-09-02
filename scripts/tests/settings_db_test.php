<?php
/**
 * scripts/tests/settings_db_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Settings save path against a live database. SettingsEditorTest
 * covers the rules with no database; this covers what the rules cannot: that a
 * save writes the value, writes exactly one audit row per value that moved,
 * writes nothing for a value that did not move, and rolls the whole tab back
 * when any part of it fails.
 *
 * Needs a migrated database and the app .env, same as the other *_db_test.php
 * files. Run it directly:
 *   php scripts/tests/settings_db_test.php
 * -----------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

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

$pdo = Database::getInstance()->getConnection();

// A staff user to be the actor, so the audit row has a real name against it.
Database::run(
    "INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
     VALUES ('Settings', 'Tester', 'settings-test@okveggies.com.ng', '+2348039999001', :h, 'staff', 'active')
     ON DUPLICATE KEY UPDATE first_name = VALUES(first_name)",
    [':h' => password_hash('scratch-only', PASSWORD_BCRYPT)]
);
$actor = (int) Database::one(
    'SELECT id FROM users WHERE email = :e',
    [':e' => 'settings-test@okveggies.com.ng']
)['id'];

/** Audit rows for one setting key, newest first. */
function audit_rows_for(string $key): array
{
    return Database::all(
        "SELECT id, action, entity_type, old_values, new_values, actor_user_id, created_at
           FROM audit_logs
          WHERE entity_type = 'site_settings'
            AND JSON_EXTRACT(new_values, :path) IS NOT NULL
       ORDER BY id DESC",
        [':path' => '$."' . $key . '"']
    );
}

// A known starting point for every value this file touches. Without it the
// assertions below depend on whatever a previous test run happened to leave
// behind, and a test that only passes in one order is not a test.
foreach ([
    'deposit_percentage_default'          => ['30', 'int'],
    'delivery_cutoff_time'                => ['16:00', 'string'],
    'delivery_min_lead_days'              => ['1', 'int'],
    'min_order_subunit'                   => ['0', 'int'],
    'pay_on_delivery_requires_activation' => ['true', 'bool'],
    'source_day'                          => ['Tuesday', 'string'],
    'support_whatsapp_number'             => ['2348000000000', 'string'],
] as $key => [$value, $type]) {
    Settings::set($key, $value, $type, null);
}
Settings::flushCache();

// --- A save writes the value and exactly one audit row ------------------------

$before = count(audit_rows_for('deposit_percentage_default'));

$changes = SettingsEditor::save('order', ['deposit_percentage_default' => 45], $actor);

t_eq(1, count($changes), 'a save reports the one value that moved');
t_eq('30%', $changes['deposit_percentage_default']['from'], 'the change reports where the deposit came from');
t_eq('45%', $changes['deposit_percentage_default']['to'], 'the change reports where the deposit went');

$stored = Database::one("SELECT setting_value, value_type, updated_by FROM site_settings WHERE setting_key = 'deposit_percentage_default'");
t_eq('45', $stored['setting_value'], 'the new deposit is in the database');
t_eq('int', $stored['value_type'], 'the value type is kept as int');
t_eq($actor, (int) $stored['updated_by'], 'the row records who changed it');

Settings::flushCache();
t_eq(45, Settings::int('deposit_percentage_default'), 'the app reads the new deposit back');

$rows = audit_rows_for('deposit_percentage_default');
t_eq($before + 1, count($rows), 'exactly one audit row was written');
t_eq('settings.update', $rows[0]['action'], 'the audit row names the action');
t_eq($actor, (int) $rows[0]['actor_user_id'], 'the audit row names the actor');
t_eq('30', json_decode($rows[0]['old_values'], true)['deposit_percentage_default'], 'the audit row keeps the old value');
t_eq('45', json_decode($rows[0]['new_values'], true)['deposit_percentage_default'], 'the audit row keeps the new value');

// --- A value that did not move writes nothing ---------------------------------

$before = count(audit_rows_for('deposit_percentage_default'));
$noChange = SettingsEditor::save('order', ['deposit_percentage_default' => 45], $actor);
t_eq(0, count($noChange), 'saving the same value reports no change');
t_eq($before, count(audit_rows_for('deposit_percentage_default')), 'saving the same value writes no audit row');

// --- A whole tab lands together ------------------------------------------------

$clean = SettingsEditor::validate('order', [
    'deposit_percentage_default'          => '25',
    'delivery_cutoff_time'                => '14:30',
    'delivery_min_lead_days'              => '3',
    'min_order_subunit'                   => '2,500',
    'pay_on_delivery_requires_activation' => '0',
]);
t_eq([], $clean['errors'], 'the whole order tab validates');

$applied = SettingsEditor::save('order', $clean['clean'], $actor);
Settings::flushCache();

t_eq(25, Settings::int('deposit_percentage_default'), 'the deposit saved with the rest of the tab');
t_eq('14:30', Settings::str('delivery_cutoff_time'), 'the cutoff saved with the rest of the tab');
t_eq(3, Settings::int('delivery_min_lead_days'), 'the days of notice saved');
t_eq(250000, Settings::int('min_order_subunit'), 'the smallest order saved in kobo');
t_eq(false, Settings::bool('pay_on_delivery_requires_activation'), 'the pay-on-delivery gate saved as off');
t_eq(5, count($applied), 'all five order settings are reported as changed');

// --- The site tab, and the storefront reading it back --------------------------

$site = SettingsEditor::validate('site', [
    'business_name'           => 'OK Veggies',
    'business_tagline'        => 'Sourced right. Priced right. Delivered right.',
    'source_day'              => 'Thursday',
    'source_regions'          => 'Ogun State, Jos',
    'support_email'           => 'hello@okveggies.com.ng',
    'support_whatsapp_number' => '+234 801 234 5678',
]);
t_eq([], $site['errors'], 'the whole site tab validates');
SettingsEditor::save('site', $site['clean'], $actor);
Settings::flushCache();
t_eq('Thursday', Settings::str('source_day'), 'the sourcing day the storefront reads is updated');
t_eq('2348012345678', Settings::str('support_whatsapp_number'), 'the WhatsApp number is stored as bare digits');

// --- A failing save leaves nothing behind --------------------------------------

Settings::flushCache();
$depositBefore = Settings::int('deposit_percentage_default');
$auditBefore   = (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE entity_type = 'site_settings'")['c'];

// A trigger makes the second write in the tab fail, which is the case that
// matters: the first write already succeeded, so the tab is half applied unless
// the transaction takes it all back.
$pdo->exec("DROP TRIGGER IF EXISTS okv_settings_tripwire");
$pdo->exec(
    "CREATE TRIGGER okv_settings_tripwire BEFORE UPDATE ON site_settings
     FOR EACH ROW
     BEGIN
       IF NEW.setting_key = 'delivery_cutoff_time' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tripwire';
       END IF;
     END"
);
try {
    SettingsEditor::save('order', [
        'deposit_percentage_default' => 55,
        'delivery_cutoff_time'       => '09:15',
    ], $actor);
    t_ok(false, 'a save that cannot be written raises');
} catch (Throwable $e) {
    t_ok(true, 'a save that cannot be written raises');
} finally {
    $pdo->exec("DROP TRIGGER IF EXISTS okv_settings_tripwire");
}

Settings::flushCache();
t_eq($depositBefore, Settings::int('deposit_percentage_default'), 'the deposit rolled back with the failed tab');
t_eq($auditBefore, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE entity_type = 'site_settings'")['c'], 'no audit row survived the rollback');
t_eq('14:30', Settings::str('delivery_cutoff_time'), 'the cutoff is still what it was before the failed tab');

// --- Only a registered key can be written --------------------------------------

$sneaky = SettingsEditor::validate('order', ['order_number_prefix' => 'HACK', 'deposit_percentage_default' => '20']);
t_ok(!isset($sneaky['clean']['order_number_prefix']), 'an unregistered key never reaches the clean set');
SettingsEditor::save('order', $sneaky['clean'], $actor);
Settings::flushCache();
t_eq('OKV', Settings::str('order_number_prefix'), 'the order number prefix is untouched by a save that tried');

// --- The history reader ---------------------------------------------------------

$recent = Audit::recent(SettingsEditor::AUDIT_ENTITY, 5);
t_ok(count($recent) > 0 && count($recent) <= 5, 'the history reader honours its limit');
t_eq('Settings Tester', $recent[0]['actor_name'], 'the history reader names the person who made the change');
t_ok(strtotime((string) $recent[0]['created_at']) > 0, 'every history row carries a readable time');

// --- Put the seeded values back so a re-run starts from the same place ----------

foreach ([
    'deposit_percentage_default' => ['30', 'int'],
    'delivery_cutoff_time'       => ['16:00', 'string'],
    'delivery_min_lead_days'     => ['1', 'int'],
    'min_order_subunit'          => ['0', 'int'],
    'source_day'                 => ['Tuesday', 'string'],
    'support_whatsapp_number'    => ['2348000000000', 'string'],
    'pay_on_delivery_requires_activation' => ['true', 'bool'],
] as $key => [$value, $type]) {
    Settings::set($key, $value, $type, null);
}

fwrite(STDOUT, "\n$passed / $tests database assertions passed.\n");
if ($passed !== $tests) {
    fwrite(STDERR, count($fails) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
exit(0);
