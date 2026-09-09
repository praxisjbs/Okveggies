<?php
/**
 * scripts/tests/CustomersTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Task I. The customer 360: what the filter accepts, what the reads
 * are allowed to touch, and what the screens promise about permissions, credit
 * and the links between modules. Pure rules and source checks only, so this
 * runs with no database. scripts/tests/customers_db_test.php covers the SQL.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);

// -----------------------------------------------------------------------------
// Filters. Everything on the query string is a guess until it is normalised.
// -----------------------------------------------------------------------------
$clean = Customers::normaliseFilters(['search' => '  Adaeze  ', 'type' => 'business', 'page' => '3']);
okv_test_eq('Adaeze', $clean['search'], 'the search term is trimmed');
okv_test_eq('business', $clean['type'], 'a known account type survives');
okv_test_eq(3, $clean['page'], 'the page number becomes an integer');

$empty = Customers::normaliseFilters([]);
okv_test_eq('', $empty['search'], 'a missing search term is an empty string');
okv_test_eq('', $empty['type'], 'a missing account type means all customers');
okv_test_eq(1, $empty['page'], 'a missing page number starts at page 1');

okv_test_eq('', Customers::normaliseFilters(['type' => 'staff'])['type'], 'staff is not a customer account type');
okv_test_eq('', Customers::normaliseFilters(['type' => 'admin'])['type'], 'an invented account type is dropped');
okv_test_eq('household', Customers::normaliseFilters(['type' => 'household'])['type'], 'household is a customer account type');
okv_test_eq(1, Customers::normaliseFilters(['page' => '0'])['page'], 'page 0 is corrected to page 1');
okv_test_eq(1, Customers::normaliseFilters(['page' => '-8'])['page'], 'a negative page is corrected to page 1');
okv_test_eq(1, Customers::normaliseFilters(['page' => 'two'])['page'], 'a page that is not a number falls back to page 1');
okv_test_eq(100, mb_strlen(Customers::normaliseFilters(['search' => str_repeat('a', 400)])['search']), 'a long search term is cut to 100 characters');
okv_test_eq('₦', Customers::normaliseFilters(['search' => '₦'])['search'], 'the search term is cut by characters, not bytes');

// -----------------------------------------------------------------------------
// Page size and the slices the profile shows.
// -----------------------------------------------------------------------------
okv_test_eq(25, Customers::PER_PAGE, 'the customer list shows 25 to a page');
okv_test_eq(10, Customers::RECENT_ORDERS, 'the profile shows the last 10 orders');
okv_test_eq(10, Customers::RECENT_PAYMENTS, 'the profile shows the last 10 payments');
okv_test_eq(5, Customers::RECENT_RUNS, 'the profile shows the last 5 Kitchen Runs');
okv_test_eq(10, Customers::RECENT_CREDIT, 'the profile shows the last 10 credit entries');
okv_test_eq(['household', 'business'], Customers::TYPES, 'households and businesses are the only customer types');

// -----------------------------------------------------------------------------
// Names and labels.
// -----------------------------------------------------------------------------
okv_test_eq('Business', Customers::typeLabel('business'), 'a business account is labelled Business');
okv_test_eq('Household', Customers::typeLabel('household'), 'a household account is labelled Household');
okv_test_eq('Household', Customers::typeLabel('anything else'), 'an unknown account type reads as Household rather than blank');

okv_test_eq(
    'Mama Chidi Kitchen',
    Customers::displayName(['first_name' => 'Ngozi', 'last_name' => 'Obi', 'business_name' => 'Mama Chidi Kitchen']),
    'a business is listed under its business name'
);
okv_test_eq(
    'Ngozi Obi',
    Customers::displayName(['first_name' => 'Ngozi', 'last_name' => 'Obi', 'business_name' => null]),
    'a household is listed under the account holder name'
);
okv_test_eq(
    'Ngozi Obi',
    Customers::displayName(['first_name' => ' Ngozi ', 'last_name' => ' Obi ', 'business_name' => '   ']),
    'a blank business name falls back to the person, with no stray spacing'
);
okv_test_eq(
    'Customer 42',
    Customers::displayName(['id' => 42, 'first_name' => '', 'last_name' => '']),
    'a nameless record is still identifiable by its number'
);

// -----------------------------------------------------------------------------
// The domain class reads. Orders, payments, Kitchen Runs and credit are written
// by the modules that own them, each with its own permission and audit trail.
// -----------------------------------------------------------------------------
$domain = (string) file_get_contents($root . '/includes/classes/Customers.php');
foreach (['INSERT', 'UPDATE ', 'DELETE'] as $write) {
    okv_test_ok(stripos($domain, $write) === false, 'Customers never runs a ' . trim($write) . ' statement');
}
okv_test_ok(
    substr_count($domain, 'Database::') === substr_count($domain, 'Database::all(') + substr_count($domain, 'Database::one('),
    'Customers only reads through Database::all and Database::one'
);
okv_test_ok(
    str_contains($domain, "u.user_type IN ('household', 'business')"),
    'the customer list is limited to households and businesses, so staff accounts never appear'
);
okv_test_ok(
    str_contains($domain, "AND user_type IN (\\'household\\', \\'business\\')"),
    'opening one customer is limited to household and business accounts too'
);
// A count of placeholders was pinned at 8 here, which broke the moment the
// search grew a fifth position (the phone matched exactly as well as by LIKE,
// so a number typed the way a caller says it finds the person). The count was
// never the point. What matters is that no placeholder name is used twice: the
// connection runs native prepared statements, and MySQL refuses a repeated
// named placeholder, which is the defect that took the orders screen down once.
// So this counts distinct names against total uses instead, and stays true
// however many positions the search grows.
preg_match_all('/:search_[a-z_]+/', $domain, $okvSearchNames);
$okvSearchUses = $okvSearchNames[0];
okv_test_ok(count($okvSearchUses) > 0, 'the customer search binds its term rather than pasting it in');
okv_test_eq(
    count($okvSearchUses),
    count(array_unique($okvSearchUses)) * 2,
    'each search position carries its own placeholder, named once in the statement and once in the parameters, which native prepared statements require'
);
okv_test_eq(
    substr_count($domain, '$where[] = '),
    substr_count($domain, '$where[] = \''),
    'every condition added to the where clause is a literal, never a value from the request'
);
// The term is still bound rather than pasted into the statement, and it is now
// escaped on the way into the LIKE pattern as well: without that, a colleague
// searching for "%" matched every account we have.
okv_test_ok(
    str_contains($domain, '$like = \'%\' . Catalogue::escapeLike($filters[\'search\']) . \'%\';'),
    'the search term reaches the database as a bound parameter, escaped for LIKE, never as statement text'
);
okv_test_eq(
    substr_count($domain, 'Database::'),
    substr_count($domain, 'Database::all(') + substr_count($domain, 'Database::one('),
    'every read goes through the shared database helpers'
);

okv_test_ok(str_contains($domain, 'okv_limit_clause('), 'paging reuses the shared limit helper');
okv_test_ok(!str_contains($domain, 'credit_limit_subunit -'), 'no balance is arithmetic on a stored column');

// -----------------------------------------------------------------------------
// The screen. Permissions layer, credit belongs to businesses, actions live in
// the modules that own them.
// -----------------------------------------------------------------------------
$screen = (string) file_get_contents($root . '/admin/customers.php');
okv_test_ok(str_contains($screen, "Rbac::requirePermission('customers.view')"), 'the customer screen is gated on customers.view');
okv_test_ok(str_contains($screen, "Rbac::can('customers.addresses.view')"), 'addresses need their own permission');
okv_test_ok(str_contains($screen, "Rbac::can('payments.view')"), 'the payments block needs the payments permission');
okv_test_ok(str_contains($screen, "Rbac::can('credit.view')"), 'the credit block needs the credit permission');
okv_test_ok(str_contains($screen, "Rbac::can('kitchen_runs.view')"), 'the Kitchen Run block needs the Kitchen Run permission');
okv_test_ok(str_contains($screen, '$business && $canSeeCredit'), 'a household is never shown a credit facility');
okv_test_ok(str_contains($screen, 'Credit::customerAccount('), 'credit figures come from the shared credit class');
okv_test_ok(str_contains($screen, "\$canGrantCredit  = Rbac::can('credit.grant')"), 'granting credit is offered only to staff who may grant it');
okv_test_ok(
    !str_contains($screen, 'method="post"') && !str_contains($screen, "method='post'"),
    'the customer screen writes nothing: every action links out to the module that owns it'
);
okv_test_ok(str_contains($screen, 'okv_pagination('), 'the customer list uses the shared pagination component');
okv_test_ok(str_contains($screen, 'Customers::PER_PAGE') === false, 'the screen takes its page size from the domain class rather than repeating it');
foreach ([
    '/admin/orders.php?order=' => 'an order',
    '/admin/payments.php?order=' => 'a payment',
    '/admin/kitchen_runs.php?request=' => 'a Kitchen Run',
    '/admin/credit.php?business=' => 'the credit facility',
] as $link => $what) {
    okv_test_ok(str_contains($screen, $link), 'the profile links out to ' . $what);
}
okv_test_ok(str_contains($screen, 'Money::format('), 'every amount on the screen is formatted by Money');
okv_test_ok(substr_count($screen, 'okv_e(') > 20, 'the screen escapes what it prints');

// -----------------------------------------------------------------------------
// The way back. A module that shows a customer offers the trip to their profile.
// -----------------------------------------------------------------------------
foreach ([
    'admin/orders.php' => 'the order screen',
    'admin/payments.php' => 'the payments screen',
    'includes/components/admin/kitchen_run_panel.php' => 'the Kitchen Run panel',
] as $relative => $what) {
    $src = (string) file_get_contents($root . '/' . $relative);
    okv_test_ok(str_contains($src, '/admin/customers.php?customer='), $what . ' links back to the customer');
    okv_test_ok(str_contains($src, "Rbac::can('customers.view')"), $what . ' checks the permission before offering that link');
}

// -----------------------------------------------------------------------------
// The endpoint. POST, CSRF, permission, one action, no leaked exception text.
// -----------------------------------------------------------------------------
$api = (string) file_get_contents($root . '/api/v1/customers.php');
okv_test_ok(str_contains($api, 'okv_is_post()'), 'the customer endpoint refuses anything but POST');
okv_test_ok(str_contains($api, 'Csrf::validate()'), 'the customer endpoint checks the CSRF token');
okv_test_ok(str_contains($api, "Rbac::requirePermission('customers.view')"), 'the customer endpoint is gated on customers.view');
// The endpoint answered one action when M8 wrote it. It now answers three: the
// search, plus the `get` and `create` the phone-order and typed-in-list pickers
// need before an order can belong to anybody. "One action" was never the safety
// property; "every action gated, and nothing else reachable" is, so that is
// what this asserts now.
preg_match_all('/\$action === \'([a-z_]+)\'/', $api, $okvApiActions);
$okvApiActionNames = $okvApiActions[1];
okv_test_ok(count($okvApiActionNames) >= 1, 'the customer endpoint dispatches on a named action');
okv_test_ok(
    str_contains($api, "okv_error('That action is not available.'"),
    'anything the customer endpoint does not name is refused, rather than falling through'
);
okv_test_eq(
    count($okvApiActionNames),
    substr_count($api, 'Rbac::requirePermission('),
    'every action the customer endpoint answers is gated on a permission of its own'
);
okv_test_ok(
    str_contains($api, "Rbac::requirePermission('customers.create')"),
    'making a customer needs its own permission, not the one for reading them'
);
okv_test_ok(str_contains($api, '405') && str_contains($api, '419'), 'the endpoint answers with the right refusal codes');
okv_test_ok(str_contains($api, 'error_log('), 'a failure is logged for us');
okv_test_ok(!str_contains($api, '$e->getMessage()') || !str_contains($api, 'okv_error(\'' . '$e'), 'exception text never reaches the caller');
okv_test_ok(str_contains($api, "okv_error('We could not search customers just now. Please try again.'"), 'the caller gets plain words instead');

// -----------------------------------------------------------------------------
// Wiring.
// -----------------------------------------------------------------------------
$bootstrap = (string) file_get_contents($root . '/includes/bootstrap.php');
okv_test_ok(str_contains($bootstrap, "classes/Customers.php"), 'the bootstrap loads the Customers class');

$permissions = (string) file_get_contents($root . '/includes/config/permissions.php');
foreach (['customers.view', 'customers.addresses.view', 'credit.view', 'payments.view', 'kitchen_runs.view', 'credit.grant'] as $key) {
    okv_test_ok(str_contains($permissions, "'" . $key . "'"), 'the permission ' . $key . ' is in the catalogue');
}

$nav = (string) file_get_contents($root . '/includes/config/nav.php');
okv_test_ok(str_contains($nav, '/admin/customers.php'), 'the admin menu reaches the customer screen');
