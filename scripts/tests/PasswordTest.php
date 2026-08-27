<?php
/**
 * scripts/tests/PasswordTest.php
 * The Password helper is the one place a staff password is hashed, verified and
 * checked against the policy. It must never be wrong. Pure unit test, no DB.
 */

require_once dirname(__DIR__, 2) . '/includes/config/env.php';
require_once dirname(__DIR__, 2) . '/includes/classes/Password.php';

// Minimum length comes from .env (10 for these tests).
okv_test_eq(10, Password::minLength(), 'minimum length reads from config');

// Policy: too short is rejected, a good password is accepted.
okv_test_ok(Password::policyError('short') !== null,               'a short password is rejected');
okv_test_ok(Password::policyError('ridged-lantern-92') === null,   'a long, uncommon password is accepted');
okv_test_ok(Password::isAcceptable('ridged-lantern-92'),           'isAcceptable agrees with policyError');

// Policy: the most common passwords are rejected even when long enough.
okv_test_ok(Password::policyError('password123') !== null,         'a common password is rejected');
okv_test_ok(Password::policyError('okveggies1') !== null,          'an obvious brand password is rejected');

// Policy: a password cannot simply be the email or the phone.
okv_test_ok(Password::policyError('owner@okveggies.com.ng', 'owner@okveggies.com.ng') !== null, 'the email cannot be the password');
okv_test_ok(Password::policyError('08031234567', 'a@b.com', '08031234567') !== null,            'the phone cannot be the password');

// Policy: bcrypt ignores bytes past 72, so a longer password is rejected up front.
okv_test_ok(Password::policyError(str_repeat('a', 73)) !== null,   'a password over 72 characters is rejected');

// Hash and verify round-trip.
$hash = Password::hash('ridged-lantern-92');
okv_test_ok(Password::verify('ridged-lantern-92', $hash),          'verify accepts the right password');
okv_test_ok(!Password::verify('wrong-password-00', $hash),         'verify rejects the wrong password');
okv_test_ok(!Password::verify('ridged-lantern-92', ''),            'verify rejects an empty hash');

// A hash made at a weaker cost is flagged for a rehash at the current cost.
$weak = password_hash('ridged-lantern-92', PASSWORD_BCRYPT, ['cost' => 10]);
okv_test_ok(Password::needsRehash($weak),                          'a weaker-cost hash needs a rehash');
okv_test_ok(!Password::needsRehash($hash),                         'a current-cost hash does not need a rehash');
