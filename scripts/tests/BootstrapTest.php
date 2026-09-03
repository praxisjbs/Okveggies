<?php
/**
 * scripts/tests/BootstrapTest.php
 *
 * Guards the failure that shipped the whole of M5 broken to production.
 *
 * There is no autoloader for application classes. includes/bootstrap.php carries
 * an explicit require list, and a class file that is not on it does not exist at
 * runtime. Five M5 classes were written, unit tested and merged without ever
 * being added, so every payment page fatalled the moment it was opened while the
 * test suite stayed green.
 *
 * It stayed green because this test runner has its own require list, which had
 * been updated. Unit tests that load their subject directly can never catch a
 * class the application does not load. This file closes that gap by checking the
 * bootstrap itself rather than the classes.
 */

$appRoot   = dirname(__DIR__, 2);
$bootstrap = (string) file_get_contents($appRoot . '/includes/bootstrap.php');

/**
 * Loaded on its own by scripts/migrate.php and public/migrate.php, which run
 * before the application exists. Deliberately not in the bootstrap.
 */
$allowedOutsideBootstrap = ['Migrator'];

$classFiles = glob($appRoot . '/includes/classes/*.php') ?: [];
okv_test_ok(count($classFiles) > 20, 'the class directory was found and is not empty');

foreach ($classFiles as $file) {
    $class = basename($file, '.php');
    if (in_array($class, $allowedOutsideBootstrap, true)) {
        continue;
    }
    okv_test_ok(
        str_contains($bootstrap, "classes/$class.php"),
        $class . ' is required by bootstrap.php, so it exists at runtime and not only in tests'
    );
}

// The five that were missing, named so a regression reads plainly in the output.
foreach (['Payments', 'ManualPayments', 'Refunds', 'Cancellation', 'OrderDocument'] as $m5Class) {
    okv_test_ok(
        str_contains($bootstrap, "classes/$m5Class.php"),
        'M5 class ' . $m5Class . ' is loaded by the application, not just by the tests'
    );
}

// Every migration must be MySQL 8 safe. MariaDB's ADD COLUMN IF NOT EXISTS and
// ADD INDEX IF NOT EXISTS are a syntax error on MySQL 8, and Migrator::apply
// throws on the first failure, so one bad migration blocks every later one.
foreach (glob($appRoot . '/migrations/*.sql') ?: [] as $migration) {
    $sql = (string) file_get_contents($migration);
    // Strip comment lines, which legitimately mention the forbidden syntax to
    // explain why it is avoided.
    $body = implode("\n", array_filter(
        explode("\n", $sql),
        static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
    ));
    foreach (['ADD COLUMN IF NOT EXISTS', 'ADD INDEX IF NOT EXISTS', 'ADD KEY IF NOT EXISTS'] as $mariaOnly) {
        okv_test_ok(
            stripos($body, $mariaOnly) === false,
            basename($migration) . ' avoids ' . $mariaOnly . ', which MySQL 8 cannot parse'
        );
    }
}

// -----------------------------------------------------------------------------
// Every class and member the payment pages call must actually exist.
//
// The unit tests exercise the domain classes directly and never open a page, so
// a page calling a method that was renamed, or a class the bootstrap does not
// load, stays invisible until someone clicks it in production. This walks the
// M5 entry points and resolves every Foo::bar reference in them.
// -----------------------------------------------------------------------------
foreach (glob($appRoot . '/includes/classes/*.php') ?: [] as $classFile) {
    require_once $classFile;
}

$entryPoints = [
    'admin/payments.php',
    'api/v1/payments.php',
    'api/v1/paystack_webhook.php',
    'public/payment/callback.php',
    'public/documents/invoice.php',
    'public/documents/receipt.php',
    'public/order.php',
    'api/v1/checkout.php',
    'scripts/payment_sweep.php',
];
$languageWords = ['PHP_EOL', 'STR_PAD_LEFT', 'self', 'static', 'parent', 'PHP_SAPI'];

foreach ($entryPoints as $relative) {
    $path = $appRoot . '/' . $relative;
    okv_test_ok(is_file($path), $relative . ' exists');
    if (!is_file($path)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    preg_match_all('/\b([A-Z][A-Za-z0-9_]+)::([a-zA-Z_][A-Za-z0-9_]*)/', $src, $matches, PREG_SET_ORDER);

    $seen = [];
    foreach ($matches as [$whole, $class, $member]) {
        if (in_array($class, $languageWords, true) || isset($seen[$whole])) {
            continue;
        }
        $seen[$whole] = true;
        okv_test_ok(
            class_exists($class) || interface_exists($class),
            $relative . ' calls a class that exists: ' . $class
        );
        if (class_exists($class)) {
            okv_test_ok(
                method_exists($class, $member) || defined($class . '::' . $member),
                $relative . ' calls something real: ' . $whole
            );
        }
    }
}
