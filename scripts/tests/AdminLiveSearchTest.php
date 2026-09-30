<?php
/**
 * Pure contracts for the admin live search bar (the auto-fill filter).
 *
 * The search on Payments, Customers and Kitchen Runs narrows from the first
 * character and offers suggestions, while staying a plain GET form underneath.
 * The behaviour itself runs in jsdom (admin_live_search_test.mjs); what this
 * file pins is the wiring a refactor could silently drop, because CI runs the
 * PHP suite on every push and never opens a browser: each screen carries the
 * four hooks the shared module looks for, each endpoint answers the browse
 * read behind the screen's own permission, and both callers render through the
 * one component, so a typed filter and a reload of the same URL cannot drift.
 */

$root = dirname(__DIR__, 2);

$screens = [
    'admin/payments.php' => [
        'api'       => 'api/v1/payments.php',
        'param'     => 'q',
        'perm'      => 'payments.view',
        'component' => 'okv_admin_payment_search_results(',
    ],
    'admin/customers.php' => [
        'api'       => 'api/v1/customers.php',
        'param'     => 'search',
        'perm'      => 'customers.view',
        'component' => 'okv_admin_customer_list(',
    ],
    'admin/kitchen_runs.php' => [
        'api'       => 'api/v1/kitchen_runs.php',
        'param'     => 'customer',
        'perm'      => 'kitchen_runs.view',
        'component' => 'okv_admin_kitchen_run_queue(',
    ],
];

foreach ($screens as $relative => $expect) {
    $screen = (string) file_get_contents($root . '/' . $relative);
    $api    = (string) file_get_contents($root . '/' . $expect['api']);

    okv_test_ok(str_contains($screen, 'data-live-form'), $relative . ' marks its search form up for the live module');
    okv_test_ok(str_contains($screen, 'data-live-input'), $relative . ' marks the box itself');
    okv_test_ok(str_contains($screen, 'data-live-results'), $relative . ' marks the region the live answer swaps');
    okv_test_ok(
        str_contains($screen, 'data-live-endpoint="/' . $expect['api'] . '"'),
        $relative . ' points the live module at its own endpoint'
    );
    okv_test_ok(
        str_contains($screen, 'data-live-param="' . $expect['param'] . '"'),
        $relative . ' tells the live module which query key the term rides in'
    );
    okv_test_ok(
        str_contains($screen, 'method="get"') || str_contains($screen, 'method="GET"'),
        $relative . ' keeps the search a GET form, so it still works with JavaScript off'
    );
    okv_test_ok(str_contains($screen, $expect['component']), $relative . ' renders its list through the shared component');

    okv_test_ok(str_contains($api, "\$action === 'browse'"), $expect['api'] . ' answers the browse read');
    $browseStart = strpos($api, "\$action === 'browse'");
    $browseBlock = substr($api, (int) $browseStart, (int) strpos($api, 'okv_json(', (int) $browseStart) - (int) $browseStart);
    okv_test_ok(
        str_contains($browseBlock, "Rbac::requirePermission('" . $expect['perm'] . "')"),
        $expect['api'] . ' gates browse on the permission the screen opens with'
    );
    okv_test_ok(str_contains($api, $expect['component']), $expect['api'] . ' renders browse through the same component as the screen');
    okv_test_ok(str_contains($api, "'suggestions'"), $expect['api'] . ' offers the auto-fill suggestions');
}

// One component per screen, and the customer list keeps the shared pagination.
foreach ([
    'includes/components/admin/payment_search_results.php',
    'includes/components/admin/customer_list.php',
    'includes/components/admin/kitchen_run_queue.php',
] as $relative) {
    $component = (string) file_get_contents($root . '/' . $relative);
    okv_test_ok(str_contains($component, 'OKV_BOOTSTRAPPED'), $relative . ' refuses to run outside a bootstrapped request');
    okv_test_ok(substr_count($component, 'okv_e(') > 0, $relative . ' escapes what it prints');
}
$customerList = (string) file_get_contents($root . '/includes/components/admin/customer_list.php');
okv_test_ok(str_contains($customerList, 'okv_pagination('), 'the customer list keeps the shared pagination component');

// The shared module: one file, debounced at the house 300ms, asking from one
// character, cancelling what it superseded, and minified into the bundle the
// kitchen screen loads by hand.
$module = (string) file_get_contents($root . '/assets/js/admin-live-search.js');
okv_test_ok(str_contains($module, 'data-live-debounce') && str_contains($module, "'300'"), 'the live search debounces at the house 300ms');
okv_test_ok(str_contains($module, 'data-live-min') && str_contains($module, "'1'"), 'the live search asks from the first character');
okv_test_ok(str_contains($module, 'AbortController'), 'a newer keystroke cancels the request it supersedes');
okv_test_ok(str_contains($module, "setAttribute('role', 'combobox')"), 'the box is an ARIA combobox');
okv_test_ok(str_contains($module, 'textContent'), 'suggestions are written with textContent, never innerHTML');
$build = (string) file_get_contents($root . '/scripts/build-js.mjs');
okv_test_ok(str_contains($build, 'assets/js/admin-live-search.js'), 'the module is in the JavaScript build');
okv_test_ok(is_file($root . '/assets/js/admin-live-search.min.js'), 'the minified module ships beside its source');

// The behaviour suite is wired into both runners, or it is a suite nobody runs.
$runAll = (string) file_get_contents($root . '/scripts/tests/run_all.sh');
$gate   = (string) file_get_contents($root . '/scripts/tests/release_gate.sh');
okv_test_ok(str_contains($runAll, 'admin_live_search_test.mjs'), 'run_all.sh runs the live search behaviour suite');
okv_test_ok(str_contains($gate, 'admin_live_search_test.mjs'), 'the release gate runs the live search behaviour suite');
