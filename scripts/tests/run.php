<?php
/**
 * scripts/tests/run.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Tiny, dependency-free test runner. No Composer needed, so it runs
 * anywhere the app runs, including cPanel. It loads every *Test.php in this
 * folder, which call the okv_test_* assertions below, then prints a summary and
 * exits non-zero if anything failed.
 *
 *   php scripts/tests/run.php
 * -----------------------------------------------------------------------------
 */

$GLOBALS['okv_tests']  = 0;
$GLOBALS['okv_passed'] = 0;
$GLOBALS['okv_fails']  = [];

function okv_test_ok($cond, string $label): void
{
    $GLOBALS['okv_tests']++;
    if ($cond) {
        $GLOBALS['okv_passed']++;
    } else {
        $GLOBALS['okv_fails'][] = $label;
        fwrite(STDERR, "  FAIL: $label\n");
    }
}

function okv_test_eq($expected, $actual, string $label): void
{
    $ok = ($expected === $actual);
    if (!$ok) {
        $label .= "  (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")";
    }
    okv_test_ok($ok, $label);
}

$appRoot = dirname(__DIR__, 2);
require_once $appRoot . '/includes/classes/Money.php';
require_once $appRoot . '/includes/classes/OrderNumber.php';
require_once $appRoot . '/includes/classes/Catalogue.php';
require_once $appRoot . '/includes/classes/Pricing.php';
require_once $appRoot . '/includes/classes/Combos.php';
require_once $appRoot . '/includes/functions/helpers.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    fwrite(STDOUT, "[test] " . basename($file) . "\n");
    require $file;
}

$t = $GLOBALS['okv_tests'];
$p = $GLOBALS['okv_passed'];
fwrite(STDOUT, "\n$p / $t assertions passed.\n");
if ($p !== $t) {
    fwrite(STDERR, count($GLOBALS['okv_fails']) . " failed.\n");
    exit(1);
}
fwrite(STDOUT, "All green.\n");
exit(0);
