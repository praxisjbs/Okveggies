<?php
/**
 * scripts/tests/SettingsEditorTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Settings screen's validation rules. These guard the deposit
 * percentage, the cutoff and the smallest order we accept, which are money and
 * fulfilment values, so CLAUDE.md requires them to be unit tested.
 *
 * Everything here is pure: no database, no session. The save path is exercised
 * against a live database by the role smoke test.
 * -----------------------------------------------------------------------------
 */

$registry = SettingsEditor::groups();
$order    = $registry['order']['fields'];
$site     = $registry['site']['fields'];

// --- The registry is the allowlist -------------------------------------------

okv_test_ok(isset($registry['order'], $registry['site']), 'the registry has an order tab and a site tab');
okv_test_eq('settings.order.edit', $registry['order']['permission'], 'the order tab is gated on settings.order.edit');
okv_test_eq('settings.edit', $registry['site']['permission'], 'the site tab is gated on settings.edit');

okv_test_ok(SettingsEditor::isEditable('order', 'deposit_percentage_default'), 'the deposit percentage is editable');
okv_test_ok(!SettingsEditor::isEditable('order', 'currency'), 'currency is not editable on the order tab');
okv_test_ok(!SettingsEditor::isEditable('site', 'order_number_prefix'), 'the order number prefix is not editable on the site tab');
okv_test_ok(!SettingsEditor::isEditable('site', 'deposit_percentage_default'), 'a key cannot be written through the wrong tab');
okv_test_ok(!SettingsEditor::isEditable('order', 'anything_a_caller_invents'), 'an unregistered key is never editable');
okv_test_ok(SettingsEditor::group('made_up') === null, 'an unknown tab resolves to nothing');

okv_test_ok(array_key_exists('currency', SettingsEditor::fixed()), 'currency is listed as set at launch');
okv_test_ok(array_key_exists('order_number_prefix', SettingsEditor::fixed()), 'the order number prefix is listed as set at launch');

// Every order setting moves money or the ability to order, so every one confirms.
foreach ($order as $key => $field) {
    okv_test_ok(!empty($field['confirm']), 'the order setting ' . $key . ' needs the confirmation step');
}

// --- Deposit percentage -------------------------------------------------------

$deposit = $order['deposit_percentage_default'];

okv_test_eq(30, SettingsEditor::validateField($deposit, '30')['value'], 'a deposit of 30 is accepted');
okv_test_eq(0, SettingsEditor::validateField($deposit, '0')['value'], 'a deposit of 0 is accepted');
okv_test_eq(100, SettingsEditor::validateField($deposit, '100')['value'], 'a deposit of 100 is accepted');
okv_test_ok(SettingsEditor::validateField($deposit, '101')['error'] !== null, 'a deposit over 100 is refused');
okv_test_ok(SettingsEditor::validateField($deposit, '-1')['error'] !== null, 'a negative deposit is refused');
okv_test_ok(SettingsEditor::validateField($deposit, '30.5')['error'] !== null, 'a fractional deposit is refused');
okv_test_ok(SettingsEditor::validateField($deposit, '')['error'] !== null, 'an empty deposit is refused');
okv_test_ok(SettingsEditor::validateField($deposit, 'thirty')['error'] !== null, 'a deposit in words is refused');
okv_test_eq(30, SettingsEditor::validateField($deposit, ' 30 ')['value'], 'a deposit with stray spaces still reads');

// --- Cutoff time --------------------------------------------------------------

$cutoff = $order['delivery_cutoff_time'];

okv_test_eq('16:00', SettingsEditor::validateField($cutoff, '16:00')['value'], 'a cutoff of 16:00 is accepted');
okv_test_eq('00:00', SettingsEditor::validateField($cutoff, '00:00')['value'], 'midnight is accepted');
okv_test_eq('23:59', SettingsEditor::validateField($cutoff, '23:59')['value'], 'one minute to midnight is accepted');
okv_test_ok(SettingsEditor::validateField($cutoff, '24:00')['error'] !== null, 'an hour of 24 is refused');
okv_test_ok(SettingsEditor::validateField($cutoff, '16:60')['error'] !== null, 'a minute of 60 is refused');
okv_test_ok(SettingsEditor::validateField($cutoff, '4pm')['error'] !== null, 'a 12-hour clock time is refused');
okv_test_ok(SettingsEditor::validateField($cutoff, '')['error'] !== null, 'an empty cutoff is refused');

// --- Days of notice -----------------------------------------------------------

$lead = $order['delivery_min_lead_days'];

okv_test_eq(1, SettingsEditor::validateField($lead, '1')['value'], 'one day of notice is accepted');
okv_test_eq(0, SettingsEditor::validateField($lead, '0')['value'], 'same-day is accepted');
okv_test_eq(14, SettingsEditor::validateField($lead, '14')['value'], 'a fortnight of notice is accepted');
okv_test_ok(SettingsEditor::validateField($lead, '15')['error'] !== null, 'more than a fortnight is refused');

// --- Smallest order, typed in naira and stored in kobo -------------------------

$minOrder = $order['min_order_subunit'];

okv_test_eq(500000, SettingsEditor::validateField($minOrder, '5000')['value'], 'N5,000 is stored as 500000 kobo');
okv_test_eq(500000, SettingsEditor::validateField($minOrder, '5,000')['value'], 'a comma in the amount is read, not refused');
okv_test_eq(0, SettingsEditor::validateField($minOrder, '0')['value'], 'zero means accept any basket');
okv_test_eq(0, SettingsEditor::validateField($minOrder, '')['value'], 'an empty amount reads as zero');
okv_test_eq(250050, SettingsEditor::validateField($minOrder, '2500.50')['value'], 'kobo in the input survives the conversion');
okv_test_ok(SettingsEditor::validateField($minOrder, '-100')['error'] !== null, 'a negative smallest order is refused');
okv_test_ok(SettingsEditor::validateField($minOrder, '2000000')['error'] !== null, 'an amount over the cap is refused');
okv_test_ok(SettingsEditor::validateField($minOrder, '5000.999')['error'] !== null, 'more than two decimal places is refused');
okv_test_eq(OKV_SETTINGS_MAX_MIN_ORDER_SUBUNIT, SettingsEditor::validateField($minOrder, '1000000')['value'], 'the cap itself is accepted');

// --- The pay-on-delivery gate --------------------------------------------------

$gate = $order['pay_on_delivery_requires_activation'];

okv_test_eq(true, SettingsEditor::validateField($gate, '1')['value'], 'a ticked box reads as true');
okv_test_eq(false, SettingsEditor::validateField($gate, '0')['value'], 'an unticked box reads as false');
okv_test_eq(true, SettingsEditor::validateField($gate, 'true')['value'], 'the word true reads as true');
okv_test_eq(false, SettingsEditor::validateField($gate, '')['value'], 'an absent value reads as false, never as true');

// --- Site details --------------------------------------------------------------

$name  = $site['business_name'];
$email = $site['support_email'];
$phone = $site['support_whatsapp_number'];
$day   = $site['source_day'];

okv_test_eq('OK Veggies', SettingsEditor::validateField($name, 'OK Veggies')['value'], 'the business name is accepted');
okv_test_ok(SettingsEditor::validateField($name, '')['error'] !== null, 'the business name cannot be emptied');
okv_test_ok(SettingsEditor::validateField($name, str_repeat('a', 121))['error'] !== null, 'a business name over 120 characters is refused');
okv_test_eq('OK Veggies', SettingsEditor::validateField($name, "OK\nVeggies")['value'], 'a pasted newline becomes a space');

okv_test_eq('hello@okveggies.com.ng', SettingsEditor::validateField($email, 'hello@okveggies.com.ng')['value'], 'a working support email is accepted');
okv_test_ok(SettingsEditor::validateField($email, 'hello@')['error'] !== null, 'a half-typed email is refused');
okv_test_ok(SettingsEditor::validateField($email, '')['error'] !== null, 'the support email cannot be emptied');

okv_test_eq('2348000000000', SettingsEditor::validateField($phone, '2348000000000')['value'], 'a WhatsApp number in international form is accepted');
okv_test_eq('2348000000000', SettingsEditor::validateField($phone, '+234 800 000 0000')['value'], 'a number typed with a plus and spaces is normalised to digits');
okv_test_ok(SettingsEditor::validateField($phone, '0800')['error'] !== null, 'a number too short to dial is refused');
okv_test_ok(SettingsEditor::validateField($phone, str_repeat('9', 16))['error'] !== null, 'a number too long to dial is refused');
okv_test_ok(SettingsEditor::validateField($phone, '')['error'] !== null, 'the support number cannot be emptied');

okv_test_eq('', SettingsEditor::validateField($day, '')['value'], 'the sourcing day may be left blank');
okv_test_eq('Tuesday', SettingsEditor::validateField($day, 'Tuesday')['value'], 'a sourcing day is accepted');

// --- A whole tab at once --------------------------------------------------------

$result = SettingsEditor::validate('order', [
    'deposit_percentage_default' => '40',
    'delivery_cutoff_time'       => '15:30',
    'delivery_min_lead_days'     => '2',
    'min_order_subunit'          => '3,000',
    'pay_on_delivery_requires_activation' => '1',
]);
okv_test_eq([], $result['errors'], 'a whole valid tab comes back with no errors');
okv_test_eq(40, $result['clean']['deposit_percentage_default'], 'the tab carries the new deposit through');
okv_test_eq(300000, $result['clean']['min_order_subunit'], 'the tab carries the smallest order through in kobo');

$bad = SettingsEditor::validate('order', [
    'deposit_percentage_default' => '150',
    'delivery_cutoff_time'       => '25:00',
    'delivery_min_lead_days'     => '2',
]);
okv_test_eq(2, count($bad['errors']), 'every problem in a tab is reported at once, not just the first');
okv_test_ok(isset($bad['clean']['delivery_min_lead_days']), 'a good value in a bad tab still validates');
okv_test_ok(!isset($bad['clean']['deposit_percentage_default']), 'a bad value never reaches the clean set');

// A key the form did not send is left alone rather than blanked.
$partial = SettingsEditor::validate('site', ['business_tagline' => 'Sourced right.']);
okv_test_eq(['business_tagline'], array_keys($partial['clean']), 'a partial post touches only what it sent');
okv_test_eq([], $partial['errors'], 'a partial post does not fail on the keys it left out');

// A key that is not in the tab is dropped rather than written.
$sneaky = SettingsEditor::validate('site', ['business_name' => 'OK Veggies', 'deposit_percentage_default' => '90']);
okv_test_ok(!isset($sneaky['clean']['deposit_percentage_default']), 'a key from another tab is dropped, not saved');

$unknown = SettingsEditor::validate('made_up', ['anything' => '1']);
okv_test_ok(isset($unknown['errors']['_group']), 'an unknown tab is refused outright');

// --- How a change reads to a person ---------------------------------------------

okv_test_eq('30%', SettingsEditor::display($deposit, 30), 'a deposit reads with its percent sign');
okv_test_eq('1 day', SettingsEditor::display($lead, 1), 'one day of notice reads in the singular');
okv_test_eq('2 days', SettingsEditor::display($lead, 2), 'two days of notice reads in the plural');
okv_test_eq('On', SettingsEditor::display($gate, true), 'a gate that is on reads as On');
okv_test_eq('Off', SettingsEditor::display($gate, false), 'a gate that is off reads as Off');
okv_test_eq('Not set', SettingsEditor::display($day, ''), 'a blank text value reads as Not set');
okv_test_ok(str_contains(SettingsEditor::display($minOrder, 500000), '5,000'), 'the smallest order reads back in naira with a comma');
