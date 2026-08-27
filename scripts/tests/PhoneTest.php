<?php
/**
 * scripts/tests/PhoneTest.php
 * OK Veggies. Phone normalisation. However a Nigerian number is typed, it must
 * land on one canonical E.164 form so registration and login agree. Runs under
 * scripts/tests/run.php (no database needed).
 */

require_once $appRoot . '/includes/classes/Phone.php';

$e164 = '+2348012345678';
okv_test_eq($e164, Phone::normalize('08012345678'),      'a plain 0-leading number');
okv_test_eq($e164, Phone::normalize('0801 234 5678'),    'spaces are ignored');
okv_test_eq($e164, Phone::normalize('0801-234-5678'),    'dashes are ignored');
okv_test_eq($e164, Phone::normalize('+2348012345678'),   'already E.164');
okv_test_eq($e164, Phone::normalize('+234 801 234 5678'),'E.164 with spaces');
okv_test_eq($e164, Phone::normalize('2348012345678'),    '234 prefix without a plus');
okv_test_eq($e164, Phone::normalize('8012345678'),       '10 digit national number');
okv_test_eq($e164, Phone::normalize('002348012345678'),  '00 international prefix');

okv_test_eq('+2347012345678', Phone::normalize('07012345678'), 'a 070 number');
okv_test_eq('+2349012345678', Phone::normalize('09012345678'), 'a 090 number');
okv_test_eq('+2348030000000', Phone::normalize('0803 000 0000'), 'the example number');

okv_test_eq(null, Phone::normalize(''),            'empty is not a number');
okv_test_eq(null, Phone::normalize('12345'),       'too short is not a number');
okv_test_eq(null, Phone::normalize('not a phone'), 'letters are not a number');
okv_test_eq(null, Phone::normalize('0601234567'),  'a 06 number is not a Nigerian mobile');
okv_test_eq(null, Phone::normalize('080123456789'),'too long is not a number');

okv_test_ok(Phone::isValid('0803 000 0000'),  'isValid true for a real number');
okv_test_ok(!Phone::isValid('hello'),         'isValid false for nonsense');

okv_test_eq('0801 234 5678', Phone::display('+2348012345678'), 'display shows a friendly local form');
okv_test_eq('same as typed', 'same as typed', 'display is safe on odd input (sanity)');
okv_test_eq('not a phone', Phone::display('not a phone'), 'display falls back to the given value');
