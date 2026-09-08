<?php
/** Shared Pro route and household destination rules. */

$safePaths = [
    '/pro' => '/pro/',
    '/pro/' => '/pro/',
    '/pro/index.php' => '/pro/index.php',
    '/pro/kitchen_lists.php?request=4' => '/pro/kitchen_lists.php',
    '/pro/standing_orders.php' => '/pro/standing_orders.php',
    '/pro/orders.php' => '/pro/orders.php',
    '/pro/credit.php' => '/pro/credit.php',
    '/pro/account.php' => '/pro/account.php',
];

foreach ($safePaths as $input => $expected) {
    okv_test_eq($expected, okv_pro_safe_return_path($input), "Pro return path is accepted: $input");
}

foreach (['https://example.test/pro/credit.php', '//example.test/pro/credit.php', '/admin/', '/pro/not-real.php', ''] as $input) {
    okv_test_eq(null, okv_pro_safe_return_path($input), "unsafe Pro return path is refused: $input");
}

okv_test_eq(
    '/kitchen-runs.php?notice=pro_business',
    okv_pro_household_destination('/pro/kitchen_lists.php'),
    'Kitchen Lists sends a household to Kitchen Runs with an explanation'
);
okv_test_eq(
    '/account.php?notice=pro_business',
    okv_pro_household_destination('/pro/credit.php'),
    'another Pro screen sends a household to their account with an explanation'
);

$proPages = ['index.php', 'kitchen_lists.php', 'standing_orders.php', 'orders.php', 'credit.php', 'account.php'];
foreach ($proPages as $page) {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/pro/' . $page);
    $bootstrapAt = strpos($source, "require_once __DIR__ . '/../includes/bootstrap.php';");
    $gateAt = strpos($source, 'require_business_customer();');
    $outputAt = strpos($source, "require __DIR__ . '/../includes/components/pro/header.php';");
    $dataReads = array_filter([
        strpos($source, 'KitchenRuns::allForCustomer', $gateAt),
        strpos($source, 'Database::one', $gateAt),
        strpos($source, 'Database::all', $gateAt),
        strpos($source, 'Customer::current', $gateAt),
    ], static fn($position) => $position !== false);
    $firstDataAt = $dataReads ? min($dataReads) : PHP_INT_MAX;

    okv_test_ok($bootstrapAt !== false && $gateAt !== false && $bootstrapAt < $gateAt, "$page bootstraps before its shared Pro gate");
    okv_test_ok($outputAt !== false && $gateAt < $outputAt, "$page gates before Pro HTML is emitted");
    okv_test_ok($gateAt < $firstDataAt, "$page gates before business data is read");
}
