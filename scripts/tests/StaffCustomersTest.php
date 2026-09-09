<?php
/**
 * scripts/tests/StaffCustomersTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The rules for finding and making a customer from the back office,
 * without a database. The persistence half lives in
 * manual_operations_db_test.php.
 *
 * The interesting case here is the caller with no email address. It is a normal
 * answer on a phone call, and users.email is NOT NULL UNIQUE, so the choice is
 * between refusing the order and building an address nobody will ever read. We
 * build one, from the phone number, so it is exactly as unique as the phone is,
 * and it is visibly not a real address so no screen ever offers to email it.
 * -----------------------------------------------------------------------------
 */

require_once $appRoot . '/includes/classes/Phone.php';
require_once $appRoot . '/includes/classes/Customer.php';
require_once $appRoot . '/includes/classes/StaffCustomers.php';

// ---------------------------------------------------------------------------
// 1. The search term.
// ---------------------------------------------------------------------------

okv_test_eq('Adaeze', StaffCustomers::cleanSearch('  Adaeze  '), 'a search term is trimmed');
okv_test_eq('', StaffCustomers::cleanSearch('   '), 'whitespace is not a search');
okv_test_eq(
    StaffCustomers::SEARCH_MAX,
    mb_strlen(StaffCustomers::cleanSearch(str_repeat('a', 500))),
    'a paste is cut to the length we act on'
);

// ---------------------------------------------------------------------------
// 2. The placeholder address for a caller who has no email.
// ---------------------------------------------------------------------------

okv_test_eq(
    '2348031234567@' . StaffCustomers::PLACEHOLDER_DOMAIN,
    StaffCustomers::placeholderEmail('+2348031234567'),
    'a placeholder address is built from the phone number, so it is as unique as the phone is'
);
okv_test_eq(
    'customer@' . StaffCustomers::PLACEHOLDER_DOMAIN,
    StaffCustomers::placeholderEmail(''),
    'with no digits at all there is still an address, rather than one starting with @'
);
okv_test_ok(
    str_ends_with(StaffCustomers::PLACEHOLDER_DOMAIN, '.invalid'),
    'the placeholder domain is reserved and undeliverable by definition, never a real domain'
);
okv_test_ok(
    StaffCustomers::isPlaceholderEmail(StaffCustomers::placeholderEmail('+2348031234567')),
    'an address we built is recognised as one of ours'
);
okv_test_ok(
    StaffCustomers::isPlaceholderEmail('2348031234567@' . strtoupper(StaffCustomers::PLACEHOLDER_DOMAIN)),
    'and it is recognised whatever case it was stored in'
);
okv_test_ok(
    !StaffCustomers::isPlaceholderEmail('adaeze@example.com'),
    'a real address the customer gave is not mistaken for ours'
);

// ---------------------------------------------------------------------------
// 3. What a new customer form has to carry.
// ---------------------------------------------------------------------------

$clean = StaffCustomers::validateNew([
    'first_name' => '  Adaeze ',
    'last_name'  => 'Okafor',
    'phone'      => '08031234567',
    'email'      => 'adaeze@example.com',
    'customer_type' => 'household',
]);
okv_test_eq('Adaeze', $clean['first_name'], 'the name is trimmed');
okv_test_eq('+2348031234567', $clean['phone'], 'the phone lands on the one canonical form every other screen stores');
okv_test_eq('adaeze@example.com', $clean['email'], 'a real address is kept as given');
okv_test_eq('household', $clean['customer_type'], 'the account type is kept');

$noEmail = StaffCustomers::validateNew([
    'first_name' => 'Chidi', 'last_name' => 'Eze', 'phone' => '0803 123 4568', 'email' => '',
]);
okv_test_ok(
    StaffCustomers::isPlaceholderEmail($noEmail['email']),
    'a caller with no email still gets an account, with an address that is visibly ours'
);

$refuses = static function (array $input, string $code, string $label): void {
    try {
        StaffCustomers::validateNew($input);
        okv_test_ok(false, $label . ' (nothing was refused)');
    } catch (DomainException $e) {
        okv_test_eq($code, $e->getMessage(), $label);
    }
};

$base = ['first_name' => 'Chidi', 'last_name' => 'Eze', 'phone' => '08031234567'];
$refuses(['last_name' => 'Eze', 'phone' => '08031234567'], 'name_required', 'a customer with no first name is refused');
$refuses(['first_name' => 'Chidi', 'phone' => '08031234567'], 'name_required', 'a customer with no last name is refused');
$refuses(['first_name' => 'Chidi', 'last_name' => 'Eze', 'phone' => 'not a number'], 'bad_phone', 'a phone number that is not one is refused');
$refuses($base + ['customer_type' => 'staff'], 'bad_customer_type', 'a customer cannot be created as staff');
$refuses($base + ['email' => 'not-an-address'], 'bad_email', 'a typed address that is not an address is refused rather than quietly replaced');

// A business carries a trading name, and falls back to the person's own.
$business = StaffCustomers::validateNew($base + ['customer_type' => 'business', 'business_name' => 'Mama Put Kitchen']);
okv_test_eq('Mama Put Kitchen', $business['business_name'], 'a business keeps its trading name');
okv_test_eq('', StaffCustomers::validateNew($base + ['customer_type' => 'business'])['business_name'], 'and an unnamed business is filled in at write time, not here');

// ---------------------------------------------------------------------------
// 4. Every refusal has words a colleague can act on.
// ---------------------------------------------------------------------------

foreach (['name_required', 'bad_customer_type', 'bad_phone', 'bad_email', 'customer_exists'] as $code) {
    $message = StaffCustomers::message($code);
    okv_test_ok($message !== StaffCustomers::message('unknown_code'), 'the refusal ' . $code . ' has words of its own');
    okv_test_ok(!str_contains($message, '_'), 'the refusal ' . $code . ' reads as a sentence, not as a code');
}

// A colleague told "sign in again" when they are already signed in goes looking
// in the wrong place, which is why the staff form does not borrow the
// customer's copy for the same fault.
okv_test_ok(
    !str_contains(StaffCustomers::message('customer_exists'), 'Sign in'),
    'a back office refusal never tells a signed-in colleague to sign in'
);
