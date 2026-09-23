<?php
/**
 * scripts/tests/IdentityTest.php
 * OK Veggies. Identity canonicalisation without a database: the one email rule
 * and the one phone rule every sign-in, registration, staff creation, customer
 * creation, guest checkout, profile edit and password-reset path shares. Runs
 * under scripts/tests/run.php.
 */

require_once $appRoot . '/includes/classes/Phone.php';
require_once $appRoot . '/includes/classes/Auth.php';

// --- Email canonicalisation: one casing everywhere ---------------------------
okv_test_eq('ada@example.com', Auth::canonicalEmail('ada@example.com'),   'a lower case email is kept');
okv_test_eq('ada@example.com', Auth::canonicalEmail('Ada@Example.COM'),   'a mixed case email is lower cased');
okv_test_eq('ada@example.com', Auth::canonicalEmail('  ada@example.com '), 'an email is trimmed before it is stored');
okv_test_eq(null, Auth::canonicalEmail(''),                'an empty email is refused');
okv_test_eq(null, Auth::canonicalEmail('not-an-address'),  'a non-address is refused');
okv_test_eq(null, Auth::canonicalEmail('ada@'),            'a half address is refused');

// --- Identifier routing: email when it has an @, phone otherwise -------------
okv_test_eq(['email', 'ada@example.com'], Auth::canonicalIdentifier('ADA@Example.com'), 'an identifier with an @ is an email, lower cased');
okv_test_eq(['phone', '+2348031234567'], Auth::canonicalIdentifier('08031234567'),     'an identifier without an @ is a phone');
okv_test_eq(null, Auth::canonicalIdentifier(''),           'an empty identifier finds nothing');
okv_test_eq(null, Auth::canonicalIdentifier('not a thing'),'nonsense finds nothing');
okv_test_eq(null, Auth::canonicalIdentifier('0601234567'), 'a number outside the phone policy finds nothing');

// --- Every accepted equivalent phone form lands on one value ------------------
$e164 = '+2348031234567';
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('0803 123 4567'),     'the spaced local form signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('08031234567'),       'the plain local form signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('2348031234567'),     'the country code without a plus signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('+2348031234567'),    'E.164 signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('002348031234567'),   'the 00 international prefix signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('+234 803 123 4567'), 'E.164 with spaces signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('0803-123-4567'),     'dashes sign in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('23408031234567'),    'country code plus a leading zero signs in');
okv_test_eq(['phone', $e164], Auth::canonicalIdentifier('8031234567'),        'the bare 10 digit national number signs in');

// --- Invalid and unsupported phone values ------------------------------------
okv_test_eq(null, Auth::canonicalIdentifier('0803123456'),    'a 10 digit local form (one short) is refused');
okv_test_eq(null, Auth::canonicalIdentifier('080312345678'),  'a 12 digit local form (one long) is refused');
okv_test_eq(null, Auth::canonicalIdentifier('01234567890'),   'a landline shaped number is refused by the mobile policy');
okv_test_eq(null, Auth::canonicalIdentifier('0601234567'),    'a 06 number is not a Nigerian mobile');
okv_test_eq(null, Auth::canonicalIdentifier('+441234567890'), 'a non-Nigerian number is refused by the phone policy');
okv_test_eq(null, Auth::canonicalIdentifier('abcdefghijk'),   'letters are refused');

// --- Rate limiting buckets agree across every form of the same identity ------
$bucketByEmail = Auth::rateBucket('Ada@Example.com');
okv_test_eq($bucketByEmail, Auth::rateBucket('ada@example.com'),   'mixed and lower case emails share one rate bucket');
okv_test_eq($bucketByEmail, Auth::rateBucket(' ADA@example.com '), 'a padded email shares the same bucket');
$bucketByPhone = Auth::rateBucket('+2348031234567');
okv_test_eq($bucketByPhone, Auth::rateBucket('0803 123 4567'),    'the spaced phone shares a bucket with E.164');
okv_test_eq($bucketByPhone, Auth::rateBucket('002348031234567'),  'the 00 prefix shares a bucket with E.164');
okv_test_ok($bucketByEmail !== $bucketByPhone,                     'different identities never share a bucket');
okv_test_ok(Auth::rateBucket('nonsense') !== '',                   'an unparseable identifier still gets a stable bucket');

// --- Landing paths, decided before any database is read -----------------------
okv_test_eq('/',      Auth::landingPath(['user_type' => 'household']), 'a household lands on the shop');
okv_test_eq('/pro',   Auth::landingPath(['user_type' => 'business']),  'a business lands on the Pro Portal');
okv_test_eq('/admin', Auth::landingPath(['user_type' => 'staff']),     'the legacy staff account type lands on the admin panel');
